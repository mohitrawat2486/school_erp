<?php

namespace App\Services;

use App\Enums\StudentStatus;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use App\Rules\AadhaarNumber;
use App\Support\AdmissionGuardianRules;
use App\Support\CurrentSchool;
use App\Support\GuardianEditorRules;
use App\Support\StudentDepartureRules;
use App\Support\StudentEnrollmentRules;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class StudentService
{
    public const FIELDS = ['admission_number', 'first_name', 'middle_name', 'last_name', 'gender', 'date_of_birth', 'admission_date', 'blood_group', 'address', 'aadhaar_number'];

    private function lockSchool(): void
    {
        School::whereKey(app(CurrentSchool::class)->id())->lockForUpdate()->firstOrFail();
    }

    private function snapshot(Student $student): array
    {
        return Arr::only($student->getAttributes(), [...self::FIELDS, 'status', 'photo_path']);
    }

    private function authorize(User $actor, ?Student $student): void
    {
        $actor = $actor->fresh();
        abort_unless($actor, 403);
        Gate::forUser($actor)->authorize($student ? 'update' : 'create', $student ?? Student::class);
    }

    public function save(User $actor, ?Student $student, array $data, ?UploadedFile $photo = null): Student
    {
        $this->authorize($actor, $student);
        Validator::make($data, ['aadhaar_number' => ['required', 'string', new AadhaarNumber]])->validate();
        Validator::make($data, AdmissionGuardianRules::rules($student === null))->validate();
        Validator::make($data, GuardianEditorRules::rules($data, $student))->validate();
        Validator::make($data, StudentEnrollmentRules::rules($data))->validate();
        Validator::make($data, StudentDepartureRules::rules($data))->validate();
        $newPath = null;
        try {
            if ($photo) {
                $newPath = $photo->storeAs('schools/'.app(CurrentSchool::class)->id().'/students/photos', Str::uuid().'.'.$photo->extension(), 'local');
                if (! $newPath) {
                    throw new RuntimeException('Student photo could not be stored.');
                }
            }

            return DB::transaction(function () use ($actor, $student, $data, $newPath, $photo) {
                $this->lockSchool();
                $this->authorize($actor, $student);
                $creating = $student === null;
                $record = $student ? Student::whereKey($student->id)->lockForUpdate()->firstOrFail() : new Student;
                $duplicate = Student::where('admission_number', $data['admission_number']);
                if ($record->exists) {
                    $duplicate->whereKeyNot($record->id);
                }
                if ($duplicate->exists()) {
                    throw ValidationException::withMessages(['admission_number' => 'This admission number is already in use.']);
                }
                $old = $record->exists ? $this->snapshot($record) : [];
                $departure = app(StudentDeparture::class)->prepare($actor, $creating ? null : $record, $data, $photo);
                if ($departure && $departure['replay']) {
                    if ($newPath) {
                        Storage::disk('local')->delete($newPath);
                    }

                    return $record;
                }
                $enrollment = app(StudentEnrollmentEditor::class)->prepare($actor, $creating ? null : $record, $data);
                if (! $creating && ! empty($data['guardians_present'])) {
                    app(StudentGuardianEditor::class)->sync($actor, $record, $data, address: array_key_exists('address', $data) ? $data['address'] : $record->address);
                }
                $record->fill(Arr::only($data, self::FIELDS));
                $admissionDateChanged = ! $creating && $record->isDirty('admission_date');
                if ($newPath) {
                    $record->photo_path = $newPath;
                }
                if ($creating || $record->isDirty()) {
                    $record->save();
                    app(AuditLogger::class)->record($actor, $creating ? 'STUDENT_CREATED' : 'STUDENT_UPDATED', 'student', $record->id, $old, $this->snapshot($record), 'students');
                }
                if ($creating) {
                    if (! empty($data['guardians_present'])) {
                        app(StudentGuardianEditor::class)->sync($actor, $record, $data, creating: true, address: $record->address);
                    } else {
                        app(AdmissionGuardian::class)->attach($actor, $record, $data);
                    }
                }
                app(StudentEnrollmentEditor::class)->apply($actor, $record, $enrollment);
                app(StudentDeparture::class)->apply($actor, $departure);
                if ($admissionDateChanged && $record->enrollments()->where('joined_at', '<', $record->admission_date->format('Y-m-d'))->exists()) {
                    throw ValidationException::withMessages(['admission_date' => 'Admission date cannot be after an existing enrollment joined date.']);
                }

                return $record;
            });
        } catch (Throwable $exception) {
            if ($newPath) {
                Storage::disk('local')->delete($newPath);
            }
            throw $exception;
        }
    }

    public function setStatus(User $actor, Student $student, StudentStatus $status): void
    {
        $this->authorize($actor, $student);
        DB::transaction(function () use ($actor, $student, $status) {
            $this->lockSchool();
            $this->authorize($actor, $student);
            $record = Student::whereKey($student->id)->lockForUpdate()->firstOrFail();
            if ($record->status === $status) {
                return;
            }
            $old = $this->snapshot($record);
            $record->status = $status;
            $record->save();
            app(AuditLogger::class)->record($actor, $status === StudentStatus::Active ? 'STUDENT_ACTIVATED' : 'STUDENT_DEACTIVATED', 'student', $record->id, $old, $this->snapshot($record), 'students');
        });
    }
}

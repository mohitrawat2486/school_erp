<?php

namespace App\Services;

use App\Enums\TeacherAssignmentStatus;
use App\Models\AcademicYear;
use App\Models\ClassSubject;
use App\Models\SchoolClass;
use App\Models\TeacherAssignment;
use App\Models\User;
use App\Support\ClassTeacherRules;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ClassTeacherSetup
{
    /** Called inside the class setup's school-locked transaction. */
    public function apply(User $actor, SchoolClass $class, array $sectionMap, array $data): void
    {
        $changes = $data['teacher_changes'] ?? [];
        if ($changes === []) {
            return;
        }
        Gate::forUser($actor)->authorize('create', TeacherAssignment::class);
        Validator::make($data, ClassTeacherRules::rules(), ClassTeacherRules::messages())->validate();
        $year = AcademicYear::whereKey($data['academic_year_id'] ?? null)->where('is_current', true)->where('status', 'ACTIVE')->first();
        if (! $year || $class->status->value !== 'ACTIVE') {
            $this->fail('Set an active current academic year and activate the class before assigning teachers.');
        }
        $sections = $class->sections()->where('status', 'ACTIVE')->get()->keyBy('id');
        $subjectIds = ClassSubject::where('academic_year_id', $year->id)->where('class_id', $class->id)->where('status', 'ACTIVE')->whereHas('subject', fn ($q) => $q->where('status', 'ACTIVE'))->pluck('subject_id')->map(fn ($id) => (int) $id)->all();
        $existing = TeacherAssignment::where('academic_year_id', $year->id)->where('class_id', $class->id)->where('status', 'ACTIVE')->orderBy('id')->get();
        $desired = [];
        foreach ($existing as $assignment) {
            $ids = $assignment->section_id === null ? $sections->keys()->all() : [$assignment->section_id];
            foreach ($ids as $id) {
                if ($sections->has($id)) {
                    $desired[$this->key($id, $assignment->subject_id)][$assignment->teacher_id] = true;
                }
            }
        }
        $affected = [];
        $seen = [];
        foreach ($changes as $change) {
            $section = $sectionMap[$change['section_key']] ?? null;
            if (! $section || ! $sections->has($section->id)) {
                $this->fail('Choose a section in this class before assigning its teacher.');
            }
            $subject = isset($change['subject_id']) ? (int) $change['subject_id'] : null;
            if ($subject !== null && ! in_array($subject, $subjectIds, true)) {
                $this->fail('Select the subject for this class before assigning its teacher.');
            }
            $key = $this->key($section->id, $subject);
            if (isset($seen[$key])) {
                $this->fail('Choose each section and subject teacher only once.');
            }
            $seen[$key] = true;
            $target = isset($change['teacher_id']) && $change['teacher_id'] !== '' ? [(int) $change['teacher_id'] => true] : [];
            if (array_keys($desired[$key] ?? []) === array_keys($target)) {
                continue;
            }
            $desired[$key] = $target;
            $affected[$key] = true;
        }
        if ($affected === []) {
            return;
        }
        // Expand only whole-class assignments affected by a changed section. Keep
        // all other coverage, including co-teachers, without rewriting their rows.
        $remaining = [];
        $service = app(TeacherAssignmentService::class);
        foreach ($existing as $assignment) {
            $ids = $assignment->section_id === null ? $sections->keys()->all() : [$assignment->section_id];
            $remove = false;
            foreach ($ids as $id) {
                $key = $this->key($id, $assignment->subject_id);
                if (isset($affected[$key]) && ! isset($desired[$key][$assignment->teacher_id])) {
                    $remove = true;
                }
            }
            if ($remove) {
                $service->setStatus($actor, $assignment, TeacherAssignmentStatus::Inactive);
            } else {
                foreach ($ids as $id) {
                    $remaining[$this->key($id, $assignment->subject_id)][$assignment->teacher_id] = true;
                }
            }
        }
        foreach ($desired as $key => $teachers) {
            [$sectionId, $subjectKey] = explode(':', $key);
            foreach (array_keys($teachers) as $teacherId) {
                if (isset($remaining[$key][$teacherId])) {
                    continue;
                }
                $subjectId = $subjectKey === 'class' ? null : (int) $subjectKey;
                $identity = ['academic_year_id' => $year->id, 'class_id' => $class->id, 'teacher_id' => $teacherId, 'section_id' => (int) $sectionId, 'subject_id' => $subjectId, 'assignment_type' => $subjectId === null ? 'CLASS_TEACHER' : 'SUBJECT_TEACHER'];
                $retained = TeacherAssignment::where($identity)->first();
                try {
                    if ($retained) {
                        $service->setStatus($actor, $retained, TeacherAssignmentStatus::Active);
                    } else {
                        $service->create($actor, $identity);
                    }
                } catch (ValidationException $exception) {
                    $this->fail(implode(' ', $exception->validator->errors()->all()).' Review the teacher choices for all sections.');
                }
            }
        }
    }

    private function key(int|string $sectionId, ?int $subjectId): string
    {
        return $sectionId.':'.($subjectId ?? 'class');
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['teacher_changes' => $message]);
    }
}

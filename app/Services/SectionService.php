<?php

namespace App\Services;

use App\Enums\ClassStatus;
use App\Enums\SectionStatus;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\User;
use App\Support\CurrentSchool;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class SectionService
{
    private function authorize(User $actor, ?Section $section): void
    {
        $actor = $actor->fresh();
        abort_unless($actor, 403);
        Gate::forUser($actor)->authorize($section ? 'update' : 'create', $section ?? Section::class);
    }

    private function lockSchool(): void
    {
        School::whereKey(app(CurrentSchool::class)->id())->lockForUpdate()->firstOrFail();
    }

    private function requireActiveClass(SchoolClass $schoolClass): void
    {
        if ($schoolClass->status !== ClassStatus::Active) {
            throw ValidationException::withMessages(['class_id' => 'An active class is required.']);
        }
    }

    public function save(User $actor, ?Section $section, array $data): Section
    {
        $this->authorize($actor, $section);

        return DB::transaction(function () use ($actor, $section, $data) {
            $this->lockSchool();
            $this->authorize($actor, $section);
            $record = $section ? Section::whereKey($section->id)->lockForUpdate()->firstOrFail() : new Section;
            $creating = ! $record->exists;
            $schoolClass = SchoolClass::whereKey($creating ? $data['class_id'] : $record->class_id)->lockForUpdate()->firstOrFail();
            if ($creating) {
                $this->requireActiveClass($schoolClass);
                $record->class_id = $schoolClass->id;
            } elseif (array_key_exists('class_id', $data)) {
                throw ValidationException::withMessages(['class_id' => 'The linked class cannot be changed.']);
            }
            $duplicate = Section::where('class_id', $record->class_id)->where('name', $data['name']);
            if (! $creating) {
                $duplicate->whereKeyNot($record->id);
            }
            if ($duplicate->exists()) {
                throw ValidationException::withMessages(['name' => 'This section name is already in use for the class.']);
            }
            $old = $creating ? [] : $record->only(Section::AUDIT_FIELDS);
            $record->fill(Arr::only($data, ['name', 'capacity']));
            if (! $creating && $record->isDirty('capacity') && $record->capacity !== null && $record->enrollments()->where('status', 'ACTIVE')->select('academic_year_id')->groupBy('academic_year_id')->havingRaw('COUNT(*) > ?', [$record->capacity])->exists()) {
                throw ValidationException::withMessages(['capacity' => 'Capacity cannot be lower than active enrollment in any academic year.']);
            }
            if (! $creating && ! $record->isDirty()) {
                return $record;
            }
            $record->save();
            app(AuditLogger::class)->record($actor, $creating ? 'SECTION_CREATED' : 'SECTION_UPDATED', 'section', $record->id, $old, $record->only(Section::AUDIT_FIELDS), 'academics');

            return $record;
        });
    }

    public function setStatus(User $actor, Section $section, SectionStatus $status): void
    {
        $this->authorize($actor, $section);
        DB::transaction(function () use ($actor, $section, $status) {
            $this->lockSchool();
            $this->authorize($actor, $section);
            $record = Section::whereKey($section->id)->lockForUpdate()->firstOrFail();
            $schoolClass = SchoolClass::whereKey($record->class_id)->lockForUpdate()->firstOrFail();
            if ($status === SectionStatus::Active) {
                $this->requireActiveClass($schoolClass);
            }
            if ($record->status === $status) {
                return;
            }
            $old = $record->only(Section::AUDIT_FIELDS);
            $record->status = $status;
            $record->save();
            app(AuditLogger::class)->record($actor, $status === SectionStatus::Active ? 'SECTION_ACTIVATED' : 'SECTION_DEACTIVATED', 'section', $record->id, $old, $record->only(Section::AUDIT_FIELDS), 'academics');
        });
    }

    public function deleteUnused(User $actor, Section $section): void
    {
        $this->authorize($actor, $section);
        DB::transaction(function () use ($actor, $section) {
            $this->lockSchool();
            $record = Section::whereKey($section->id)->withUsage()->lockForUpdate()->firstOrFail();
            $this->authorize($actor, $record);
            if ($record->isInUse()) {
                throw ValidationException::withMessages(['sections' => 'Section '.$record->name.' has students, teachers or academic history. Keep it selected.']);
            }
            $before = $record->only(Section::AUDIT_FIELDS);
            try {
                $record->delete();
            } catch (QueryException $exception) {
                if (($exception->errorInfo[1] ?? null) !== 1451) {
                    throw $exception;
                }
                throw ValidationException::withMessages(['sections' => 'Section '.$record->name.' is referenced by another record and cannot be removed.']);
            }
            app(AuditLogger::class)->record($actor, 'SECTION_DELETED', 'section', $record->id, $before, [], 'academics');
        });
    }
}

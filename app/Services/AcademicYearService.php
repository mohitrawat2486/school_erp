<?php

namespace App\Services;

use App\Enums\AcademicYearStatus;
use App\Models\AcademicYear;
use App\Models\School;
use App\Models\User;
use App\Support\CurrentSchool;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AcademicYearService
{
    private function lockSchool(): void
    {
        School::whereKey(app(CurrentSchool::class)->id())->lockForUpdate()->firstOrFail();
    }

    private function ensureUniqueName(string $name, ?int $except = null): void
    {
        $query = AcademicYear::withTrashed()->where('name', $name);
        if ($except !== null) {
            $query->whereKeyNot($except);
        }
        if ($query->exists()) {
            throw ValidationException::withMessages(['name' => 'This academic year name is already in use.']);
        }
    }

    private function snapshot(AcademicYear $year): array
    {
        return ['name' => $year->name, 'start_date' => $year->start_date->toDateString(), 'end_date' => $year->end_date->toDateString(), 'status' => $year->status->value, 'is_current' => $year->is_current, 'deleted_at' => $year->deleted_at?->toIso8601String()];
    }

    private function audit(User $actor, string $action, AcademicYear $year, array $old): void
    {
        app(AuditLogger::class)->record($actor, $action, 'academic_year', $year->id, $old, $this->snapshot($year), 'academics');
    }

    public function create(User $actor, array $data): AcademicYear
    {
        Gate::forUser($actor)->authorize('create', AcademicYear::class);

        return DB::transaction(function () use ($actor, $data) {
            $this->lockSchool();
            $this->ensureUniqueName($data['name']);
            $year = AcademicYear::create($data);
            $this->audit($actor, 'ACADEMIC_YEAR_CREATED', $year, []);

            return $year;
        });
    }

    public function update(User $actor, AcademicYear $year, array $data): void
    {
        Gate::forUser($actor)->authorize('update', $year);
        DB::transaction(function () use ($actor, $year, $data) {
            $this->lockSchool();
            $record = AcademicYear::whereKey($year->id)->lockForUpdate()->firstOrFail();
            if ($record->status !== AcademicYearStatus::Draft) {
                throw ValidationException::withMessages(['academic_year' => 'Only draft years can be edited.']);
            }
            $old = $this->snapshot($record);
            $this->ensureUniqueName($data['name'], $record->id);
            $record->fill($data);
            if ($record->enrollments()->where(fn ($q) => $q->where('joined_at', '<', $record->start_date->format('Y-m-d'))->orWhere('joined_at', '>', $record->end_date->format('Y-m-d')))->exists()) {
                throw ValidationException::withMessages(['academic_year' => 'Year dates must include all existing enrollment joined dates.']);
            }
            if ($record->exams()->where(fn ($q) => $q->where('start_date', '<', $record->start_date->format('Y-m-d'))->orWhere('end_date', '>', $record->end_date->format('Y-m-d')))->exists()) {
                throw ValidationException::withMessages(['academic_year' => 'Year dates must include all existing exam dates.']);
            }
            if (! $record->isDirty()) {
                return;
            }
            $record->save();
            $this->audit($actor, 'ACADEMIC_YEAR_UPDATED', $record, $old);
        });
    }

    public function transition(User $actor, AcademicYear $year, string $action): void
    {
        if (! in_array($action, ['activate', 'close', 'archive', 'delete'], true)) {
            throw new \InvalidArgumentException('Invalid academic year action.');
        }
        Gate::forUser($actor)->authorize($action, $year);
        DB::transaction(function () use ($actor, $year, $action) {
            $this->lockSchool();
            $record = AcademicYear::whereKey($year->id)->lockForUpdate()->firstOrFail();
            $old = $this->snapshot($record);
            $allowed = match ($action) {
                'activate' => in_array($record->status, [AcademicYearStatus::Draft, AcademicYearStatus::Active], true),
                'close' => $record->status === AcademicYearStatus::Active,
                'archive' => $record->status === AcademicYearStatus::Closed,
                'delete' => $record->status === AcademicYearStatus::Draft,
            };
            if (! $allowed) {
                throw ValidationException::withMessages(['academic_year' => 'This action is not allowed for the current year status.']);
            }
            if ($action === 'delete' && ($record->classSubjects()->exists() || $record->teacherAssignments()->exists() || $record->enrollments()->exists() || $record->examTerms()->exists() || $record->exams()->exists())) {
                throw ValidationException::withMessages(['academic_year' => 'Academic years with subject allocations, teacher assignments, enrollments or exam setup cannot be removed.']);
            }
            if ($action === 'close' && $record->exams()->where('status', '!=', 'CLOSED')->exists()) {
                throw ValidationException::withMessages(['academic_year' => 'Close all exams before closing the academic year.']);
            }
            if ($action === 'activate') {
                if ($record->is_current) {
                    return;
                }
                foreach (AcademicYear::where('is_current', true)->whereKeyNot($record->id)->lockForUpdate()->get() as $previous) {
                    $before = $this->snapshot($previous);
                    $previous->is_current = false;
                    $previous->save();
                    $this->audit($actor, 'ACADEMIC_YEAR_CURRENT_REMOVED', $previous, $before);
                }
                $record->status = AcademicYearStatus::Active;
                $record->is_current = true;
            } elseif ($action === 'close') {
                $record->status = AcademicYearStatus::Closed;
                $record->is_current = false;
            } elseif ($action === 'archive') {
                $record->status = AcademicYearStatus::Archived;
            }
            if ($action === 'delete') {
                $record->delete();
            } else {
                $record->save();
            }
            $this->audit($actor, match ($action) {
                'activate' => 'ACADEMIC_YEAR_ACTIVATED',
                'close' => 'ACADEMIC_YEAR_CLOSED',
                'archive' => 'ACADEMIC_YEAR_ARCHIVED',
                'delete' => 'ACADEMIC_YEAR_DELETED',
            }, $record, $old);
        });
    }
}

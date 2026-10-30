<?php

namespace App\Services;

use App\Enums\ClassStatus;
use App\Enums\SectionStatus;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\User;
use App\Support\ClassCode;
use App\Support\CurrentSchool;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class SchoolClassService
{
    private function authorize(User $actor, ?SchoolClass $schoolClass): void
    {
        $actor = $actor->fresh();
        abort_unless($actor, 403);
        Gate::forUser($actor)->authorize($schoolClass ? 'update' : 'create', $schoolClass ?? SchoolClass::class);
    }

    private function lockSchool(): void
    {
        School::whereKey(app(CurrentSchool::class)->id())->lockForUpdate()->firstOrFail();
    }

    public function save(User $actor, ?SchoolClass $schoolClass, array $data): SchoolClass
    {
        $this->authorize($actor, $schoolClass);

        return DB::transaction(function () use ($actor, $schoolClass, $data) {
            $this->lockSchool();
            $this->authorize($actor, $schoolClass);
            $record = $schoolClass ? SchoolClass::whereKey($schoolClass->id)->lockForUpdate()->firstOrFail() : new SchoolClass;
            $creating = ! $record->exists;
            $data['name'] = trim($data['name']);
            $data['code'] = $creating || $data['name'] !== $record->name ? ClassCode::fromName($data['name']) : $record->code;
            if (! isset($data['sort_order'])) {
                $data['sort_order'] = $creating ? (preg_match('/\b(\d+)\b/', $data['name'], $match) ? min((int) $match[1], 4294967295) : min((int) SchoolClass::max('sort_order') + 1, 4294967295)) : $record->sort_order;
            }

            foreach (['name', 'code'] as $field) {
                $duplicate = SchoolClass::where($field, $data[$field]);
                if (! $creating) {
                    $duplicate->whereKeyNot($record->id);
                }
                if ($duplicate->exists()) {
                    throw ValidationException::withMessages([$field => 'This class '.$field.' is already in use.']);
                }
            }
            $old = $creating ? [] : $record->only(SchoolClass::AUDIT_FIELDS);
            $record->fill(Arr::only($data, ['name', 'code', 'sort_order']));
            if (! $creating && ! $record->isDirty()) {
                return $record;
            }
            $record->save();
            app(AuditLogger::class)->record($actor, $creating ? 'CLASS_CREATED' : 'CLASS_UPDATED', 'class', $record->id, $old, $record->only(SchoolClass::AUDIT_FIELDS), 'academics');

            return $record;
        });
    }

    public function setStatus(User $actor, SchoolClass $schoolClass, ClassStatus $status): void
    {
        $this->authorize($actor, $schoolClass);
        DB::transaction(function () use ($actor, $schoolClass, $status) {
            $this->lockSchool();
            $this->authorize($actor, $schoolClass);
            $record = SchoolClass::whereKey($schoolClass->id)->lockForUpdate()->firstOrFail();
            if ($record->status === $status) {
                return;
            }
            $old = $record->only(SchoolClass::AUDIT_FIELDS);
            $record->status = $status;
            $record->save();
            if ($status === ClassStatus::Inactive) {
                $record->sections()->where('status', SectionStatus::Active->value)->lockForUpdate()->chunkById(100, function ($sections) use ($actor) {
                    foreach ($sections as $section) {
                        $before = $section->only(Section::AUDIT_FIELDS);
                        $section->status = SectionStatus::Inactive;
                        $section->save();
                        app(AuditLogger::class)->record($actor, 'SECTION_DEACTIVATED', 'section', $section->id, $before, [...$section->only(Section::AUDIT_FIELDS), 'reason' => 'class_inactive'], 'academics');
                    }
                });
            }
            app(AuditLogger::class)->record($actor, $status === ClassStatus::Active ? 'CLASS_ACTIVATED' : 'CLASS_DEACTIVATED', 'class', $record->id, $old, $record->only(SchoolClass::AUDIT_FIELDS), 'academics');
        });
    }
}

<?php

namespace App\Services;

use App\Enums\GuardianStatus;
use App\Models\Guardian;
use App\Models\School;
use App\Models\User;
use App\Support\CurrentSchool;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class GuardianService
{
    public const FIELDS = ['first_name', 'last_name', 'phone', 'alternate_phone', 'email', 'occupation', 'address'];

    public function lock(User $actor, ?Guardian $guardian): void
    {
        School::whereKey(app(CurrentSchool::class)->id())->lockForUpdate()->firstOrFail();
        $actor = $actor->fresh();
        abort_unless($actor, 403);
        Gate::forUser($actor)->authorize($guardian ? 'update' : 'create', $guardian ?? Guardian::class);
    }

    public function save(User $actor, ?Guardian $guardian, array $data): Guardian
    {
        return DB::transaction(function () use ($actor, $guardian, $data) {
            $this->lock($actor, $guardian);
            $record = $guardian ? Guardian::whereKey($guardian->id)->lockForUpdate()->firstOrFail() : new Guardian;
            $before = $record->exists ? $record->only([...self::FIELDS, 'status']) : [];
            $creating = ! $record->exists;
            $record->fill(Arr::only($data, self::FIELDS));
            if (! $creating && ! $record->isDirty()) {
                return $record;
            }
            $record->save();
            app(AuditLogger::class)->record($actor, $creating ? 'GUARDIAN_CREATED' : 'GUARDIAN_UPDATED', 'guardian', $record->id, $before, $record->only([...self::FIELDS, 'status']), 'guardians');

            return $record;
        });
    }

    public function setStatus(User $actor, Guardian $guardian, GuardianStatus $status): void
    {
        DB::transaction(function () use ($actor, $guardian, $status) {
            $this->lock($actor, $guardian);
            $record = Guardian::whereKey($guardian->id)->lockForUpdate()->firstOrFail();
            if ($record->status === $status) {
                return;
            }
            if ($status === GuardianStatus::Inactive && $record->studentLinks()->where('status', 'ACTIVE')->where('is_primary', true)->exists()) {
                throw ValidationException::withMessages(['guardian' => 'Replace or unlink this guardian as primary contact for every student before deactivating.']);
            }
            $before = ['status' => $record->status->value];
            $record->status = $status;
            $record->save();
            app(AuditLogger::class)->record($actor, $status === GuardianStatus::Active ? 'GUARDIAN_ACTIVATED' : 'GUARDIAN_DEACTIVATED', 'guardian', $record->id, $before, ['status' => $status->value], 'guardians');
        });
    }
}

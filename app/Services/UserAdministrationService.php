<?php

namespace App\Services;

use App\Models\User;
use App\Support\CurrentSchool;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UserAdministrationService
{
    public function save(User $actor, ?User $target, array $data): User
    {
        return DB::transaction(function () use ($actor, $target, $data) {
            app(RbacGuard::class)->lockAndAuthorize($actor);
            $creating = $target === null;
            if ($target) {
                $target = User::where('school_id', app(CurrentSchool::class)->id())->whereKey($target->id)->lockForUpdate()->firstOrFail();
            }
            $target ??= new User;
            if ($target->id === $actor->id && ! $data['is_active']) {
                throw ValidationException::withMessages(['is_active' => 'You cannot disable your own account.']);
            }
            $duplicate = User::where('email', $data['email']);
            if ($target->exists) {
                $duplicate->whereKeyNot($target->id);
            }
            if ($duplicate->exists()) {
                throw ValidationException::withMessages(['email' => 'This email address is already in use.']);
            }
            $old = $target->exists ? $target->only(['name', 'email', 'is_active']) : [];
            $target->fill(['name' => $data['name'], 'email' => $data['email']]);
            $target->school_id = app(CurrentSchool::class)->id();
            $target->is_active = (bool) $data['is_active'];
            $credentialsChanged = ! empty($data['password']) || $target->isDirty('email');
            if (! empty($data['password'])) {
                $target->password = $data['password'];
            }
            if ($target->isDirty('email')) {
                $target->email_verified_at = null;
            }
            if ($credentialsChanged || ! $target->is_active) {
                $target->remember_token = Str::random(60);
            }
            $target->save();
            app(AccessManager::class)->assignRoles($actor, $target, $data['roles'] ?? []);
            app(RbacGuard::class)->ensureManagerRemains();
            if (! $creating && ($credentialsChanged || ! $target->is_active)) {
                DB::table('sessions')->where('user_id', $target->id)->delete();
            }
            $new = $target->only(['name', 'email', 'is_active']);
            $new['credentials_changed'] = $credentialsChanged;
            app(AuditLogger::class)->record($actor, $creating ? 'USER_CREATED' : 'USER_UPDATED', 'user', $target->id, $old, $new);

            if (! $creating && (bool) $old['is_active'] !== (bool) $target->is_active) {
                app(AuditLogger::class)->record($actor, $target->is_active ? 'USER_ENABLED' : 'USER_DISABLED', 'user', $target->id, ['is_active' => $old['is_active']], ['is_active' => $target->is_active]);
            }

            return $target;
        });
    }
}

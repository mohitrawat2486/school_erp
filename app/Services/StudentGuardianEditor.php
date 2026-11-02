<?php

namespace App\Services;

use App\Enums\GuardianStatus;
use App\Models\Guardian;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use App\Support\CurrentSchool;
use App\Support\GuardianEditorRules;
use App\Support\GuardianEditorState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class StudentGuardianEditor
{
    public function sync(User $actor, Student $student, array $data, bool $creating = false, ?string $address = null): void
    {
        DB::transaction(function () use ($actor, $student, $data, $creating, $address) {
            School::whereKey(app(CurrentSchool::class)->id())->lockForUpdate()->firstOrFail();
            $actor = $actor->fresh();
            Gate::forUser($actor)->authorize('update', $student);
            Gate::forUser($actor)->authorize('create', Guardian::class);
            $record = Student::whereKey($student->id)->lockForUpdate()->firstOrFail();
            $data = Validator::make($data, GuardianEditorRules::rules($data, $creating ? null : $record))->validate();
            if (! $creating && ! hash_equals(GuardianEditorState::student($record), $data['guardian_setup_revision'] ?? '')) {
                throw ValidationException::withMessages(['guardian_setup_revision' => 'Student or guardian details changed while this form was open. The latest guardians have been loaded. Review your changes and save again.']);
            }
            $rows = $data['guardians'] ?? [];
            foreach (array_keys($rows) as $key) {
                if (! ctype_digit((string) $key) || strlen((string) $key) > 6) {
                    throw ValidationException::withMessages(['guardians' => 'Please add guardians using the guardian cards.']);
                }
            }
            $current = $record->guardianLinks()->where('status', 'ACTIVE')->with('guardian')->lockForUpdate()->get()->keyBy('guardian_id');
            $ids = [];
            foreach ($rows as $key => $row) {
                if ($row['kind'] === 'existing') {
                    $id = (int) $row['guardian_id'];
                    if (in_array($id, $ids, true)) {
                        throw ValidationException::withMessages(['guardians.'.$key.'.guardian_id' => 'This guardian is already selected. Choose a different contact.']);
                    }
                    $ids[] = $id;
                    $guardian = Guardian::whereKey($id)->lockForUpdate()->firstOrFail();
                    if (! hash_equals(GuardianEditorState::contact($guardian), $row['revision'])) {
                        throw ValidationException::withMessages(['guardians.'.$key.'.revision' => 'This contact changed. Select the contact again to load the latest details.']);
                    }
                    if ($guardian->status !== GuardianStatus::Active && (! $current->has($id) || ! empty($row['edit_contact']) || $row['relationship'] !== $current[$id]->relationship->value || (bool) $row['is_emergency_contact'] !== $current[$id]->is_emergency_contact || (string) ($data['primary_guardian'] ?? '') === (string) $key)) {
                        throw ValidationException::withMessages(['guardians.'.$key.'.guardian_id' => 'This guardian is inactive. Remove this link or choose an active guardian.']);
                    }
                }
            }
            if (count($rows) > 3 && (count($rows) > $current->count() || count($ids) !== count($rows) || array_diff($ids, $current->keys()->all()))) {
                throw ValidationException::withMessages(['guardians' => 'A student can have up to 3 guardians. Remove contacts before adding another.']);
            }
            if ($rows && ! array_key_exists($data['primary_guardian'] ?? '', $rows)) {
                throw ValidationException::withMessages(['primary_guardian' => 'Choose one primary contact for this student.']);
            }
            $removed = $current->filter(fn ($link) => ! in_array((int) $link->guardian_id, $ids, true));
            if ($removed->isNotEmpty() && empty($data['confirm_guardian_removals'])) {
                throw ValidationException::withMessages(['confirm_guardian_removals' => 'Confirm the guardian removals before saving. The guardian records and history will be retained.']);
            }
            foreach ($removed as $link) {
                app(StudentGuardianService::class)->unlink($actor, $record, $link->guardian);
            }
            // Process the primary contact last so the existing unique-primary constraint always holds.
            uksort($rows, fn ($a, $b) => ((string) $a === (string) ($data['primary_guardian'] ?? '')) <=> ((string) $b === (string) ($data['primary_guardian'] ?? '')));
            foreach ($rows as $key => $row) {
                $guardian = $row['kind'] === 'existing' ? Guardian::whereKey($row['guardian_id'])->firstOrFail() : null;
                if ($guardian && $guardian->status !== GuardianStatus::Active) {
                    continue;
                }
                if (! $guardian || ! empty($row['edit_contact'])) {
                    $details = $row['details'];
                    if ($details['same_address']) {
                        $details['address'] = $address;
                    }
                    $guardian = app(GuardianService::class)->save($actor, $guardian, $details);
                }
                app(StudentGuardianService::class)->save($actor, $record, $guardian, ['relationship' => $row['relationship'], 'is_primary' => (string) $key === (string) ($data['primary_guardian'] ?? ''), 'is_emergency_contact' => $row['is_emergency_contact']]);
            }
        });
    }
}

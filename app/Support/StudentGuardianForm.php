<?php

namespace App\Support;

use App\Models\Guardian;
use App\Models\Student;
use Illuminate\Http\Request;

final class StudentGuardianForm
{
    public static function data(Request $request, Student $student): array
    {
        $links = $student->exists ? $student->guardianLinks()->where('status', 'ACTIVE')->with('guardian')->orderByDesc('is_primary')->orderBy('id')->get() : collect();
        $saved = [];
        foreach ($links as $index => $link) {
            $option = GuardianEditorState::option($link->guardian);
            $saved[$index] = ['kind' => 'existing', 'guardian_id' => $link->guardian_id, 'revision' => $option['revision'], 'edit_contact' => false, 'relationship' => $link->relationship->value, 'is_emergency_contact' => $link->is_emergency_contact, 'details' => $option['details'], 'option' => $option, 'saved_id' => $link->guardian_id, 'removed' => false, 'primary' => $link->is_primary];
        }
        $errors = $request->session()->get('errors');
        $restore = $request->old('guardians_present') && ! $errors?->has('guardian_setup_revision');
        $legacy = ! $student->exists && in_array($request->old('guardian_mode'), ['new', 'existing'], true);
        $rows = $saved;
        if ($restore || $legacy) {
            $rows = [];
            $old = $request->old('guardians', []);
            if ($legacy && ! $restore) {
                $old = [0 => ['kind' => $request->old('guardian_mode'), 'guardian_id' => $request->old('guardian_id'), 'relationship' => $request->old('guardian_relationship', 'GUARDIAN'), 'is_emergency_contact' => $request->old('guardian_emergency', true), 'details' => $request->old('guardian', [])]];
            }
            $old = is_array($old) ? array_slice($old, 0, max(3, $links->count()), true) : [];
            $savedById = collect($saved)->keyBy('guardian_id');
            foreach ($old as $key => $row) {
                if (! ctype_digit((string) $key) || strlen((string) $key) > 6 || ! is_array($row)) {
                    continue;
                }
                $id = filter_var($row['guardian_id'] ?? null, FILTER_VALIDATE_INT);
                $guardian = $id ? Guardian::whereKey($id)->first() : null;
                $option = $guardian ? GuardianEditorState::option($guardian) : null;
                $freshContact = $errors?->has('guardians.'.$key.'.revision');
                $rows[$key] = [
                    'kind' => ($row['kind'] ?? '') === 'existing' ? 'existing' : 'new',
                    'guardian_id' => $guardian?->id,
                    'revision' => $freshContact || $legacy ? ($option['revision'] ?? '') : (is_string($row['revision'] ?? null) ? $row['revision'] : ''),
                    'edit_contact' => ! $freshContact && ! empty($row['edit_contact']),
                    'relationship' => is_string($row['relationship'] ?? null) ? $row['relationship'] : 'GUARDIAN',
                    'is_emergency_contact' => ! empty($row['is_emergency_contact']),
                    'details' => $freshContact ? ($option['details'] ?? []) : (is_array($row['details'] ?? null) ? $row['details'] : ($option['details'] ?? [])),
                    'option' => $option, 'saved_id' => $id && $savedById->has($id) ? $id : null, 'removed' => false,
                    'primary' => $legacy || (string) $request->old('primary_guardian', '') === (string) $key,
                ];
            }
            $retained = collect($rows)->pluck('saved_id')->filter()->all();
            $next = $rows ? max(array_keys($rows)) + 1 : 0;
            foreach ($saved as $row) {
                if (! in_array($row['saved_id'], $retained)) {
                    $row['removed'] = true;
                    $row['primary'] = false;
                    $rows[$next++] = $row;
                }
            }
        }
        $options = Guardian::where('status', 'ACTIVE')->orderBy('first_name')->orderBy('last_name')->orderBy('id')->limit(20)->get()->map(fn ($guardian) => GuardianEditorState::option($guardian))->keyBy('id');
        foreach ($rows as $row) {
            if ($row['option'] ?? null) {
                $options->put($row['option']['id'], $row['option']);
            }
        }

        return ['guardianRows' => $rows, 'guardianOptions' => $options->values()->all(), 'guardianRevision' => $student->exists ? GuardianEditorState::student($student) : null, 'guardianLegacyCount' => $links->count(), 'guardianRestore' => (bool) $restore];
    }
}

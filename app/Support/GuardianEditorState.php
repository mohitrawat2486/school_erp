<?php

namespace App\Support;

use App\Models\Guardian;
use App\Models\Student;
use App\Services\GuardianService;
use App\Services\StudentService;

final class GuardianEditorState
{
    public static function contact(Guardian $guardian): string
    {
        return hash('sha256', json_encode([
            $guardian->only(['id', ...GuardianService::FIELDS, 'status', 'updated_at']),
            $guardian->studentLinks()->where('status', 'ACTIVE')->orderBy('student_id')->pluck('student_id')->all(),
        ]));
    }

    public static function student(Student $student): string
    {
        return hash('sha256', json_encode([
            $student->only(['id', ...StudentService::FIELDS, 'status', 'updated_at']),
            $student->guardianLinks()->where('status', 'ACTIVE')->with('guardian')->orderBy('id')->get()->map(fn ($link) => [
                $link->only(['id', 'guardian_id', 'relationship', 'is_primary', 'is_emergency_contact', 'status', 'updated_at']),
                self::contact($link->guardian),
            ])->all(),
        ]));
    }

    public static function option(Guardian $guardian): array
    {
        return [
            'id' => $guardian->id, 'name' => $guardian->fullName(), 'phone' => $guardian->phone,
            'revision' => self::contact($guardian), 'status' => $guardian->status->value,
            'shared_count' => $guardian->studentLinks()->where('status', 'ACTIVE')->count(),
            'details' => $guardian->only(GuardianService::FIELDS) + ['same_address' => false],
        ];
    }
}

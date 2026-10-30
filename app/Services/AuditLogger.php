<?php
namespace App\Services;
use App\Models\AuditLog;
use App\Models\StudentMark;
use App\Models\User;
use App\Support\CurrentSchool;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;

class AuditLogger
{
    public function record(User $actor, string $action, string $entityType, int $entityId, array $old = [], array $new = [], string $module = 'administration'): AuditLog
    {
        if ((int) $actor->school_id !== app(CurrentSchool::class)->id()) {
            throw new AuthorizationException('School mismatch.');
        }
        $redact = function (array $values) use (&$redact): array {
            foreach ($values as $key => $value) {
                if (preg_match('/password|token|secret/i', (string) $key)) {
                    $values[$key] = '[REDACTED]';
                } elseif (is_array($value)) {
                    $values[$key] = $redact($value);
                }
            }

            return $values;
        };

        $context = ['actor_name' => $actor->name];
        if ($entityType === 'student_mark') {
            $mark = StudentMark::with(['student', 'examSubject.subject', 'exam', 'enrollment.schoolClass', 'enrollment.section'])->findOrFail($entityId);
            $context += [
                'student_name' => $mark->student->fullName(),
                'admission_number' => $mark->student->admission_number,
                'subject_name' => $mark->examSubject->subject->name,
                'exam_name' => $mark->exam->name,
                'class_name' => $mark->enrollment->schoolClass->name,
                'section_name' => $mark->enrollment->section->name,
            ];
        }
        $http = request()->route() !== null;

        return AuditLog::create(['user_id' => $actor->id, 'module' => $module, 'action' => $action, 'entity_type' => $entityType, 'entity_id' => $entityId, 'old_values' => $redact($old), 'new_values' => $redact($new), 'context' => $redact($context), 'ip_address' => $http ? request()->ip() : null, 'user_agent' => $http ? Str::limit(request()->userAgent() ?? '', 4000, '') : null, 'created_at' => now()]);
    }
}

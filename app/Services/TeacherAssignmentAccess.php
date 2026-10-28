<?php

namespace App\Services;

use App\Enums\AssignmentType;
use App\Models\ClassSubject;
use App\Models\Section;
use App\Models\TeacherAssignment;
use App\Models\User;
use App\Support\CurrentSchool;
use Illuminate\Support\Facades\Gate;

class TeacherAssignmentAccess
{
    private function permits(User $user, string $permission, AssignmentType $type, int $yearId, int $classId, int $sectionId, ?int $subjectId): bool
    {
        $user = $user->fresh();
        if (! $user || (int) $user->school_id !== app(CurrentSchool::class)->id() || ! Gate::forUser($user)->allows($permission) || ! $user->employee_id) {
            return false;
        }
        $teacher = $user->employee?->teacherProfile;
        if (! $teacher || ! $teacher->isEligible()) {
            return false;
        }
        if (! Section::eligible()->where('class_id', $classId)->whereKey($sectionId)->exists()) {
            return false;
        }
        if ($subjectId !== null && ! ClassSubject::eligible()->where('academic_year_id', $yearId)->where('class_id', $classId)->where('subject_id', $subjectId)->exists()) {
            return false;
        }
        $query = TeacherAssignment::effective()->where('teacher_id', $teacher->id)->where('academic_year_id', $yearId)->where('class_id', $classId)->where('assignment_type', $type->value)->where(fn ($q) => $q->whereNull('section_id')->orWhere('section_id', $sectionId));
        if ($type === AssignmentType::SubjectTeacher) {
            $query->where('subject_id', $subjectId);
        }

        return $query->exists();
    }

    public function canEnterMarks(User $user, int $yearId, int $classId, int $sectionId, int $subjectId): bool
    {
        return $this->permits($user, 'marks.enter', AssignmentType::SubjectTeacher, $yearId, $classId, $sectionId, $subjectId);
    }

    public function canUpdateSubjectMarks(User $user, int $yearId, int $classId, int $sectionId, int $subjectId): bool
    {
        return $this->permits($user, 'marks.update', AssignmentType::SubjectTeacher, $yearId, $classId, $sectionId, $subjectId);
    }

    public function canVerifyClass(User $user, int $yearId, int $classId, int $sectionId): bool
    {
        return $this->permits($user, 'marks.verify', AssignmentType::ClassTeacher, $yearId, $classId, $sectionId, null);
    }

    public function canCorrectClassMarks(User $user, int $yearId, int $classId, int $sectionId, int $subjectId): bool
    {
        return $this->permits($user, 'marks.update', AssignmentType::ClassTeacher, $yearId, $classId, $sectionId, $subjectId);
    }
}

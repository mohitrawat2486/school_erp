<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\TeacherAssignment;
use App\Models\User;
use App\Support\CurrentSchool;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class SectionDirectory
{
    public function context(User $user, array $filters): array
    {
        return [
            'year' => ! empty($filters['academic_year_id']) ? AcademicYear::findOrFail($filters['academic_year_id']) : app(CurrentSchool::class)->academicYear(),
            'canTeachers' => $user->can('teacher_assignment.view'),
        ];
    }

    private function assignments(?AcademicYear $year): Builder
    {
        return TeacherAssignment::where('academic_year_id', $year?->id)->where('assignment_type', 'CLASS_TEACHER')->where('status', 'ACTIVE')
            ->whereHas('teacher', fn ($teachers) => $teachers->eligible());
    }

    private function coverage(?AcademicYear $year): Builder
    {
        return $this->assignments($year)->whereColumn('teacher_assignments.class_id', 'sections.class_id')
            ->where(fn ($scope) => $scope->whereNull('section_id')->orWhereColumn('teacher_assignments.section_id', 'sections.id'));
    }

    public function query(array $filters, array $context): Builder
    {
        $query = Section::with('schoolClass');
        if ($context['canTeachers']) {
            $query->with(['schoolClass.teacherAssignments' => fn ($assignments) => $assignments->whereIn('id', $this->assignments($context['year'])->select('id'))
                ->with('teacher.employee:id,school_id,first_name,middle_name,last_name')]);
        }
        if ($class = $filters['class_id'] ?? null) {
            $query->where('class_id', $class);
        }
        $term = data_get($filters, 'tables.page.search', $filters['q'] ?? '');
        if (is_string($term) && $term !== '') {
            $query->where(function ($search) use ($term, $context) {
                $pattern = '%'.$term.'%';
                $search->where('name', 'like', $pattern)->orWhereHas('schoolClass', fn ($classes) => $classes->where(fn ($names) => $names->where('name', 'like', $pattern)->orWhere('code', 'like', $pattern)))
                    ->orWhereRaw("CONCAT((SELECT code FROM classes WHERE classes.id = sections.class_id AND classes.school_id = sections.school_id), '-', sections.name) LIKE ?", [$pattern]);
                if ($context['canTeachers']) {
                    $search->orWhereExists($this->coverage($context['year'])->whereHas('teacher.employee', fn ($employees) => $employees->whereRaw("CONCAT_WS(' ', first_name, middle_name, last_name) LIKE ?", [$pattern]))->selectRaw('1')->toBase());
                }
            });
        }
        if (($status = $filters['status'] ?? null) === 'PENDING') {
            if (! $context['canTeachers'] || ! $context['year']) {
                throw ValidationException::withMessages(['status' => 'Choose an academic year with access to teacher assignments to filter pending sections.']);
            }
            $query->eligible()->whereNotExists($this->coverage($context['year'])->selectRaw('1')->toBase());
        } elseif ($status) {
            $query->where('status', $status);
        }

        return $query->orderBy(SchoolClass::select('sort_order')->whereColumn('classes.id', 'sections.class_id'))->orderBy('class_id')->orderBy('name')->orderBy('id');
    }

    public function row(Section $section, array $context): array
    {
        $teacher = $context['canTeachers'] ? $section->schoolClass->teacherAssignments->first(fn ($assignment) => $assignment->section_id === null || (int) $assignment->section_id === $section->id)?->teacher : null;
        $status = $section->status->value;
        if ($section->isEligible() && $context['year'] && $context['canTeachers'] && ! $teacher) {
            $status = 'PENDING';
        }

        return ['section' => $section, 'teacher' => $teacher, 'status' => $status, 'reference' => $section->schoolClass->code.'-'.$section->name];
    }

    public function data(User $user, array $filters): array
    {
        $context = $this->context($user, $filters);
        $sections = $this->query($filters, $context)->paginateTable(20, ['*'], 'page', [])->withQueryString();

        return $context + [
            'sections' => $sections, 'rows' => $sections->map(fn ($section) => $this->row($section, $context)), 'filters' => $filters,
            'selectedClass' => ! empty($filters['class_id']) ? SchoolClass::findOrFail($filters['class_id']) : null,
            'classOptions' => SchoolClass::orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'status']),
            'years' => AcademicYear::orderByDesc('start_date')->get(['id', 'name', 'is_current']),
            'totals' => ['sections' => Section::count(), 'active' => Section::where('status', 'ACTIVE')->count(), 'inactive' => Section::where('status', 'INACTIVE')->count(),
                'pending' => $context['canTeachers'] && $context['year'] ? Section::eligible()->whereNotExists($this->coverage($context['year'])->selectRaw('1')->toBase())->count() : null],
        ];
    }

    public function csvRow(Section $section, array $context): array
    {
        $row = $this->row($section, $context);
        $values = [$section->schoolClass->name, $section->name, $row['reference'], $section->capacity ?? ''];
        if ($context['canTeachers']) {
            $values[] = $row['teacher']?->employee->fullName() ?? '';
        }
        $values[] = $row['status'];

        return array_map(fn ($value) => is_string($value) && preg_match('/^\s*[=+\-@]|^[\t\r\n]/', $value) ? "'".$value : $value, $values);
    }
}

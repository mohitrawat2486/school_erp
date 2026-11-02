<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\StudentEnrollment;
use App\Models\Subject;
use App\Models\TeacherAssignment;
use App\Models\User;
use App\Support\CurrentSchool;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class ClassDirectory
{
    public function context(User $user, array $filters): array
    {
        $year = ! empty($filters['academic_year_id']) ? AcademicYear::findOrFail($filters['academic_year_id']) : app(CurrentSchool::class)->academicYear();

        return [
            'year' => $year,
            'mode' => $filters['view'] ?? ($user->can('section.view') ? 'sections' : 'classes'),
            'canSections' => $user->can('section.view'), 'canStudents' => $user->can('student.view'),
            'canTeachers' => $user->can('teacher_assignment.view'), 'canSubjects' => $user->can('subject.view'),
        ];
    }

    private function assignments(?AcademicYear $year): Builder
    {
        return TeacherAssignment::where('academic_year_id', $year?->id)->where('status', 'ACTIVE')->whereHas('teacher', fn ($query) => $query->eligible());
    }

    private function teacherCoverage(?AcademicYear $year): Builder
    {
        return $this->assignments($year)->where('assignment_type', 'CLASS_TEACHER')
            ->whereColumn('teacher_assignments.class_id', 'sections.class_id')
            ->where(fn ($query) => $query->whereNull('teacher_assignments.section_id')->orWhereColumn('teacher_assignments.section_id', 'sections.id'));
    }

    private function subjects($query, ?AcademicYear $year): void
    {
        $query->where('academic_year_id', $year?->id)->where('status', 'ACTIVE')->whereHas('subject', fn ($subjects) => $subjects->where('status', 'ACTIVE'));
    }

    private function pendingClasses(Builder $query, ?AcademicYear $year): void
    {
        $query->where('status', 'ACTIVE')->where(function ($classes) use ($year) {
            $classes->whereDoesntHave('sections', fn ($sections) => $sections->where('status', 'ACTIVE'))
                ->orWhereHas('sections', fn ($sections) => $sections->where('status', 'ACTIVE')->whereNotExists($this->teacherCoverage($year)->selectRaw('1')->toBase()));
        });
    }

    public function query(User $user, array $filters, array $context): Builder
    {
        $year = $context['year'];
        $sectionMode = $context['mode'] === 'sections';
        $query = $sectionMode ? Section::eligible() : SchoolClass::query();
        $relations = [];
        if ($context['canSections']) {
            $relations[$sectionMode ? 'schoolClass.sections' : 'sections'] = fn ($sections) => $sections->where('status', 'ACTIVE')->orderBy('name');
        }
        if ($sectionMode) {
            $relations[] = 'schoolClass';
        }
        if ($context['canTeachers']) {
            $relations[$sectionMode ? 'schoolClass.teacherAssignments' : 'teacherAssignments'] = function ($assignments) use ($year, $context) {
                $assignments->where('academic_year_id', $year?->id)->where('status', 'ACTIVE')->whereHas('teacher', fn ($teachers) => $teachers->eligible())
                    ->with('teacher.employee:id,school_id,first_name,middle_name,last_name');
                if ($context['canSubjects']) {
                    $assignments->with('subject');
                } else {
                    $assignments->where('assignment_type', 'CLASS_TEACHER');
                }
            };
        }
        if ($context['canSubjects']) {
            $relations[$sectionMode ? 'schoolClass.classSubjects' : 'classSubjects'] = function ($subjects) use ($year) {
                $this->subjects($subjects, $year);
                $subjects->with('subject');
            };
        }
        $query->with($relations);
        if ($context['canStudents']) {
            $query->withCount(['enrollments as students_count' => fn ($enrollments) => $enrollments->where('academic_year_id', $year?->id)->where('status', 'ACTIVE')]);
        }
        if ($class = $filters['class_id'] ?? null) {
            $query->where($sectionMode ? 'class_id' : 'id', $class);
        }
        if ($subject = $filters['subject_id'] ?? null) {
            $relation = $sectionMode ? 'schoolClass.classSubjects' : 'classSubjects';
            $query->whereHas($relation, function ($subjects) use ($year, $subject) {
                $this->subjects($subjects, $year);
                $subjects->where('subject_id', $subject);
            });
        }
        $term = data_get($filters, 'tables.page.search', $filters['q'] ?? '');
        if (is_string($term) && $term !== '') {
            $query->where(function ($search) use ($term, $sectionMode, $context, $year) {
                $pattern = '%'.$term.'%';
                if ($sectionMode) {
                    $search->where('sections.name', 'like', $pattern)->orWhereHas('schoolClass', fn ($classes) => $classes->where(fn ($names) => $names->where('name', 'like', $pattern)->orWhere('code', 'like', $pattern)))
                        ->orWhereRaw("CONCAT((SELECT code FROM classes WHERE classes.id = sections.class_id AND classes.school_id = sections.school_id), '-', sections.name) LIKE ?", [$pattern]);
                } else {
                    $search->where('name', 'like', $pattern)->orWhere('code', 'like', $pattern);
                    if ($context['canSections']) {
                        $search->orWhereHas('sections', fn ($sections) => $sections->where('status', 'ACTIVE')->where('name', 'like', $pattern));
                    }
                }
                if ($context['canSubjects']) {
                    $search->orWhereHas($sectionMode ? 'schoolClass.classSubjects' : 'classSubjects', function ($subjects) use ($year, $pattern) {
                        $this->subjects($subjects, $year);
                        $subjects->whereHas('subject', fn ($subject) => $subject->where(fn ($names) => $names->where('name', 'like', $pattern)->orWhere('code', 'like', $pattern)));
                    });
                }
                if ($context['canTeachers']) {
                    $search->orWhereHas($sectionMode ? 'schoolClass.teacherAssignments' : 'teacherAssignments', function ($assignments) use ($year, $pattern, $sectionMode) {
                        $assignments->where('academic_year_id', $year?->id)->where('status', 'ACTIVE')->whereHas('teacher', fn ($teachers) => $teachers->eligible()->whereHas('employee', fn ($employees) => $employees->whereRaw("CONCAT_WS(' ', first_name, middle_name, last_name) LIKE ?", [$pattern])));
                        if ($sectionMode) {
                            $assignments->where(fn ($scope) => $scope->whereNull('section_id')->orWhereColumn('teacher_assignments.section_id', 'sections.id'));
                        }
                    });
                }
            });
        }
        $status = $filters['status'] ?? null;
        if ($status === 'PENDING' && (! $year || ! $context['canTeachers'])) {
            throw ValidationException::withMessages(['status' => 'Choose an academic year with access to teacher assignments to filter pending setup.']);
        }
        if ($status === 'INACTIVE') {
            $query->where('status', 'INACTIVE');
        } elseif (in_array($status, ['ACTIVE', 'PENDING'], true)) {
            $query->where('status', 'ACTIVE');
            if ($year && $context['canTeachers']) {
                if ($sectionMode) {
                    $coverage = $this->teacherCoverage($year)->selectRaw('1')->toBase();
                    $status === 'PENDING' ? $query->whereNotExists($coverage) : $query->whereExists($coverage);
                } elseif ($status === 'PENDING') {
                    $this->pendingClasses($query, $year);
                } else {
                    $query->whereHas('sections', fn ($sections) => $sections->where('status', 'ACTIVE'))
                        ->whereDoesntHave('sections', fn ($sections) => $sections->where('status', 'ACTIVE')->whereNotExists($this->teacherCoverage($year)->selectRaw('1')->toBase()));
                }
            }
        }

        return $sectionMode
            ? $query->orderBy(SchoolClass::select('sort_order')->whereColumn('classes.id', 'sections.class_id'))->orderBy('class_id')->orderBy('name')->orderBy('id')
            : $query->orderBy('sort_order')->orderBy('name')->orderBy('id');
    }

    public function row(SchoolClass|Section $record, array $context): array
    {
        $isSection = $record instanceof Section;
        $class = $isSection ? $record->schoolClass : $record;
        $sections = $context['canSections'] ? $class->sections : collect();
        $assignments = $context['canTeachers'] ? $class->teacherAssignments : collect();
        $classTeachers = $assignments->where('assignment_type.value', 'CLASS_TEACHER')->filter(fn ($assignment) => $isSection ? ($assignment->section_id === null || (int) $assignment->section_id === $record->id) : ($assignment->section_id === null || $sections->contains('id', $assignment->section_id)));
        $teacher = $classTeachers->first()?->teacher;
        $subjects = $context['canSubjects'] ? $class->classSubjects->pluck('subject')->unique('id') : collect();
        $taught = $context['canSubjects'] && $teacher && $isSection ? $assignments->where('assignment_type.value', 'SUBJECT_TEACHER')->where('teacher_id', $teacher->id)->filter(fn ($assignment) => $assignment->section_id === null || (int) $assignment->section_id === $record->id)->pluck('subject')->filter(fn ($subject) => $subject?->status->value === 'ACTIVE' && $subjects->contains('id', $subject->id))->unique('id') : collect();
        $assigned = $isSection ? $teacher !== null : $sections->isNotEmpty() && $sections->every(fn ($section) => $classTeachers->contains(fn ($assignment) => $assignment->section_id === null || (int) $assignment->section_id === $section->id));
        $status = $record->status->value === 'INACTIVE' ? 'INACTIVE' : ($context['year'] && $context['canTeachers'] && ! $assigned ? 'PENDING' : 'ACTIVE');

        return compact('class', 'sections', 'classTeachers', 'teacher', 'subjects', 'taught', 'status') + ['record' => $record, 'students' => $record->students_count ?? null, 'reference' => $isSection ? $class->code.'-'.$record->name : $class->code];
    }

    public function data(User $user, array $filters): array
    {
        $context = $this->context($user, $filters);
        $records = $this->query($user, $filters, $context)->paginateTable(20, ['*'], 'page', [])->withQueryString();
        $sectionCount = $context['canSections'] ? Section::eligible()->count() : null;
        $assigned = $context['canTeachers'] && $context['year'] && $context['canSections'] ? Section::eligible()->whereExists($this->teacherCoverage($context['year'])->selectRaw('1')->toBase())->count() : null;
        $totals = ['classes' => SchoolClass::count(), 'sections' => $sectionCount, 'students' => $context['canStudents'] && $context['year'] ? StudentEnrollment::where('academic_year_id', $context['year']->id)->where('status', 'ACTIVE')->whereHas('section', fn ($sections) => $sections->eligible())->count() : null, 'teachers' => $assigned];

        return $context + [
            'records' => $records, 'rows' => $records->map(fn ($record) => $this->row($record, $context)), 'filters' => $filters, 'totals' => $totals,
            'classes' => $context['mode'] === 'classes' ? $records : null,
            'classOptions' => SchoolClass::orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'status']),
            'subjectOptions' => $context['canSubjects'] ? Subject::where('status', 'ACTIVE')->orderBy('name')->get(['id', 'name']) : collect(),
            'years' => AcademicYear::orderByDesc('start_date')->get(['id', 'name', 'is_current']),
            'coverage' => ['assigned' => $assigned, 'pending' => $assigned === null ? null : $sectionCount - $assigned],
        ];
    }

    public function export(User $user, array $filters): array
    {
        $context = $this->context($user, $filters);
        $headers = ['Class'];
        if ($context['mode'] === 'sections' || $context['canSections']) {
            $headers[] = $context['mode'] === 'sections' ? 'Section' : 'Sections';
        }
        $headers[] = 'Reference';
        if ($context['canStudents']) {
            $headers[] = 'Enrolled students';
        }
        if ($context['canTeachers']) {
            $headers[] = 'Class teacher';
        }
        if ($context['canSubjects']) {
            if ($context['canTeachers'] && $context['mode'] === 'sections') {
                $headers[] = 'Class teacher subjects';
            }
            $headers[] = 'Total subjects';
        }
        $headers[] = 'Status';
        $query = $this->query($user, $filters, $context);

        return [$headers, $query, $context];
    }

    public function csvRow(SchoolClass|Section $record, array $context): array
    {
        $row = $this->row($record, $context);
        $values = [$row['class']->name];
        if ($record instanceof Section || $context['canSections']) {
            $values[] = $record instanceof Section ? $record->name : $row['sections']->pluck('name')->join(', ');
        }
        $values[] = $row['reference'];
        if ($context['canStudents']) {
            $values[] = $context['year'] ? $row['students'] : '';
        }
        if ($context['canTeachers']) {
            $values[] = $row['classTeachers']->pluck('teacher')->map(fn ($teacher) => $teacher->employee->fullName())->unique()->join(', ');
        }
        if ($context['canSubjects']) {
            if ($context['canTeachers'] && $context['mode'] === 'sections') {
                $values[] = $row['taught']->pluck('name')->join(', ');
            }
            $values[] = $context['year'] ? $row['subjects']->count() : '';
        }
        $values[] = $row['status'];

        return array_map(fn ($value) => is_string($value) && preg_match('/^\s*[=+\-@]|^[\t\r\n]/', $value) ? "'".$value : $value, $values);
    }
}

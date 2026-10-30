<?php

namespace App\Services;

use App\Enums\ClassSubjectStatus;
use App\Enums\SectionStatus;
use App\Models\ClassSubject;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\TeacherAssignment;
use App\Models\User;
use App\Support\ClassSetupRules;
use App\Support\ClassSetupState;
use App\Support\CurrentSchool;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class UpdateClassWithSetup
{
    public function update(User $actor, SchoolClass $class, array $data): SchoolClass
    {
        abort_unless((int) $class->school_id === app(CurrentSchool::class)->id(), 403);

        return DB::transaction(function () use ($actor, $class, $data) {
            School::whereKey(app(CurrentSchool::class)->id())->lockForUpdate()->firstOrFail();
            $actor = $actor->fresh();
            abort_unless($actor, 403);
            $class = SchoolClass::whereKey($class->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('update', $class);
            $sectionsSubmitted = array_key_exists('sections', $data);
            $subjectsSubmitted = array_key_exists('subject_ids', $data);
            $teachersSubmitted = ! empty($data['teacher_changes']);
            Validator::make($data, ClassSetupRules::creation($subjectsSubmitted))->validate();
            if ($sectionsSubmitted || $subjectsSubmitted || $teachersSubmitted) {
                if (empty($data['setup_revision']) || ! hash_equals(ClassSetupState::revision($class), $data['setup_revision'])) {
                    throw ValidationException::withMessages(['setup_revision' => 'This class setup changed since you opened it. Review the latest sections, subjects and teachers before saving again.']);
                }
                if ($class->status->value !== 'ACTIVE') {
                    throw ValidationException::withMessages(['sections' => 'Activate this class before changing its sections or subjects.']);
                }
            }
            $sectionMap = [];
            if (! $sectionsSubmitted && $teachersSubmitted) {
                foreach ($class->sections()->where('status', 'ACTIVE')->get() as $section) {
                    $key = in_array($section->name, ['A', 'B', 'C', 'D'], true) ? $section->name : 'existing'.$section->id;
                    $sectionMap[$key] = $section;
                }
            }
            if ($sectionsSubmitted) {
                Gate::forUser($actor)->authorize('create', Section::class);
                $sectionMap = $this->sections($actor, $class, $data['sections']);
            }
            if ($subjectsSubmitted) {
                Gate::forUser($actor)->authorize('create', ClassSubject::class);
                $this->subjects($actor, $class, (int) $data['academic_year_id'], $data['subject_ids']);
            }

            $class = app(SchoolClassService::class)->save($actor, $class, $data);
            app(ClassTeacherSetup::class)->apply($actor, $class, $sectionMap, $data);

            return $class;
        });
    }

    private function sections(User $actor, SchoolClass $class, array $rows): array
    {
        $existing = $class->sections()->withUsage()->orderBy('id')->get();
        $selectedNames = collect($rows)->map(fn ($row) => mb_strtolower(trim($row['name'])))->all();
        foreach ($existing as $section) {
            if (in_array(mb_strtolower($section->name), $selectedNames, true)) {
                continue;
            }
            if ($section->status === SectionStatus::Inactive && $section->isInUse()) {
                // Older, deliberately inactive sections with history remain protected.
                continue;
            }
            app(SectionService::class)->deleteUnused($actor, $section);
        }
        $map = [];
        foreach ($rows as $key => $row) {
            $section = $existing->first(fn ($item) => mb_strtolower($item->name) === mb_strtolower(trim($row['name'])));
            try {
                if ($section) {
                    if ($section->status === SectionStatus::Inactive) {
                        app(SectionService::class)->setStatus($actor, $section, SectionStatus::Active);
                    }
                    $map[(string) $key] = app(SectionService::class)->save($actor, $section, $row);
                } else {
                    $map[(string) $key] = app(SectionService::class)->save($actor, null, ['class_id' => $class->id, ...$row]);
                }
            } catch (ValidationException $exception) {
                $errors = [];
                foreach ($exception->errors() as $field => $messages) {
                    $errors['sections.'.$key.'.'.$field] = $messages;
                }
                throw ValidationException::withMessages($errors);
            }
        }

        return $map;
    }

    private function subjects(User $actor, SchoolClass $class, int $yearId, array $subjectIds): void
    {
        $existing = $class->classSubjects()->where('academic_year_id', $yearId)->with('subject')->withCount('examSubjects')->get();
        $assigned = TeacherAssignment::where('academic_year_id', $yearId)->where('class_id', $class->id)->whereNotNull('subject_id')->pluck('subject_id')->all();
        $subjectIds = array_map('intval', $subjectIds);
        foreach ($existing as $allocation) {
            // Inactive catalog subjects remain recorded; this form only edits active choices.
            if ($allocation->subject->status->value !== 'ACTIVE') {
                continue;
            }
            if ($allocation->status === ClassSubjectStatus::Active && ! in_array($allocation->subject_id, $subjectIds, true)) {
                if ($allocation->exam_subjects_count || in_array($allocation->subject_id, $assigned)) {
                    throw ValidationException::withMessages(['subject_ids' => $allocation->subject->name.' is used in an exam or has a teacher assigned. Keep it selected.']);
                }
                app(ClassSubjectService::class)->setStatus($actor, $allocation, ClassSubjectStatus::Inactive);
            }
        }
        foreach ($subjectIds as $subjectId) {
            $allocation = $existing->firstWhere('subject_id', $subjectId);
            if (! $allocation) {
                app(ClassSubjectService::class)->save($actor, null, ['academic_year_id' => $yearId, 'class_id' => $class->id, 'subject_id' => $subjectId]);
            } elseif ($allocation->status === ClassSubjectStatus::Inactive) {
                app(ClassSubjectService::class)->setStatus($actor, $allocation, ClassSubjectStatus::Active);
            }
        }
    }
}

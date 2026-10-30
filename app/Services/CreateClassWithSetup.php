<?php

namespace App\Services;

use App\Models\ClassSubject;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\User;
use App\Support\ClassSetupRules;
use App\Support\CurrentSchool;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

class CreateClassWithSetup
{
    public function create(User $actor, array $data): SchoolClass
    {
        return DB::transaction(function () use ($actor, $data) {
            School::whereKey(app(CurrentSchool::class)->id())->lockForUpdate()->firstOrFail();
            $actor = $actor->fresh();
            abort_unless($actor, 403);
            Gate::forUser($actor)->authorize('create', SchoolClass::class);
            Validator::make($data, ClassSetupRules::creation(), [
                'sections.*.name.distinct' => 'Each section needs a different name.',
                'academic_year_id.exists' => 'The current academic year changed. Reload this page before choosing subjects.',
            ])->validate();
            if (! empty($data['sections'])) {
                Gate::forUser($actor)->authorize('create', Section::class);
            }
            if (! empty($data['subject_ids'])) {
                Gate::forUser($actor)->authorize('create', ClassSubject::class);
            }
            $sectionMap = [];
            $class = app(SchoolClassService::class)->save($actor, null, $data);
            foreach ($data['sections'] ?? [] as $key => $section) {
                $sectionMap[(string) $key] = app(SectionService::class)->save($actor, null, ['class_id' => $class->id, ...$section]);
            }
            foreach ($data['subject_ids'] ?? [] as $subjectId) {
                app(ClassSubjectService::class)->save($actor, null, ['academic_year_id' => $data['academic_year_id'], 'class_id' => $class->id, 'subject_id' => $subjectId]);
            }

            app(ClassTeacherSetup::class)->apply($actor, $class, $sectionMap, $data);

            return $class;
        });
    }
}

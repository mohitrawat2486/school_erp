<?php

namespace Tests\Concerns;

use App\Enums\GradingSchemeStatus;
use App\Models\GradingScheme;
use App\Models\StudentMark;
use App\Services\GradingSchemeService;
use App\Services\MarksEntryService;
use App\Services\MarksVerificationService;

trait BuildsResultContext
{
    private function scheme(array $c): GradingScheme
    {
        $svc = app(GradingSchemeService::class);
        $s = $svc->save($c['admin'], null, ['name' => 'Results scheme']);
        $svc->saveRule($c['admin'], $s, null, ['version' => 1, 'min_percentage' => 0, 'max_percentage' => '49.99', 'grade' => 'Developing']);
        $svc->saveRule($c['admin'], $s, null, ['version' => 2, 'min_percentage' => 50, 'max_percentage' => 100, 'grade' => 'Achieved', 'grade_point' => 4]);
        $svc->setStatus($c['admin'], $s, GradingSchemeStatus::Active, ['version' => 3]);

        return $s->fresh();
    }

    private function locked(array $c, array $extra = []): StudentMark
    {
        $data = $this->payload($c, $extra);
        $data['action'] = 'submit';
        app(MarksEntryService::class)->save($c['admin'], $c['subject'], $c['section'], $data);
        $m = StudentMark::where('enrollment_id', $data['rows'][0]['enrollment_id'])->where('exam_subject_id', $c['subject']->id)->first();
        app(MarksVerificationService::class)->transition($c['admin'], $m, ['action' => 'verify', 'version' => $m->lock_version]);
        app(MarksVerificationService::class)->transition($c['admin'], $m, ['action' => 'lock', 'version' => $m->fresh()->lock_version]);

        return $m->fresh();
    }
}

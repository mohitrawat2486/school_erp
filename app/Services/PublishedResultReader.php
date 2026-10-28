<?php

namespace App\Services;

use App\Models\ResultBatch;
use App\Models\StudentResult;
use App\Support\CurrentSchool;

class PublishedResultReader
{
    public function forBatch(ResultBatch $batch)
    {
        abort_unless((int) $batch->school_id === app(CurrentSchool::class)->id(), 403);
        abort_unless($batch->status === 'PUBLISHED' && $batch->snapshots_completed_at, 409, 'The published archive is not yet available.');

        return $batch->studentResults()->orderBy('enrollment_id')->paginate(20)->withQueryString();
    }

    public function forDraft(ResultBatch $batch, int $draftId): StudentResult
    {
        abort_unless((int) $batch->school_id === app(CurrentSchool::class)->id(), 403);
        abort_unless($batch->status === 'PUBLISHED' && $batch->snapshots_completed_at, 409, 'The published archive is not yet available.');

        return $batch->studentResults()->where('source_draft_id', $draftId)->firstOrFail();
    }
}

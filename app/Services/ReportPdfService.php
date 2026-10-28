<?php

namespace App\Services;

use App\Jobs\GenerateReportPdf;
use App\Models\ReportPdfRequest;
use App\Models\School;
use App\Models\StudentResult;
use App\Models\User;
use App\Support\CurrentSchool;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReportPdfService
{
    public function request(User $actor, iterable $results): ReportPdfRequest
    {
        $ids = collect($results)->pluck('id')->all();
        if (! $ids || count($ids) > (int) config('reports.max_batch_students', 500)) {
            throw ValidationException::withMessages(['results' => 'Choose between 1 and '.config('reports.max_batch_students').' students for one PDF batch.']);
        }

        return DB::transaction(function () use ($actor, $ids) {
            School::whereKey(app(CurrentSchool::class)->id())->lockForUpdate()->firstOrFail();
            $actor = $actor->fresh();
            abort_unless($actor && $actor->can('result.view') && $actor->can('result.print'), 403);
            $count = StudentResult::whereIn('id', $ids)->whereHas('resultBatch', fn ($q) => $q->where('status', 'PUBLISHED')->whereNotNull('snapshots_completed_at'))->count();
            abort_unless($count === count($ids), 403);
            $request = new ReportPdfRequest;
            $first = StudentResult::findOrFail($ids[0]);
            $request->forceFill(['label' => $first->exam_name.' · '.$first->class_name, 'user_id' => $actor->id, 'snapshot_ids' => $ids, 'student_count' => $count, 'expires_at' => now()->addHours((int) config('reports.pdf_expiry_hours', 24))]);
            $request->save();
            GenerateReportPdf::dispatch($request->id, $request->school_id)->onConnection('result-pdfs')->onQueue('result-pdfs')->afterCommit();

            return $request;
        });
    }
}

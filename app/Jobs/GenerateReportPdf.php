<?php

namespace App\Jobs;

use App\Models\ReportPdfRequest;
use App\Models\School;
use App\Models\StudentResult;
use App\Models\User;
use App\Services\ReportCardPdf;
use App\Support\CurrentSchool;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class GenerateReportPdf implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public bool $failOnTimeout = true;

    public function __construct(public int $requestId, public int $schoolId) {}

    public function handle(ReportCardPdf $renderer): void
    {
        $context = app(CurrentSchool::class);
        $path = null;
        try {
            $context->set(School::findOrFail($this->schoolId));
            $request = ReportPdfRequest::findOrFail($this->requestId);
            if ($request->expires_at->isPast() || $request->status !== 'QUEUED') {
                return;
            }
            $actor = User::findOrFail($request->user_id);
            abort_unless($actor->can('result.view') && $actor->can('result.print'), 403);
            $claimed = ReportPdfRequest::whereKey($request->id)->where('status', 'QUEUED')->update(['status' => 'PROCESSING']);
            if (! $claimed) {
                return;
            }
            $results = StudentResult::whereIn('id', $request->snapshot_ids)->whereHas('resultBatch', fn ($q) => $q->where('status', 'PUBLISHED')->whereNotNull('snapshots_completed_at'))->get()->keyBy('id');
            if ($results->count() !== $request->student_count) {
                throw new \RuntimeException('Published PDF source selection changed.');
            }
            $ordered = collect($request->snapshot_ids)->map(fn ($id) => $results[$id]);
            $bytes = $renderer->render($ordered);
            $actor = $actor->fresh();
            abort_unless($actor && $actor->can('result.view') && $actor->can('result.print'), 403);
            $path = 'result-pdfs/'.$this->schoolId.'/'.Str::uuid().'.pdf';
            Storage::disk('local')->put($path, $bytes);
            $saved = ReportPdfRequest::whereKey($request->id)->where('status', 'PROCESSING')->where('expires_at', '>', now())->update(['status' => 'READY', 'path' => $path, 'finished_at' => now()]);
            if (! $saved) {
                Storage::disk('local')->delete($path);
            }
        } catch (\Throwable $e) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            $this->failed($e);
            throw $e;
        } finally {
            $context->clear();
        }
    }

    public function failed(?\Throwable $exception): void
    {
        DB::table('report_pdf_requests')->where('school_id', $this->schoolId)->where('id', $this->requestId)->whereIn('status', ['QUEUED', 'PROCESSING'])->update(['status' => 'FAILED', 'finished_at' => now(), 'updated_at' => now()]);
    }
}

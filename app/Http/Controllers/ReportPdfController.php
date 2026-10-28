<?php

namespace App\Http\Controllers;

use App\Models\ReportPdfRequest;
use App\Models\StudentResult;
use App\Services\ClassResultDirectory;
use App\Services\ReportCardPdf;
use App\Services\ReportPdfService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class ReportPdfController extends Controller
{
    private function access(Request $request): void
    {
        abort_unless($request->user()->can('result.view') && $request->user()->can('result.print'), 403);
    }

    public function individual(Request $request, StudentResult $student_result, ReportCardPdf $renderer, ReportPdfService $service)
    {
        $this->access($request);
        Gate::authorize('view', $student_result);
        if ($student_result->subjects()->count() > 60) {
            $job = $service->request($request->user(), [$student_result]);

            return redirect()->route('report-pdfs.show', $job);
        }

        return response($renderer->render([$student_result]))->header('Content-Type', 'application/pdf')->header('Content-Disposition', 'attachment; filename="report-card-'.$student_result->id.'.pdf"')->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, ClassResultDirectory $directory, ReportPdfService $service)
    {
        $this->access($request);
        $filters = $directory->validate($request->all());
        $data = $request->validate(['scope' => 'required|in:selected,filtered,class', 'ids' => 'array', 'ids.*' => 'integer']);
        $results = $directory->output($filters, $data['scope'], $data['ids'] ?? []);
        $job = $service->request($request->user(), $results);

        return redirect()->route('report-pdfs.show', $job)->with('status', 'PDF batch queued. Download it here when ready.');
    }

    public function index(Request $request)
    {
        $this->access($request);

        return view('report-pdfs.index', ['requests' => ReportPdfRequest::where('user_id', $request->user()->id)->orderByDesc('id')->paginate(20)]);
    }

    public function show(Request $request, ReportPdfRequest $report_pdf_request)
    {
        $this->access($request);
        abort_unless($report_pdf_request->user_id === $request->user()->id, 403);

        return view('report-pdfs.show', ['job' => $report_pdf_request]);
    }

    public function download(Request $request, ReportPdfRequest $report_pdf_request)
    {
        $this->access($request);
        abort_unless($report_pdf_request->user_id === $request->user()->id, 403);
        abort_if($report_pdf_request->expires_at->isPast(), 410, 'This PDF download has expired.');
        abort_unless($report_pdf_request->status === 'READY' && $report_pdf_request->artifactPath() && Storage::disk('local')->exists($report_pdf_request->artifactPath()), 409, 'The PDF is not ready.');

        return Storage::disk('local')->download($report_pdf_request->artifactPath(), 'report-cards.pdf', ['Content-Type' => 'application/pdf', 'Cache-Control' => 'private, no-store']);
    }
}

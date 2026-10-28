<?php

namespace App\Http\Controllers;

use App\Models\StudentResult;
use App\Services\ReportCardContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ReportCardController extends Controller
{
    public function show(Request $request, StudentResult $student_result, ReportCardContext $context)
    {
        Gate::authorize('view', $student_result);
        abort_unless($request->user()->can('result.print'), 403);
        $school = $student_result->resultBatch->calculation_context['report_school'] ?? [];

        return response()->view('report-cards.show', ['result' => $student_result, 'subjects' => $student_result->subjects()->orderBy('subject_id')->get(), 'school' => $school, 'logo' => $context->logo($school), 'identity' => $student_result->report_context ?? []])->header('Cache-Control', 'private, no-store');
    }
}

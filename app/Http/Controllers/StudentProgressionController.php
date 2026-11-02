<?php

namespace App\Http\Controllers;

use App\Services\StudentProgression;
use App\Support\StudentProgressionRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentProgressionController extends Controller
{
    public function roster(Request $request, StudentProgression $service): JsonResponse
    {
        $data = $request->validate(StudentProgressionRules::source() + ['q' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1']]);

        return response()->json($service->roster($request->user(), $data))->header('Cache-Control', 'private, no-store');
    }

    public function review(Request $request, StudentProgression $service): JsonResponse
    {
        return response()->json($service->review($request->user(), $request->all()))->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, StudentProgression $service): JsonResponse
    {
        $data = $request->validate(['review_token' => ['required', 'string', 'max:200000'], 'confirmed' => ['accepted']]);
        $batch = $service->confirm($request->user(), $data['review_token']);

        return response()->json(['batch_id' => $batch->id, 'promoted' => $batch->outcomes()->where('action', 'PROMOTE')->count(), 'repeating' => $batch->outcomes()->where('action', 'REPEAT')->count(), 'history_url' => route('promotions.show', $batch)])->header('Cache-Control', 'private, no-store');
    }
}

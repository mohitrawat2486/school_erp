<?php

namespace App\Http\Controllers;

use App\Models\Guardian;
use App\Models\Student;
use App\Support\GuardianEditorState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class StudentGuardianEditorController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'student_id' => ['nullable', 'integer']]);
        if ($id = $filters['student_id'] ?? null) {
            Gate::authorize('update', Student::findOrFail($id));
        } else {
            Gate::authorize('create', Student::class);
        }
        Gate::authorize('create', Guardian::class);
        $query = Guardian::where('status', 'ACTIVE');
        if (($term = $filters['q'] ?? '') !== '') {
            $query->where(fn ($search) => $search->whereRaw("CONCAT_WS(' ', first_name, last_name) LIKE ?", ['%'.$term.'%'])->orWhere('phone', 'like', '%'.$term.'%'));
        }
        $guardians = $query->orderBy('first_name')->orderBy('last_name')->orderBy('id')->limit(21)->get();

        return response()->json(['guardians' => $guardians->take(20)->map(fn ($guardian) => GuardianEditorState::option($guardian))->values(), 'has_more' => $guardians->count() > 20])->header('Cache-Control', 'private, no-store');
    }
}

<?php

namespace App\Http\Controllers;

use App\Actions\UpdateSchoolDetails;
use App\Http\Requests\UpdateSchoolRequest;
use App\Support\CurrentSchool;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SchoolDetailsController extends Controller
{
    public function show(CurrentSchool $context): View
    {
        Gate::authorize('view', $context->get());

        return view('settings.school', ['school' => $context->get()]);
    }

    public function update(UpdateSchoolRequest $request, CurrentSchool $context, UpdateSchoolDetails $action): RedirectResponse
    {
        $action->execute($request->user(), $context->get(), $request->validated(), $request->file('logo'));

        return redirect()->route('school.show')->with('status', 'School details saved.');
    }

    public function logo(CurrentSchool $context): StreamedResponse
    {
        $path = $context->get()->logo_path;
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}

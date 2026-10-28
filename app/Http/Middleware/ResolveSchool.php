<?php

namespace App\Http\Middleware;

use App\Support\CurrentSchool;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveSchool
{
    public function handle(Request $request, Closure $next): Response
    {
        $context = app(CurrentSchool::class);
        $context->clear();
        try {
            $user = $request->user();
            $user?->refresh();
            $user?->load('school');
            abort_unless($user && $user->is_active && $user->school && $user->school->is_active, 403, 'An active school account is required.');
            $context->set($user->school);

            return $next($request);
        } finally {
            $context->clear();
        }
    }
}

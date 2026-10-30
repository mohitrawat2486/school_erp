<?php

use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\User;
use App\Services\SectionService;
use App\Support\CurrentSchool;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

// From the project root: php development/maintenance/prune-unused-inactive-sections.php USER_ID CLASS_ID [--dry-run]
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$actor = User::findOrFail($argv[1] ?? null);
app(CurrentSchool::class)->set($actor->school);
try {
    $dryRun = in_array('--dry-run', $argv, true);
    $report = DB::transaction(function () use ($actor, $argv, $dryRun) {
        School::whereKey($actor->school_id)->lockForUpdate()->firstOrFail();
        $actor = $actor->fresh();
        $class = SchoolClass::whereKey($argv[2] ?? null)->lockForUpdate()->firstOrFail();
        Gate::forUser($actor)->authorize('update', $class);
        Gate::forUser($actor)->authorize('create', Section::class);
        $sections = $class->sections()->where('status', 'INACTIVE')->withUsage()->orderBy('id')->get();
        $unused = $sections->reject(fn ($section) => $section->isInUse());
        $records = $unused->map(fn ($section) => ['id' => $section->id, 'name' => $section->name])->values()->all();
        if (! $dryRun) {
            foreach ($unused as $section) {
                app(SectionService::class)->deleteUnused($actor, $section);
            }
        }

        return ['class_id' => $class->id, 'class_name' => $class->name, 'dry_run' => $dryRun, 'unused_sections' => $records, 'protected_sections_retained' => $sections->count() - $unused->count(), 'remaining_section_count' => $class->sections()->count()];
    });
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
} finally {
    app(CurrentSchool::class)->clear();
}

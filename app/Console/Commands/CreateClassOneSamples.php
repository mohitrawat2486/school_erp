<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\ClassOneSampleData;
use App\Support\CurrentSchool;
use Illuminate\Console\Command;

class CreateClassOneSamples extends Command
{
    protected $signature = 'school:sample-class-one {user : Authorized school operator user ID}';

    protected $description = 'Create fictitious Class 1A/1B students, teachers, subjects and published sample results';

    public function handle(ClassOneSampleData $samples): int
    {
        $actor = User::findOrFail($this->argument('user'));
        abort_unless($actor->school && $actor->is_active, 403);
        app(CurrentSchool::class)->set($actor->school);
        try {
            $summary = $samples->run($actor);
            $this->info($summary['already_exists'] ? 'Sample data already exists; no duplicates created.' : 'Sample data created successfully.');
            $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } finally {
            app(CurrentSchool::class)->clear();
        }
    }
}

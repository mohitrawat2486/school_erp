<?php

use App\Models\ResultBatch;
use App\Models\School;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ProvisionSchool;
use App\Services\ResultSnapshotService;
use App\Support\CurrentSchool;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

Artisan::command('app:create-user {--school= : School code for a new member account}', function () {
    $name = text('Name', required: true);
    $email = text('Email', required: true);
    $secret = password('Password (at least 12 characters)', required: true);
    $validator = Validator::make(['name' => $name, 'email' => $email, 'password' => $secret], ['name' => 'required|string|max:255', 'email' => 'required|email|max:255|unique:users,email', 'password' => 'required|string|min:12']);
    if ($validator->fails()) {
        foreach ($validator->errors()->all() as $error) {
            $this->error($error);
        }

        return 1;
    }
    $school = null;
    if ($code = $this->option('school')) {
        $school = School::where('code', $code)->where('is_active', true)->first();
        if (! $school) {
            $this->error('Active school code not found.');

            return 1;
        }
    }
    DB::transaction(function () use ($name, $email, $secret, $school) {
        $user = new User(['name' => $name, 'email' => $email, 'password' => Hash::make($secret)]);
        $user->school_id = $school?->id;
        $user->save();
        if ($school) {
            try {
                app(CurrentSchool::class)->set($school);
                app(AuditLogger::class)->record($user, 'USER_CREATED', 'user', $user->id, [], ['email' => $email]);
            } finally {
                app(CurrentSchool::class)->clear();
            }
        }
    });
    $this->info('User created.');

    return 0;
})->purpose('Create a school workspace account interactively');

Artisan::command('app:provision-school {code} {name} {email}', function () {
    $validator = Validator::make($this->arguments(), ['code' => 'required|string|max:50|alpha_dash', 'name' => 'required|string|max:255', 'email' => 'required|email|exists:users,email']);
    if ($validator->fails()) {
        foreach ($validator->errors()->all() as $error) {
            $this->error($error);
        }

        return 1;
    }
    app(ProvisionSchool::class)->run($this->argument('code'), $this->argument('name'), $this->argument('email'));
    $this->info('School provisioned; account assigned school administrator permissions.');

    return 0;
})->purpose('Explicitly provision a school and grant an existing account school administrator access');

Artisan::command('results:archive-published {school : School code} {email : Authorized operator email} {--batch= : Optional published batch ID}', function () {
    $school = School::where('code', $this->argument('school'))->where('is_active', true)->first();
    if (! $school) {
        $this->error('Active school not found.');

        return 1;
    }
    try {
        app(CurrentSchool::class)->set($school);
        $actor = User::where('school_id', $school->id)->where('email', $this->argument('email'))->first();
        if (! $actor || ! $actor->can('result.view') || ! $actor->can('result.publish')) {
            $this->error('The operator requires active result.view and result.publish access in this school.');

            return 1;
        }
        $query = ResultBatch::where('status', 'PUBLISHED');
        if ($id = $this->option('batch')) {
            if (! ctype_digit((string) $id) || (int) $id < 1) {
                $this->error('Use a positive batch ID.');

                return 1;
            }
            $query->whereKey($id);
            if (! (clone $query)->exists()) {
                $this->error('Published batch not found in this school.');

                return 1;
            }
        }
        $created = 0;
        $failures = 0;
        $query->chunkById(50, function ($batches) use ($actor, &$created, &$failures) {
            foreach ($batches as $batch) {
                try {
                    $created += app(ResultSnapshotService::class)->archive($actor, $batch);
                } catch (Throwable $e) {
                    $failures++;
                    $this->error('Batch '.$batch->id.' failed: '.$e->getMessage());
                }
            }
        });
        $this->info('Archived '.$created.' student snapshots; failed batches: '.$failures.'.');

        return $failures ? 1 : 0;
    } finally {
        app(CurrentSchool::class)->clear();
    }
})->purpose('Archive saved published payloads without recalculating historical results');

Artisan::command('results:prune-pdfs', function () {
    $count = 0;
    DB::table('report_pdf_requests')->where('expires_at', '<=', now())->orderBy('id')->chunkById(100, function ($rows) use (&$count) {
        foreach ($rows as $row) {
            if ($row->path && preg_match('~^result-pdfs/'.(int) $row->school_id.'/[a-f0-9-]{36}\.pdf$~D', $row->path)) {
                Storage::disk('local')->delete($row->path);
            }$count += DB::table('report_pdf_requests')->where('id', $row->id)->where('expires_at', '<=', now())->delete();
        }
    });
    $disk = Storage::disk('local');
    foreach ($disk->allFiles('result-pdfs') as $file) {
        if ($disk->lastModified($file) < now()->subDay()->timestamp && ! DB::table('report_pdf_requests')->where('path', $file)->where('expires_at', '>', now())->exists()) {
            $disk->delete($file);
        }
    }
    foreach ($disk->allDirectories('result-pdfs/tmp') as $directory) {
        if (is_dir($disk->path($directory)) && filemtime($disk->path($directory)) < now()->subDay()->timestamp && ! $disk->allFiles($directory)) {
            $disk->deleteDirectory($directory);
        }
    }
    $this->info('Removed '.$count.' expired PDF requests.');
})->purpose('Remove expired private PDF artifacts and request metadata');
Schedule::command('results:prune-pdfs')->daily();

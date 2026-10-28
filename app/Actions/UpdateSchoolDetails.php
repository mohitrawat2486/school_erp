<?php

namespace App\Actions;

use App\Models\School;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\CurrentSchool;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class UpdateSchoolDetails
{
    public const FIELDS = ['code', 'name', 'short_name', 'affiliation_number', 'board', 'email', 'phone', 'alternate_phone', 'address_line_1', 'address_line_2', 'city', 'state', 'country', 'postal_code', 'timezone'];

    public function execute(User $actor, School $school, array $data, ?UploadedFile $logo = null): void
    {
        Gate::forUser($actor)->authorize('update', $school);
        abort_unless($school->id === app(CurrentSchool::class)->id(), 403);
        $newPath = null;
        try {
            if ($logo) {
                $newPath = $logo->store('schools/'.$school->id.'/logos', 'local');
                if (! $newPath) {
                    throw new RuntimeException('School logo could not be stored.');
                }
            }
            DB::transaction(function () use ($actor, $school, $data, $newPath) {
                $record = School::whereKey($school->id)->lockForUpdate()->firstOrFail();
                $fields = [...self::FIELDS, 'logo_path'];
                $old = $record->only($fields);
                $record->fill(Arr::only($data, self::FIELDS));
                if ($newPath) {
                    $record->logo_path = $newPath;
                }
                if (! $record->isDirty()) {
                    return;
                }
                $record->save();
                app(AuditLogger::class)->record($actor, 'SCHOOL_DETAILS_UPDATED', 'school', $record->id, $old, $record->only($fields), 'school');
            });
        } catch (Throwable $exception) {
            if ($newPath) {
                Storage::disk('local')->delete($newPath);
            }
            throw $exception;
        }
    }
}

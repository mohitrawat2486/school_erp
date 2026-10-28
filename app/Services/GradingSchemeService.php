<?php

namespace App\Services;

use App\Enums\GradingSchemeStatus;
use App\Models\GradeRule;
use App\Models\GradingScheme;
use App\Models\School;
use App\Models\User;
use App\Support\CurrentSchool;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class GradingSchemeService
{
    private function lock(User $actor, ?GradingScheme $scheme, ?int $version): array
    {
        School::whereKey(app(CurrentSchool::class)->id())->lockForUpdate()->firstOrFail();
        $actor = $actor->fresh();
        abort_unless($actor, 403);
        if ($scheme) {
            abort_unless((int) $scheme->school_id === app(CurrentSchool::class)->id(), 403);
            $scheme = GradingScheme::whereKey($scheme->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('manage', $scheme);
            if ($version !== $scheme->lock_version) {
                throw ValidationException::withMessages(['version' => 'This scheme changed. Reload before continuing.']);
            }
        } else {
            Gate::forUser($actor)->authorize('create', GradingScheme::class);
            $scheme = new GradingScheme;
        }

        return [$actor, $scheme];
    }

    private function inactive(GradingScheme $scheme): void
    {
        if ($scheme->status === GradingSchemeStatus::Active) {
            throw ValidationException::withMessages(['scheme' => 'Deactivate this scheme before editing its configuration.']);
        }
    }

    private function version(array $data): int
    {
        Validator::make($data, ['version' => 'required|integer|min:1'])->validate();

        return (int) $data['version'];
    }

    private function audit(User $actor, GradingScheme $scheme, string $action, array $before, array $after): void
    {
        $before['lock_version'] ??= $scheme->lock_version;
        $scheme->lock_version++;
        $scheme->save();
        app(AuditLogger::class)->record($actor, $action, 'grading_scheme', $scheme->id, $before, array_replace($after, ['lock_version' => $scheme->lock_version]), 'examinations');
    }

    public function save(User $actor, ?GradingScheme $scheme, array $data): GradingScheme
    {
        $data['name'] = is_string($data['name'] ?? null) ? trim($data['name']) : ($data['name'] ?? null);
        Validator::make($data, ['name' => 'required|string|max:100', 'school_id' => 'prohibited', 'status' => 'prohibited', 'id' => 'prohibited'])->validate();
        $version = $scheme ? $this->version($data) : null;

        return DB::transaction(function () use ($actor, $scheme, $data, $version) {
            [$actor,$record] = $this->lock($actor, $scheme, $version);
            $this->inactive($record);
            $q = GradingScheme::where('name', $data['name']);
            if ($record->exists) {
                $q->whereKeyNot($record->id);
            }
            if ($q->exists()) {
                throw ValidationException::withMessages(['name' => 'This grading scheme name already exists.']);
            }
            $before = $record->exists ? $record->getAttributes() : [];
            $record->name = $data['name'];
            if ($record->exists && ! $record->isDirty()) {
                return $record;
            }
            if (! $record->exists) {
                $record->save();
                app(AuditLogger::class)->record($actor, 'GRADING_SCHEME_CREATED', 'grading_scheme', $record->id, [], $record->getAttributes(), 'examinations');
            } else {
                $this->audit($actor, $record, 'GRADING_SCHEME_UPDATED', $before, $record->getAttributes());
            }

            return $record;
        });
    }

    public function saveRule(User $actor, GradingScheme $scheme, ?GradeRule $rule, array $data): GradeRule
    {
        if ($rule) {
            abort_unless((int) $rule->school_id === app(CurrentSchool::class)->id() && (int) $rule->grading_scheme_id === (int) $scheme->id, 404);
        }
        $data['grade'] = is_string($data['grade'] ?? null) ? trim($data['grade']) : ($data['grade'] ?? null);
        Validator::make($data, ['min_percentage' => 'required|numeric|decimal:0,2|between:0,100', 'max_percentage' => 'required|numeric|decimal:0,2|between:0,100|gte:min_percentage', 'grade' => 'required|string|max:20', 'grade_point' => 'nullable|numeric|decimal:0,2|between:0,999.99', 'remarks' => 'nullable|string|max:500', 'grading_scheme_id' => 'prohibited', 'school_id' => 'prohibited', 'id' => 'prohibited', 'status' => 'prohibited'])->validate();
        $version = $this->version($data);

        return DB::transaction(function () use ($actor, $scheme, $rule, $data, $version) {
            [$actor,$scheme] = $this->lock($actor, $scheme, $version);
            $this->inactive($scheme);
            $record = $rule ? GradeRule::where('grading_scheme_id', $scheme->id)->whereKey($rule->id)->lockForUpdate()->firstOrFail() : new GradeRule;
            $q = $scheme->rules();
            if ($record->exists) {
                $q->whereKeyNot($record->id);
            }
            if ((clone $q)->where('grade', $data['grade'])->exists()) {
                throw ValidationException::withMessages(['grade' => 'Each grade label must be unique within this scheme.']);
            }
            if ($q->where('min_percentage', '<=', $data['max_percentage'])->where('max_percentage', '>=', $data['min_percentage'])->exists()) {
                throw ValidationException::withMessages(['min_percentage' => 'This range overlaps an existing rule, including shared endpoints.']);
            }
            $before = $record->exists ? $record->getAttributes() : [];
            $record->fill(array_replace(['grade_point' => null, 'remarks' => null], array_intersect_key($data, array_flip(GradeRule::FIELDS))));
            $creating = ! $record->exists;
            if (! $creating && ! $record->isDirty()) {
                return $record;
            }
            $record->grading_scheme_id = $scheme->id;
            $record->save();
            $this->audit($actor, $scheme, $creating ? 'GRADE_RULE_CREATED' : 'GRADE_RULE_UPDATED', ['rule' => $before], ['rule' => $record->getAttributes()]);

            return $record;
        });
    }

    public function deleteRule(User $actor, GradingScheme $scheme, GradeRule $rule, array $data): void
    {
        abort_unless((int) $rule->school_id === app(CurrentSchool::class)->id() && (int) $rule->grading_scheme_id === (int) $scheme->id, 404);
        $version = $this->version($data);
        DB::transaction(function () use ($actor, $scheme, $rule, $version) {
            [$actor,$scheme] = $this->lock($actor, $scheme, $version);
            $this->inactive($scheme);
            $record = $scheme->rules()->whereKey($rule->id)->lockForUpdate()->firstOrFail();
            $before = $record->getAttributes();
            $record->delete();
            $this->audit($actor, $scheme, 'GRADE_RULE_DELETED', ['rule' => $before], ['rule' => null]);
        });
    }

    public function setStatus(User $actor, GradingScheme $scheme, GradingSchemeStatus $status, array $data): void
    {
        $version = $this->version($data);
        DB::transaction(function () use ($actor, $scheme, $status, $version) {
            [$actor,$scheme] = $this->lock($actor, $scheme, $version);
            if ($scheme->status === $status) {
                return;
            }
            if ($status === GradingSchemeStatus::Active) {
                $this->assertComplete($scheme);
            }
            $before = $scheme->getAttributes();
            $scheme->status = $status;
            $this->audit($actor, $scheme, $status === GradingSchemeStatus::Active ? 'GRADING_SCHEME_ACTIVATED' : 'GRADING_SCHEME_DEACTIVATED', $before, $scheme->getAttributes());
        });
    }

    public function assertComplete(GradingScheme $scheme): void
    {
        $expected = 0;
        $count = 0;
        foreach ($scheme->rules()->orderBy('min_percentage')->cursor() as $rule) {
            $min = $this->hundredths($rule->min_percentage);
            $max = $this->hundredths($rule->max_percentage);
            if ($min !== $expected || $max < $min || $max > 10000) {
                throw ValidationException::withMessages(['rules' => 'Rules must cover 0.00–100.00 without gaps or overlaps. Use inclusive endpoints and increments of 0.01.']);
            }
            $expected = $max + 1;
            $count++;
        }
        if (! $count || $expected !== 10001) {
            throw ValidationException::withMessages(['rules' => 'Add continuous rules covering all percentages from 0.00 to 100.00 before activation.']);
        }
    }

    private function hundredths(string $value): int
    {
        $parts = explode('.', $value);

        return (int) $parts[0] * 100 + (int) str_pad($parts[1] ?? '', 2, '0');
    }
}

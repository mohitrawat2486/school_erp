<?php

namespace App\Models\Concerns;

use App\Support\CurrentSchool;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

trait BelongsToSchool
{
    protected static function bootBelongsToSchool(): void
    {
        static::addGlobalScope('school', function (Builder $query) {
            $query->where($query->getModel()->qualifyColumn('school_id'), app(CurrentSchool::class)->id());
        });
        static::saving(function (Model $model) {
            $schoolId = app(CurrentSchool::class)->id();
            if ($model->exists && (int) $model->getOriginal('school_id') !== $schoolId) {
                throw new AuthorizationException('School mismatch.');
            }
            if ($model->school_id !== null && (int) $model->school_id !== $schoolId) {
                throw new AuthorizationException('School mismatch.');
            }
            $model->school_id = $schoolId;
        });
        static::deleting(function (Model $model) {
            if ((int) $model->school_id !== app(CurrentSchool::class)->id()) {
                throw new AuthorizationException('School mismatch.');
            }
        });
    }
}

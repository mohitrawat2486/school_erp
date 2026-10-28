<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GradeRule extends Model
{
    use BelongsToSchool;

    public const FIELDS = ['min_percentage', 'max_percentage', 'grade', 'grade_point', 'remarks'];

    protected $fillable = self::FIELDS;

    protected function casts(): array
    {
        return ['min_percentage' => 'decimal:2', 'max_percentage' => 'decimal:2', 'grade_point' => 'decimal:2'];
    }

    public function gradingScheme(): BelongsTo
    {
        return $this->belongsTo(GradingScheme::class);
    }
}

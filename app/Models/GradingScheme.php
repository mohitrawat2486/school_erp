<?php

namespace App\Models;

use App\Enums\GradingSchemeStatus;
use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GradingScheme extends Model
{
    use BelongsToSchool;

    protected $fillable = ['name'];

    protected $attributes = ['status' => 'INACTIVE', 'lock_version' => 1];

    protected function casts(): array
    {
        return ['status' => GradingSchemeStatus::class, 'lock_version' => 'integer'];
    }

    public function rules(): HasMany
    {
        return $this->hasMany(GradeRule::class);
    }
}

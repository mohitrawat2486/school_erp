<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

class ReportPdfRequest extends Model
{
    use BelongsToSchool;

    protected $guarded = ['*'];

    protected $attributes = ['status' => 'QUEUED'];

    protected $hidden = ['path', 'snapshot_ids'];

    protected function casts(): array
    {
        return ['snapshot_ids' => 'array', 'expires_at' => 'datetime', 'finished_at' => 'datetime', 'student_count' => 'integer'];
    }

    public function artifactPath(): ?string
    {
        return is_string($this->path) && preg_match('~^result-pdfs/'.(int) $this->school_id.'/[a-f0-9-]{36}\\.pdf$~D', $this->path) ? $this->path : null;
    }
}

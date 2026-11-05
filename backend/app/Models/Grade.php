<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'submission_id',
    'graded_by',
    'score',
    'feedback',
    'published_by',
    'published_at',
    'rubric_scores',
])]
class Grade extends Model
{
    protected function casts(): array
    {
        return [
            'score' => 'decimal:2',
            'published_at' => 'datetime',
            'rubric_scores' => 'array',
        ];
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }

    public function grader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'graded_by');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }
}

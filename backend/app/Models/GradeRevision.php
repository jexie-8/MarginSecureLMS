<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GradeRevision extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'grade_id',
        'changed_by',
        'reason',
        'previous_score',
        'new_score',
        'previous_feedback',
        'new_feedback',
        'previous_rubric_scores',
        'new_rubric_scores',
    ];

    protected function casts(): array
    {
        return [
            'previous_score' => 'decimal:2',
            'new_score' => 'decimal:2',
            'previous_rubric_scores' => 'array',
            'new_rubric_scores' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function grade(): BelongsTo
    {
        return $this->belongsTo(Grade::class);
    }

    public function changer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}

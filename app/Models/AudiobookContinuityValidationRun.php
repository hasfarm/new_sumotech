<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AudiobookContinuityValidationRun extends Model
{
    protected $fillable = [
        'audio_book_id',
        'status',
        'scope',
        'target_scene_ids',
        'target_shot_ids',
        'continuity_validator_version',
        'batches',
        'total_scenes',
        'processed_scenes',
        'error_message',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'target_scene_ids' => 'array',
        'target_shot_ids' => 'array',
        'batches' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function audioBook()
    {
        return $this->belongsTo(AudioBook::class);
    }

    public function issues()
    {
        return $this->hasMany(AudiobookContinuityIssue::class, 'validator_run_id');
    }
}

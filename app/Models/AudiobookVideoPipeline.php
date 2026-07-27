<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AudiobookVideoPipeline extends Model
{
    protected $fillable = [
        'audio_book_id',
        'source_version_id',
        'context_hint',
        'image_style',
        'image_api_provider',
        'image_api_model',
        'status',
        'current_stage',
        'last_heartbeat_at',
        'shot_chunks',
        'total_batches',
        'processed_batches',
        'error_message',
    ];

    protected $casts = [
        'shot_chunks' => 'array',
        'last_heartbeat_at' => 'datetime',
    ];

    public function audioBook()
    {
        return $this->belongsTo(AudioBook::class);
    }
}

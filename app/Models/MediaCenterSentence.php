<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MediaCenterSentence extends Model
{
    use HasFactory;

    protected $fillable = [
        'media_center_project_id',
        'sentence_index',
        'sentence_text',
        'tts_text',
        'image_prompt',
        'video_prompt',
        'character_notes',
        'tts_provider',
        'tts_voice_gender',
        'tts_voice_name',
        'tts_speed',
        'tts_audio_path',
        'image_provider',
        'image_path',
        'metadata_json',
    ];

    protected $casts = [
        'tts_speed' => 'float',
        'metadata_json' => 'array',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(MediaCenterProject::class, 'media_center_project_id');
    }
}

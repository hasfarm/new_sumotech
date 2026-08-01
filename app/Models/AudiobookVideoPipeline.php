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
        'tts_provider',
        'tts_voice_gender',
        'tts_voice_name',
        'avatar_tts_provider',
        'avatar_tts_voice_gender',
        'avatar_tts_voice_name',
        'status',
        'current_stage',
        'last_heartbeat_at',
        'shot_chunks',
        'bulk_ai_generate_status',
        'bulk_narration_tts_status',
        'bulk_avatar_tts_status',
        'story_bible_regenerate_stale_status',
        'bulk_scene_ambience_status',
        'bulk_scene_music_status',
        'bulk_shot_sfx_status',
        'bulk_shot_ambience_override_status',
        'bulk_shot_music_override_status',
        'total_batches',
        'processed_batches',
        'error_message',
    ];

    protected $casts = [
        'shot_chunks' => 'array',
        'bulk_ai_generate_status' => 'array',
        'bulk_narration_tts_status' => 'array',
        'bulk_avatar_tts_status' => 'array',
        'story_bible_regenerate_stale_status' => 'array',
        'bulk_scene_ambience_status' => 'array',
        'bulk_scene_music_status' => 'array',
        'bulk_shot_sfx_status' => 'array',
        'bulk_shot_ambience_override_status' => 'array',
        'bulk_shot_music_override_status' => 'array',
        'last_heartbeat_at' => 'datetime',
    ];

    public function audioBook()
    {
        return $this->belongsTo(AudioBook::class);
    }
}

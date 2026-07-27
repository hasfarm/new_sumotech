<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AudiobookVideoScene extends Model
{
    protected $fillable = [
        'audio_book_id',
        'audiobook_summary_id',
        'source_version_id',
        'scene_index',
        'cluster_index',
        'title',
        'script_text',
        'estimated_duration_seconds',
        'scene_type',
        'keywords',
        'is_avatar_segment',
        'is_emotional_climax',
        'status',
        'resolved_source',
        'resolved_asset_path',
        'resolved_score',
        'avatar_video_path',
        'error_message',
    ];

    protected $casts = [
        'keywords' => 'array',
        'is_avatar_segment' => 'boolean',
        'is_emotional_climax' => 'boolean',
        'resolved_score' => 'float',
    ];

    public function audioBook()
    {
        return $this->belongsTo(AudioBook::class);
    }

    public function summary()
    {
        return $this->belongsTo(AudiobookSummary::class, 'audiobook_summary_id');
    }

    public function candidates()
    {
        return $this->hasMany(AudiobookVideoSceneCandidate::class, 'video_scene_id')->orderByDesc('score_final');
    }

    public function shots()
    {
        return $this->hasMany(AudiobookVideoShot::class, 'video_scene_id')->orderBy('shot_index');
    }
}

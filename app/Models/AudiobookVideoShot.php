<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AudiobookVideoShot extends Model
{
    protected $fillable = [
        'video_scene_id',
        'shot_index',
        'sentence_text',
        'estimated_duration_seconds',
        'keywords',
        'image_request',
        'is_avatar_segment',
        'is_real_world',
        'status',
        'resolved_source',
        'resolved_asset_path',
        'resolved_score',
        'resolved_library_asset_id',
        'avatar_video_path',
        'error_message',
    ];

    protected $casts = [
        'keywords' => 'array',
        'is_avatar_segment' => 'boolean',
        'is_real_world' => 'boolean',
        'resolved_score' => 'float',
    ];

    public function scene()
    {
        return $this->belongsTo(AudiobookVideoScene::class, 'video_scene_id');
    }

    public function candidates()
    {
        return $this->hasMany(AudiobookVideoShotCandidate::class, 'video_shot_id')->orderByDesc('score_final');
    }

    public function libraryAsset()
    {
        return $this->belongsTo(VideoAssetLibrary::class, 'resolved_library_asset_id');
    }
}

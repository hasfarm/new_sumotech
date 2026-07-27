<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AudiobookVideoSceneCandidate extends Model
{
    protected $fillable = [
        'video_scene_id',
        'source',
        'external_id',
        'thumbnail_url',
        'download_url',
        'media_type',
        'width',
        'height',
        'duration_seconds',
        'license_label',
        'score_semantic',
        'score_historical',
        'score_resolution',
        'score_mood',
        'score_license',
        'score_final',
        'is_selected',
        'raw_metadata',
    ];

    protected $casts = [
        'is_selected' => 'boolean',
        'raw_metadata' => 'array',
        'score_semantic' => 'float',
        'score_historical' => 'float',
        'score_resolution' => 'float',
        'score_mood' => 'float',
        'score_license' => 'float',
        'score_final' => 'float',
    ];

    public function scene()
    {
        return $this->belongsTo(AudiobookVideoScene::class, 'video_scene_id');
    }
}

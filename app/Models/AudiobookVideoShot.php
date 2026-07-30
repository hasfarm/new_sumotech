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
        'enrichment_status',
        'prompt_version',
        'story_bible_version_used',
        'validation_status',
        'continuity_error',
        'enriched_at',
        'status',
        'resolved_source',
        'resolved_asset_path',
        'resolved_score',
        'resolved_library_asset_id',
        'avatar_video_path',
        'error_message',
        'timeline_binding',
        'location_binding',
        'shot_story_phase',
        'narrative_mode',
    ];

    protected $casts = [
        'keywords' => 'array',
        'is_avatar_segment' => 'boolean',
        'is_real_world' => 'boolean',
        'continuity_error' => 'array',
        'enriched_at' => 'datetime',
        'resolved_score' => 'float',
        'timeline_binding' => 'array',
        'location_binding' => 'array',
    ];

    public function scene()
    {
        return $this->belongsTo(AudiobookVideoScene::class, 'video_scene_id');
    }

    public function candidates()
    {
        return $this->hasMany(AudiobookVideoShotCandidate::class, 'video_shot_id')->orderByDesc('score_final');
    }

    public function shotCharacters()
    {
        return $this->hasMany(AudiobookVideoShotCharacter::class, 'video_shot_id');
    }

    /** The timeline row THIS SHOT was locally bound to (chunk-level), if any — falls back to the scene's binding when null. */
    public function resolvedTimeline(): ?AudiobookTimeline
    {
        $id = data_get($this->timeline_binding, 'timeline_id');
        return $id ? AudiobookTimeline::find($id) : null;
    }

    /** The location row THIS SHOT was locally bound to (chunk-level), if any — falls back to the scene's binding when null. */
    public function resolvedLocation(): ?AudiobookLocation
    {
        $id = data_get($this->location_binding, 'location_id');
        return $id ? AudiobookLocation::find($id) : null;
    }

    public function libraryAsset()
    {
        return $this->belongsTo(VideoAssetLibrary::class, 'resolved_library_asset_id');
    }
}

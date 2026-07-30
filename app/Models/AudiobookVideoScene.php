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
        'story_bible_id',
        'story_bible_version_used',
        'scene_direction_version',
        'timeline_binding',
        'location_binding',
        'story_phase',
    ];

    protected $casts = [
        'keywords' => 'array',
        'is_avatar_segment' => 'boolean',
        'is_emotional_climax' => 'boolean',
        'resolved_score' => 'float',
        'timeline_binding' => 'array',
        'location_binding' => 'array',
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

    public function storyBible()
    {
        return $this->belongsTo(AudiobookStoryBible::class, 'story_bible_id');
    }

    /** The timeline row this scene was bound to, if timeline_binding resolved to one. */
    public function resolvedTimeline(): ?AudiobookTimeline
    {
        $id = data_get($this->timeline_binding, 'timeline_id');
        return $id ? AudiobookTimeline::find($id) : null;
    }

    /** The location row this scene was bound to, if location_binding resolved to one. */
    public function resolvedLocation(): ?AudiobookLocation
    {
        $id = data_get($this->location_binding, 'location_id');
        return $id ? AudiobookLocation::find($id) : null;
    }

    /** Which characters this scene involves, each possibly at a specific phase (nullable). */
    public function sceneCharacters()
    {
        return $this->hasMany(AudiobookVideoSceneCharacter::class, 'video_scene_id');
    }
}

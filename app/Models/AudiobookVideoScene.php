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
        'needs_ambience',
        'ambience_keywords',
        'ambience_prompt',
        'ambience_asset_id',
        'ambience_status',
        'ambience_selected_by',
        'ambience_approved_by',
        'ambience_approved_at',
        'ambience_locked_by',
        'ambience_locked_at',
        'needs_music',
        'music_keywords',
        'music_prompt',
        'music_asset_id',
        'music_status',
        'music_selected_by',
        'music_approved_by',
        'music_approved_at',
        'music_locked_by',
        'music_locked_at',
        'audio_direction_version',
    ];

    protected $casts = [
        'keywords' => 'array',
        'is_avatar_segment' => 'boolean',
        'is_emotional_climax' => 'boolean',
        'resolved_score' => 'float',
        'timeline_binding' => 'array',
        'location_binding' => 'array',
        'needs_ambience' => 'boolean',
        'ambience_keywords' => 'array',
        'ambience_approved_at' => 'datetime',
        'ambience_locked_at' => 'datetime',
        'needs_music' => 'boolean',
        'music_keywords' => 'array',
        'music_approved_at' => 'datetime',
        'music_locked_at' => 'datetime',
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

    /** This scene's baseline ambience clip, if resolved — shots inherit this by default. */
    public function ambienceAsset()
    {
        return $this->belongsTo(AudiobookAudioAsset::class, 'ambience_asset_id');
    }

    /** This scene's baseline music clip, if resolved — shots inherit this by default. */
    public function musicAsset()
    {
        return $this->belongsTo(AudiobookAudioAsset::class, 'music_asset_id');
    }

    public function ambienceSelectedByUser()
    {
        return $this->belongsTo(User::class, 'ambience_selected_by');
    }

    public function ambienceApprovedByUser()
    {
        return $this->belongsTo(User::class, 'ambience_approved_by');
    }

    public function ambienceLockedByUser()
    {
        return $this->belongsTo(User::class, 'ambience_locked_by');
    }

    public function musicSelectedByUser()
    {
        return $this->belongsTo(User::class, 'music_selected_by');
    }

    public function musicApprovedByUser()
    {
        return $this->belongsTo(User::class, 'music_approved_by');
    }

    public function musicLockedByUser()
    {
        return $this->belongsTo(User::class, 'music_locked_by');
    }

    /** True when this slot's status is 'locked' — bulk jobs/stale regeneration must never touch it. */
    public function isAudioSlotLocked(string $slot): bool
    {
        return $this->{"{$slot}_status"} === 'locked';
    }
}

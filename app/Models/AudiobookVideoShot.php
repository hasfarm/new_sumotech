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
        'avatar_image_path',
        'avatar_audio_path',
        'narration_audio_path',
        'error_message',
        'timeline_binding',
        'location_binding',
        'shot_story_phase',
        'narrative_mode',
        'needs_sfx',
        'sfx_keywords',
        'sfx_prompt',
        'sfx_asset_id',
        'sfx_status',
        'sfx_selected_by',
        'sfx_approved_by',
        'sfx_approved_at',
        'sfx_locked_by',
        'sfx_locked_at',
        'ambience_override',
        'ambience_keywords',
        'ambience_prompt',
        'ambience_asset_id',
        'ambience_status',
        'ambience_selected_by',
        'ambience_approved_by',
        'ambience_approved_at',
        'ambience_locked_by',
        'ambience_locked_at',
        'music_override',
        'music_keywords',
        'music_prompt',
        'music_asset_id',
        'music_status',
        'music_selected_by',
        'music_approved_by',
        'music_approved_at',
        'music_locked_by',
        'music_locked_at',
        'audio_mix_path',
        'audio_prompt_version',
        'motion_preset',
        'motion_focus_x',
        'motion_focus_y',
        'motion_intensity',
        'motion_duration_seconds',
        'motion_reason',
        'motion_asset_path',
        'motion_prompt_version',
        'motion_status',
        'motion_selected_by',
        'motion_approved_by',
        'motion_approved_at',
        'motion_locked_by',
        'motion_locked_at',
        'transition_preset',
        'transition_intensity',
        'transition_duration_seconds',
        'transition_reason',
        'transition_asset_path',
        'transition_prompt_version',
        'transition_status',
        'transition_selected_by',
        'transition_approved_by',
        'transition_approved_at',
        'transition_locked_by',
        'transition_locked_at',
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
        'needs_sfx' => 'boolean',
        'sfx_keywords' => 'array',
        'sfx_approved_at' => 'datetime',
        'sfx_locked_at' => 'datetime',
        'ambience_override' => 'boolean',
        'ambience_keywords' => 'array',
        'ambience_approved_at' => 'datetime',
        'ambience_locked_at' => 'datetime',
        'music_override' => 'boolean',
        'music_keywords' => 'array',
        'music_approved_at' => 'datetime',
        'music_locked_at' => 'datetime',
        'motion_focus_x' => 'float',
        'motion_focus_y' => 'float',
        'motion_intensity' => 'float',
        'motion_duration_seconds' => 'float',
        'motion_approved_at' => 'datetime',
        'motion_locked_at' => 'datetime',
        'transition_intensity' => 'float',
        'transition_duration_seconds' => 'float',
        'transition_approved_at' => 'datetime',
        'transition_locked_at' => 'datetime',
    ];

    protected $appends = ['avatar_image_url', 'avatar_audio_url', 'narration_audio_url'];

    /** Public URL for this shot's OWN chosen avatar image, if one was set — null means "use the book's speaker default" (see AvatarSegmentService::resolveAvatarImageUrl()). */
    public function getAvatarImageUrlAttribute(): ?string
    {
        return $this->avatar_image_path ? asset('storage/' . $this->avatar_image_path) : null;
    }

    /** Public URL for this shot's persisted avatar-voice TTS, if one was already generated. */
    public function getAvatarAudioUrlAttribute(): ?string
    {
        return $this->avatar_audio_path ? self::ttsStorageUrl($this->avatar_audio_path) : null;
    }

    /** Public URL for this shot's persisted main-voice narration TTS, if one was already generated. */
    public function getNarrationAudioUrlAttribute(): ?string
    {
        return $this->narration_audio_path ? self::ttsStorageUrl($this->narration_audio_path) : null;
    }

    /**
     * TTSService::generateAudio() saves via the default 'local' disk with an explicit
     * "public/" prefix folder (storage/app/public/...) rather than the 'public' disk with
     * no prefix (storage/app/public/... too, but referenced without "public/" in the path) —
     * a different convention than the rest of this pipeline (e.g. resolved_asset_path). Both
     * land in the same physical directory; the leading "public/" just needs stripping before
     * building the storage:// URL, or this doubles up as storage/public/public/...
     */
    private static function ttsStorageUrl(string $path): string
    {
        return asset('storage/' . preg_replace('#^public/#', '', $path));
    }

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

    public function sfxAsset()
    {
        return $this->belongsTo(AudiobookAudioAsset::class, 'sfx_asset_id');
    }

    public function ambienceAsset()
    {
        return $this->belongsTo(AudiobookAudioAsset::class, 'ambience_asset_id');
    }

    public function musicAsset()
    {
        return $this->belongsTo(AudiobookAudioAsset::class, 'music_asset_id');
    }

    public function sfxSelectedByUser()
    {
        return $this->belongsTo(User::class, 'sfx_selected_by');
    }

    public function sfxApprovedByUser()
    {
        return $this->belongsTo(User::class, 'sfx_approved_by');
    }

    public function sfxLockedByUser()
    {
        return $this->belongsTo(User::class, 'sfx_locked_by');
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

    /** True when this shot's OWN slot status is 'locked' (sfx is always shot-scoped; ambience/music only meaningful when overridden here). */
    public function isAudioSlotLocked(string $slot): bool
    {
        return $this->{"{$slot}_status"} === 'locked';
    }

    public function motionSelectedByUser()
    {
        return $this->belongsTo(User::class, 'motion_selected_by');
    }

    public function motionApprovedByUser()
    {
        return $this->belongsTo(User::class, 'motion_approved_by');
    }

    public function motionLockedByUser()
    {
        return $this->belongsTo(User::class, 'motion_locked_by');
    }

    public function transitionSelectedByUser()
    {
        return $this->belongsTo(User::class, 'transition_selected_by');
    }

    public function transitionApprovedByUser()
    {
        return $this->belongsTo(User::class, 'transition_approved_by');
    }

    public function transitionLockedByUser()
    {
        return $this->belongsTo(User::class, 'transition_locked_by');
    }

    /**
     * Whether resolved_asset_path already points at a VIDEO (Kling/Seedance via animateShot(),
     * or a stock video source) rather than a still image — motion (Ken Burns zoompan) only
     * makes sense on a still, so callers must skip motion analysis/rendering entirely when this
     * is true. Same extension check the frontend already uses (video-pipeline.blade.php's
     * vpPreviewThumb()), now available server-side too.
     */
    public function isResolvedAssetVideo(): bool
    {
        return (bool) preg_match('/\.(mp4|mov|webm)$/i', (string) $this->resolved_asset_path);
    }

    /**
     * This shot's EFFECTIVE ambience — its own override if `ambience_override` is set,
     * otherwise the scene's baseline. Mirrors resolvedTimeline()/resolvedLocation()'s
     * shot-falls-back-to-scene convention exactly, applied to audio.
     *
     * @return array{source:string,keywords:array,prompt:?string,asset:?AudiobookAudioAsset,status:?string}
     */
    public function resolvedAmbience(): array
    {
        if ($this->ambience_override) {
            return [
                'source' => 'shot',
                'keywords' => $this->ambience_keywords ?? [],
                'prompt' => $this->ambience_prompt,
                'asset' => $this->ambienceAsset,
                'status' => $this->ambience_status,
                'is_locked' => $this->ambience_status === 'locked',
            ];
        }

        $scene = $this->scene;

        return [
            'source' => 'scene',
            'keywords' => $scene?->ambience_keywords ?? [],
            'prompt' => $scene?->ambience_prompt,
            'asset' => $scene?->ambienceAsset,
            'status' => $scene?->ambience_status,
            'is_locked' => $scene?->ambience_status === 'locked',
        ];
    }

    /**
     * This shot's EFFECTIVE music — same shot-overrides-scene fallback as
     * resolvedAmbience() above.
     *
     * @return array{source:string,keywords:array,prompt:?string,asset:?AudiobookAudioAsset,status:?string}
     */
    public function resolvedMusic(): array
    {
        if ($this->music_override) {
            return [
                'source' => 'shot',
                'keywords' => $this->music_keywords ?? [],
                'prompt' => $this->music_prompt,
                'asset' => $this->musicAsset,
                'status' => $this->music_status,
                'is_locked' => $this->music_status === 'locked',
            ];
        }

        $scene = $this->scene;

        return [
            'source' => 'scene',
            'keywords' => $scene?->music_keywords ?? [],
            'prompt' => $scene?->music_prompt,
            'asset' => $scene?->musicAsset,
            'status' => $scene?->music_status,
            'is_locked' => $scene?->music_status === 'locked',
        ];
    }
}

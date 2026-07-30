<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per character present in a scene. character_phase_id is nullable — no phase
 * exists for that character, or one couldn't be confidently resolved — and consumers
 * (VideoSceneAnalysisService::buildStableContextBlock, SceneAssetResolverService) always
 * fall back to the character's baseline_traits/identity_anchor directly when it's null;
 * resolution_status is for continuity review (Phase 4), not for branching prompt behavior.
 */
class AudiobookVideoSceneCharacter extends Model
{
    protected $fillable = [
        'video_scene_id',
        'character_id',
        'character_phase_id',
        'confidence',
        'source_type',
        'evidence',
        'resolution_status',
    ];

    protected $casts = [
        'evidence' => 'array',
    ];

    public function scene()
    {
        return $this->belongsTo(AudiobookVideoScene::class, 'video_scene_id');
    }

    public function character()
    {
        return $this->belongsTo(AudiobookCharacter::class, 'character_id');
    }

    public function phase()
    {
        return $this->belongsTo(AudiobookCharacterPhase::class, 'character_phase_id');
    }
}

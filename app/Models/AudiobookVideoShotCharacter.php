<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Shot-scoped character presence — additive alongside AudiobookVideoSceneCharacter (the
 * scene-wide roster, unchanged). Used when a character's presence/phase in one shot differs
 * from the scene-wide default, or was only resolvable within the shot's own enrichment-chunk
 * window (e.g. a character named in one shot and referred to only by pronoun in the next).
 * Consumers fall back to the scene-level pivot when no row exists here for a given shot.
 */
class AudiobookVideoShotCharacter extends Model
{
    protected $fillable = [
        'video_shot_id',
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

    public function shot()
    {
        return $this->belongsTo(AudiobookVideoShot::class, 'video_shot_id');
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

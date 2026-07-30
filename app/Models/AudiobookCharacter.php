<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AudiobookCharacter extends Model
{
    protected $fillable = [
        'story_bible_id',
        'canonical_name',
        'aliases',
        'role',
        'cultural_origin',
        'identity_anchor',
        'baseline_traits',
        'notes',
    ];

    protected $casts = [
        'aliases' => 'array',
        'role' => 'array',
        'cultural_origin' => 'array',
        'identity_anchor' => 'array',
        'baseline_traits' => 'array',
    ];

    public function storyBible()
    {
        return $this->belongsTo(AudiobookStoryBible::class, 'story_bible_id');
    }

    /** Ordered by story-time, not creation order — needed to resolve "which phase applies at this point". */
    public function phases()
    {
        return $this->hasMany(AudiobookCharacterPhase::class, 'character_id')->orderBy('chronological_order');
    }
}

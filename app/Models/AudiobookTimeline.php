<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AudiobookTimeline extends Model
{
    protected $fillable = [
        'story_bible_id',
        'canonical_key',
        'label',
        'aliases',
        'timeline_type',
        'chronological_order',
        'narrative_introduction_order',
        'profile',
    ];

    protected $casts = [
        'aliases' => 'array',
        'profile' => 'array',
    ];

    public function storyBible()
    {
        return $this->belongsTo(AudiobookStoryBible::class, 'story_bible_id');
    }

    public function characterPhases()
    {
        return $this->hasMany(AudiobookCharacterPhase::class, 'timeline_id');
    }
}

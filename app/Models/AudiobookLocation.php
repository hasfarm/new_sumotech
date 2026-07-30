<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AudiobookLocation extends Model
{
    protected $fillable = [
        'story_bible_id',
        'canonical_name',
        'aliases',
        'cultural_context',
        'visual_notes',
    ];

    protected $casts = [
        'aliases' => 'array',
        'cultural_context' => 'array',
        'visual_notes' => 'array',
    ];

    public function storyBible()
    {
        return $this->belongsTo(AudiobookStoryBible::class, 'story_bible_id');
    }

    public function characterPhases()
    {
        return $this->hasMany(AudiobookCharacterPhase::class, 'current_location_id');
    }
}

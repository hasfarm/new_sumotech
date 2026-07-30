<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AudiobookCharacterPhase extends Model
{
    protected $fillable = [
        'character_id',
        'timeline_id',
        'current_location_id',
        'label',
        'chronological_order',
        'mutable_traits',
        'profile',
    ];

    protected $casts = [
        'mutable_traits' => 'array',
        'profile' => 'array',
    ];

    public function character()
    {
        return $this->belongsTo(AudiobookCharacter::class, 'character_id');
    }

    public function timeline()
    {
        return $this->belongsTo(AudiobookTimeline::class, 'timeline_id');
    }

    public function currentLocation()
    {
        return $this->belongsTo(AudiobookLocation::class, 'current_location_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MediaCenterProject extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'title',
        'source_text',
        'language',
        'main_character_name',
        'main_character_profile',
        'characters_json',
        'settings_json',
        'status',
    ];

    protected $casts = [
        'characters_json' => 'array',
        'settings_json' => 'array',
    ];

    public function sentences(): HasMany
    {
        return $this->hasMany(MediaCenterSentence::class)->orderBy('sentence_index');
    }
}

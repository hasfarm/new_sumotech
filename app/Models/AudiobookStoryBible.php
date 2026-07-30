<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Versioned: not 1:1 with AudioBook. Only the row with is_active=true is the one Phase 3+
 * should read from; other rows are drafts being built or past versions kept for postmortem
 * after a failed regenerate (see AnalyzeStoryDirectionJob for the draft->validate->activate
 * flow that guarantees the active row is never deleted before a replacement succeeds).
 */
class AudiobookStoryBible extends Model
{
    protected $fillable = [
        'audio_book_id',
        'bible_version',
        'schema_version',
        'status',
        'is_active',
        'source_facts',
        'director_treatment',
        'raw_facts',
        'batches',
        'total_batches',
        'processed_batches',
        'error_message',
        'activated_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'source_facts' => 'array',
        'director_treatment' => 'array',
        'raw_facts' => 'array',
        'batches' => 'array',
        'activated_at' => 'datetime',
    ];

    public function audioBook()
    {
        return $this->belongsTo(AudioBook::class);
    }

    public function timelines()
    {
        return $this->hasMany(AudiobookTimeline::class, 'story_bible_id');
    }

    public function locations()
    {
        return $this->hasMany(AudiobookLocation::class, 'story_bible_id');
    }

    public function characters()
    {
        return $this->hasMany(AudiobookCharacter::class, 'story_bible_id');
    }
}

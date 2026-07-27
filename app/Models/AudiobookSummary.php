<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AudiobookSummary extends Model
{
    protected $fillable = [
        'audio_book_id',
        'status',
        'clusters',
        'total_batches',
        'processed_batches',
        'target_level',
        'timelines',
        'retells',
        'outro',
        'versions',
        'error_message',
    ];

    protected $casts = [
        'clusters' => 'array',
        'timelines' => 'array',
        'retells' => 'array',
        'versions' => 'array',
    ];

    public function audioBook()
    {
        return $this->belongsTo(AudioBook::class);
    }
}

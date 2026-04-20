<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AudioBookChapterChunk extends Model
{
    use HasFactory;

    protected $table = 'audiobook_chapter_chunks';

    protected $fillable = [
        'audiobook_chapter_id',
        'book_id',
        'chapter_id',
        'chunk_index',
        'chunk_number',
        'text_content',
        'audio_file',
        'duration',
        'status',
        'error_message',
        'embedding_status',
        'embedded_at',
        'qdrant_point_id',
        'content_hash',
        'character_tags',
        'scene_type',
        'topic_tags',
        'importance_score',
    ];

    protected $casts = [
        'embedded_at' => 'datetime',
        'book_id' => 'integer',
        'chapter_id' => 'integer',
        'chunk_index' => 'integer',
        'character_tags' => 'array',
        'scene_type' => 'array',
        'topic_tags' => 'array',
        'importance_score' => 'float',
    ];

    public function chapter()
    {
        return $this->belongsTo(AudioBookChapter::class, 'audiobook_chapter_id');
    }
}

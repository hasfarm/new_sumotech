<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AudioBookChapter extends Model
{
    use HasFactory;

    protected $table = 'audiobook_chapters';

    protected $fillable = [
        'audio_book_id',
        'chapter_number',
        'title',
        'content',
        'cover_image',
        'video_path',
        'tts_voice',
        'tts_speed',
        'total_chunks',
        'total_duration',
        'audio_file',
        'status',
        'error_message',
        'youtube_video_id',
        'youtube_video_title',
        'youtube_video_description',
        'youtube_uploaded_at',
        'audio_boosted_at'
    ];

    protected $casts = [
        'tts_speed' => 'float',
        'total_duration' => 'float',
        'audio_boosted_at' => 'datetime',
    ];

    public function audioBook()
    {
        return $this->belongsTo(AudioBook::class);
    }

    public function chunks()
    {
        return $this->hasMany(AudioBookChapterChunk::class, 'audiobook_chapter_id', 'id')->orderBy('chunk_number');
    }

    /**
     * Split content into chunks (max 1000 characters per chunk)
     */
    public function splitIntoChunks($maxChunkSize = 1000)
    {
        $content = $this->content;
        $chunks = [];
        $currentChunk = '';
        $sentences = preg_split('/(?<=[.!?])\s+/', $content);

        foreach ($sentences as $sentence) {
            if (strlen($currentChunk) + strlen($sentence) + 1 <= $maxChunkSize) {
                $currentChunk .= ($currentChunk ? ' ' : '') . $sentence;
            } else {
                if ($currentChunk) {
                    $chunks[] = $currentChunk;
                }
                $currentChunk = $sentence;
            }
        }

        if ($currentChunk) {
            $chunks[] = $currentChunk;
        }

        return $chunks;
    }
}

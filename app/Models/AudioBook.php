<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AudioBook extends Model
{
    use HasFactory;

    protected $fillable = [
        'youtube_channel_id',
        'speaker_id',
        'title',
        'book_type',
        'author',
        'category',
        'description',
        'description_audio',
        'description_audio_duration',
        'description_lipsync_video',
        'description_lipsync_duration',
        'description_scene_video',
        'description_scene_video_duration',
        'cover_image',
        'language',
        'total_chapters',
        'tts_provider',
        'tts_voice_gender',
        'tts_voice_name',
        'tts_style_instruction',
        'tts_speed',
        'pause_between_chunks',
        'intro_music',
        'outro_music',
        'outro_use_intro',
        'intro_fade_duration',
        'outro_fade_duration',
        'outro_extend_duration',
        'wave_enabled',
        'wave_type',
        'wave_position',
        'wave_height',
        'wave_width',
        'wave_color',
        'wave_opacity',
        'youtube_playlist_id',
        'youtube_playlist_title',
        'youtube_video_title',
        'youtube_video_description',
        'youtube_video_tags',
        'full_book_video',
        'full_book_video_duration'
    ];

    protected $casts = [
        'outro_use_intro' => 'boolean',
        'wave_enabled' => 'boolean',
        'wave_opacity' => 'float',
    ];

    public function youtubeChannel()
    {
        return $this->belongsTo(YoutubeChannel::class);
    }

    /**
     * Get the speaker (MC) for this audiobook.
     */
    public function speaker()
    {
        return $this->belongsTo(ChannelSpeaker::class, 'speaker_id');
    }

    public function chapters()
    {
        return $this->hasMany(AudioBookChapter::class)->orderBy('chapter_number');
    }

    public function videoSegments()
    {
        return $this->hasMany(AudioBookVideoSegment::class)->orderBy('sort_order');
    }
}

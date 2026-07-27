<?php

namespace App\Services;

use App\Models\AudiobookVideoShot;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class AvatarSegmentService
{
    public function __construct(
        private readonly TTSService $ttsService,
        private readonly HeyGenLipsyncService $lipsyncService
    ) {}

    /**
     * Generate a real talking-head HeyGen clip for one avatar-flagged shot (~15-20s of
     * narration): TTS just that shot's text, then lipsync it onto the book's speaker avatar
     * image.
     *
     * @return string storage/app/public-relative path to the resulting mp4
     */
    public function generateSegment(AudiobookVideoShot $shot): string
    {
        $scene = $shot->scene;
        $audioBook = $scene?->audioBook;
        $speaker = $audioBook?->speaker;

        if (!$speaker || empty($speaker->avatar)) {
            throw new \RuntimeException('Sách chưa gán người dẫn chương trình (Speaker) có ảnh đại diện để tạo avatar.');
        }

        if (!str_starts_with((string) $speaker->avatar_url, 'https://') && !str_starts_with((string) $speaker->avatar_url, 'http://')) {
            throw new \RuntimeException('Ảnh đại diện của Speaker chưa phải URL công khai — HeyGen không thể truy cập được.');
        }

        $ttsPath = $this->ttsService->generateAudio(
            $shot->sentence_text,
            $shot->id,
            $audioBook->tts_voice_gender ?: 'female',
            $audioBook->tts_voice_name,
            $audioBook->tts_provider ?: 'google',
            null,
            null,
            (float) ($audioBook->tts_speed ?: 1.0)
        );

        $result = $this->lipsyncService->generateVideo($ttsPath, $speaker->avatar_url);
        $videoUrl = $result['video_url'] ?? null;

        if (!$videoUrl) {
            throw new \RuntimeException('HeyGen không trả về video_url.');
        }

        $relativePath = "audiobooks/{$audioBook->id}/video-pipeline/scenes/{$scene->id}/shots/{$shot->id}/avatar.mp4";

        $download = Http::timeout(240)->retry(2, 1000)->get($videoUrl);
        if (!$download->successful()) {
            throw new \RuntimeException('Không tải được video avatar từ HeyGen.');
        }

        Storage::disk('public')->put($relativePath, $download->body());

        return $relativePath;
    }
}

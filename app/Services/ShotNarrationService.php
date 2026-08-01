<?php

namespace App\Services;

use App\Models\AudiobookVideoShot;
use Illuminate\Support\Facades\Storage;

/**
 * Main-voice (whole-work) narration TTS for non-avatar shots — mirrors
 * AvatarSegmentService::generateTts() but always uses the pipeline's MAIN voice settings
 * (tts_provider/tts_voice_gender/tts_voice_name), never the avatar-specific ones, and persists
 * to its own column (narration_audio_path) so the two voices/audio tracks never collide.
 */
class ShotNarrationService
{
    public function __construct(
        private readonly TTSService $ttsService
    ) {}

    /**
     * @return string storage/app/public-relative path to the generated mp3/wav
     */
    public function generateNarrationTts(AudiobookVideoShot $shot): string
    {
        $scene = $shot->scene;
        $audioBook = $scene?->audioBook;
        if (!$audioBook) {
            throw new \RuntimeException('Không tìm thấy audiobook cho shot này.');
        }

        $pipeline = $audioBook->videoPipeline;
        $ttsProvider = $pipeline?->tts_provider ?: ($audioBook->tts_provider ?: 'google');
        $ttsVoiceGender = $pipeline?->tts_voice_gender ?: ($audioBook->tts_voice_gender ?: 'female');
        $ttsVoiceName = $pipeline?->tts_voice_name ?: $audioBook->tts_voice_name;

        $path = $this->ttsService->generateAudio(
            $shot->sentence_text,
            $shot->id,
            $ttsVoiceGender,
            $ttsVoiceName,
            $ttsProvider,
            null,
            null,
            (float) ($audioBook->tts_speed ?: 1.0)
        );

        $shot->update(['narration_audio_path' => $path]);

        return $path;
    }

    /**
     * Same 'local'-disk-vs-'public'-disk path-convention caveat as
     * AvatarSegmentService::avatarAudioExists() — TTSService always saves with a "public/"
     * prefix on the default 'local' disk.
     */
    public static function narrationAudioExists(?string $path): bool
    {
        return $path !== null && $path !== '' && Storage::disk('local')->exists($path);
    }
}

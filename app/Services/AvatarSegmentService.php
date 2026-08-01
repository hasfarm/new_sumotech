<?php

namespace App\Services;

use App\Models\AudiobookVideoShot;
use App\Services\AssetLibrary\R2StorageService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class AvatarSegmentService
{
    public function __construct(
        private readonly TTSService $ttsService,
        private readonly HeyGenLipsyncService $lipsyncService,
        private readonly R2StorageService $r2Storage
    ) {}

    /**
     * Which avatar image this shot should lipsync onto: its own chosen override
     * (avatar_image_path) if one was set, otherwise a sensible channel-wide default — this
     * fallback is what lets most shots never need an explicit per-shot choice. The avatar
     * library (MC/Speaker + their photos) is managed once per YouTube channel, not per book —
     * so the default here is the book's explicitly assigned speaker if one was set, else simply
     * the channel's own speaker (its avatar), never something created fresh per book. Returns a
     * raw storage-relative path or a full URL, whichever is stored; generateLipsync() below
     * uploads a local path to R2 for a presigned URL before ever handing it to HeyGen.
     */
    public function resolveAvatarImageUrl(AudiobookVideoShot $shot): ?string
    {
        if ($shot->avatar_image_path) {
            return $shot->avatar_image_path;
        }

        $audioBook = $shot->scene?->audioBook;
        $speaker = $audioBook?->speaker ?: $audioBook?->youtubeChannel?->speakers()->whereNotNull('avatar')->first();

        return $speaker->avatar ?? null;
    }

    /**
     * Generate (or regenerate) just the TTS narration for one avatar shot, using the video
     * pipeline's avatar-specific voice settings (falls back to the pipeline's main voice, then
     * the book's own regular tts_* settings). Persisted on the shot (avatar_audio_path) so a
     * later lipsync generation — or a retry — never silently re-spends a TTS call for text
     * that's already been synthesized; this used to be fully transient (regenerated fresh on
     * every single call, discarded immediately after being consumed by HeyGen).
     *
     * @return string storage/app/public-relative path to the generated mp3/wav
     */
    public function generateTts(AudiobookVideoShot $shot): string
    {
        $scene = $shot->scene;
        $audioBook = $scene?->audioBook;
        if (!$audioBook) {
            throw new \RuntimeException('Không tìm thấy audiobook cho shot này.');
        }

        $pipeline = $audioBook->videoPipeline;
        $ttsProvider = $pipeline?->avatar_tts_provider ?: ($pipeline?->tts_provider ?: ($audioBook->tts_provider ?: 'google'));
        $ttsVoiceGender = $pipeline?->avatar_tts_voice_gender ?: ($pipeline?->tts_voice_gender ?: ($audioBook->tts_voice_gender ?: 'female'));
        $ttsVoiceName = $pipeline?->avatar_tts_voice_name ?: ($pipeline?->tts_voice_name ?: $audioBook->tts_voice_name);

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

        $shot->update(['avatar_audio_path' => $path]);

        return $path;
    }

    /**
     * The lipsync step itself — requires an already-resolved avatar image AND an
     * already-generated TTS track (generateTts() above). Deliberately never generates either
     * on the fly: the UI's "missing avatar image / missing TTS" gate (see
     * AudioBookVideoPipelineController::generateAvatar()) is the only path that triggers them,
     * so a user always sees and can review/replace both before a HeyGen call is spent.
     *
     * @return string storage/app/public-relative path to the resulting mp4
     */
    public function generateLipsync(AudiobookVideoShot $shot): string
    {
        $scene = $shot->scene;
        $audioBook = $scene?->audioBook;

        $avatarImage = $this->resolveAvatarImageUrl($shot);
        if (!$avatarImage) {
            throw new \RuntimeException('Chưa có ảnh avatar — hãy chọn hoặc upload ảnh trước.');
        }

        if (!self::avatarAudioExists($shot->avatar_audio_path)) {
            throw new \RuntimeException('Chưa có audio TTS cho đoạn này — hãy tạo TTS trước.');
        }

        // HeyGen fetches these over the public internet — a local-dev APP_URL (or any host
        // HeyGen's servers can't reach) can never work here regardless of URL scheme, since
        // it's not a reachability problem "storage/app/public" can solve by itself. Uploading
        // a transient copy to R2 (already used for the reusable asset library elsewhere in
        // this app) and handing HeyGen a presigned URL sidesteps that on both local dev and
        // production, without requiring the R2 bucket itself to be public.
        $imageIsAlreadyUrl = filter_var($avatarImage, FILTER_VALIDATE_URL) !== false;
        $imageR2Key = "heygen-temp/{$shot->id}/image" . self::extensionOf($avatarImage);
        $audioR2Key = "heygen-temp/{$shot->id}/audio.mp3";

        $imageUrl = $imageIsAlreadyUrl
            ? $avatarImage
            : $this->uploadTempToR2(Storage::disk('public')->path($avatarImage), $imageR2Key);
        $audioUrl = $this->uploadTempToR2(Storage::disk('local')->path($shot->avatar_audio_path), $audioR2Key);

        try {
            $result = $this->lipsyncService->generateVideo($audioUrl, $imageUrl);
        } finally {
            $this->r2Storage->delete($audioR2Key);
            if (!$imageIsAlreadyUrl) {
                $this->r2Storage->delete($imageR2Key);
            }
        }

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

    /**
     * TTSService::generateAudio() saves via the default 'local' disk with an explicit
     * "public/" prefix folder (storage/app/public/...), not the 'public' disk with no prefix
     * — a different convention than the rest of this pipeline. Checking existence on the
     * 'public' disk with this path as-is silently mismatches (looks for a nonexistent nested
     * storage/app/public/public/... instead), so this must check the 'local' disk directly.
     */
    public static function avatarAudioExists(?string $path): bool
    {
        return $path !== null && $path !== '' && Storage::disk('local')->exists($path);
    }

    private function uploadTempToR2(string $localAbsolutePath, string $r2Key): string
    {
        $this->r2Storage->putFile($localAbsolutePath, $r2Key);
        return $this->r2Storage->temporaryUrl($r2Key, 30);
    }

    private static function extensionOf(string $path): string
    {
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        return $ext ? '.' . $ext : '.png';
    }
}

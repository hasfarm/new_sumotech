<?php

namespace App\Services;

use App\Models\AudioBook;
use App\Models\AudioBookChapter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Helper service để migrate sang FFmpeg Standard
 * Wrapper cho các function thường dùng trong AudioBook system
 */
class AudioBookStandardHelper
{
    protected FFmpegStandardService $ffmpegService;
    protected TTSService $ttsService;

    public function __construct(
        FFmpegStandardService $ffmpegService,
        TTSService $ttsService
    ) {
        $this->ffmpegService = $ffmpegService;
        $this->ttsService = $ttsService;
    }

    /**
     * Tạo audio chuẩn cho chapter từ TTS
     */
    public function generateChapterStandardAudio(AudioBookChapter $chapter, array $options = []): array
    {
        Log::info("Generating standard audio for chapter", ['chapter_id' => $chapter->id]);

        // Step 1: Generate raw TTS audio
        $text = $chapter->content;
        $audioBook = $chapter->audioBook;

        // Determine TTS settings from audiobook or speaker
        $provider = $options['provider'] ?? $audioBook->tts_provider ?? 'gemini';
        $voiceGender = $options['voice_gender'] ?? $audioBook->tts_voice_gender ?? 'female';
        $voiceName = $options['voice_name'] ?? $audioBook->tts_voice_name;
        $styleInstruction = $options['style'] ?? $audioBook->tts_style_instruction;

        $rawAudioPath = $this->ttsService->generateAudioFromText(
            $text,
            1, // index
            $voiceGender,
            $voiceName,
            $provider,
            $styleInstruction
        );

        // Step 2: Convert to standard MP3
        $standardDir = "audiobooks/{$audioBook->id}/chapters/{$chapter->id}";
        $standardFilename = "{$standardDir}/standard_audio.mp3";
        $standardPath = storage_path("app/public/{$standardFilename}");

        // Ensure directory exists
        $dir = dirname($standardPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $quality = $options['quality'] ?? 'high';

        $result = $this->ffmpegService->createStandardMP3(
            storage_path("app/{$rawAudioPath}"),
            $standardPath,
            [
                'quality' => $quality,
                'remove_silence' => $options['remove_silence'] ?? true,
                'metadata' => [
                    'title' => $chapter->title ?? "Chương {$chapter->chapter_number}",
                    'artist' => $audioBook->author ?? 'Unknown',
                    'album' => $audioBook->title,
                    'author' => $audioBook->speaker->name ?? 'AI Voice',
                    'description' => $chapter->description ?? '',
                    'genre' => 'Audiobook',
                    'year' => date('Y')
                ]
            ]
        );

        // Step 3: Update chapter with new audio info
        $chapter->update([
            'audio_file' => "public/{$standardFilename}",
            'audio_duration' => $result['duration'],
            'audio_size' => $result['size']
        ]);

        Log::info("Chapter standard audio generated", [
            'chapter_id' => $chapter->id,
            'duration' => $result['duration'],
            'size' => $result['size_formatted']
        ]);

        return $result;
    }

    /**
     * Tạo video chuẩn cho chapter từ image + audio
     */
    public function generateChapterStandardVideo(AudioBookChapter $chapter, array $options = []): array
    {
        Log::info("Generating standard video for chapter", ['chapter_id' => $chapter->id]);

        $audioBook = $chapter->audioBook;

        // Audio path
        $audioPath = $chapter->audio_file
            ? storage_path("app/{$chapter->audio_file}")
            : null;

        if (!$audioPath || !file_exists($audioPath)) {
            throw new \Exception("Chapter audio not found. Generate audio first.");
        }

        // Image/Cover path
        $imagePath = null;
        if ($chapter->cover_image) {
            $imagePath = storage_path("app/public/{$chapter->cover_image}");
        } elseif ($audioBook->cover_image) {
            $imagePath = storage_path("app/public/{$audioBook->cover_image}");
        }

        if (!$imagePath || !file_exists($imagePath)) {
            throw new \Exception("Cover image not found for chapter or audiobook.");
        }

        // Output video path
        $videoDir = "audiobooks/{$audioBook->id}/chapters/{$chapter->id}";
        $videoFilename = "{$videoDir}/standard_video.mp4";
        $videoPath = storage_path("app/public/{$videoFilename}");

        $dir = dirname($videoPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Video settings
        $resolution = $options['resolution'] ?? '1080p';
        $audioQuality = $options['audio_quality'] ?? 'premium';

        // Wave settings from audiobook
        $waveEnabled = $audioBook->wave_enabled ?? false;
        $waveSettings = [];
        if ($waveEnabled) {
            $waveSettings = [
                'type' => $audioBook->wave_type ?? 'line',
                'color' => $audioBook->wave_color ?? 'white',
                'position' => $audioBook->wave_position ?? 'bottom',
                'height' => $audioBook->wave_height ?? 100,
                'width_percent' => $audioBook->wave_width ?? 100,
                'opacity' => $audioBook->wave_opacity ?? 0.8
            ];
        }

        $result = $this->ffmpegService->createStandardMP4(
            $imagePath,
            $audioPath,
            $videoPath,
            [
                'resolution' => $resolution,
                'audio_quality' => $audioQuality,
                'zoom_effect' => $options['zoom_effect'] ?? true,
                'wave_effect' => $waveEnabled,
                'wave_settings' => $waveSettings,
                'normalize_audio' => true,
                'metadata' => [
                    'title' => $chapter->title ?? "Chương {$chapter->chapter_number}",
                    'description' => $audioBook->description ?? '',
                    'author' => $audioBook->youtubeChannel->title ?? 'Channel',
                    'year' => date('Y'),
                    'genre' => 'Audiobook'
                ]
            ]
        );

        // Update chapter
        $chapter->update([
            'video_file' => "public/{$videoFilename}",
            'video_duration' => $result['duration'],
            'video_size' => $result['size']
        ]);

        Log::info("Chapter standard video generated", [
            'chapter_id' => $chapter->id,
            'duration' => $result['duration'],
            'size' => $result['size_formatted'],
            'resolution' => $resolution
        ]);

        return $result;
    }

    /**
     * Merge tất cả chapter audios thành full book audio
     */
    public function mergeFullBookAudio(AudioBook $audioBook, array $options = []): array
    {
        Log::info("Merging full book audio", ['audiobook_id' => $audioBook->id]);

        $chapters = $audioBook->chapters()->orderBy('chapter_number')->get();

        if ($chapters->isEmpty()) {
            throw new \Exception("No chapters found for audiobook.");
        }

        // Collect all chapter audio paths
        $audioPaths = [];
        foreach ($chapters as $chapter) {
            if ($chapter->audio_file && file_exists(storage_path("app/{$chapter->audio_file}"))) {
                $audioPaths[] = storage_path("app/{$chapter->audio_file}");
            }
        }

        if (empty($audioPaths)) {
            throw new \Exception("No chapter audios found. Generate chapter audios first.");
        }

        // Add intro music if exists
        $finalPaths = [];
        if ($audioBook->intro_music) {
            $introPath = storage_path("app/public/{$audioBook->intro_music}");
            if (file_exists($introPath)) {
                $finalPaths[] = $introPath;
            }
        }

        // Add all chapter audios
        $finalPaths = array_merge($finalPaths, $audioPaths);

        // Add outro music if exists
        if ($audioBook->outro_music && !$audioBook->outro_use_intro) {
            $outroPath = storage_path("app/public/{$audioBook->outro_music}");
            if (file_exists($outroPath)) {
                $finalPaths[] = $outroPath;
            }
        } elseif ($audioBook->outro_use_intro && $audioBook->intro_music) {
            // Use intro as outro
            $introPath = storage_path("app/public/{$audioBook->intro_music}");
            if (file_exists($introPath)) {
                $finalPaths[] = $introPath;
            }
        }

        // Output path
        $fullBookDir = "audiobooks/{$audioBook->id}/full";
        $fullBookFilename = "{$fullBookDir}/fullbook_standard.mp3";
        $fullBookPath = storage_path("app/public/{$fullBookFilename}");

        $dir = dirname($fullBookPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Merge with crossfade
        $crossfade = $options['crossfade'] ?? 0.5;
        $quality = $options['quality'] ?? 'high';

        $result = $this->ffmpegService->mergeAudioFiles(
            $finalPaths,
            $fullBookPath,
            [
                'quality' => $quality,
                'crossfade' => $crossfade
            ]
        );

        // Update audiobook
        $audioBook->update([
            'full_audio_file' => "public/{$fullBookFilename}",
            'full_audio_duration' => $result['duration'],
            'full_audio_size' => $result['size']
        ]);

        Log::info("Full book audio merged", [
            'audiobook_id' => $audioBook->id,
            'chapters_count' => count($audioPaths),
            'duration' => $result['duration']
        ]);

        return $result;
    }

    /**
     * Generate description audio (giới thiệu sách)
     */
    public function generateDescriptionStandardAudio(AudioBook $audioBook, array $options = []): array
    {
        Log::info("Generating description audio", ['audiobook_id' => $audioBook->id]);

        if (empty($audioBook->description)) {
            throw new \Exception("Audiobook description is empty.");
        }

        // Generate raw TTS
        $provider = $options['provider'] ?? $audioBook->tts_provider ?? 'gemini';
        $voiceGender = $options['voice_gender'] ?? $audioBook->tts_voice_gender ?? 'female';
        $voiceName = $options['voice_name'] ?? $audioBook->tts_voice_name;
        $styleInstruction = $options['style'] ?? $audioBook->tts_style_instruction;

        $rawAudioPath = $this->ttsService->generateAudioFromText(
            $audioBook->description,
            0, // index 0 for description
            $voiceGender,
            $voiceName,
            $provider,
            $styleInstruction
        );

        // Convert to standard
        $descDir = "audiobooks/{$audioBook->id}/description";
        $descFilename = "{$descDir}/description_standard.mp3";
        $descPath = storage_path("app/public/{$descFilename}");

        $dir = dirname($descPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $result = $this->ffmpegService->createStandardMP3(
            storage_path("app/{$rawAudioPath}"),
            $descPath,
            [
                'quality' => 'premium',
                'remove_silence' => true,
                'metadata' => [
                    'title' => "Giới thiệu - {$audioBook->title}",
                    'artist' => $audioBook->author ?? 'Unknown',
                    'album' => $audioBook->title,
                    'author' => $audioBook->speaker->name ?? 'AI Voice',
                    'description' => 'Giới thiệu sách',
                    'genre' => 'Audiobook',
                    'year' => date('Y')
                ]
            ]
        );

        // Update audiobook
        $audioBook->update([
            'description_audio' => $descFilename,
            'description_audio_duration' => $result['duration']
        ]);

        Log::info("Description audio generated", [
            'audiobook_id' => $audioBook->id,
            'duration' => $result['duration']
        ]);

        return $result;
    }

    /**
     * Batch generate standard audio for multiple chapters
     */
    public function batchGenerateChapterAudios(AudioBook $audioBook, array $chapterIds = [], array $options = []): array
    {
        $chapters = $chapterIds
            ? $audioBook->chapters()->whereIn('id', $chapterIds)->get()
            : $audioBook->chapters()->get();

        $results = [];
        $successCount = 0;
        $failCount = 0;

        foreach ($chapters as $chapter) {
            try {
                $result = $this->generateChapterStandardAudio($chapter, $options);
                $results[] = [
                    'chapter_id' => $chapter->id,
                    'success' => true,
                    'result' => $result
                ];
                $successCount++;
            } catch (\Exception $e) {
                $results[] = [
                    'chapter_id' => $chapter->id,
                    'success' => false,
                    'error' => $e->getMessage()
                ];
                $failCount++;
                Log::error("Failed to generate audio for chapter", [
                    'chapter_id' => $chapter->id,
                    'error' => $e->getMessage()
                ]);
            }
        }

        Log::info("Batch audio generation completed", [
            'audiobook_id' => $audioBook->id,
            'total' => count($chapters),
            'success' => $successCount,
            'failed' => $failCount
        ]);

        return [
            'total' => count($chapters),
            'success' => $successCount,
            'failed' => $failCount,
            'results' => $results
        ];
    }

    /**
     * Batch generate standard videos for multiple chapters
     */
    public function batchGenerateChapterVideos(AudioBook $audioBook, array $chapterIds = [], array $options = []): array
    {
        $chapters = $chapterIds
            ? $audioBook->chapters()->whereIn('id', $chapterIds)->get()
            : $audioBook->chapters()->get();

        $results = [];
        $successCount = 0;
        $failCount = 0;

        foreach ($chapters as $chapter) {
            try {
                $result = $this->generateChapterStandardVideo($chapter, $options);
                $results[] = [
                    'chapter_id' => $chapter->id,
                    'success' => true,
                    'result' => $result
                ];
                $successCount++;
            } catch (\Exception $e) {
                $results[] = [
                    'chapter_id' => $chapter->id,
                    'success' => false,
                    'error' => $e->getMessage()
                ];
                $failCount++;
                Log::error("Failed to generate video for chapter", [
                    'chapter_id' => $chapter->id,
                    'error' => $e->getMessage()
                ]);
            }
        }

        Log::info("Batch video generation completed", [
            'audiobook_id' => $audioBook->id,
            'total' => count($chapters),
            'success' => $successCount,
            'failed' => $failCount
        ]);

        return [
            'total' => count($chapters),
            'success' => $successCount,
            'failed' => $failCount,
            'results' => $results
        ];
    }
}

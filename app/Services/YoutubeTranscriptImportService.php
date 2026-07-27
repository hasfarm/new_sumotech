<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Orchestrates "get a book chapter's worth of text from one YouTube video": YouTube's own
 * captions first, falling back to downloading the audio and transcribing it with Gemini
 * (App\Services\TranscriptionService), then auto-translating to Vietnamese if needed
 * (App\Services\TranslationService). Extracted out of AudioBookController so both the
 * synchronous flow and App\Jobs\FetchYoutubeTranscriptJob can share it, with an optional
 * progress callback for the job to report stage changes for the frontend to poll.
 */
class YoutubeTranscriptImportService
{
    // Gemini inline-audio practical limit — longer videos only get their first 20 minutes
    // transcribed for now when falling back to AI (no captions available).
    private const MAX_FALLBACK_SECONDS = 1200;

    public function __construct(
        private readonly YouTubeTranscriptService $youtubeTranscriptService,
        private readonly TranscriptionService $transcriptionService,
        private readonly TranslationService $translationService
    ) {}

    public function extractVideoId(string $url): ?string
    {
        $pattern = '/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/\s]{11})/i';
        return preg_match($pattern, $url, $matches) ? $matches[1] : null;
    }

    /**
     * @return array{success:bool,source?:string,title?:string,content?:string,translated?:bool,warning?:string,error?:string}
     */
    public function fetch(string $videoId, ?callable $onProgress = null): array
    {
        $report = fn(string $stage) => $onProgress && $onProgress($stage);

        $report('fetching_metadata');
        $title = '';
        try {
            $metadata = $this->youtubeTranscriptService->getMetadata($videoId);
            $title = trim((string) ($metadata['title'] ?? ''));
        } catch (\Throwable $e) {
            Log::warning('YoutubeTranscriptImportService: metadata fetch failed', ['video_id' => $videoId, 'error' => $e->getMessage()]);
        }

        // 1) YouTube's own captions first — fast, free, no audio processing needed.
        $report('fetching_captions');
        try {
            $segments = $this->youtubeTranscriptService->getTranscript($videoId);
            if (!empty($segments)) {
                [$segments, $wasTranslated] = $this->translateSegmentsIfNeeded($segments, $report);
                $content = $this->segmentsToChapterText($segments);
                if (trim($content) !== '') {
                    return [
                        'success' => true,
                        'source' => 'youtube_captions',
                        'title' => $title,
                        'content' => $content,
                        'translated' => $wasTranslated,
                    ];
                }
            }
        } catch (\Throwable $e) {
            Log::info('YoutubeTranscriptImportService: no YouTube captions, falling back to Gemini', [
                'video_id' => $videoId,
                'reason' => $e->getMessage(),
            ]);
        }

        // 2) Fallback: download audio, transcribe with Gemini.
        $report('downloading_audio');
        $audioPath = $this->downloadYoutubeAudio($videoId);
        if (!$audioPath) {
            return [
                'success' => false,
                'error' => 'Video này không có phụ đề YouTube sẵn có, và không tải được audio để chuyển bằng AI.',
            ];
        }

        try {
            $report('transcribing_ai');
            $segments = $this->transcriptionService->transcribeWithGemini($audioPath, self::MAX_FALLBACK_SECONDS);

            if (empty($segments)) {
                return [
                    'success' => false,
                    'error' => 'Video không có phụ đề sẵn, và AI (Gemini) không tạo được transcript từ audio.',
                ];
            }

            [$segments, $wasTranslated] = $this->translateSegmentsIfNeeded($segments, $report);
            $content = $this->segmentsToChapterText($segments);

            return [
                'success' => true,
                'source' => 'gemini_ai',
                'title' => $title,
                'content' => $content,
                'translated' => $wasTranslated,
                'warning' => 'Video không có phụ đề sẵn — nội dung được tạo bằng AI (Gemini), chỉ lấy tối đa 20 phút đầu.',
            ];
        } finally {
            @unlink($audioPath);
        }
    }

    /**
     * @param array<int,array{text?:string,start?:float,end?:float,duration?:float}> $segments
     * @return array{0:array<int,array{text?:string,start?:float,end?:float,duration?:float}>,1:bool}
     */
    private function translateSegmentsIfNeeded(array $segments, callable $report): array
    {
        $sample = mb_substr(implode(' ', array_map(fn($s) => (string) ($s['text'] ?? ''), array_slice($segments, 0, 15))), 0, 1000);

        if ($this->translationService->isLikelyVietnamese($sample)) {
            return [$segments, false];
        }

        $report('translating');

        try {
            $translated = $this->translationService->translateSegmentsWithGemini($segments);
            return [$translated, true];
        } catch (\Throwable $e) {
            Log::warning('YoutubeTranscriptImportService: translation failed, keeping original language', ['error' => $e->getMessage()]);
            return [$segments, false];
        }
    }

    /**
     * @param array<int,array{text?:string,start?:float,end?:float,duration?:float}> $segments
     */
    private function segmentsToChapterText(array $segments): string
    {
        $paragraphs = [];
        $current = '';

        foreach ($segments as $seg) {
            $text = trim((string) ($seg['text'] ?? ''));
            if ($text === '' || $text === '[Music]' || $text === '[Nhạc]') {
                continue;
            }
            $current .= ($current === '' ? '' : ' ') . $text;
            if (mb_strlen($current) >= 500) {
                $paragraphs[] = $current;
                $current = '';
            }
        }
        if (trim($current) !== '') {
            $paragraphs[] = $current;
        }

        return implode("\n\n", $paragraphs);
    }

    /**
     * Download best-audio-only for a YouTube video to a temp mp3 file.
     */
    private function downloadYoutubeAudio(string $videoId): ?string
    {
        $ytDlpPath = env('YTDLP_PATH', 'python -m yt_dlp');
        $ffmpegPath = env('FFMPEG_PATH', '');
        $ffmpegLocation = is_file($ffmpegPath) ? dirname($ffmpegPath) : (is_dir($ffmpegPath) ? $ffmpegPath : '');

        $outputBase = sys_get_temp_dir() . '/yt_audio_' . $videoId . '_' . time();

        $args = [
            '-f bestaudio',
            '--extract-audio',
            '--audio-format mp3',
            '--audio-quality 5',
            '--no-playlist',
        ];
        if ($ffmpegLocation !== '') {
            $args[] = '--ffmpeg-location ' . escapeshellarg($ffmpegLocation);
        }
        // NOTE: no yt-dlp %(...)s output template here — PHP's escapeshellarg() mangles the
        // literal "%" on Windows (turns it into a space, breaking the template placeholder).
        // Force a literal .mp3 filename instead; safe since --audio-format mp3 already
        // guarantees that's the extracted extension.
        $args[] = '-o ' . escapeshellarg($outputBase . '.mp3');
        $args[] = escapeshellarg('https://www.youtube.com/watch?v=' . $videoId);

        $command = trim($ytDlpPath . ' ' . implode(' ', $args)) . ' 2>&1';
        exec($command, $output, $code);

        $expected = $outputBase . '.mp3';
        if ($code !== 0 || !file_exists($expected) || filesize($expected) < 1000) {
            Log::warning('downloadYoutubeAudio failed', ['video_id' => $videoId, 'code' => $code, 'output' => implode("\n", array_slice($output, -10))]);
            @unlink($expected);
            return null;
        }

        return $expected;
    }
}

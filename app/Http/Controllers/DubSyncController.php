<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateDubSyncBatchTtsJob;
use App\Models\DubSyncProject;
use App\Services\GeminiImageService;
use App\Services\TranslationService;
use App\Services\TTSService;
use App\Services\ApiUsageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DubSyncController extends Controller
{
    /**
     * Display the DubSync main page
     */
    public function index()
    {
        try {
            \Log::info('DubSyncController: Loading index page');

            // Only select needed columns to reduce data load (exclude large JSON columns)
            $projects = DubSyncProject::select([
                'id',
                'video_id',
                'youtube_url',
                'youtube_title',
                'youtube_description',
                'youtube_thumbnail',
                'youtube_duration',
                'status',
                'segments',
                'created_at'
            ])->orderBy('created_at', 'desc')->paginate(10);

            \Log::info('DubSyncController: Successfully loaded projects', ['count' => $projects->count()]);

            return view('dubsync.index', compact('projects'));
        } catch (\Exception $e) {
            \Log::error('DubSyncController: Error loading index', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Return empty projects array as fallback
            $projects = collect([]);
            return view('dubsync.index', compact('projects'));
        }
    }

    /**
     * Get available TTS voices
     */
    public function getAvailableVoices(Request $request)
    {
        try {
            $gender = $request->query('gender', 'female');
            $provider = $request->query('provider', 'google');

            if ($provider === 'all') {
                $voices = [
                    'google' => \App\Services\TTSService::getAllVoices('google'),
                    'openai' => \App\Services\TTSService::getAllVoices('openai'),
                    'gemini' => \App\Services\TTSService::getAllVoices('gemini'),
                    'microsoft' => \App\Services\TTSService::getAllVoices('microsoft'),
                    'vbee' => \App\Services\TTSService::getAllVoices('vbee')
                ];
            } elseif ($gender === 'all') {
                $voices = \App\Services\TTSService::getAllVoices($provider);
            } else {
                $voices = [
                    $gender => \App\Services\TTSService::getAvailableVoices($gender, $provider)
                ];
            }

            return response()->json([
                'success' => true,
                'provider' => $provider,
                'voices' => $voices
            ]);
        } catch (\Exception $e) {
            \Log::error('Get available voices error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'gender' => $request->query('gender'),
                'provider' => $request->query('provider')
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Process YouTube URL and extract transcript
     */
    public function processYouTube(Request $request)
    {
        \Log::info('processYouTube called', ['request' => $request->all()]);

        $request->validate([
            'youtube_url' => 'required|url',
            'youtube_channel_id' => 'nullable|exists:youtube_channels,id'
        ]);

        try {
            $videoUrl = $request->youtube_url;
            \Log::info('Processing video URL', ['url' => $videoUrl]);

            $isBilibili = $this->isBilibiliVideoUrl($videoUrl);

            if ($isBilibili) {
                // Extract BV ID for storage
                if (preg_match('/\/video\/(BV[a-zA-Z0-9]+)/i', $videoUrl, $bvMatch)) {
                    $videoId = 'bili:' . $bvMatch[1];
                } else {
                    return response()->json(['error' => 'Bilibili URL không hợp lệ. Ví dụ: https://www.bilibili.com/video/BV1xxxx'], 400);
                }

                // Create a temporary project to use fetchBilibiliTranscriptData
                $project = DubSyncProject::create([
                    'user_id'            => auth()->id(),
                    'youtube_channel_id' => $request->input('youtube_channel_id'),
                    'video_id'           => $videoId,
                    'youtube_url'        => $videoUrl,
                    'status'             => 'new',
                ]);

                try {
                    // Fetch metadata via yt-dlp --dump-json
                    $ytDlpPath = env('YTDLP_PATH', 'python -m yt_dlp');
                    $metaJson  = [];
                    exec(sprintf('%s --dump-json --no-playlist %s 2>/dev/null', $ytDlpPath, escapeshellarg($videoUrl)), $metaJson);
                    $metaRaw  = json_decode(implode('', $metaJson), true) ?? [];
                    $metadata = [
                        'title'       => $metaRaw['title']     ?? null,
                        'thumbnail'   => $metaRaw['thumbnail'] ?? null,
                        'duration'    => isset($metaRaw['duration']) ? gmdate('H:i:s', (int) $metaRaw['duration']) : null,
                        'description' => $metaRaw['description'] ?? null,
                    ];

                    $bilibiliData = $this->fetchBilibiliTranscriptData($project);
                    $transcript   = $bilibiliData['transcript'];

                    $cleanedTranscript = app('App\Services\TranscriptCleanerService')->clean($transcript);
                    $segments = app('App\Services\TranscriptSegmentationService')->segment($cleanedTranscript);

                    $project->update([
                        'youtube_title'       => $metadata['title']       ?? null,
                        'youtube_thumbnail'   => $metadata['thumbnail']   ?? null,
                        'youtube_duration'    => $metadata['duration']    ?? null,
                        'youtube_description' => $metadata['description'] ?? null,
                        'original_transcript' => $transcript,
                        'segments'            => $segments,
                        'status'              => 'transcribed',
                    ]);
                } catch (\Exception $e) {
                    $project->update(['status' => 'error']);
                    throw $e;
                }

                \Log::info('Bilibili project created', ['project_id' => $project->id, 'segments' => count($segments)]);

                return response()->json([
                    'success'            => true,
                    'project_id'         => $project->id,
                    'video_id'           => $videoId,
                    'metadata'           => $metadata,
                    'segments'           => $segments,
                    'processing_complete' => true,
                ]);
            }

            // ── YouTube flow ──────────────────────────────────────────────────
            $videoId = $this->extractVideoId($videoUrl);

            if (!$videoId) {
                \Log::warning('Invalid video ID', ['url' => $videoUrl]);
                return response()->json(['error' => 'URL không hợp lệ. Hỗ trợ YouTube và Bilibili.'], 400);
            }

            // Step 0: Get YouTube metadata (title, description, duration, thumbnail)
            $metadata = app('App\Services\YouTubeTranscriptService')->getMetadata($videoId);
            \Log::info('YouTube metadata retrieved', ['metadata' => $metadata]);

            // Step 1: Get transcript with timestamps
            $transcript = app('App\Services\YouTubeTranscriptService')->getTranscript($videoId);

            // Step 2: Clean transcript
            $cleanedTranscript = app('App\Services\TranscriptCleanerService')->clean($transcript);

            // Step 3: Segment transcript using basic segmentation (no AI processing)
            $segmentationService = app('App\Services\TranscriptSegmentationService');
            $segments = $segmentationService->segment($cleanedTranscript);

            // Create project with segmented transcript
            $project = DubSyncProject::create([
                'user_id' => auth()->id(),
                'youtube_channel_id' => $request->input('youtube_channel_id'),
                'video_id' => $videoId,
                'youtube_url' => $videoUrl,
                'youtube_title' => $metadata['title'] ?? null,
                'youtube_description' => $metadata['description'] ?? null,
                'youtube_thumbnail' => $metadata['thumbnail'] ?? null,
                'youtube_duration' => $metadata['duration'] ?? null,
                'original_transcript' => $transcript,
                'segments' => $segments,
                'status' => 'transcribed'
            ]);

            \Log::info('Project created with segments', ['project_id' => $project->id, 'segment_count' => count($segments)]);

            // Return response with segments ready
            return response()->json([
                'success' => true,
                'project_id' => $project->id,
                'video_id' => $videoId,
                'metadata' => $metadata,
                'segments' => $segments,
                'processing_complete' => true // Processing is done immediately
            ]);
        } catch (\Exception $e) {
            \Log::error('ProcessYouTube error', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get transcript for an existing project
     */
    public function getTranscriptForProject(Request $request, DubSyncProject $project)
    {
        if ($project->user_id !== auth()->id()) {
            abort(403, 'Unauthorized');
        }

        try {
            [$videoId, $metadata, $transcript, $segments, $translatedSegments] = $this->buildTranscriptPayloadForProject($project);
            $resolvedTitleVi = $this->resolveVietnameseTitleForUpdate(
                (string) ($project->youtube_title_vi ?? ''),
                (string) ($metadata['title_vi'] ?? '')
            );

            $project->update([
                'video_id' => $videoId,
                'youtube_title' => $metadata['title'] ?? $project->youtube_title,
                'youtube_title_vi' => $resolvedTitleVi,
                'youtube_description' => $metadata['description'] ?? $project->youtube_description,
                'youtube_thumbnail' => $metadata['thumbnail'] ?? $project->youtube_thumbnail,
                'youtube_duration' => $metadata['duration'] ?? $project->youtube_duration,
                'original_transcript' => $transcript,
                'segments' => $segments,
                'translated_segments' => $translatedSegments,
                'status' => 'transcribed',
            ]);

            return redirect()->route('projects.edit', $project)
                ->with('success', 'Transcript đã sẵn sàng.');
        } catch (\Exception $e) {
            return redirect()->back()->withErrors(['error' => $e->getMessage()]);
        }
    }

    /**
     * Get transcript for an existing project (async JSON)
     */
    public function getTranscriptForProjectAsync(Request $request, DubSyncProject $project)
    {
        if ($project->user_id !== auth()->id()) {
            return response()->json(['success' => false, 'error' => 'Unauthorized'], 403);
        }

        try {
            $project->update(['status' => 'processing']);

            [$videoId, $metadata, $transcript, $segments, $translatedSegments] = $this->buildTranscriptPayloadForProject($project);
            $resolvedTitleVi = $this->resolveVietnameseTitleForUpdate(
                (string) ($project->youtube_title_vi ?? ''),
                (string) ($metadata['title_vi'] ?? '')
            );

            $project->update([
                'video_id' => $videoId,
                'youtube_title' => $metadata['title'] ?? $project->youtube_title,
                'youtube_title_vi' => $resolvedTitleVi,
                'youtube_description' => $metadata['description'] ?? $project->youtube_description,
                'youtube_thumbnail' => $metadata['thumbnail'] ?? $project->youtube_thumbnail,
                'youtube_duration' => $metadata['duration'] ?? $project->youtube_duration,
                'original_transcript' => $transcript,
                'segments' => $segments,
                'translated_segments' => $translatedSegments,
                'status' => 'transcribed',
            ]);

            return response()->json(['success' => true, 'status' => 'transcribed']);
        } catch (\Exception $e) {
            $project->update([
                'status' => 'error',
                'error_message' => $e->getMessage()
            ]);
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Build transcript + segmented payload for project source.
     * Supports YouTube and Bilibili videos.
     */
    private function buildTranscriptPayloadForProject(DubSyncProject $project): array
    {
        $sourceUrl = trim((string) ($project->youtube_url ?? ''));
        $isBilibili = $this->isBilibiliVideoUrl($sourceUrl) || str_starts_with((string) $project->video_id, 'bili:');

        if ($isBilibili) {
            $bilibiliData = $this->fetchBilibiliTranscriptData($project);
            $transcript = $bilibiliData['transcript'];
            $cleanedTranscript = app('App\\Services\\TranscriptCleanerService')->clean($transcript);
            $segments = app('App\\Services\\TranscriptSegmentationService')->segment($cleanedTranscript);

            $translatedSegments = [];
            $translatedTitleVi = null;

            $sourceLang = (string) ($bilibiliData['language'] ?? 'auto');
            if ($sourceLang !== 'vi' && !$this->isLikelyVietnameseTranscript($segments)) {
                $translator = app(TranslationService::class);
                $translatedSegments = $translator->translateSegments($segments, 'google', $project->id, $sourceLang);

                if (!empty($project->youtube_title) && preg_match('/\\p{Han}/u', $project->youtube_title) === 1) {
                    $translatedTitleVi = $translator->translateText($project->youtube_title, 'zh-CN', 'vi', 'google');
                }
            }

            return [
                $project->video_id,
                [
                    'title' => $project->youtube_title,
                    'title_vi' => $translatedTitleVi,
                    'description' => $project->youtube_description,
                    'thumbnail' => $project->youtube_thumbnail,
                    'duration' => $project->youtube_duration,
                ],
                $transcript,
                $segments,
                $translatedSegments,
            ];
        }

        $videoId = $project->video_id ?: $this->extractVideoId($sourceUrl);
        if (!$videoId) {
            throw new \Exception('Invalid source URL');
        }

        $metadata = app('App\\Services\\YouTubeTranscriptService')->getMetadata($videoId);
        $transcript = app('App\\Services\\YouTubeTranscriptService')->getTranscript($videoId);
        $cleanedTranscript = app('App\\Services\\TranscriptCleanerService')->clean($transcript);
        $segments = app('App\\Services\\TranscriptSegmentationService')->segment($cleanedTranscript);

        return [$videoId, $metadata, $transcript, $segments, []];
    }

    private function isBilibiliVideoUrl(string $url): bool
    {
        return preg_match('/(?:bilibili\\.com\\/video\\/|b23\\.tv\\/)/i', $url) === 1;
    }

    private function resolveBilibiliVideoUrl(DubSyncProject $project): string
    {
        $url = trim((string) ($project->youtube_url ?? ''));
        if ($this->isBilibiliVideoUrl($url)) {
            return $url;
        }

        $videoId = trim((string) ($project->video_id ?? ''));
        if (str_starts_with($videoId, 'bili:')) {
            $bvid = trim(substr($videoId, 5));
            if ($bvid !== '') {
                return "https://www.bilibili.com/video/{$bvid}";
            }
        }

        throw new \Exception('Bilibili URL khong hop le');
    }

    private function fetchBilibiliTranscriptData(DubSyncProject $project): array
    {
        $videoUrl = $this->resolveBilibiliVideoUrl($project);
        $ytDlpPath = env('YTDLP_PATH', 'python -m yt_dlp');

        $tmpRoot = storage_path('app/tmp');
        if (!is_dir($tmpRoot)) {
            mkdir($tmpRoot, 0755, true);
        }

        $tmpDir = $tmpRoot . DIRECTORY_SEPARATOR . 'bili_subs_' . $project->id . '_' . time();
        mkdir($tmpDir, 0755, true);

        $outputTemplate = $tmpDir . DIRECTORY_SEPARATOR . '%(id)s.%(ext)s';
        $command = sprintf(
            '%s --skip-download --write-subs --write-auto-subs --sub-langs "vi,zh-Hans,zh-CN,zh,zh-TW,en" --sub-format "vtt" --no-playlist --no-part -o %s %s 2>&1',
            $ytDlpPath,
            '"' . $outputTemplate . '"',
            escapeshellarg($videoUrl)
        );

        $output = [];
        $returnCode = 0;
        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            $this->cleanupDirectory($tmpDir);
            throw new \Exception('Khong lay duoc subtitle tu Bilibili: ' . implode("\n", array_slice($output, -5)));
        }

        $vttFiles = glob($tmpDir . DIRECTORY_SEPARATOR . '*.vtt') ?: [];
        if (empty($vttFiles)) {
            $fallback = $this->transcribeBilibiliFromAudio($videoUrl, $ytDlpPath, $tmpDir);
            $this->cleanupDirectory($tmpDir);

            if (!empty($fallback['transcript'])) {
                return $fallback;
            }

            throw new \Exception('Bilibili video khong co subtitle va khong the transcribe tu audio.');
        }

        $selected = $this->pickBestBilibiliSubtitleFile($vttFiles);
        $language = $selected['language'];
        $transcript = $this->parseVttTranscript($selected['path']);

        if (empty($transcript)) {
            $fallback = $this->transcribeBilibiliFromAudio($videoUrl, $ytDlpPath, $tmpDir);
            $this->cleanupDirectory($tmpDir);

            if (!empty($fallback['transcript'])) {
                return $fallback;
            }

            throw new \Exception('Subtitle Bilibili rong va khong the transcribe tu audio.');
        }

        $this->cleanupDirectory($tmpDir);

        return [
            'language' => $language,
            'transcript' => $transcript,
        ];
    }

    private function transcribeBilibiliFromAudio(string $videoUrl, string $ytDlpPath, string $tmpDir): array
    {
        $apiKey = (string) config('services.openai.api_key', '');
        if ($apiKey === '') {
            return ['language' => 'unknown', 'transcript' => []];
        }

        $ffmpegPath = env('FFMPEG_PATH', '');
        $ffmpegLocationArg = '';
        if ($ffmpegPath !== '') {
            if (is_file($ffmpegPath)) {
                $ffmpegLocationArg = '--ffmpeg-location ' . escapeshellarg(dirname($ffmpegPath));
            } elseif (is_dir($ffmpegPath)) {
                $ffmpegLocationArg = '--ffmpeg-location ' . escapeshellarg($ffmpegPath);
            }
        }

        $audioTemplate = $tmpDir . DIRECTORY_SEPARATOR . 'audio_%(id)s.%(ext)s';
        $downloadAudioCmd = sprintf(
            '%s -x --audio-format mp3 --audio-quality 64K --no-playlist --no-part %s -o %s %s 2>&1',
            $ytDlpPath,
            $ffmpegLocationArg,
            '"' . $audioTemplate . '"',
            escapeshellarg($videoUrl)
        );

        $downloadOut = [];
        $downloadCode = 0;
        exec($downloadAudioCmd, $downloadOut, $downloadCode);
        if ($downloadCode !== 0) {
            \Log::warning('Bilibili audio fallback download failed', [
                'video_url' => $videoUrl,
                'tail' => array_slice($downloadOut, -5),
            ]);
            return ['language' => 'unknown', 'transcript' => []];
        }

        $audioFiles = glob($tmpDir . DIRECTORY_SEPARATOR . 'audio_*.*') ?: [];
        if (empty($audioFiles)) {
            return ['language' => 'unknown', 'transcript' => []];
        }

        usort($audioFiles, function ($a, $b) {
            return filesize($b) <=> filesize($a);
        });
        $sourceAudio = $audioFiles[0];

        $ffmpegPath = config('services.ffmpeg.path', env('FFMPEG_PATH', 'ffmpeg'));
        $preparedAudio = $tmpDir . DIRECTORY_SEPARATOR . 'whisper_input.mp3';
        $prepareCmd = sprintf(
            '%s -y -i %s -vn -ac 1 -ar 16000 -b:a 24k %s 2>&1',
            escapeshellarg($ffmpegPath),
            escapeshellarg($sourceAudio),
            escapeshellarg($preparedAudio)
        );
        exec($prepareCmd, $prepareOut, $prepareCode);

        $audioForWhisper = ($prepareCode === 0 && file_exists($preparedAudio)) ? $preparedAudio : $sourceAudio;

        // OpenAI whisper-1 upload limit is about 25MB.
        if (!file_exists($audioForWhisper) || filesize($audioForWhisper) > (24 * 1024 * 1024)) {
            \Log::warning('Bilibili audio fallback too large for Whisper', [
                'file' => $audioForWhisper,
                'size' => @filesize($audioForWhisper),
            ]);
            return ['language' => 'unknown', 'transcript' => []];
        }

        try {
            $client = new \GuzzleHttp\Client();
            $response = $client->post('https://api.openai.com/v1/audio/transcriptions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                ],
                'multipart' => [
                    ['name' => 'file', 'contents' => fopen($audioForWhisper, 'r'), 'filename' => basename($audioForWhisper)],
                    ['name' => 'model', 'contents' => 'whisper-1'],
                    ['name' => 'response_format', 'contents' => 'verbose_json'],
                    ['name' => 'timestamp_granularities[]', 'contents' => 'segment'],
                ],
                'timeout' => 600,
            ]);

            $result = json_decode($response->getBody()->getContents(), true);
            $segments = $result['segments'] ?? [];
            $language = (string) ($result['language'] ?? 'unknown');

            $mapped = [];
            foreach ($segments as $seg) {
                $text = trim((string) ($seg['text'] ?? ''));
                if ($text === '') {
                    continue;
                }
                $start = (float) ($seg['start'] ?? 0);
                $end = (float) ($seg['end'] ?? $start + 0.1);
                $mapped[] = [
                    'text' => $text,
                    'start' => round($start, 3),
                    'duration' => round(max(0.1, $end - $start), 3),
                ];
            }

            return [
                'language' => str_starts_with($language, 'vi') ? 'vi' : (str_starts_with($language, 'zh') ? 'zh' : $language),
                'transcript' => $mapped,
            ];
        } catch (\Throwable $e) {
            \Log::warning('Bilibili audio fallback transcription failed', [
                'error' => $e->getMessage(),
            ]);
            return ['language' => 'unknown', 'transcript' => []];
        }
    }

    private function pickBestBilibiliSubtitleFile(array $files): array
    {
        $priorities = [
            'vi' => ['.vi.', '.vi-'],
            'zh' => ['.zh-Hans.', '.zh-CN.', '.zh-TW.', '.zh.', '.zh-'],
            'en' => ['.en.', '.en-'],
        ];

        foreach ($priorities as $lang => $needles) {
            foreach ($files as $file) {
                $name = basename($file);
                foreach ($needles as $needle) {
                    if (stripos($name, $needle) !== false) {
                        return ['path' => $file, 'language' => $lang];
                    }
                }
            }
        }

        return ['path' => $files[0], 'language' => 'unknown'];
    }

    private function parseVttTranscript(string $filePath): array
    {
        $content = @file_get_contents($filePath);
        if ($content === false) {
            return [];
        }

        $lines = preg_split('/\R/u', $content) ?: [];
        $entries = [];
        $currentStart = null;
        $currentEnd = null;
        $buffer = [];

        foreach ($lines as $lineRaw) {
            $line = trim($lineRaw);

            if ($line === '' || strtoupper($line) === 'WEBVTT' || str_starts_with(strtoupper($line), 'NOTE')) {
                if ($currentStart !== null && !empty($buffer)) {
                    $text = trim(html_entity_decode(strip_tags(implode(' ', $buffer)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                    if ($text !== '') {
                        $duration = max(0.1, $currentEnd - $currentStart);
                        $entries[] = [
                            'text' => $text,
                            'start' => round($currentStart, 3),
                            'duration' => round($duration, 3),
                        ];
                    }
                }

                $currentStart = null;
                $currentEnd = null;
                $buffer = [];
                continue;
            }

            if (preg_match('/^([0-9:.]+)\s+-->\s+([0-9:.]+)/', $line, $m)) {
                $currentStart = $this->vttTimeToSeconds($m[1]);
                $currentEnd = $this->vttTimeToSeconds($m[2]);
                $buffer = [];
                continue;
            }

            if ($currentStart !== null) {
                $buffer[] = preg_replace('/<[^>]+>/', '', $line);
            }
        }

        // Deduplicate consecutive identical lines.
        $deduped = [];
        $lastText = null;
        foreach ($entries as $entry) {
            if ($entry['text'] === $lastText) {
                continue;
            }
            $deduped[] = $entry;
            $lastText = $entry['text'];
        }

        return $deduped;
    }

    private function vttTimeToSeconds(string $time): float
    {
        $time = str_replace(',', '.', $time);
        $parts = explode(':', $time);
        if (count($parts) === 3) {
            return ((float) $parts[0]) * 3600 + ((float) $parts[1]) * 60 + (float) $parts[2];
        }
        if (count($parts) === 2) {
            return ((float) $parts[0]) * 60 + (float) $parts[1];
        }
        return (float) $time;
    }

    private function cleanupDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        @rmdir($dir);
    }

    private function isLikelyVietnameseTranscript(array $segments): bool
    {
        if (empty($segments)) {
            return false;
        }

        $sample = array_slice($segments, 0, min(8, count($segments)));
        $viCount = 0;

        foreach ($sample as $seg) {
            $text = trim((string) ($seg['text'] ?? ''));
            if ($text === '') {
                continue;
            }

            if (preg_match('/[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}]/u', $text) === 1) {
                continue;
            }

            if (preg_match('/[ăâêôơưđáàảãạắằẳẵặấầẩẫậéèẻẽẹếềểễệóòỏõọốồổỗộớờởỡợúùủũụứừửữựíìỉĩịýỳỷỹỵ]/iu', $text) === 1
                || preg_match('/\b(khong|cua|nhung|duoc|trong|mot|voi|la)\b/iu', $text) === 1) {
                $viCount++;
            }
        }

        return $viCount >= max(1, (int) floor(count($sample) * 0.4));
    }

    private function resolveVietnameseTitleForUpdate(string $existingTitleVi, string $candidateTitleVi): string
    {
        $existing = trim($existingTitleVi);
        $candidate = trim($candidateTitleVi);

        if ($candidate === '') {
            return $existing;
        }

        // Reject CJK-heavy strings for VI title to avoid accidental overwrite with source title.
        if (preg_match('/[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}]/u', $candidate) === 1) {
            return $existing;
        }

        // Accept when candidate looks Vietnamese.
        if (preg_match('/[ăâêôơưđáàảãạắằẳẵặấầẩẫậéèẻẽẹếềểễệóòỏõọốồổỗộớờởỡợúùủũụứừửữựíìỉĩịýỳỷỹỵ]/iu', $candidate) === 1
            || preg_match('/\b(khong|cua|nhung|duoc|trong|mot|voi|la|ban|toi)\b/iu', $candidate) === 1) {
            return $candidate;
        }

        return $existing;
    }

    /**
     * Fetch YouTube channel videos via Google API
     */
    public function fetchChannelVideos(Request $request)
    {
        $request->validate([
            'channel_url' => 'required|url',
            'max_results' => 'nullable|integer|min:1|max:50',
        ]);

        $channelUrl = $request->input('channel_url');
        $maxResults = (int) $request->input('max_results', 20);
        $maxResults = max(1, min(50, $maxResults));

        if ($this->isBilibiliSpaceUrl($channelUrl)) {
            return $this->fetchBilibiliSpaceVideos($channelUrl, $maxResults);
        }

        $apiKey = config('services.youtube.api_key');
        if (!$apiKey) {
            return response()->json([
                'success' => false,
                'error' => 'Missing YOUTUBE_API_KEY in .env'
            ], 500);
        }

        $handle = null;
        $channelId = null;
        $username = null;

        if (preg_match('/youtube\.com\/@([^\/?]+)/i', $channelUrl, $matches)) {
            $handle = $matches[1];
        } elseif (preg_match('/youtube\.com\/channel\/([^\/?]+)/i', $channelUrl, $matches)) {
            $channelId = $matches[1];
        } elseif (preg_match('/youtube\.com\/user\/([^\/?]+)/i', $channelUrl, $matches)) {
            $username = $matches[1];
        }

        if (!$handle && !$channelId && !$username) {
            return response()->json([
                'success' => false,
                'error' => 'Unsupported channel URL. Use @handle or /channel/ ID.'
            ], 422);
        }

        $channelParams = [
            'part' => 'id,snippet,contentDetails',
            'key' => $apiKey,
        ];

        if ($handle) {
            $channelParams['forHandle'] = $handle;
        } elseif ($channelId) {
            $channelParams['id'] = $channelId;
        } else {
            $channelParams['forUsername'] = $username;
        }

        $channelResponse = Http::get('https://www.googleapis.com/youtube/v3/channels', $channelParams);

        if (!$channelResponse->ok()) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to fetch channel info from YouTube API.'
            ], 500);
        }

        $channelItems = $channelResponse->json('items', []);
        if (empty($channelItems)) {
            return response()->json([
                'success' => false,
                'error' => 'Channel not found.'
            ], 404);
        }

        $channelItem = $channelItems[0];
        $uploadsPlaylistId = data_get($channelItem, 'contentDetails.relatedPlaylists.uploads');

        if (!$uploadsPlaylistId) {
            return response()->json([
                'success' => false,
                'error' => 'Unable to locate channel uploads playlist.'
            ], 500);
        }

        $playlistResponse = Http::get('https://www.googleapis.com/youtube/v3/playlistItems', [
            'part' => 'snippet,contentDetails',
            'playlistId' => $uploadsPlaylistId,
            'maxResults' => $maxResults,
            'key' => $apiKey,
        ]);

        if (!$playlistResponse->ok()) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to fetch channel videos from YouTube API.'
            ], 500);
        }

        $videos = collect($playlistResponse->json('items', []))
            ->map(function ($item) {
                $videoId = data_get($item, 'contentDetails.videoId');
                $title = data_get($item, 'snippet.title');
                $thumbnail = data_get($item, 'snippet.thumbnails.medium.url')
                    ?? data_get($item, 'snippet.thumbnails.default.url');
                $publishedAt = data_get($item, 'snippet.publishedAt');

                return [
                    'video_id' => $videoId,
                    'title' => $title,
                    'thumbnail' => $thumbnail,
                    'video_url' => $videoId ? "https://www.youtube.com/watch?v={$videoId}" : null,
                    'published_at' => $publishedAt,
                ];
            })
            ->filter(fn($video) => !empty($video['video_id']))
            ->values();

        return response()->json([
            'success' => true,
            'channel' => [
                'id' => data_get($channelItem, 'id'),
                'title' => data_get($channelItem, 'snippet.title'),
                'description' => data_get($channelItem, 'snippet.description'),
                'thumbnail' => data_get($channelItem, 'snippet.thumbnails.medium.url')
                    ?? data_get($channelItem, 'snippet.thumbnails.default.url'),
            ],
            'videos' => $videos,
        ]);
    }

    private function isBilibiliSpaceUrl(string $channelUrl): bool
    {
        return preg_match('/space\.bilibili\.com\/\d+/i', $channelUrl) === 1;
    }

    private function extractBilibiliMid(string $channelUrl): ?string
    {
        if (preg_match('/space\.bilibili\.com\/(\d+)/i', $channelUrl, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function normalizeBilibiliImageUrl(?string $url): ?string
    {
        if (!$url) {
            return null;
        }

        if (str_starts_with($url, '//')) {
            return 'https:' . $url;
        }

        if (str_starts_with($url, 'http://')) {
            return 'https://' . substr($url, 7);
        }

        return $url;
    }

    private function fetchBilibiliSpaceVideos(string $channelUrl, int $maxResults)
    {
        $mid = $this->extractBilibiliMid($channelUrl);
        if (!$mid) {
            return response()->json([
                'success' => false,
                'error' => 'Bilibili URL không hợp lệ. Ví dụ: https://space.bilibili.com/123456'
            ], 422);
        }

        $baseHeaders = [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
            'Referer'    => 'https://space.bilibili.com/',
            'Origin'     => 'https://space.bilibili.com',
        ];
        $sessdata = env('BILIBILI_SESSDATA', '');
        if ($sessdata !== '') {
            $baseHeaders['Cookie'] = 'SESSDATA=' . $sessdata;
        }

        try {
            // Fetch channel info and video list in parallel.
            [$infoResponse, $listResponse] = \Illuminate\Support\Facades\Http::pool(fn ($pool) => [
                $pool->withHeaders($baseHeaders)->timeout(10)
                    ->get('https://api.bilibili.com/x/space/acc/info', ['mid' => $mid, 'jsonp' => 'jsonp']),
                $pool->withHeaders($baseHeaders)->timeout(15)
                    ->get('https://api.bilibili.com/x/space/arc/search', [
                        'mid'   => $mid,
                        'ps'    => min($maxResults, 50),
                        'pn'    => 1,
                        'order' => 'pubdate',
                        'jsonp' => 'jsonp',
                    ]),
            ]);

            // Parse channel info.
            $channelName = null;
            $channelAvatar = null;
            if ($infoResponse->ok()) {
                $infoJson = $infoResponse->json();
                if (($infoJson['code'] ?? -1) === 0) {
                    $channelName   = data_get($infoJson, 'data.name');
                    $channelAvatar = $this->normalizeBilibiliImageUrl(data_get($infoJson, 'data.face'));
                }
            }

            // Parse video list.
            if (!$listResponse->ok()) {
                return response()->json(['success' => false, 'error' => 'Không thể lấy danh sách video từ Bilibili.'], 502);
            }

            $listJson = $listResponse->json();
            if (($listJson['code'] ?? -1) !== 0) {
                return response()->json([
                    'success' => false,
                    'error'   => $listJson['message'] ?? 'Bilibili từ chối yêu cầu (có thể do anti-crawler).',
                ], 422);
            }

            $rawVideos = data_get($listJson, 'data.list.vlist', []);
            $videos = collect($rawVideos)
                ->map(function ($item) {
                    $bvid        = data_get($item, 'bvid');
                    $publishedTs = (int) (data_get($item, 'created') ?? 0);
                    return [
                        'video_id'     => $bvid ? ('bili:' . $bvid) : null,
                        'title'        => data_get($item, 'title'),
                        'thumbnail'    => $this->normalizeBilibiliImageUrl(data_get($item, 'pic')),
                        'video_url'    => $bvid ? "https://www.bilibili.com/video/{$bvid}" : null,
                        'published_at' => $publishedTs > 0 ? date('c', $publishedTs) : null,
                    ];
                })
                ->filter(fn($v) => !empty($v['video_id']))
                ->values();

            // If maxResults exceeds one page (50), fetch remaining pages.
            $total = (int) data_get($listJson, 'data.page.count', 0);
            if ($maxResults > 50 && $total > 50) {
                $page = 2;
                while ($videos->count() < $maxResults) {
                    $nextResponse = Http::withHeaders($baseHeaders)->timeout(15)
                        ->get('https://api.bilibili.com/x/space/arc/search', [
                            'mid'   => $mid,
                            'ps'    => 50,
                            'pn'    => $page,
                            'order' => 'pubdate',
                            'jsonp' => 'jsonp',
                        ]);
                    if (!$nextResponse->ok()) break;
                    $nextJson = $nextResponse->json();
                    if (($nextJson['code'] ?? -1) !== 0) break;
                    $moreRaw = data_get($nextJson, 'data.list.vlist', []);
                    if (empty($moreRaw)) break;
                    $moreVideos = collect($moreRaw)->map(function ($item) {
                        $bvid        = data_get($item, 'bvid');
                        $publishedTs = (int) (data_get($item, 'created') ?? 0);
                        return [
                            'video_id'     => $bvid ? ('bili:' . $bvid) : null,
                            'title'        => data_get($item, 'title'),
                            'thumbnail'    => $this->normalizeBilibiliImageUrl(data_get($item, 'pic')),
                            'video_url'    => $bvid ? "https://www.bilibili.com/video/{$bvid}" : null,
                            'published_at' => $publishedTs > 0 ? date('c', $publishedTs) : null,
                        ];
                    })->filter(fn($v) => !empty($v['video_id']));
                    $videos = $videos->merge($moreVideos);
                    $page++;
                }
            }

            $videos = $videos->take($maxResults)->values();

            return response()->json([
                'success' => true,
                'channel' => [
                    'id'          => 'bilibili:' . $mid,
                    'title'       => $channelName ?: ('Bilibili Space ' . $mid),
                    'description' => null,
                    'thumbnail'   => $channelAvatar,
                ],
                'videos' => $videos,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => 'Lỗi khi lấy dữ liệu Bilibili: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Check AI segmentation progress status
     */
    public function checkAIProgress(Request $request)
    {
        $request->validate([
            'project_id' => 'required'
        ]);

        $projectId = $request->input('project_id');

        // Get project
        $project = DubSyncProject::find($projectId);

        if (!$project) {
            return response()->json(['error' => 'Project not found'], 404);
        }

        // Check if processing is complete (segments have been filled)
        $isComplete = $project->status === 'transcribed' && !empty($project->segments);

        if ($isComplete) {
            // Get progress message from cache (may indicate fallback was used)
            $cachedProgress = Cache::get("ai_segmentation_progress_{$projectId}", [
                'message' => 'Hoàn tất!'
            ]);

            // Return segments when complete
            return response()->json([
                'status' => 'completed',
                'percentage' => 100,
                'message' => $cachedProgress['message'] ?? 'Hoàn tất!',
                'is_complete' => true,
                'segments' => $project->segments
            ]);
        }

        // Check for error state
        if ($project->status === 'error') {
            return response()->json([
                'status' => 'error',
                'percentage' => 0,
                'message' => $project->error_message ?? 'Lỗi xử lý, vui lòng thử lại',
                'is_complete' => true, // Stop polling
                'segments' => []
            ]);
        }

        // Still processing - get current progress from cache
        $cachedProgress = Cache::get("ai_segmentation_progress_{$projectId}", [
            'status' => 'processing',
            'percentage' => 50,
            'message' => 'Đang xử lý...'
        ]);

        return response()->json([
            'status' => $cachedProgress['status'] ?? 'processing',
            'percentage' => $cachedProgress['percentage'] ?? 50,
            'message' => $cachedProgress['message'] ?? 'Đang xử lý...',
            'is_complete' => false,
            'segments' => []
        ]);
    }

    /**
     * Translate segments to Vietnamese
     */
    public function translate(Request $request, $projectId)
    {
        $request->validate([
            'segments' => 'required|array',
            'provider' => 'nullable|in:openai,google,gemini',
            'style' => 'nullable|in:default,humorous'
        ]);

        try {
            $project = DubSyncProject::findOrFail($projectId);
            $segments = $request->segments;
            $provider = $request->provider ?? env('TRANSLATION_PROVIDER', 'google');
            $style = $request->style ?? 'default';

            $translationService = new \App\Services\TranslationService($provider);

            if ($provider === 'gemini') {
                // Context-aware translation: Gemini reads entire article then translates each segment coherent.
                $translatedSegments = $translationService->translateSegmentsWithGemini($segments, (int) $projectId, $style);
            } else {
                // Legacy per-segment translation.
                $translatedSegments = $translationService->translateSegments($segments, null, (int) $projectId, 'auto', $style);
            }

            $translatedFullTranscript = collect($translatedSegments)
                ->map(fn($segment) => data_get($segment, 'text', ''))
                ->filter(fn($text) => trim((string) $text) !== '')
                ->implode("\n");

            $updateData = [
                'translated_segments' => $translatedSegments,
                'translated_full_transcript' => $translatedFullTranscript,
                'status' => 'translated',
                'translation_provider' => $provider
            ];

            // Translate title and description: use Gemini for single text or fallback to google.
            $titleDescProvider = $provider === 'gemini' ? 'google' : $provider;
            if (!empty($project->youtube_title)) {
                $updateData['youtube_title_vi'] = $translationService->translateText($project->youtube_title, 'en', 'vi', $titleDescProvider);
            }

            if (!empty($project->youtube_description)) {
                $updateData['youtube_description_vi'] = $translationService->translateText($project->youtube_description, 'en', 'vi', $titleDescProvider);
            }

            $project->update($updateData);

            return response()->json([
                'success' => true,
                'translated_segments' => $translatedSegments,
                'provider' => $provider
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Fix selected segments using OpenAI (cleanup broken sentences)
     */
    public function fixSelectedSegments(Request $request, $projectId)
    {
        $request->validate([
            'segments' => 'required|array',
            'segments.*.index' => 'required|integer',
            'segments.*.text' => 'required|string'
        ]);

        try {
            $project = DubSyncProject::findOrFail($projectId);
            $segments = $request->segments;

            $timestamp = now()->format('Ymd_His');
            $inputPath = "dubsync/segment-fix/{$projectId}_input_{$timestamp}.json";
            Storage::disk('local')->put($inputPath, json_encode($segments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            $fixService = new \App\Services\SegmentFixService();
            $fixedSegments = $fixService->fixSegments($segments);

            $outputPath = "dubsync/segment-fix/{$projectId}_output_{$timestamp}.json";
            Storage::disk('local')->put($outputPath, json_encode($fixedSegments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return response()->json([
                'success' => true,
                'fixed_segments' => $fixedSegments
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Save segments
     */
    public function saveSegments(Request $request, $projectId)
    {
        $request->validate([
            'segments' => 'required_without:translated_segments|array',
            'translated_segments' => 'required_without:segments|array',
            'tts_provider' => 'nullable|in:google,openai,gemini,microsoft,vbee',
            'audio_mode' => 'nullable|in:single,multi',
            'speakers_config' => 'nullable|array',
            'style_instruction' => 'nullable|string'
        ]);

        try {
            $project = DubSyncProject::findOrFail($projectId);
            $segments = $request->input('segments');
            $translatedSegments = $request->input('translated_segments');

            // Update segments and optional TTS configuration
            $updateData = [];

            if (!is_null($segments)) {
                $updateData['segments'] = $segments;
            }

            if (!is_null($translatedSegments)) {
                $updateData['translated_segments'] = $translatedSegments;
            }

            if ($request->filled('tts_provider')) {
                $updateData['tts_provider'] = $request->tts_provider;
            }

            if ($request->filled('audio_mode')) {
                $updateData['audio_mode'] = $request->audio_mode;
            }

            if ($request->has('speakers_config')) {
                $updateData['speakers_config'] = $request->speakers_config;
            }

            if ($request->has('style_instruction')) {
                $updateData['style_instruction'] = $request->style_instruction;
            }

            $project->update($updateData);

            $savedCount = is_array($translatedSegments)
                ? count($translatedSegments)
                : (is_array($segments) ? count($segments) : 0);

            \Log::info('Segments saved', ['project_id' => $projectId, 'count' => $savedCount]);

            return response()->json([
                'success' => true,
                'message' => 'Segments saved successfully',
                'count' => $savedCount,
                'tts_provider' => $project->tts_provider,
                'audio_mode' => $project->audio_mode,
                'speakers_config' => $project->speakers_config,
                'style_instruction' => $project->style_instruction
            ]);
        } catch (\Exception $e) {
            \Log::error('Save segments error', ['error' => $e->getMessage()]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }


    /**
     * Generate TTS for Vietnamese segments
     */
    public function generateTTS(Request $request, $projectId)
    {
        try {
            $project = DubSyncProject::findOrFail($projectId);
            $translatedSegments = $project->translated_segments;
            $ttsProvider = $project->tts_provider ?? 'google';
            $audioMode = $project->audio_mode ?? 'single';
            $speakersConfig = $project->speakers_config ?? [];

            if (!$translatedSegments) {
                return response()->json(['error' => 'No translated segments found'], 400);
            }

            // Step 5: Generate TTS for each segment
            $ttsService = app('App\Services\TTSService');
            $audioSegments = [];

            foreach ($translatedSegments as $index => $segment) {
                // Resolve voice settings based on audio mode
                if ($audioMode === 'multi' && isset($segment['speaker_name'])) {
                    // Multi-speaker mode: Look up speaker configuration
                    $speakerName = $segment['speaker_name'];
                    $speaker = collect($speakersConfig)->firstWhere('name', $speakerName);

                    if ($speaker) {
                        $voiceGender = $speaker['gender'] ?? 'female';
                        $voiceName = $speaker['voice'] ?? null;
                    } else {
                        // Fallback if speaker not found
                        $voiceGender = 'female';
                        $voiceName = null;
                    }
                } else {
                    // Single-speaker mode or legacy: Use segment's voice settings
                    $voiceGender = $segment['voice_gender'] ?? 'female';
                    $voiceName = $segment['voice_name'] ?? null;
                }

                $audioPath = $ttsService->generateAudio(
                    $segment['text'],
                    $index,
                    $voiceGender,
                    $voiceName,
                    $ttsProvider,
                    null,
                    $project->id
                );
                // Handle both 'start' and 'start_time' keys
                $startTime = $segment['start'] ?? $segment['start_time'] ?? 0;
                $audioSegments[] = [
                    'index' => $index,
                    'text' => $segment['text'],
                    'audio_path' => $audioPath,
                    'start' => $startTime,
                    'start_time' => $startTime,  // Keep for backward compatibility
                    'end_time' => $segment['end_time'] ?? 0,
                    'duration' => $segment['duration'] ?? 0,
                    'voice_gender' => $voiceGender,
                    'voice_name' => $voiceName,
                    'speaker_name' => $segment['speaker_name'] ?? null,
                    'tts_provider' => $ttsProvider
                ];
            }

            $project->update([
                'audio_segments' => $audioSegments,
                'status' => 'tts_generated'
            ]);

            return response()->json([
                'success' => true,
                'audio_segments' => $audioSegments,
                'tts_provider' => $ttsProvider
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Align audio timing with original timestamps
     */
    public function alignTiming(Request $request, $projectId)
    {
        try {
            $project = DubSyncProject::findOrFail($projectId);

            // Get selected segment indices from request
            $selectedIndices = $request->input('segment_indices', []);

            if (empty($selectedIndices)) {
                throw new \Exception('No segments selected for alignment');
            }

            // Use segments (which now contain audio info after TTS generation)
            $segments = $project->segments;

            if (!$segments || count($segments) === 0) {
                throw new \Exception('No segments found to align');
            }

            // Get only selected segments that have audio
            $segmentsToAlign = [];
            foreach ($selectedIndices as $index) {
                if (isset($segments[$index]) && isset($segments[$index]['audio_path']) && !empty($segments[$index]['audio_path'])) {
                    $segmentsToAlign[] = array_merge($segments[$index], ['index' => $index]);
                }
            }

            if (count($segmentsToAlign) === 0) {
                throw new \Exception('No audio segments found in selection. Please generate TTS first.');
            }

            // Step 6: Time-fit and alignment
            $alignedResults = app('App\Services\AudioAlignmentService')->alignSegments($segmentsToAlign);

            // Update original segments array with aligned info
            foreach ($alignedResults as $alignedSegment) {
                $index = $alignedSegment['index'];
                $segments[$index]['audio_path'] = $alignedSegment['audio_path'];
                $segments[$index]['adjusted'] = $alignedSegment['adjusted'];
                $segments[$index]['speed_ratio'] = $alignedSegment['speed_ratio'];
                $segments[$index]['actual_duration'] = $alignedSegment['actual_duration'];
                $segments[$index]['aligned'] = true; // Mark as aligned
            }

            // Check if all segments with audio have been aligned
            $allAudioSegments = array_filter($segments, function ($segment) {
                return isset($segment['audio_path']) && !empty($segment['audio_path']);
            });

            $allAligned = true;
            foreach ($allAudioSegments as $seg) {
                if (!isset($seg['aligned']) || !$seg['aligned']) {
                    $allAligned = false;
                    break;
                }
            }

            $updateData = ['segments' => $segments];

            // Only change status to 'aligned' if all audio segments are aligned
            if ($allAligned) {
                $updateData['status'] = 'aligned';
            }

            $project->update($updateData);

            \Log::info('Align timing success', [
                'project_id' => $projectId,
                'aligned_count' => count($alignedResults),
                'total_audio_segments' => count($allAudioSegments),
                'all_aligned' => $allAligned
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Audio timing aligned successfully',
                'aligned_count' => count($alignedResults),
                'all_aligned' => $allAligned,
                'total_audio_segments' => count($allAudioSegments)
            ]);
        } catch (\Exception $e) {
            \Log::error('Align timing error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Merge all audio segments into final track
     */
    public function mergeAudio(Request $request, $projectId)
    {
        try {
            $project = DubSyncProject::findOrFail($projectId);

            // Use aligned_segments if available, otherwise fall back to audio_segments
            $segments = $project->aligned_segments;

            if (empty($segments)) {
                $segments = $project->audio_segments;
            }

            if (empty($segments)) {
                // Last resort: check segments array for audio_path entries
                $allSegments = $project->segments ?? [];
                $segments = array_filter($allSegments, function ($seg) {
                    return !empty($seg['audio_path']);
                });
            }

            if (empty($segments)) {
                return response()->json(['error' => 'Không có audio segments nào để merge. Vui lòng tạo TTS trước.'], 400);
            }

            $mergeMode = $request->input('merge_mode', 'timeline'); // 'timeline' | 'sequential'

            \Log::info("MergeAudio: Starting merge for project {$projectId}", [
                'source'     => !empty($project->aligned_segments) ? 'aligned_segments' : (!empty($project->audio_segments) ? 'audio_segments' : 'segments'),
                'count'      => count($segments),
                'merge_mode' => $mergeMode,
            ]);

            $mergeService = app('App\Services\AudioMergeService');

            // Step 7: Merge audio according to timeline
            if ($mergeMode === 'sequential') {
                $finalAudioPath = $mergeService->mergeSegments(array_values($segments), $projectId);
            } else {
                // Default: place each segment at its exact original start_time
                $finalAudioPath = $mergeService->mergeByTimeline(array_values($segments), $projectId);
            }

            $project->update([
                'final_audio_path' => $finalAudioPath,
                'status' => 'merged'
            ]);

            return response()->json([
                'success' => true,
                'audio_path' => $finalAudioPath,
                'message' => 'Đã merge ' . count($segments) . ' segments theo đúng timeline thành công!'
            ]);
        } catch (\Exception $e) {
            \Log::error("MergeAudio error: " . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Manually change project status
     */
    public function changeStatus(Request $request, $projectId)
    {
        $allowed = ['new', 'source_downloaded', 'transcribed', 'translated', 'tts_generated', 'aligned', 'merged', 'completed', 'error'];

        $request->validate([
            'status' => 'required|in:' . implode(',', $allowed),
        ]);

        $project = DubSyncProject::findOrFail($projectId);
        $old = $project->status;
        $project->update(['status' => $request->status]);

        \Log::info("changeStatus: project {$projectId} {$old} => {$request->status}");

        return response()->json(['success' => true, 'old' => $old, 'new' => $request->status]);
    }

    /**
     * Export all files (SRT/VTT, Audio, JSON)
     */
    public function export(Request $request, $projectId)
    {
        $request->validate([
            'formats' => 'required|array'
        ]);

        try {
            $project = DubSyncProject::findOrFail($projectId);
            $formats = $request->formats;
            $exportedFiles = [];

            // Step 8: Export files
            $exportService = app('App\Services\ExportService');

            if (in_array('srt', $formats)) {
                $srtPath = $exportService->generateSRT($project);
                $exportedFiles['srt'] = $srtPath;
            }

            if (in_array('vtt', $formats)) {
                $vttPath = $exportService->generateVTT($project);
                $exportedFiles['vtt'] = $vttPath;
            }

            if (in_array('audio_wav', $formats)) {
                $wavPath = $exportService->exportAudioAsWAV($project);
                $exportedFiles['wav'] = $wavPath;
            }

            if (in_array('audio_mp3', $formats)) {
                $mp3Path = $exportService->exportAudioAsMP3($project);
                $exportedFiles['mp3'] = $mp3Path;
            }

            if (in_array('json', $formats)) {
                $jsonPath = $exportService->generateProjectJSON($project);
                $exportedFiles['json'] = $jsonPath;
            }

            $project->update([
                'exported_files' => $exportedFiles,
                'status' => 'completed'
            ]);

            return response()->json([
                'success' => true,
                'files' => $exportedFiles
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Download exported file
     */
    public function download($projectId, $fileType)
    {
        try {
            $project = DubSyncProject::findOrFail($projectId);
            $exportedFiles = $project->exported_files;

            if (!isset($exportedFiles[$fileType])) {
                abort(404, 'File not found');
            }

            $filePath = $exportedFiles[$fileType];

            if (!Storage::exists($filePath)) {
                abort(404, 'File not found');
            }

            return Storage::download($filePath);
        } catch (\Exception $e) {
            abort(500, $e->getMessage());
        }
    }

    /**
     * Regenerate TTS for specific segment
     */
    public function regenerateSegment(Request $request, $projectId, $segmentIndex)
    {
        $request->validate([
            'text' => 'required|string',
            'voice_gender' => 'sometimes|in:male,female',
            'voice_name' => 'nullable|string'
        ]);

        try {
            $project = DubSyncProject::findOrFail($projectId);
            $audioSegments = $project->audio_segments;
            $ttsProvider = $project->tts_provider ?? 'google';

            // Get voice settings from request or use existing
            $voiceGender = $request->voice_gender ?? ($audioSegments[$segmentIndex]['voice_gender'] ?? 'female');
            $voiceName = $request->voice_name ?? ($audioSegments[$segmentIndex]['voice_name'] ?? null);

            // Regenerate TTS for this segment
            $ttsService = app('App\Services\TTSService');
            $audioPath = $ttsService->generateAudio(
                $request->text,
                $segmentIndex,
                $voiceGender,
                $voiceName,
                $ttsProvider,
                null,
                $project->id
            );

            $audioSegments[$segmentIndex]['text'] = $request->text;
            $audioSegments[$segmentIndex]['audio_path'] = $audioPath;
            $audioSegments[$segmentIndex]['voice_gender'] = $voiceGender;
            $audioSegments[$segmentIndex]['voice_name'] = $voiceName;
            $audioSegments[$segmentIndex]['tts_provider'] = $ttsProvider;

            $project->update([
                'audio_segments' => $audioSegments
            ]);

            return response()->json([
                'success' => true,
                'segment' => $audioSegments[$segmentIndex]
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Update TTS provider for a project
     */
    public function updateTtsProvider(Request $request, $projectId)
    {
        $request->validate([
            'tts_provider' => 'required|in:google,openai,gemini,microsoft,vbee'
        ]);

        try {
            $project = DubSyncProject::findOrFail($projectId);
            $project->update([
                'tts_provider' => $request->tts_provider
            ]);

            return response()->json([
                'success' => true,
                'tts_provider' => $project->tts_provider
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Update audio mode for a project
     */
    public function updateAudioMode(Request $request, $projectId)
    {
        $request->validate([
            'audio_mode' => 'required|in:single,multi'
        ]);

        try {
            $project = DubSyncProject::findOrFail($projectId);
            $project->update([
                'audio_mode' => $request->audio_mode
            ]);

            return response()->json([
                'success' => true,
                'audio_mode' => $project->audio_mode
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Update speakers configuration for a project
     */
    public function updateSpeakersConfig(Request $request, $projectId)
    {
        $request->validate([
            'speakers_config' => 'required|array'
        ]);

        try {
            $project = DubSyncProject::findOrFail($projectId);
            $project->update([
                'speakers_config' => $request->speakers_config
            ]);

            return response()->json([
                'success' => true,
                'speakers_config' => $project->speakers_config
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Update Vietnamese title for a project
     */
    public function updateVietnameseTitle(Request $request, $projectId)
    {
        $request->validate([
            'youtube_title_vi' => 'nullable|string|max:500'
        ]);

        try {
            $project = DubSyncProject::findOrFail($projectId);
            $titleVi = trim((string) $request->input('youtube_title_vi', ''));

            $project->update([
                'youtube_title_vi' => $titleVi !== '' ? $titleVi : null,
            ]);

            return response()->json([
                'success' => true,
                'youtube_title_vi' => $project->youtube_title_vi,
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Preview voice with sample text
     */
    public function previewVoice(Request $request)
    {
        $request->validate([
            'text' => 'required|string',
            'voice_gender' => 'required|in:male,female',
            'voice_name' => 'required|string',
            'provider' => 'required|in:google,openai,gemini,microsoft,vbee'
        ]);

        try {
            if ($request->provider === 'gemini' && !config('services.gemini.tts_api_key') && !config('services.gemini.api_key')) {
                return response()->json([
                    'success' => false,
                    'error' => 'GEMINI_TTS_API_KEY chưa được cấu hình'
                ], 400);
            }

            // Microsoft TTS uses local edge-tts, no API key needed

            $ttsService = app('App\Services\TTSService');

            $cacheKey = md5(
                $request->text . '|' .
                    $request->voice_gender . '|' .
                    $request->voice_name . '|' .
                    $request->provider
            );
            $cacheDir = 'public/dubsync/tts_preview';
            $cacheBase = $cacheDir . "/preview_{$cacheKey}";
            $cachedPath = null;

            if (!Storage::exists($cacheDir)) {
                Storage::makeDirectory($cacheDir);
            }

            foreach (['wav', 'mp3'] as $ext) {
                $candidatePath = $cacheBase . ".{$ext}";
                if (Storage::exists($candidatePath)) {
                    $cachedSize = Storage::size($candidatePath);
                    if ($cachedSize >= 200) {
                        $isValid = true;
                        $fullPath = Storage::path($candidatePath);
                        $fh = @fopen($fullPath, 'rb');
                        if ($fh) {
                            $magic = fread($fh, 4);
                            fclose($fh);
                            if ($ext === 'wav' && $magic !== 'RIFF') {
                                $isValid = false;
                            }
                            if ($ext === 'mp3') {
                                $isId3 = strncmp($magic, 'ID3', 3) === 0;
                                $isFrameSync = strlen($magic) >= 2 && (ord($magic[0]) === 0xFF) && ((ord($magic[1]) & 0xE0) === 0xE0);
                                if (!$isId3 && !$isFrameSync) {
                                    $isValid = false;
                                }
                            }
                        }

                        if ($isValid) {
                            return response()->json([
                                'success' => true,
                                'audio_url' => Storage::url($candidatePath),
                                'audio_path' => $candidatePath,
                                'cached' => true
                            ]);
                        }
                    }

                    Storage::delete($candidatePath);
                }
            }

            // Use 0 as index for preview (will create unique filename anyway)
            $audioPath = $ttsService->generateAudio(
                $request->text,
                0,
                $request->voice_gender,
                $request->voice_name,
                $request->provider
            );

            $ext = pathinfo($audioPath, PATHINFO_EXTENSION) ?: 'mp3';
            $cachedPath = $cacheBase . ".{$ext}";

            // Copy to cached path for reuse
            if ($audioPath !== $cachedPath && Storage::exists($audioPath)) {
                Storage::copy($audioPath, $cachedPath);
                Storage::delete($audioPath);
            }

            // Get public URL for the cached audio file
            $audioUrl = Storage::url($cachedPath);

            return response()->json([
                'success' => true,
                'audio_url' => $audioUrl,
                'audio_path' => $cachedPath,
                'cached' => false
            ]);
        } catch (\Exception $e) {
            \Log::error('Preview voice error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Delete project
     */
    public function destroy($projectId)
    {
        try {
            $project = DubSyncProject::findOrFail($projectId);

            // Delete associated files
            $this->deleteProjectFiles($project);

            $project->delete();

            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get queue status: pending, running, failed jobs
     */
    public function queueStatus()
    {
        try {
            // Progress cache key prefixes mapped by job class
            $progressKeyMap = [
                'GenerateBatchVideoJob' => 'batch_video_progress_',
                'GenerateChapterTtsBatchJob' => 'tts_batch_progress_',
                'GenerateThumbnailJob' => 'thumbnail_progress_',
                'GenerateDescriptionAudioJob' => 'desc_audio_progress_',
                'PublishYoutubeJob' => 'publish_progress_',
                'BoostChapterAudioBatchJob' => 'boost_batch_progress_',
                'GenerateBookReviewVideoJob' => 'review_video_progress_',
                'GenerateReviewAssetsJob' => 'review_assets_progress_',
                'GenerateDubSyncBatchTtsJob' => 'dubsync_tts_batch_progress_',
                'DownloadSourceVideoJob' => 'source_video_download_progress_',
            ];

            $pending = \DB::table('jobs')
                ->select('id', 'queue', 'payload', 'attempts', 'created_at', 'reserved_at')
                ->orderBy('id')
                ->limit(100)
                ->get()
                ->map(function ($job) use ($progressKeyMap) {
                    $payload = json_decode($job->payload, true);
                    $displayName = $payload['displayName'] ?? 'Unknown';
                    $isRunning = $job->reserved_at !== null;
                    $jobBaseName = class_basename($displayName);

                    // Extract target ID from serialized command
                    $targetId = null;
                    $targetType = null;
                    try {
                        $command = unserialize($payload['data']['command'] ?? '');
                        if (isset($command->audioBookId)) {
                            $targetId = $command->audioBookId;
                            $targetType = 'audiobook';
                        } elseif (isset($command->projectId)) {
                            $targetId = $command->projectId;
                            $targetType = 'project';
                        }
                    } catch (\Throwable $e) {}

                    // Get cached progress for running jobs
                    $progress = null;
                    if ($isRunning && $targetId && isset($progressKeyMap[$jobBaseName])) {
                        $progress = \Cache::get($progressKeyMap[$jobBaseName] . $targetId);
                    }

                    return [
                        'id' => $job->id,
                        'job' => $jobBaseName,
                        'full_class' => $displayName,
                        'queue' => $job->queue,
                        'attempts' => $job->attempts,
                        'status' => $isRunning ? 'running' : 'pending',
                        'target_id' => $targetId,
                        'target_type' => $targetType,
                        'progress' => $progress,
                        'created_at' => $job->created_at ? date('H:i:s d/m', $job->created_at) : null,
                        'reserved_at' => $job->reserved_at ? date('H:i:s d/m', $job->reserved_at) : null,
                    ];
                });

            $failed = \DB::table('failed_jobs')
                ->select('id', 'uuid', 'connection', 'queue', 'payload', 'exception', 'failed_at')
                ->orderByDesc('id')
                ->limit(20)
                ->get()
                ->map(function ($job) {
                    $payload = json_decode($job->payload, true);
                    $displayName = $payload['displayName'] ?? 'Unknown';
                    return [
                        'id' => $job->id,
                        'uuid' => $job->uuid,
                        'job' => class_basename($displayName),
                        'full_class' => $displayName,
                        'queue' => $job->queue,
                        'error' => mb_substr((string) $job->exception, 0, 200),
                        'failed_at' => $job->failed_at,
                    ];
                });

            // Job history from DB
            $history = \DB::table('job_histories')
                ->orderByDesc('finished_at')
                ->limit(50)
                ->get()
                ->map(function ($h) {
                    return [
                        'id' => $h->id,
                        'job' => $h->job_name,
                        'target_id' => $h->target_id,
                        'target_type' => $h->target_type,
                        'status' => $h->status,
                        'duration_seconds' => $h->duration_seconds,
                        'message' => $h->message,
                        'started_at' => $h->started_at,
                        'finished_at' => $h->finished_at,
                    ];
                });

            $running = $pending->where('status', 'running')->values();
            $waiting = $pending->where('status', 'pending')->values();

            return response()->json([
                'success' => true,
                'summary' => [
                    'running' => $running->count(),
                    'pending' => $waiting->count(),
                    'failed' => $failed->count(),
                    'total' => $pending->count(),
                ],
                'running' => $running,
                'pending' => $waiting,
                'failed' => $failed,
                'history' => $history,
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Clear all pending/failed jobs
     */
    public function queueClear()
    {
        try {
            $pendingCount = \DB::table('jobs')->count();
            $failedCount = \DB::table('failed_jobs')->count();

            \DB::table('jobs')->delete();
            \DB::table('failed_jobs')->delete();

            return response()->json([
                'success' => true,
                'cleared_pending' => $pendingCount,
                'cleared_failed' => $failedCount,
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Show project details
     */
    public function show($projectId)
    {
        try {
            $project = DubSyncProject::findOrFail($projectId);

            return response()->json([
                'success' => true,
                'id' => $project->id,
                'video_id' => $project->video_id,
                'youtube_url' => $project->youtube_url,
                'status' => $project->status,
                'segments' => $project->segments,
                'translated_segments' => $project->translated_segments,
                'audio_segments' => $project->audio_segments,
                'aligned_segments' => $project->aligned_segments,
                'final_audio_path' => $project->final_audio_path,
                'exported_files' => $project->exported_files,
                'tts_provider' => $project->tts_provider,
                'created_at' => $project->created_at,
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Extract video ID from YouTube URL
     */
    private function extractVideoId($url)
    {
        $pattern = '/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/\s]{11})/i';

        if (preg_match($pattern, $url, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Generate TTS for a single segment
     */
    public function generateSegmentTTS(Request $request, $projectId)
    {
        try {
            $project = DubSyncProject::findOrFail($projectId);

            // Check if this is a bulk request (segment_indices array) or single segment request
            $isBulkRequest = $request->has('segment_indices');

            \Log::info('TTS Request received', [
                'is_bulk' => $isBulkRequest,
                'has_segment_indices_key' => $request->has('segment_indices'),
                'request_keys' => array_keys($request->all()),
                'segment_indices_raw' => $request->get('segment_indices'),
                'raw_input' => $request->all()
            ]);

            if ($isBulkRequest) {
                // Bulk TTS generation for multiple selected segments
                $validated = $request->validate([
                    'segment_indices' => 'required|array',
                    'segment_indices.*' => 'integer',
                    'segment_texts' => 'sometimes|array',
                    'segment_texts.*' => 'nullable|string',
                    'voice_settings' => 'sometimes|array',
                    'voice_settings.*.voice_gender' => 'required_with:voice_settings|in:male,female',
                    'voice_settings.*.voice_name' => 'required_with:voice_settings|string',
                    'voice_gender' => 'sometimes|in:male,female',
                    'voice_name' => 'sometimes|string',
                    'provider' => 'required|string|in:google,openai,gemini,microsoft,vbee',
                    'style_instruction' => 'nullable|string'
                ]);

                $voiceSettings = $validated['voice_settings'] ?? [];
                $segmentTexts = $validated['segment_texts'] ?? [];
                $fallbackGender = $validated['voice_gender'] ?? null;
                $fallbackName = $validated['voice_name'] ?? null;

                \Log::info('Bulk TTS Request Validated', [
                    'segment_indices' => $validated['segment_indices'],
                    'indices_count' => count($validated['segment_indices']),
                    'indices_as_string' => implode(',', $validated['segment_indices']),
                    'voice_settings' => array_keys($voiceSettings ?? [])
                ]);

                // Save style instruction to project if provided
                if (isset($validated['style_instruction']) && !empty($validated['style_instruction'])) {
                    $project->style_instruction = $validated['style_instruction'];
                    $project->save();
                }

                // Default to async queue mode for bulk requests to avoid HTTP timeout.
                if ($request->boolean('async', true)) {
                    $this->initDubSyncTtsBatchProgress(
                        (int) $projectId,
                        count($validated['segment_indices']),
                        'queued',
                        'Da dua batch TTS vao queue. Dang cho worker xu ly...'
                    );

                    GenerateDubSyncBatchTtsJob::dispatch(
                        (int) $projectId,
                        $validated['segment_indices'],
                        $segmentTexts,
                        $voiceSettings,
                        $fallbackGender,
                        $fallbackName,
                        $validated['provider'],
                        $validated['style_instruction'] ?? null
                    );

                    $this->ensureQueueWorkerRunning();

                    return response()->json([
                        'success' => true,
                        'queued' => true,
                        'status' => 'queued',
                        'message' => 'Batch TTS da vao queue, vui long doi tien trinh realtime.',
                        'total' => count($validated['segment_indices']),
                    ]);
                }

                $ttsService = app(\App\Services\TTSService::class);
                $segments = $project->segments;
                $successCount = 0;
                $errors = [];
                $segmentsData = [];

                \Log::info('Starting TTS generation loop', [
                    'total_segments_in_project' => count($segments),
                    'segments_to_process' => $validated['segment_indices']
                ]);

                // Process only selected segments
                foreach ($validated['segment_indices'] as $segmentIndex) {
                    \Log::info('Processing segment', [
                        'index' => $segmentIndex,
                        'segment_exists' => isset($segments[$segmentIndex]),
                        'total_segments_available' => count($segments)
                    ]);
                    try {
                        if (!isset($segments[$segmentIndex])) {
                            $errors[] = "Segment {$segmentIndex} not found";
                            \Log::warning("Segment not found", ['index' => $segmentIndex, 'available_keys' => array_keys($segments)]);
                            continue;
                        }

                        $segment = $segments[$segmentIndex];
                        $textFromRequest = $segmentTexts[$segmentIndex] ?? null;
                        $text = is_string($textFromRequest) && trim($textFromRequest) !== ''
                            ? $textFromRequest
                            : ($segment['text'] ?? '');

                        // Prepend style instruction if provided
                        $styleInstruction = $validated['style_instruction'] ?? '';
                        $textToSend = $styleInstruction ? "{$styleInstruction}\n\n{$text}" : $text;

                        $voiceGender = $voiceSettings[$segmentIndex]['voice_gender'] ?? $fallbackGender;
                        $voiceName = $voiceSettings[$segmentIndex]['voice_name'] ?? $fallbackName;

                        if (!$voiceGender || !$voiceName) {
                            $errors[] = "Segment {$segmentIndex}: missing voice settings";
                            continue;
                        }

                        // Generate TTS for this segment
                        $audioPath = $ttsService->generateAudio(
                            $textToSend,
                            $segmentIndex,
                            $voiceGender,
                            $voiceName,
                            $validated['provider'],
                            $styleInstruction,
                            $project->id
                        );

                        // Update the segment
                        $segments[$segmentIndex]['audio_path'] = $audioPath;
                        $segments[$segmentIndex]['voice_gender'] = $voiceGender;
                        $segments[$segmentIndex]['voice_name'] = $voiceName;
                        $segments[$segmentIndex]['tts_provider'] = $validated['provider'];
                        $segments[$segmentIndex]['audio_url'] = Storage::url($audioPath);

                        $segmentsData[$segmentIndex] = [
                            'audio_path' => $audioPath,
                            'audio_url' => Storage::url($audioPath),
                            'voice_gender' => $voiceGender,
                            'voice_name' => $voiceName,
                            'tts_provider' => $validated['provider']
                        ];

                        $successCount++;
                    } catch (\Exception $e) {
                        $errors[] = "Segment {$segmentIndex}: " . $e->getMessage();
                    }
                }

                // Save only updated segments to database
                // Retrieve fresh segments from DB to avoid overwriting
                $freshProject = DubSyncProject::findOrFail($projectId);
                $freshSegments = $freshProject->segments ?? [];

                \Log::info('Before segment update check', [
                    'segments_to_update' => array_keys($segments),
                    'fresh_segments_keys' => array_keys($freshSegments),
                    'total_fresh_segments' => count($freshSegments)
                ]);

                // Only update the segments that were processed
                foreach ($validated['segment_indices'] as $segmentIndex) {
                    if (isset($segments[$segmentIndex])) {
                        $freshSegments[$segmentIndex] = $segments[$segmentIndex];
                    }
                }

                \Log::info('After segment update', [
                    'freshSegments_keys_after' => array_keys($freshSegments),
                    'segments_with_audio' => array_keys(array_filter($freshSegments, function ($s) {
                        return isset($s['audio_path']);
                    }))
                ]);

                $freshProject->segments = $freshSegments;
                $freshProject->save();

                \Log::info('Bulk TTS save complete', [
                    'project_id' => $projectId,
                    'segment_indices_requested' => $validated['segment_indices'],
                    'segments_updated' => count($validated['segment_indices']),
                    'total_segments_in_project' => count($freshSegments),
                    'segments_with_audio_after_save' => array_keys(array_filter($freshSegments, function ($s) {
                        return isset($s['audio_path']);
                    }))
                ]);

                return response()->json([
                    'success' => true,
                    'message' => "Generated TTS for {$successCount} of " . count($validated['segment_indices']) . " segments",
                    'generated_count' => $successCount,
                    'errors' => $errors,
                    'segments_data' => $segmentsData
                ]);
            } else {
                // Single segment TTS generation
                $validated = $request->validate([
                    'segment_index' => 'required|integer',
                    'text' => 'required|string',
                    'voice_gender' => 'required|string',
                    'voice_name' => 'required|string',
                    'provider' => 'required|string|in:google,openai,gemini,microsoft,vbee',
                    'style_instruction' => 'nullable|string'
                ]);

                // Save style instruction to project if provided
                if (isset($validated['style_instruction']) && !empty($validated['style_instruction'])) {
                    $project->style_instruction = $validated['style_instruction'];
                    $project->save();
                }

                $ttsService = app(\App\Services\TTSService::class);

                // Generate TTS for the segment - pass style instruction separately
                $audioPath = $ttsService->generateAudio(
                    $validated['text'],
                    $validated['segment_index'],
                    $validated['voice_gender'],
                    $validated['voice_name'],
                    $validated['provider'],
                    $validated['style_instruction'] ?? null,
                    $project->id
                );

                // Update the segment in the project
                $segments = $project->segments;
                if (isset($segments[$validated['segment_index']])) {
                    $segments[$validated['segment_index']]['audio_path'] = $audioPath;
                    $segments[$validated['segment_index']]['voice_gender'] = $validated['voice_gender'];
                    $segments[$validated['segment_index']]['voice_name'] = $validated['voice_name'];
                    $segments[$validated['segment_index']]['tts_provider'] = $validated['provider'];
                    $segments[$validated['segment_index']]['audio_url'] = Storage::url($audioPath);
                    if (isset($validated['style_instruction'])) {
                        $segments[$validated['segment_index']]['style_instruction'] = $validated['style_instruction'];
                    }
                    $project->segments = $segments;
                    $project->save();
                }

                return response()->json([
                    'success' => true,
                    'message' => 'TTS generated successfully for segment',
                    'audio_path' => $audioPath,
                    'audio_url' => Storage::url($audioPath)
                ]);
            }
        } catch (\Exception $e) {
            \Log::error('Generate segment TTS error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getSegmentTtsBatchProgress($projectId)
    {
        $projectId = (int) $projectId;
        $progress = Cache::get($this->getDubSyncTtsBatchProgressKey($projectId));

        if (!$progress) {
            return response()->json([
                'success' => true,
                'status' => 'idle',
                'percent' => 0,
                'message' => 'Chua co tien trinh batch TTS.',
                'project_id' => $projectId,
            ]);
        }

        return response()->json(array_merge([
            'success' => true,
        ], $progress));
    }

    private function getDubSyncTtsBatchProgressKey(int $projectId): string
    {
        return "dubsync_tts_batch_progress_{$projectId}";
    }

    private function initDubSyncTtsBatchProgress(int $projectId, int $total, string $status, string $message): void
    {
        Cache::put($this->getDubSyncTtsBatchProgressKey($projectId), [
            'status' => $status,
            'percent' => 1,
            'message' => $message,
            'project_id' => $projectId,
            'current_segment_index' => null,
            'processed' => 0,
            'total' => max(0, $total),
            'success_count' => 0,
            'failed_count' => 0,
            'errors' => [],
            'segments_data' => [],
            'updated_at' => now()->toIso8601String(),
        ], now()->addHours(6));
    }

    /**
     * Delete all files associated with a project
     */
    private function deleteProjectFiles(DubSyncProject $project)
    {
        // Delete audio segments
        if ($project->audio_segments) {
            $audioSegments = $project->audio_segments;
            foreach ($audioSegments as $segment) {
                if (isset($segment['audio_path']) && Storage::exists($segment['audio_path'])) {
                    Storage::delete($segment['audio_path']);
                }
            }
        }

        // Delete final audio
        if ($project->final_audio_path && Storage::exists($project->final_audio_path)) {
            Storage::delete($project->final_audio_path);
        }

        // Delete exported files
        if ($project->exported_files) {
            $exportedFiles = $project->exported_files;
            foreach ($exportedFiles as $filePath) {
                if (Storage::exists($filePath)) {
                    Storage::delete($filePath);
                }
            }
        }
    }

    /**
     * Update style instruction for a project
     */
    public function updateStyleInstruction(Request $request, $projectId)
    {
        try {
            $validated = $request->validate([
                'style_instruction' => 'nullable|string'
            ]);

            $project = DubSyncProject::findOrFail($projectId);
            $project->update([
                'style_instruction' => $validated['style_instruction']
            ]);

            return response()->json([
                'success' => true,
                'style_instruction' => $project->style_instruction
            ]);
        } catch (\Exception $e) {
            \Log::error('Update style instruction error', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Normalize segment timings to prevent overlaps and fix durations
     */
    public function normalizeSegmentTimes(Request $request, $projectId)
    {
        try {
            $project = DubSyncProject::findOrFail($projectId);
            $segments = $project->segments ?? [];

            if (empty($segments)) {
                return response()->json([
                    'success' => true,
                    'normalized' => 0,
                    'segments' => []
                ]);
            }

            $normalized = 0;
            $segmentsCount = count($segments);

            for ($i = 0; $i < $segmentsCount; $i++) {
                $start = $segments[$i]['start_time'] ?? ($segments[$i]['start'] ?? 0.0);
                $end = $segments[$i]['end_time'] ?? ($start + ($segments[$i]['duration'] ?? 0.0));

                $nextStart = null;
                if ($i + 1 < $segmentsCount) {
                    $nextStart = $segments[$i + 1]['start_time'] ?? ($segments[$i + 1]['start'] ?? null);
                }

                // Clamp end to next segment start to avoid overlap
                if ($nextStart !== null && $end > $nextStart) {
                    $end = $nextStart;
                    $normalized++;
                }

                // Ensure end is not before start
                if ($end < $start) {
                    $end = $start;
                }

                $segments[$i]['start_time'] = $start;
                $segments[$i]['end_time'] = $end;
                $segments[$i]['duration'] = max(0, round($end - $start, 3));
            }

            $project->segments = $segments;
            $project->save();

            return response()->json([
                'success' => true,
                'normalized' => $normalized,
                'segments' => $segments
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get all audio versions for a specific segment
     */
    public function getSegmentAudioVersions($projectId, $segmentIndex)
    {
        try {
            $project = DubSyncProject::findOrFail($projectId);
            $projectPath = "public/projects/{$projectId}";

            // Get all files in project directory
            $allFiles = Storage::files($projectPath);

            // Filter files for this segment (pattern: s{index}_*)
            $pattern = "s{$segmentIndex}_";
            $segmentFiles = array_filter($allFiles, function ($file) use ($pattern) {
                return str_contains(basename($file), $pattern);
            });

            $versions = [];
            foreach ($segmentFiles as $file) {
                $filename = basename($file);

                // Parse filename to extract info: s{index}_{timestamp}_{provider}.wav
                if (preg_match('/s(\d+)_(\d+)_([^.]+)\.wav/', $filename, $matches)) {
                    $timestamp = $matches[2];
                    $provider = $matches[3];

                    // Try to find voice info from project segments history
                    $voiceInfo = $this->getVoiceInfoFromFilename($project, $segmentIndex, $timestamp);

                    $versions[] = [
                        'filename' => $filename,
                        'url' => Storage::url($file),
                        'path' => $file,
                        'timestamp' => $timestamp,
                        'created_at' => date('Y-m-d H:i:s', $timestamp),
                        'provider' => $provider,
                        'voice_gender' => $voiceInfo['voice_gender'] ?? 'unknown',
                        'voice_name' => $voiceInfo['voice_name'] ?? 'unknown',
                        'size' => Storage::size($file)
                    ];
                }
            }

            // Sort by timestamp descending (newest first)
            usort($versions, function ($a, $b) {
                return $b['timestamp'] - $a['timestamp'];
            });

            return response()->json([
                'success' => true,
                'segment_index' => $segmentIndex,
                'versions' => $versions,
                'total' => count($versions)
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Try to get voice info from project segment or metadata
     */
    private function getVoiceInfoFromFilename($project, $segmentIndex, $timestamp)
    {
        // Check current segment
        $segments = $project->segments ?? [];
        if (isset($segments[$segmentIndex])) {
            $segment = $segments[$segmentIndex];

            // If audio_path matches this timestamp, use current voice info
            if (isset($segment['audio_path']) && str_contains($segment['audio_path'], $timestamp)) {
                return [
                    'voice_gender' => $segment['voice_gender'] ?? null,
                    'voice_name' => $segment['voice_name'] ?? null
                ];
            }
        }

        // Check audio_segments (historical data)
        $audioSegments = $project->audio_segments ?? [];
        if (isset($audioSegments[$segmentIndex])) {
            $audioSegment = $audioSegments[$segmentIndex];
            if (isset($audioSegment['audio_path']) && str_contains($audioSegment['audio_path'], $timestamp)) {
                return [
                    'voice_gender' => $audioSegment['voice_gender'] ?? null,
                    'voice_name' => $audioSegment['voice_name'] ?? null
                ];
            }
        }

        return [
            'voice_gender' => null,
            'voice_name' => null
        ];
    }

    /**
     * Delete audio files for selected segments
     */
    public function deleteSegmentAudios($projectId)
    {
        try {
            $request = request();
            $segmentIndices = $request->input('segment_indices', []);
            $deleteAll = $request->input('delete_all', false);

            $project = DubSyncProject::findOrFail($projectId);
            $projectPath = "public/projects/{$projectId}";

            $allFiles = Storage::files($projectPath);
            $deletedCount = 0;
            $deletedFiles = [];

            // Determine which segments to delete
            $indicesToDelete = $deleteAll ?
                array_keys($project->segments ?? []) :
                $segmentIndices;

            foreach ($indicesToDelete as $segmentIndex) {
                // Find all audio files for this segment (pattern: s{index}_*)
                $pattern = "s{$segmentIndex}_";
                $segmentFiles = array_filter($allFiles, function ($file) use ($pattern) {
                    return str_contains(basename($file), $pattern);
                });

                foreach ($segmentFiles as $file) {
                    if (Storage::exists($file)) {
                        Storage::delete($file);
                        $deletedCount++;
                        $deletedFiles[] = basename($file);
                    }
                }

                // Remove audio_path from segment
                $segments = $project->segments ?? [];
                if (isset($segments[$segmentIndex])) {
                    unset($segments[$segmentIndex]['audio_path']);
                    unset($segments[$segmentIndex]['audio_url']);
                    unset($segments[$segmentIndex]['voice_gender']);
                    unset($segments[$segmentIndex]['voice_name']);
                    unset($segments[$segmentIndex]['tts_provider']);
                }
                $project->segments = $segments;
            }

            // Save project
            $project->save();

            \Log::info('Delete segment audios', [
                'project_id' => $projectId,
                'segments_count' => count($indicesToDelete),
                'files_deleted' => $deletedCount
            ]);

            return response()->json([
                'success' => true,
                'message' => "Đã xóa {$deletedCount} file audio cho " . count($indicesToDelete) . " segment(s)",
                'deleted_count' => $deletedCount,
                'deleted_files' => $deletedFiles,
                'segments' => $project->segments
            ]);
        } catch (\Exception $e) {
            \Log::error('Delete segment audios error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Reset project to Generate TTS stage (before audio generation)
     */
    public function resetToTtsGeneration($projectId)
    {
        try {
            $project = DubSyncProject::findOrFail($projectId);
            $projectPath = "public/projects/{$projectId}";

            // Get all files in project directory
            $allFiles = Storage::files($projectPath);

            // Delete all audio files (s*_*.wav)
            $deletedCount = 0;
            foreach ($allFiles as $file) {
                $filename = basename($file);
                if (preg_match('/^s\d+_\d+_[^.]+\.wav$/', $filename)) {
                    if (Storage::exists($file)) {
                        Storage::delete($file);
                        $deletedCount++;
                    }
                }
            }

            // Get segments and remove all TTS-related fields
            $segments = $project->segments ?? [];
            foreach ($segments as &$segment) {
                unset($segment['audio_path']);
                unset($segment['audio_url']);
                unset($segment['voice_gender']);
                unset($segment['voice_name']);
                unset($segment['tts_provider']);
                unset($segment['aligned']);
                unset($segment['adjusted']);
                unset($segment['speed_ratio']);
                unset($segment['actual_duration']);
            }

            // Update project: reset to 'translated' status (ready for TTS generation)
            $project->update([
                'segments' => $segments,
                'status' => 'translated'  // Back to state before TTS generation
            ]);

            \Log::info('Reset to TTS generation', [
                'project_id' => $projectId,
                'audio_files_deleted' => $deletedCount,
                'segments_cleaned' => count($segments)
            ]);

            return response()->json([
                'success' => true,
                'message' => "Reset thành công! Đã xóa {$deletedCount} file audio. Bây giờ bạn có thể Generate TTS Voice lại.",
                'deleted_count' => $deletedCount,
                'status' => 'translated',
                'segments' => $segments
            ]);
        } catch (\Exception $e) {
            \Log::error('Reset to TTS generation error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Save full transcript to database
     */
    public function saveFullTranscript(Request $request, $projectId)
    {
        try {
            $project = DubSyncProject::findOrFail($projectId);

            $fullTranscript = $request->input('full_transcript', null);
            $translatedFullTranscript = $request->input('translated_full_transcript', null);

            $updateData = [];
            if (!is_null($fullTranscript)) {
                $updateData['full_transcript'] = $fullTranscript;
            }
            if (!is_null($translatedFullTranscript)) {
                $updateData['translated_full_transcript'] = $translatedFullTranscript;
            }

            $project->update($updateData);

            \Log::info('Full transcript saved', [
                'project_id' => $projectId,
                'content_length' => is_null($fullTranscript) ? 0 : strlen($fullTranscript),
                'translated_content_length' => is_null($translatedFullTranscript) ? 0 : strlen($translatedFullTranscript)
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Full transcript saved successfully'
            ]);
        } catch (\Exception $e) {
            \Log::error('Error saving full transcript', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get full transcript from database
     */
    public function getFullTranscript($projectId)
    {
        try {
            $project = DubSyncProject::select('id', 'full_transcript', 'translated_full_transcript')->findOrFail($projectId);

            return response()->json([
                'success' => true,
                'full_transcript' => $project->full_transcript ?? '',
                'translated_full_transcript' => $project->translated_full_transcript ?? ''
            ])->header('Cache-Control', 'no-cache, no-store, must-revalidate')
                ->header('Pragma', 'no-cache')
                ->header('Expires', '0');
        } catch (\Exception $e) {
            \Log::error('Error getting full transcript', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Rewrite translated full transcript with Gemini to be clearer for narration.
     */
    public function rewriteFullTranscript(Request $request, $projectId)
    {
        $request->validate([
            'translated_full_transcript' => 'required|string',
        ]);

        try {
            $project = DubSyncProject::findOrFail($projectId);

            $rawText = trim((string) $request->input('translated_full_transcript', ''));
            if ($rawText === '') {
                return response()->json([
                    'success' => false,
                    'error' => 'No translated transcript text provided'
                ], 422);
            }

            $apiKey = env('GEMINI_API_KEY');
            $configuredModel = trim((string) env('GEMINI_MODEL', 'gemini-2.0-flash'));

            if (!$apiKey) {
                return response()->json([
                    'success' => false,
                    'error' => 'GEMINI_API_KEY not configured'
                ], 500);
            }

            $prompt = $this->buildGeminiRewritePrompt($rawText);

            $modelCandidates = array_values(array_unique(array_filter([
                ltrim($configuredModel, ' /'),
                'gemini-2.0-flash',
                'gemini-1.5-flash',
            ])));

            $response = null;
            $model = null;
            foreach ($modelCandidates as $candidateModel) {
                $candidateResponse = Http::timeout(90)->post(
                    "https://generativelanguage.googleapis.com/v1beta/models/{$candidateModel}:generateContent?key={$apiKey}",
                    [
                        'contents' => [
                            [
                                'role' => 'user',
                                'parts' => [
                                    ['text' => $prompt],
                                ],
                            ],
                        ],
                        'generationConfig' => [
                            'temperature' => 0.5,
                            'maxOutputTokens' => 8192,
                        ],
                    ]
                );

                // If model not found, try next fallback model.
                if ($candidateResponse->status() === 404) {
                    continue;
                }

                $response = $candidateResponse;
                $model = $candidateModel;
                break;
            }

            if (!$response || !$response->successful()) {
                $statusCode = $response ? $response->status() : 404;
                $responseBody = $response ? (string) $response->body() : 'No successful Gemini model available';

                ApiUsageService::logFailure(
                    'Gemini',
                    'rewrite_full_transcript',
                    'HTTP ' . $statusCode . ': ' . substr($responseBody, 0, 300),
                    (int) $projectId,
                    ['model' => $configuredModel, 'input_characters' => mb_strlen($rawText, 'UTF-8')]
                );

                return response()->json([
                    'success' => false,
                    'error' => 'Gemini API Error: ' . $statusCode
                ], 500);
            }

            $data = $response->json();
            $rewritten = (string) ($data['candidates'][0]['content']['parts'][0]['text'] ?? '');
            $rewritten = trim($rewritten);

            // Remove markdown fences if model wraps output in ``` blocks.
            $rewritten = preg_replace('/^```(?:text|markdown)?\s*/i', '', $rewritten) ?? $rewritten;
            $rewritten = preg_replace('/\s*```$/', '', $rewritten) ?? $rewritten;
            $rewritten = trim($rewritten);
            $rewritten = $this->sanitizeRewrittenFullTranscript($rewritten);

            if ($rewritten === '') {
                ApiUsageService::logFailure(
                    'Gemini',
                    'rewrite_full_transcript',
                    'Empty rewrite content returned by Gemini',
                    (int) $projectId,
                    ['model' => $model, 'input_characters' => mb_strlen($rawText, 'UTF-8')]
                );

                return response()->json([
                    'success' => false,
                    'error' => 'Gemini returned empty content'
                ], 500);
            }

            $project->update([
                'translated_full_transcript' => $rewritten,
            ]);

            ApiUsageService::log([
                'api_type' => 'Gemini',
                'api_endpoint' => "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent",
                'purpose' => 'rewrite_full_transcript',
                'project_id' => (int) $projectId,
                'characters_used' => mb_strlen($rawText, 'UTF-8'),
                'description' => "Model: {$model}",
                'estimated_cost' => 0,
            ]);

            return response()->json([
                'success' => true,
                'rewritten_transcript' => $rewritten,
            ]);
        } catch (\Exception $e) {
            \Log::error('Rewrite full transcript error', [
                'project_id' => $projectId,
                'error' => $e->getMessage(),
            ]);

            ApiUsageService::logFailure(
                'Gemini',
                'rewrite_full_transcript',
                $e->getMessage(),
                (int) $projectId
            );

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function buildGeminiRewritePrompt(string $rawText): string
    {
        return <<<PROMPT
Vai trò: "Bạn là một người viết kịch bản (Scriptwriter) chuyên nghiệp cho các kênh Podcast hoặc YouTube kiến thức như 'Người Nổi Tiếng' hay 'Kiến Thức Thú Vị'. Nhiệm vụ của bạn là chuyển thể một bản dịch thô thành một kịch bản nói để dùng cho AI Voice (TTS)."

Yêu cầu về định dạng TTS:

Ngôn ngữ nói (Spoken Language): Sử dụng từ ngữ bình dân, tự nhiên như đang trò chuyện trực tiếp với khán giả. Tuyệt đối tránh các từ quá học thuật hoặc cấu trúc câu dài dòng, lắt léo.

Ngắt nghỉ tự nhiên: Sử dụng câu ngắn. Những câu dài phải có dấu phẩy ở các điểm nghỉ hợp lý để AI không bị hụt hơi khi đọc.

Xử lý ký tự đặc biệt: - Không dùng các ký hiệu lạ, không lạm dụng dấu ngoặc đơn ().

Nếu có từ tiếng Anh (như Hacker, Zoom, Schwarzenegger), hãy cân nhắc viết phiên âm hoặc dùng từ tiếng Việt tương đương nếu cần.

Các con số hoặc đơn vị phải viết sao cho dễ đọc nhất.

Cấu trúc kịch bản:

Có lời chào mở đầu (Hook) thu hút.

Có lời dẫn nối (Transition) giữa các đoạn để người nghe không thấy bị hẫng.

Có lời kết thúc và kêu gọi hành động nhẹ nhàng.

Nhiệm vụ cụ thể:
"Hãy viết lại nội dung thô dưới đây thành một kịch bản hấp dẫn, làm rõ sự khác biệt giữa Lầm tưởng trên phim và Sự thật ngoài đời. Hãy viết theo phong cách kể chuyện, có chút hóm hỉnh và bất ngờ."

Yêu cầu xuất kết quả:
- Chỉ trả về nội dung kịch bản đã viết lại bằng tiếng Việt.
- Không thêm tiêu đề phụ ngoài nội dung.
- Không thêm markdown, không thêm dấu ```.
- Tuyệt đối KHÔNG dùng câu: "Chào mừng các bạn đến với kênh podcast hôm nay!"
- Không mở đầu bằng câu chào khuôn mẫu kiểu podcast/channel intro.

Nội dung gốc cần xử lý:
{$rawText}
PROMPT;
    }

    private function sanitizeRewrittenFullTranscript(string $text): string
    {
        $cleaned = trim($text);

        $exactPhrases = [
            'Chào mừng các bạn đến với kênh podcast hôm nay!',
            'Chào mừng các bạn đến với kênh podcast hôm nay',
        ];

        foreach ($exactPhrases as $phrase) {
            $cleaned = str_ireplace($phrase, '', $cleaned);
        }

        // Remove common intro line variants if they still appear at the beginning.
        $cleaned = preg_replace('/^\s*chào\s+mừng[^\n.!?]{0,160}(podcast|kênh|channel)[^\n.!?]{0,160}[.!?]?\s*/iu', '', $cleaned) ?? $cleaned;

        // Normalize excessive leading newlines/spaces after cleanup.
        $cleaned = preg_replace('/^\s+/', '', $cleaned) ?? $cleaned;

        return trim($cleaned);
    }

    /**
     * Generate TTS audio for translated full transcript (chunked by 1000 words)
     */
    public function generateFullTranscriptTTS(Request $request, $projectId)
    {
        try {
            $project = DubSyncProject::findOrFail($projectId);

            $text = $request->input('text');
            if (!$text) {
                $text = $project->translated_full_transcript ?? '';
            }

            $text = trim((string) $text);
            if ($text === '') {
                return response()->json([
                    'success' => false,
                    'error' => 'No translated transcript text available'
                ], 400);
            }

            $provider = $request->input('provider', $project->tts_provider ?? 'google');
            $voiceGender = $request->input('voice_gender', 'female');
            $voiceName = $request->input('voice_name');
            $styleInstruction = $request->input('style_instruction', $project->style_instruction ?? null);

            if (strtolower((string) $provider) === 'vbee') {
                // Keep each Vbee request well below provider-side text rejection threshold.
                $chunks = $this->splitTextByCharacterLimit($text, 1500);
            } else {
                $words = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
                $chunks = array_chunk($words, 1000);
            }

            $maxParts = (int) $request->input('max_parts', 0);
            if ($maxParts > 0) {
                $chunks = array_slice($chunks, 0, $maxParts);
            }

            $ttsService = app(TTSService::class);
            $savedFiles = [];

            $targetDir = "public/projects/{$projectId}/full_script";
            Storage::makeDirectory($targetDir);

            $partIndexOffset = (int) $request->input('part_index', 0);

            foreach ($chunks as $index => $chunkWords) {
                $chunkText = is_array($chunkWords)
                    ? implode(' ', $chunkWords)
                    : trim((string) $chunkWords);

                if ($chunkText === '') {
                    continue;
                }

                $partIndex = $partIndexOffset > 0 ? $partIndexOffset + $index : $index + 1;
                $audioPath = $ttsService->generateAudio(
                    $chunkText,
                    $partIndex,
                    $voiceGender,
                    $voiceName,
                    $provider,
                    $styleInstruction,
                    $projectId
                );

                $extension = pathinfo($audioPath, PATHINFO_EXTENSION) ?: 'mp3';
                $targetPath = $targetDir . "/part_" . str_pad((string) $partIndex, 3, '0', STR_PAD_LEFT) . "_" . time() . "." . $extension;
                $finalPath = $audioPath;

                if (Storage::exists($audioPath)) {
                    Storage::move($audioPath, $targetPath);
                    $finalPath = $targetPath;
                }

                $savedFiles[] = [
                    'index' => $partIndex,
                    'path' => $finalPath,
                    'url' => Storage::url($finalPath),
                    'word_count' => count(preg_split('/\s+/u', $chunkText, -1, PREG_SPLIT_NO_EMPTY) ?: [])
                ];
            }

            // Save audio files list to database - scan all files in directory to get complete list
            $allFiles = Storage::files($targetDir);
            $allAudioFiles = [];

            foreach ($allFiles as $file) {
                // Skip files in subdirectories
                $relativePath = str_replace($targetDir . '/', '', $file);
                if (strpos($relativePath, '/') !== false) {
                    continue;
                }

                $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                if (!in_array($extension, ['mp3', 'wav', 'ogg', 'aac'])) {
                    continue;
                }

                $filename = basename($file);
                if (!preg_match('/^part_\d+_\d+\.' . $extension . '$/', $filename)) {
                    continue;
                }

                preg_match('/part_(\d+)/', $filename, $matches);
                $partNumber = isset($matches[1]) ? (int)$matches[1] : 0;

                $allAudioFiles[] = [
                    'index' => $partNumber,
                    'filename' => $filename,
                    'path' => $file,
                    'url' => Storage::url($file),
                    'size' => Storage::size($file),
                    'part_number' => $partNumber,
                    'modified' => Storage::lastModified($file)
                ];
            }

            // Sort by part number
            usort($allAudioFiles, function ($a, $b) {
                return $a['part_number'] - $b['part_number'];
            });

            $project->update([
                'full_transcript_audio_files' => $allAudioFiles
            ]);

            return response()->json([
                'success' => true,
                'total_parts' => count($savedFiles),
                'files' => $savedFiles
            ]);
        } catch (\Exception $e) {
            \Log::error('Generate full transcript TTS error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    private function splitTextByCharacterLimit(string $text, int $maxChars): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }

        $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $chunks = [];
        $currentWords = [];
        $currentLength = 0;

        foreach ($words as $word) {
            $wordLength = mb_strlen($word, 'UTF-8');
            $addition = $currentLength === 0 ? $wordLength : $wordLength + 1;

            if (($currentLength + $addition) > $maxChars && !empty($currentWords)) {
                $chunks[] = implode(' ', $currentWords);
                $currentWords = [$word];
                $currentLength = $wordLength;
                continue;
            }

            $currentWords[] = $word;
            $currentLength += $addition;
        }

        if (!empty($currentWords)) {
            $chunks[] = implode(' ', $currentWords);
        }

        return $chunks;
    }

    /**
     * Get list of full transcript audio files (from DB cache or from storage)
     */
    public function getFullTranscriptAudioList(Request $request, $projectId)
    {
        try {
            $project = DubSyncProject::findOrFail($projectId);
            $forceRefresh = $request->query('refresh', false);

            // First, try to load from database cache
            $audioFiles = [];
            $mergedFile = null;

            \Log::info('Loading full transcript audio list', [
                'project_id' => $projectId,
                'force_refresh' => $forceRefresh,
                'db_audio_files_count' => is_array($project->full_transcript_audio_files) ? count($project->full_transcript_audio_files) : 0,
                'db_has_merged_file' => !empty($project->full_transcript_merged_file)
            ]);

            if (!$forceRefresh && $project->full_transcript_audio_files && is_array($project->full_transcript_audio_files)) {
                $audioFiles = $project->full_transcript_audio_files;
                \Log::info('Loaded audio files from database', ['count' => count($audioFiles)]);
            }

            if (!$forceRefresh && $project->full_transcript_merged_file && is_array($project->full_transcript_merged_file)) {
                $mergedFile = $project->full_transcript_merged_file;
                \Log::info('Loaded merged file from database');
            }

            // If no data in DB, force refresh requested, or data is empty, scan from storage
            if ($forceRefresh || empty($audioFiles)) {
                \Log::info('Scanning storage for audio files', [
                    'reason' => $forceRefresh ? 'force_refresh' : 'no_db_data'
                ]);

                $targetDir = "public/projects/{$projectId}/full_script";

                if (Storage::exists($targetDir)) {
                    $files = Storage::files($targetDir);
                    $scannedAudioFiles = [];
                    $totalSize = 0;

                    foreach ($files as $file) {
                        // Skip files in subdirectories
                        $relativePath = str_replace($targetDir . '/', '', $file);
                        if (strpos($relativePath, '/') !== false) {
                            continue;
                        }

                        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                        if (!in_array($extension, ['mp3', 'wav', 'ogg', 'aac'])) {
                            continue;
                        }

                        $filename = basename($file);

                        // Only process files with pattern part_XXX_timestamp
                        if (!preg_match('/^part_\d+_\d+\.' . $extension . '$/', $filename)) {
                            continue;
                        }

                        preg_match('/part_(\d+)/', $filename, $matches);
                        $partNumber = isset($matches[1]) ? (int)$matches[1] : 0;

                        $scannedAudioFiles[] = [
                            'index' => $partNumber,
                            'filename' => $filename,
                            'path' => $file,
                            'url' => Storage::url($file),
                            'size' => Storage::size($file),
                            'part_number' => $partNumber,
                            'modified' => Storage::lastModified($file)
                        ];
                        $totalSize += Storage::size($file);
                    }

                    // Sort by part number
                    usort($scannedAudioFiles, function ($a, $b) {
                        return $a['part_number'] - $b['part_number'];
                    });

                    if (!empty($scannedAudioFiles)) {
                        $audioFiles = $scannedAudioFiles;
                        // Always update DB with latest scanned files
                        $project->update(['full_transcript_audio_files' => $audioFiles]);
                    }

                    // Check for merged file (always scan if refreshing or not in DB)
                    if ($forceRefresh || empty($mergedFile)) {
                        $mergedDir = "public/projects/{$projectId}/full_script/merged";
                        if (Storage::exists($mergedDir)) {
                            $mergedFiles = Storage::files($mergedDir);
                            foreach ($mergedFiles as $file) {
                                $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                                if (in_array($extension, ['mp3', 'wav', 'ogg', 'aac'])) {
                                    $mergedFile = [
                                        'filename' => basename($file),
                                        'path' => $file,
                                        'url' => Storage::url($file),
                                        'size' => Storage::size($file),
                                        'modified' => Storage::lastModified($file)
                                    ];
                                    // Update DB with merged file
                                    $project->update(['full_transcript_merged_file' => $mergedFile]);
                                    break;
                                }
                            }
                        }
                    }
                }
            }

            // Check for aligned file (always scan if refreshing or not in DB)
            $alignedFile = null;
            $alignedDir = "public/projects/{$projectId}/full_script/aligned";
            if (Storage::exists($alignedDir)) {
                $alignedFiles = Storage::files($alignedDir);
                // Get the most recent aligned file
                $latestAlignedFile = null;
                $latestTimestamp = 0;

                foreach ($alignedFiles as $file) {
                    $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                    if (in_array($extension, ['mp3', 'wav', 'ogg', 'aac'])) {
                        $modified = Storage::lastModified($file);
                        if ($modified > $latestTimestamp) {
                            $latestTimestamp = $modified;
                            $latestAlignedFile = $file;
                        }
                    }
                }

                if ($latestAlignedFile) {
                    $alignedFile = [
                        'filename' => basename($latestAlignedFile),
                        'path' => $latestAlignedFile,
                        'url' => Storage::url($latestAlignedFile),
                        'size' => Storage::size($latestAlignedFile),
                        'modified' => Storage::lastModified($latestAlignedFile)
                    ];
                }
            }

            $totalSize = 0;
            foreach ($audioFiles as $file) {
                if (isset($file['size'])) {
                    $totalSize += $file['size'];
                }
            }

            $response = [
                'success' => true,
                'files' => $audioFiles,
                'merged_file' => $mergedFile,
                'aligned_file' => $alignedFile,
                'total_size' => $totalSize,
                'count' => count($audioFiles)
            ];

            \Log::info('Returning audio list', [
                'project_id' => $projectId,
                'files_count' => count($audioFiles),
                'has_merged' => !empty($mergedFile),
                'has_aligned' => !empty($alignedFile),
                'total_size' => $totalSize
            ]);

            return response()->json($response);
        } catch (\Exception $e) {
            \Log::error('Get full transcript audio list error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Merge all full transcript audio files into one
     */
    public function mergeFullTranscriptAudio($projectId)
    {
        try {
            $project = DubSyncProject::findOrFail($projectId);
            $sourceDir = "public/projects/{$projectId}/full_script";

            if (!Storage::exists($sourceDir)) {
                return response()->json([
                    'success' => false,
                    'error' => 'No audio files found'
                ], 404);
            }

            // Get all audio files from full_script directory (NOT from subdirectories like merged/)
            // Only get files matching pattern: part_XXX_*.mp3
            $allFiles = Storage::files($sourceDir);
            $audioFiles = [];

            foreach ($allFiles as $file) {
                // Skip files in subdirectories (like merged/)
                $relativePath = str_replace($sourceDir . '/', '', $file);
                if (strpos($relativePath, '/') !== false) {
                    continue; // Skip files in subdirectories
                }

                // Only process audio files
                $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                if (!in_array($extension, ['mp3', 'wav', 'ogg', 'aac'])) {
                    continue;
                }

                $filename = basename($file);

                // Only process files with pattern part_XXX_timestamp (full transcript audio files)
                // This excludes segment audio files which have different naming pattern
                if (!preg_match('/^part_\d+_\d+\.' . $extension . '$/', $filename)) {
                    continue;
                }

                // Extract part number
                preg_match('/part_(\d+)/', $filename, $matches);
                $partNumber = isset($matches[1]) ? (int)$matches[1] : 0;

                $audioFiles[] = [
                    'path' => $file,
                    'filename' => $filename,
                    'part_number' => $partNumber
                ];
            }

            if (empty($audioFiles)) {
                return response()->json([
                    'success' => false,
                    'error' => 'No full transcript audio files to merge. Please generate full transcript audio first.'
                ], 404);
            }

            // Sort by part number
            usort($audioFiles, function ($a, $b) {
                return $a['part_number'] - $b['part_number'];
            });

            \Log::info('Merging full transcript audio files', [
                'project_id' => $projectId,
                'source_dir' => $sourceDir,
                'files_count' => count($audioFiles),
                'files' => array_column($audioFiles, 'filename')
            ]);

            // Create merged directory
            $mergedDir = "public/projects/{$projectId}/full_script/merged";
            Storage::makeDirectory($mergedDir);

            // Detect output format from first file (can be mp3 or wav)
            $firstFilename = $audioFiles[0]['filename'];
            $outputExtension = strtolower(pathinfo($firstFilename, PATHINFO_EXTENSION));
            if (!in_array($outputExtension, ['mp3', 'wav', 'ogg', 'aac'])) {
                $outputExtension = 'mp3'; // Default fallback
            }

            // Create file list for FFmpeg
            $fileListPath = storage_path("app/public/projects/{$projectId}/full_script/concat_list.txt");
            $fileListContent = '';

            foreach ($audioFiles as $audioFile) {
                $absolutePath = Storage::path($audioFile['path']);
                // Escape single quotes in path for FFmpeg
                $escapedPath = str_replace("'", "'\\''", $absolutePath);
                $fileListContent .= "file '{$escapedPath}'\n";
            }

            file_put_contents($fileListPath, $fileListContent);

            // Output file
            $timestamp = time();
            $outputFilename = "merged_full_transcript_{$timestamp}.{$outputExtension}";
            $outputPath = "public/projects/{$projectId}/full_script/merged/{$outputFilename}";
            $absoluteOutputPath = Storage::path($outputPath);

            // Ensure output directory exists
            $outputDir = dirname($absoluteOutputPath);
            if (!file_exists($outputDir)) {
                mkdir($outputDir, 0755, true);
            }

            // Use FFmpeg to merge audio files
            $ffmpegPath = env('FFMPEG_PATH', 'ffmpeg');

            // Check if FFmpeg is available
            $checkCommand = sprintf('%s -version', escapeshellarg($ffmpegPath));
            $output = [];
            $returnCode = 0;
            exec($checkCommand . ' 2>&1', $output, $returnCode);

            if ($returnCode !== 0) {
                \Log::error('FFmpeg not found or not executable', [
                    'path' => $ffmpegPath,
                    'return_code' => $returnCode,
                    'output' => $output
                ]);

                return response()->json([
                    'success' => false,
                    'error' => 'FFmpeg is not installed or not in system PATH. Please check FFMPEG_PATH environment variable.'
                ], 500);
            }

            $command = sprintf(
                '%s -f concat -safe 0 -i %s -c copy %s 2>&1',
                escapeshellarg($ffmpegPath),
                escapeshellarg($fileListPath),
                escapeshellarg($absoluteOutputPath)
            );

            \Log::info('Executing FFmpeg merge command', [
                'command' => $command,
                'file_list' => $fileListPath,
                'output_path' => $absoluteOutputPath,
                'file_count' => count($audioFiles)
            ]);

            $mergeOutput = [];
            exec($command, $mergeOutput, $returnCode);

            \Log::info('FFmpeg merge completed', [
                'return_code' => $returnCode,
                'output' => $mergeOutput,
                'file_exists' => file_exists($absoluteOutputPath)
            ]);

            // Clean up file list
            if (file_exists($fileListPath)) {
                unlink($fileListPath);
            }

            if ($returnCode !== 0 || !file_exists($absoluteOutputPath)) {
                $fileListContent = file_exists($fileListPath) ? file_get_contents($fileListPath) : 'File not found';
                \Log::error('FFmpeg merge failed', [
                    'command' => $command,
                    'output' => $mergeOutput,
                    'return_code' => $returnCode,
                    'file_exists' => file_exists($absoluteOutputPath),
                    'output_dir' => dirname($absoluteOutputPath),
                    'file_list_content' => $fileListContent
                ]);

                return response()->json([
                    'success' => false,
                    'error' => 'Failed to merge audio files. FFmpeg error: ' . implode("\n", $mergeOutput)
                ], 500);
            }

            $fileSize = Storage::size($outputPath);

            // Get audio duration using FFprobe
            $ffprobePath = env('FFPROBE_PATH', 'ffprobe');
            $durationCommand = sprintf(
                '%s -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 %s',
                escapeshellarg($ffprobePath),
                escapeshellarg($absoluteOutputPath)
            );

            $duration = trim(shell_exec($durationCommand));
            $durationFormatted = null;

            if ($duration && is_numeric($duration)) {
                $seconds = (int)$duration;
                $hours = floor($seconds / 3600);
                $minutes = floor(($seconds % 3600) / 60);
                $secs = $seconds % 60;
                $durationFormatted = sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
            }

            \Log::info('Successfully merged full transcript audio', [
                'project_id' => $projectId,
                'output_file' => $outputFilename,
                'size' => $fileSize,
                'duration' => $durationFormatted,
                'merged_files_count' => count($audioFiles)
            ]);

            // Save merged file info to database
            $mergedFileInfo = [
                'filename' => $outputFilename,
                'path' => $outputPath,
                'url' => Storage::url($outputPath),
                'size' => $fileSize,
                'modified' => time()
            ];
            $project->update(['full_transcript_merged_file' => $mergedFileInfo]);

            return response()->json([
                'success' => true,
                'filename' => $outputFilename,
                'path' => $outputPath,
                'url' => Storage::url($outputPath),
                'size' => $fileSize,
                'duration' => $durationFormatted,
                'merged_files_count' => count($audioFiles)
            ]);
        } catch (\Exception $e) {
            \Log::error('Merge full transcript audio error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a single full transcript audio file
     */
    public function deleteFullTranscriptAudio(Request $request, $projectId)
    {
        try {
            $project = DubSyncProject::findOrFail($projectId);
            $filePath = $request->input('path');

            if (!$filePath) {
                return response()->json([
                    'success' => false,
                    'error' => 'File path is required'
                ], 400);
            }

            // Security check: ensure the file is within the project's full_script directory
            if (!str_contains($filePath, "projects/{$projectId}/full_script")) {
                return response()->json([
                    'success' => false,
                    'error' => 'Invalid file path'
                ], 403);
            }

            if (Storage::exists($filePath)) {
                Storage::delete($filePath);
            }

            // Update database - remove file from audio files list or merged file
            if (str_contains($filePath, '/merged/')) {
                // This is a merged file
                $project->update(['full_transcript_merged_file' => null]);
            } else {
                // This is a regular audio file
                $audioFiles = $project->full_transcript_audio_files ?? [];
                $audioFiles = array_filter($audioFiles, function ($file) use ($filePath) {
                    return $file['path'] !== $filePath;
                });
                $project->update(['full_transcript_audio_files' => array_values($audioFiles)]);
            }

            return response()->json([
                'success' => true,
                'message' => 'File deleted successfully'
            ]);
        } catch (\Exception $e) {
            \Log::error('Delete full transcript audio error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
    /**
     * Delete all full transcript audio files
     */
    public function deleteAllFullTranscriptAudio($projectId)
    {
        try {
            $project = DubSyncProject::findOrFail($projectId);
            $targetDir = "public/projects/{$projectId}/full_script";

            if (!Storage::exists($targetDir)) {
                return response()->json([
                    'success' => true,
                    'deleted_count' => 0,
                    'message' => 'No files to delete'
                ]);
            }

            $files = Storage::files($targetDir);
            $deletedCount = 0;

            foreach ($files as $file) {
                // Only delete audio files
                $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                if (in_array($extension, ['mp3', 'wav', 'ogg', 'aac'])) {
                    Storage::delete($file);
                    $deletedCount++;
                }
            }

            // Update database - clear all audio files and merged file
            $project->update([
                'full_transcript_audio_files' => null,
                'full_transcript_merged_file' => null
            ]);

            return response()->json([
                'success' => true,
                'deleted_count' => $deletedCount,
                'message' => "Deleted {$deletedCount} audio files"
            ]);
        } catch (\Exception $e) {
            \Log::error('Delete all full transcript audio error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function alignFullTranscriptDuration(Request $request, $projectId)
    {
        try {
            \Log::info('=== START alignFullTranscriptDuration ===', ['project_id' => $projectId]);

            $validated = $request->validate([
                'merged_file_path' => 'required|string'
            ]);

            \Log::info('Request validated', ['merged_file_path' => $validated['merged_file_path']]);

            $project = DubSyncProject::findOrFail($projectId);

            \Log::info('Project found', [
                'project_id' => $project->id,
                'youtube_duration_raw' => $project->youtube_duration,
                'youtube_duration_type' => gettype($project->youtube_duration)
            ]);

            // Get target duration from YouTube
            $targetDuration = $this->parseDurationToSeconds($project->youtube_duration);

            if (!$targetDuration) {
                \Log::error('Failed to parse YouTube duration', ['youtube_duration' => $project->youtube_duration]);
                return response()->json([
                    'success' => false,
                    'error' => 'Không tìm thấy YouTube duration'
                ], 400);
            }

            \Log::info('Target duration calculated', ['target_duration_seconds' => $targetDuration]);

            // Get original audio duration
            $inputPath = Storage::path($validated['merged_file_path']);

            \Log::info('Input path resolved', [
                'requested_path' => $validated['merged_file_path'],
                'absolute_path' => $inputPath,
                'file_exists' => file_exists($inputPath),
                'file_size' => file_exists($inputPath) ? filesize($inputPath) : null
            ]);

            if (!file_exists($inputPath)) {
                \Log::error('Merged file not found', [
                    'path_requested' => $validated['merged_file_path'],
                    'full_path' => $inputPath,
                    'exists' => file_exists($inputPath)
                ]);
                return response()->json([
                    'success' => false,
                    'error' => 'Không tìm thấy file audio'
                ], 404);
            }

            $originalDuration = $this->getAudioDuration($inputPath);

            if (!$originalDuration) {
                \Log::error('Failed to get original audio duration');
                return response()->json([
                    'success' => false,
                    'error' => 'Không thể lấy duration của file audio'
                ], 500);
            }

            \Log::info('Original duration extracted', ['original_duration_seconds' => $originalDuration]);

            // Calculate tempo ratio
            // Formula: tempo = original_duration / target_duration
            // If original is longer, tempo > 1 (speed up to shorten)
            // If original is shorter, tempo < 1 (slow down to lengthen)
            $tempoRatio = $originalDuration / $targetDuration;

            \Log::info('Tempo ratio calculated', [
                'target_duration' => $targetDuration,
                'original_duration' => $originalDuration,
                'tempo_ratio' => $tempoRatio,
                'calculation' => "{$targetDuration} / {$originalDuration} = {$tempoRatio}",
                'effect' => $tempoRatio < 1 ? 'SLOW DOWN' : ($tempoRatio > 1 ? 'SPEED UP' : 'NO CHANGE')
            ]);

            // Create aligned directory
            $alignedDir = Storage::path("public/projects/{$projectId}/full_script/aligned");
            if (!file_exists($alignedDir)) {
                mkdir($alignedDir, 0755, true);
                \Log::info('Aligned directory created', ['directory' => $alignedDir]);
            }

            // Generate output filename
            $timestamp = now()->timestamp;
            $extension = strtolower(pathinfo($inputPath, PATHINFO_EXTENSION));
            $outputFilename = "aligned_full_transcript_{$timestamp}.{$extension}";
            $outputPath = "{$alignedDir}/{$outputFilename}";

            \Log::info('Output path prepared', [
                'output_filename' => $outputFilename,
                'output_path' => $outputPath,
                'directory_exists' => file_exists($alignedDir)
            ]);

            // Adjust audio tempo
            $success = $this->adjustAudioTempo($inputPath, $outputPath, $tempoRatio);

            if (!$success) {
                \Log::error('Audio tempo adjustment failed');
                return response()->json([
                    'success' => false,
                    'error' => 'Không thể căn chỉnh audio duration'
                ], 500);
            }

            \Log::info('Audio tempo adjustment succeeded', ['output_file_exists' => file_exists($outputPath)]);

            // Get actual output duration to verify
            $alignedDuration = $this->getAudioDuration($outputPath);

            \Log::info('=== ALIGN COMPLETE ===', [
                'original_duration' => $originalDuration,
                'target_duration' => $targetDuration,
                'aligned_duration' => $alignedDuration,
                'tempo_ratio_applied' => $tempoRatio,
                'difference_from_target' => abs($alignedDuration - $targetDuration),
                'success' => true
            ]);

            return response()->json([
                'success' => true,
                'aligned_file' => $outputFilename,
                'original_duration' => round($originalDuration, 2),
                'target_duration' => round($targetDuration, 2),
                'aligned_duration' => round($alignedDuration, 2),
                'tempo_ratio' => round($tempoRatio, 4)
            ]);
        } catch (\Exception $e) {
            \Log::error('Align full transcript duration error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    private function parseDurationToSeconds($duration)
    {
        if (!$duration) {
            \Log::warning('Duration is empty', ['duration' => $duration]);
            return null;
        }

        // Parse "5:23" or "1:02:15" to seconds
        $parts = array_reverse(explode(':', $duration));
        $seconds = 0;

        foreach ($parts as $i => $part) {
            $seconds += intval($part) * pow(60, $i);
        }

        \Log::info('Parse duration to seconds', [
            'youtube_duration' => $duration,
            'parts' => $parts,
            'calculated_seconds' => $seconds
        ]);

        return $seconds;
    }

    private function getAudioDuration($filePath)
    {
        try {
            $command = sprintf(
                'ffprobe -i %s -show_entries format=duration -v quiet -of csv="p=0" 2>&1',
                escapeshellarg($filePath)
            );

            \Log::debug('FFprobe command', [
                'file_path' => $filePath,
                'file_exists' => file_exists($filePath),
                'command' => $command
            ]);

            $output = shell_exec($command);
            $duration = trim($output);
            $floatDuration = floatval($duration);

            \Log::info('Audio duration extracted', [
                'file_path' => $filePath,
                'raw_output' => $output,
                'trimmed' => $duration,
                'float_duration' => $floatDuration,
                'formatted' => gmdate('H:i:s', intval($floatDuration))
            ]);

            return $floatDuration;
        } catch (\Exception $e) {
            \Log::error('Get audio duration error', [
                'file' => $filePath,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    private function adjustAudioTempo($inputPath, $outputPath, $tempoRatio)
    {
        try {
            // Build atempo filter chain
            // FFmpeg atempo filter constraint: 0.5 ≤ tempo ≤ 2.0
            $filters = [];
            $remaining = $tempoRatio;

            \Log::info('Building atempo filter chain', [
                'input_tempo_ratio' => $tempoRatio,
                'input_remaining' => $remaining
            ]);

            // Handle tempo > 2.0
            $chainCount = 0;
            while ($remaining > 2.0) {
                $filters[] = 'atempo=2.0';
                $remaining /= 2.0;
                $chainCount++;
                \Log::debug('Added atempo=2.0 filter', ['remaining' => $remaining, 'iteration' => $chainCount]);
            }

            // Handle tempo < 0.5
            while ($remaining < 0.5) {
                $filters[] = 'atempo=0.5';
                $remaining /= 0.5;
                $chainCount++;
                \Log::debug('Added atempo=0.5 filter', ['remaining' => $remaining, 'iteration' => $chainCount]);
            }

            // Add final tempo
            $filters[] = sprintf('atempo=%.4f', $remaining);

            $filterChain = implode(',', $filters);

            \Log::info('Final filter chain', [
                'filters' => $filters,
                'filter_chain' => $filterChain,
                'final_remaining' => $remaining
            ]);

            // Run FFmpeg command
            $command = sprintf(
                'ffmpeg -i %s -filter:a "%s" -y %s 2>&1',
                escapeshellarg($inputPath),
                $filterChain,
                escapeshellarg($outputPath)
            );

            \Log::info('Executing FFmpeg align command', [
                'input_path' => $inputPath,
                'output_path' => $outputPath,
                'command' => $command
            ]);

            exec($command, $output, $returnCode);

            \Log::info('FFmpeg execution result', [
                'return_code' => $returnCode,
                'output_lines' => count($output),
                'last_output' => array_slice($output, -5) // Last 5 lines
            ]);

            if ($returnCode !== 0) {
                \Log::error('FFmpeg align failed', [
                    'return_code' => $returnCode,
                    'full_output' => implode("\n", $output),
                    'output_file_exists' => file_exists($outputPath)
                ]);
                return false;
            }

            $fileExists = file_exists($outputPath);
            \Log::info('FFmpeg align success', [
                'output_path' => $outputPath,
                'output_file_exists' => $fileExists,
                'output_file_size' => $fileExists ? filesize($outputPath) : 0
            ]);

            return $fileExists;
        } catch (\Exception $e) {
            \Log::error('Adjust audio tempo error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    public function downloadYoutubeVideo(Request $request, $projectId)
    {
        $projectId = (int) $projectId;

        try {
            $project = DubSyncProject::findOrFail($projectId);

            if (!$project->video_id && !$project->youtube_url) {
                return response()->json(['success' => false, 'error' => 'Không tìm thấy thông tin video nguồn'], 400);
            }

            $source = $this->resolveDownloadSourceForProject($project);
            if (!$source) {
                return response()->json(['success' => false, 'error' => 'Nguồn video chưa được hỗ trợ.'], 422);
            }

            // If a merged video already exists, return it immediately without queuing.
            $videoDir = Storage::path("public/projects/{$projectId}/video");
            if (is_dir($videoDir)) {
                $existingFiles = $this->findMergedVideoFiles($videoDir);
                if (!empty($existingFiles)) {
                    $existingFile = $existingFiles[0];
                    $filename     = basename($existingFile);

                    $currentStatus = (string) ($project->status ?? '');
                    $allowedToSetSourceDownloaded = in_array($currentStatus, ['', 'new', 'pending', 'error', 'source_downloaded'], true);
                    if ($allowedToSetSourceDownloaded && $currentStatus !== 'source_downloaded') {
                        $project->status = 'source_downloaded';
                        $project->save();
                    }

                    $result       = [
                        'success'  => true,
                        'queued'   => false,
                        'platform' => $source['platform'],
                        'filename' => $filename,
                        'path'     => "public/projects/{$projectId}/video/{$filename}",
                        'url'      => Storage::url("public/projects/{$projectId}/video/{$filename}"),
                        'size'     => filesize($existingFile),
                    ];
                    $this->setSourceDownloadProgress($projectId, 'completed', 100, 'Video đã tồn tại.', $result);
                    return response()->json($result);
                }
            }

            // Reset progress and dispatch the job.
            $this->setSourceDownloadProgress($projectId, 'processing', 3, 'Đang xếp hàng tải xuống...');
            \App\Jobs\DownloadSourceVideoJob::dispatch($projectId, $source);
            $this->ensureQueueWorkerRunning();

            return response()->json(['success' => true, 'queued' => true]);
        } catch (\Exception $e) {
            \Log::error('downloadYoutubeVideo dispatch error', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function getDownloadYoutubeVideoProgress($projectId)
    {
        $projectId = (int) $projectId;
        $progress = Cache::get($this->getSourceDownloadProgressKey($projectId), [
            'status' => 'idle',
            'percent' => 0,
            'message' => 'Chua co tien trinh tai video.',
            'updated_at' => now()->toIso8601String(),
        ]);

        return response()->json([
            'success' => true,
            'progress' => $progress,
        ]);
    }

    public function generateThumbnail(Request $request, $projectId)
    {
        $request->validate([
            'ratio' => 'required|in:16:9,9:16',
            'style' => 'required|string|max:50',
        ]);

        try {
            $project = DubSyncProject::findOrFail((int) $projectId);

            $ratio = (string) $request->input('ratio', '16:9');
            $style = trim((string) $request->input('style', 'cinematic'));
            $styleMap = [
                'cinematic' => 'cinematic lighting, dramatic depth, movie-like composition',
                'dramatic' => 'high tension, strong contrast, dynamic perspective',
                'minimal' => 'clean composition, minimalist modern design, high readability',
                'news' => 'documentary/news visual tone, realistic details, editorial framing',
                'bold' => 'bold colors, strong contrast, intense focal point, high click-through appeal',
            ];
            $styleDescriptor = $styleMap[$style] ?? $styleMap['cinematic'];

            $title = trim((string) ($project->youtube_title_vi ?: $project->youtube_title ?: ''));

            $transcriptSource = trim((string) ($project->translated_full_transcript ?: $project->full_transcript ?: ''));
            if ($transcriptSource === '' && is_array($project->translated_segments)) {
                $transcriptSource = collect($project->translated_segments)
                    ->map(fn($seg) => trim((string) ($seg['text'] ?? '')))
                    ->filter()
                    ->take(12)
                    ->implode("\n");
            }

            $transcriptSnippet = mb_substr($transcriptSource, 0, 1800, 'UTF-8');
            if ($transcriptSnippet === '') {
                $transcriptSnippet = 'A compelling educational story with surprising facts and clear contrast between myth and reality.';
            }

            $prompt = "Create a highly clickable YouTube thumbnail image with ratio {$ratio}.\n"
                . "Style direction: {$styleDescriptor}.\n"
                . "Core title/topic: {$title}.\n"
                . "Use this content context to shape scene and hook: {$transcriptSnippet}\n\n"
                . "Requirements:\n"
                . "- Visual must communicate contrast between common belief and real truth\n"
                . "- One dominant focal subject, strong visual storytelling, clear hierarchy\n"
                . "- Use topic/title only as semantic guidance, never render written words from it\n"
                . "- ABSOLUTELY NO TEXT in the image: no letters, no words, no numbers, no subtitles\n"
                . "- No signboards, posters, UI labels, interface text, watermark, logo, or typographic symbols\n"
                . "- If any text appears, treat output as invalid and regenerate a text-free version\n"
                . "- Vibrant but tasteful colors, high detail, modern thumbnail aesthetics\n"
                . "- Make it feel surprising and curiosity-driven";

            $filename = 'thumb_' . now()->format('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.png';
            $relativePath = "projects/{$project->id}/thumbnails/{$filename}";
            $absolutePath = Storage::path('public/' . $relativePath);

            /** @var GeminiImageService $imageService */
            $imageService = app(GeminiImageService::class);
            $result = $imageService->generateImage($prompt, $absolutePath, $ratio, 'gemini-nano-banana-pro');

            if (!($result['success'] ?? false)) {
                return response()->json([
                    'success' => false,
                    'error' => $result['error'] ?? 'Không thể tạo thumbnail',
                ], 500);
            }

            $url = Storage::url('public/' . $relativePath);
            $project->update([
                'youtube_thumbnail' => $url,
            ]);

            return response()->json([
                'success' => true,
                'thumbnail_url' => $url,
                'ratio' => $ratio,
                'style' => $style,
            ]);
        } catch (\Throwable $e) {
            \Log::error('generateThumbnail error', [
                'project_id' => $projectId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function getSourceDownloadProgressKey(int $projectId): string
    {
        return "source_video_download_progress_{$projectId}";
    }

    private function setSourceDownloadProgress(int $projectId, string $status, int $percent, string $message, array $extra = []): void
    {
        Cache::put($this->getSourceDownloadProgressKey($projectId), array_merge([
            'status' => $status,
            'percent' => max(0, min(100, $percent)),
            'message' => $message,
            'updated_at' => now()->toIso8601String(),
        ], $extra), now()->addHours(2));
    }

    private function findMergedVideoFiles(string $videoDir): array
    {
        $files = glob("{$videoDir}/*.mp4") ?: [];

        $merged = array_values(array_filter($files, function ($path) {
            $name = basename($path);

            // yt-dlp fragmented tracks are usually *.f12345.mp4 and may have no audio.
            if (preg_match('/\.f\d+\.mp4$/i', $name)) {
                return false;
            }

            return true;
        }));

        usort($merged, function ($a, $b) {
            return filemtime($b) <=> filemtime($a);
        });

        return $merged;
    }

    private function cleanupStaleDownloadArtifacts(string $videoDir): void
    {
        $patterns = [
            "{$videoDir}/*.part",
            "{$videoDir}/*.ytdl",
            "{$videoDir}/*.f*.mp4",
            "{$videoDir}/*.f*.m4a",
            "{$videoDir}/*.f*.webm",
        ];

        foreach ($patterns as $pattern) {
            foreach (glob($pattern) ?: [] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }
    }

    private function renameDownloadedVideoWithLocalizedTitle(DubSyncProject $project, string $videoFile, string $videoDir, string $platform): ?string
    {
        try {
            $titleVi = trim((string) ($project->youtube_title_vi ?? ''));

            if ($titleVi === '' && $platform === 'bilibili') {
                $sourceTitle = trim((string) ($project->youtube_title ?? ''));
                if ($sourceTitle !== '' && preg_match('/\p{Han}/u', $sourceTitle) === 1) {
                    /** @var TranslationService $translator */
                    $translator = app(TranslationService::class);
                    $translated = trim((string) $translator->translateText($sourceTitle, 'zh-CN', 'vi', 'google'));
                    if ($translated !== '') {
                        $titleVi = $translated;
                        $project->youtube_title_vi = $translated;
                        $project->save();
                    }
                }
            }

            if ($titleVi === '') {
                return null;
            }

            $safeBase = $this->sanitizeWindowsFilename($titleVi);
            if ($safeBase === '') {
                return null;
            }

            $ext = pathinfo($videoFile, PATHINFO_EXTENSION) ?: 'mp4';
            $targetPath = $videoDir . DIRECTORY_SEPARATOR . $safeBase . '.' . $ext;
            $suffix = 1;
            while (file_exists($targetPath) && realpath($targetPath) !== realpath($videoFile)) {
                $targetPath = $videoDir . DIRECTORY_SEPARATOR . $safeBase . ' (' . $suffix . ').' . $ext;
                $suffix++;
            }

            if (realpath($targetPath) === realpath($videoFile)) {
                return $videoFile;
            }

            if (@rename($videoFile, $targetPath)) {
                return $targetPath;
            }
        } catch (\Throwable $e) {
            \Log::warning('Rename downloaded source video failed', [
                'project_id' => $project->id,
                'video_file' => $videoFile,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    private function sanitizeWindowsFilename(string $name): string
    {
        $sanitized = preg_replace('/[<>:"\/\\|?*]+/u', ' ', $name) ?? '';
        $sanitized = trim(preg_replace('/\s+/u', ' ', $sanitized) ?? '');

        // Windows reserved names.
        $reserved = ['CON', 'PRN', 'AUX', 'NUL', 'COM1', 'COM2', 'COM3', 'COM4', 'COM5', 'COM6', 'COM7', 'COM8', 'COM9', 'LPT1', 'LPT2', 'LPT3', 'LPT4', 'LPT5', 'LPT6', 'LPT7', 'LPT8', 'LPT9'];
        if (in_array(strtoupper($sanitized), $reserved, true)) {
            $sanitized = '_' . $sanitized;
        }

        // Keep filename reasonable for Windows path limits.
        return mb_substr($sanitized, 0, 120);
    }

    private function resolveDownloadSourceForProject(DubSyncProject $project): ?array
    {
        $url = trim((string) ($project->youtube_url ?? ''));

        if ($url !== '') {
            if (preg_match('/(?:youtube\.com|youtu\.be)/i', $url)) {
                return ['platform' => 'youtube', 'url' => $url];
            }

            if (preg_match('/(?:bilibili\.com|b23\.tv)/i', $url)) {
                return ['platform' => 'bilibili', 'url' => $url];
            }
        }

        // Backward compatibility when old records only have video_id.
        $videoId = trim((string) ($project->video_id ?? ''));
        if ($videoId === '') {
            return null;
        }

        if (str_starts_with($videoId, 'bili:')) {
            $bvid = trim(substr($videoId, 5));
            return $bvid !== ''
                ? ['platform' => 'bilibili', 'url' => "https://www.bilibili.com/video/{$bvid}"]
                : null;
        }

        return ['platform' => 'youtube', 'url' => "https://www.youtube.com/watch?v={$videoId}"];
    }

    /**
     * Ensure a persistent queue worker is running.
     * Stores the worker PID in storage/app/queue-worker.pid.
     * If the PID is still alive, skips starting a new one.
     */
    private function ensureQueueWorkerRunning(): void
    {
        try {
            $pidFile = storage_path('app/queue-worker.pid');
            $php     = PHP_BINARY;
            $artisan = base_path('artisan');
            $workdir = base_path();

            // Check if existing worker is still alive.
            if (file_exists($pidFile)) {
                $pid = (int) trim((string) file_get_contents($pidFile));
                if ($pid > 0 && $this->isWorkerProcessAlive($pid)) {
                    \Log::info('[Queue] Worker already running', ['pid' => $pid]);
                    return;
                }
            }

            // Start a persistent worker (no --stop-when-empty).
            if (PHP_OS_FAMILY === 'Windows') {
                // PowerShell: start detached process, capture PID.
                $psCmd = sprintf(
                    '(Start-Process -FilePath \'%s\' -ArgumentList \'"%s" queue:work --sleep=3 --tries=1 --timeout=3600\' -WorkingDirectory \'%s\' -WindowStyle Hidden -PassThru).Id',
                    str_replace("'", "''", $php),
                    str_replace("'", "''", $artisan),
                    str_replace("'", "''", $workdir)
                );
                $pid = (int) trim((string) shell_exec('powershell -NoProfile -Command "' . $psCmd . '"'));
            } else {
                $cmd = sprintf(
                    'cd %s && %s %s queue:work --sleep=3 --tries=1 --timeout=3600 > /dev/null 2>&1 & echo $!',
                    escapeshellarg($workdir),
                    escapeshellarg($php),
                    escapeshellarg($artisan)
                );
                $pid = (int) trim((string) shell_exec($cmd));
            }

            if ($pid > 0) {
                file_put_contents($pidFile, $pid);
                \Log::info('[Queue] Worker started', ['pid' => $pid]);
            } else {
                \Log::warning('[Queue] Worker started but could not capture PID');
            }
        } catch (\Throwable $e) {
            \Log::warning('[Queue] Could not auto-start queue worker: ' . $e->getMessage());
        }
    }

    private function isWorkerProcessAlive(int $pid): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $out = shell_exec("tasklist /NH /FO CSV 2>NUL");
            if (!$out) return false;
            foreach (explode("\n", $out) as $line) {
                $parts = str_getcsv(trim($line));
                if (isset($parts[1]) && (int) $parts[1] === $pid) {
                    return true;
                }
            }
            return false;
        }

        // Linux/Mac: sending signal 0 checks if process exists without killing it.
        return function_exists('posix_kill') && posix_kill($pid, 0);
    }
}

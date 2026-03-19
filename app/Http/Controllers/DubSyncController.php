<?php

namespace App\Http\Controllers;

use App\Models\DubSyncProject;
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
                    'gemini' => \App\Services\TTSService::getAllVoices('gemini')
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
            $youtubeUrl = $request->youtube_url;
            \Log::info('Processing YouTube URL', ['url' => $youtubeUrl]);

            $videoId = $this->extractVideoId($youtubeUrl);

            if (!$videoId) {
                \Log::warning('Invalid video ID', ['url' => $youtubeUrl]);
                return response()->json(['error' => 'Invalid YouTube URL'], 400);
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
                'youtube_url' => $youtubeUrl,
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

        $videoId = $project->video_id ?: $this->extractVideoId($project->youtube_url);
        if (!$videoId) {
            return redirect()->back()->withErrors(['error' => 'Invalid YouTube URL']);
        }

        try {
            $metadata = app('App\\Services\\YouTubeTranscriptService')->getMetadata($videoId);
            $transcript = app('App\\Services\\YouTubeTranscriptService')->getTranscript($videoId);
            $cleanedTranscript = app('App\\Services\\TranscriptCleanerService')->clean($transcript);
            $segments = app('App\\Services\\TranscriptSegmentationService')->segment($cleanedTranscript);

            $project->update([
                'video_id' => $videoId,
                'youtube_title' => $metadata['title'] ?? $project->youtube_title,
                'youtube_description' => $metadata['description'] ?? $project->youtube_description,
                'youtube_thumbnail' => $metadata['thumbnail'] ?? $project->youtube_thumbnail,
                'youtube_duration' => $metadata['duration'] ?? $project->youtube_duration,
                'original_transcript' => $transcript,
                'segments' => $segments,
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

        $videoId = $project->video_id ?: $this->extractVideoId($project->youtube_url);
        if (!$videoId) {
            return response()->json(['success' => false, 'error' => 'Invalid YouTube URL'], 422);
        }

        try {
            $project->update(['status' => 'processing']);

            $metadata = app('App\\Services\\YouTubeTranscriptService')->getMetadata($videoId);
            $transcript = app('App\\Services\\YouTubeTranscriptService')->getTranscript($videoId);
            $cleanedTranscript = app('App\\Services\\TranscriptCleanerService')->clean($transcript);
            $segments = app('App\\Services\\TranscriptSegmentationService')->segment($cleanedTranscript);

            $project->update([
                'video_id' => $videoId,
                'youtube_title' => $metadata['title'] ?? $project->youtube_title,
                'youtube_description' => $metadata['description'] ?? $project->youtube_description,
                'youtube_thumbnail' => $metadata['thumbnail'] ?? $project->youtube_thumbnail,
                'youtube_duration' => $metadata['duration'] ?? $project->youtube_duration,
                'original_transcript' => $transcript,
                'segments' => $segments,
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
     * Fetch YouTube channel videos via Google API
     */
    public function fetchChannelVideos(Request $request)
    {
        $request->validate([
            'channel_url' => 'required|url',
            'max_results' => 'nullable|integer|min:1|max:50',
        ]);

        $apiKey = config('services.youtube.api_key');
        if (!$apiKey) {
            return response()->json([
                'success' => false,
                'error' => 'Missing YOUTUBE_API_KEY in .env'
            ], 500);
        }

        $channelUrl = $request->input('channel_url');

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

        $maxResults = (int) $request->input('max_results', 20);
        $maxResults = max(1, min(50, $maxResults));

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
            'provider' => 'nullable|in:openai,google'
        ]);

        try {
            $project = DubSyncProject::findOrFail($projectId);
            $segments = $request->segments;
            $provider = $request->provider ?? env('TRANSLATION_PROVIDER', 'google');

            // Merge segments into complete sentences for better translation quality
            $segmentationService = new \App\Services\TranscriptSegmentationService();
            $mergedSegments = $segmentationService->mergeSegmentsIntoSentences($segments);

            // Step 4: Translate to Vietnamese
            $translationService = new \App\Services\TranslationService($provider);
            $translatedSegments = $translationService->translateSegments($mergedSegments);

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

            if (!empty($project->youtube_title)) {
                $updateData['youtube_title_vi'] = $translationService->translateText($project->youtube_title, 'en', 'vi', $provider);
            }

            if (!empty($project->youtube_description)) {
                $updateData['youtube_description_vi'] = $translationService->translateText($project->youtube_description, 'en', 'vi', $provider);
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
            'tts_provider' => 'nullable|in:google,openai,gemini',
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
            $alignedSegments = $project->aligned_segments;

            // Step 7: Merge audio according to timeline
            $finalAudioPath = app('App\Services\AudioMergeService')->mergeSegments($alignedSegments, $projectId);

            $project->update([
                'final_audio_path' => $finalAudioPath,
                'status' => 'merged'
            ]);

            return response()->json([
                'success' => true,
                'audio_path' => $finalAudioPath
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
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
            'tts_provider' => 'required|in:google,openai,gemini'
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
                    'voice_settings' => 'sometimes|array',
                    'voice_settings.*.voice_gender' => 'required_with:voice_settings|in:male,female',
                    'voice_settings.*.voice_name' => 'required_with:voice_settings|string',
                    'voice_gender' => 'sometimes|in:male,female',
                    'voice_name' => 'sometimes|string',
                    'provider' => 'required|string|in:google,openai,gemini',
                    'style_instruction' => 'nullable|string'
                ]);

                $voiceSettings = $validated['voice_settings'] ?? [];
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
                        $text = $segment['text'] ?? '';

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
                    'provider' => 'required|string|in:google,openai,gemini',
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

            $words = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
            $chunks = array_chunk($words, 1000);

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
                $chunkText = implode(' ', $chunkWords);
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
                    'word_count' => count($chunkWords)
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

    public function downloadYoutubeVideo($projectId)
    {
        try {
            $project = DubSyncProject::findOrFail($projectId);

            if (!$project->video_id) {
                return response()->json([
                    'success' => false,
                    'error' => 'Không tìm thấy video ID'
                ], 400);
            }

            \Log::info('Starting YouTube video download', [
                'project_id' => $projectId,
                'video_id' => $project->video_id
            ]);

            // Create video directory
            $videoDir = Storage::path("public/projects/{$projectId}/video");
            if (!file_exists($videoDir)) {
                mkdir($videoDir, 0755, true);
            }

            // Check if video already exists
            $existingFiles = glob("{$videoDir}/*.mp4");
            if (!empty($existingFiles)) {
                $existingFile = $existingFiles[0];
                return response()->json([
                    'success' => true,
                    'message' => 'Video đã tồn tại',
                    'filename' => basename($existingFile),
                    'path' => "public/projects/{$projectId}/video/" . basename($existingFile),
                    'url' => Storage::url("public/projects/{$projectId}/video/" . basename($existingFile))
                ]);
            }

            // Download using yt-dlp or youtube-dl
            $ytDlpPath = env('YTDLP_PATH', 'python -m yt_dlp');
            $youtubeUrl = "https://www.youtube.com/watch?v={$project->video_id}";
            $outputTemplate = "{$videoDir}/%(title)s.%(ext)s";

            // Build command - don't escape paths that contain yt-dlp special characters like %(...)s
            $command = sprintf(
                '%s -f "best[ext=mp4]" -o %s %s 2>&1',
                $ytDlpPath,  // Don't escape - may contain spaces for "python -m yt_dlp"
                '"' . $outputTemplate . '"',  // Quote but don't escape to preserve %(...)s
                escapeshellarg($youtubeUrl)
            );

            \Log::info('Executing yt-dlp command', [
                'command' => $command,
                'video_id' => $project->video_id,
                'output_dir' => $videoDir,
                'output_template' => $outputTemplate
            ]);

            $output = [];
            $returnCode = 0;
            exec($command, $output, $returnCode);

            if ($returnCode !== 0) {
                \Log::error('YouTube download failed', [
                    'return_code' => $returnCode,
                    'output' => implode("\n", $output)
                ]);

                return response()->json([
                    'success' => false,
                    'error' => 'Không thể tải video từ YouTube. Vui lòng kiểm tra video ID hoặc cài đặt yt-dlp'
                ], 500);
            }

            // Find downloaded file
            $downloadedFiles = glob("{$videoDir}/*.mp4");
            if (empty($downloadedFiles)) {
                \Log::error('No video file found after download', ['directory' => $videoDir]);
                return response()->json([
                    'success' => false,
                    'error' => 'Không tìm thấy file video sau khi tải'
                ], 500);
            }

            $videoFile = $downloadedFiles[0];
            $filename = basename($videoFile);

            \Log::info('YouTube video downloaded successfully', [
                'project_id' => $projectId,
                'filename' => $filename,
                'file_size' => filesize($videoFile)
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Tải video thành công',
                'filename' => $filename,
                'path' => "public/projects/{$projectId}/video/{$filename}",
                'url' => Storage::url("public/projects/{$projectId}/video/{$filename}"),
                'size' => filesize($videoFile)
            ]);
        } catch (\Exception $e) {
            \Log::error('Download YouTube video error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
}

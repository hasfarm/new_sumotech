<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\DubSyncController;
use App\Http\Controllers\CoquiTtsController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\YouTubeChannelController;
use App\Http\Controllers\YoutubeChannelContentController;
use App\Http\Controllers\ApiUsageController;
use App\Http\Controllers\AudioBookController;
use App\Http\Controllers\AudioBookChapterController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\App;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', function () {
    return view('welcome');
});

Route::get('/lang/{locale}', function (string $locale) {
    if (!in_array($locale, ['en', 'vi'])) {
        abort(400);
    }
    session(['app_locale' => $locale]);
    App::setLocale($locale);
    return redirect()->back();
})->name('lang.switch');

Route::get('/dashboard', function () {
    return view('dashboard');
})->name('dashboard');

Route::group([], function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

// Admin Routes - Temporarily accessible without auth
Route::prefix('admin')->name('admin.')->group(function () {
    Route::get('/', function () {
        return redirect()->route('admin.dashboard');
    })->name('home');

    Route::get('/dashboard', [AdminController::class, 'dashboard'])->name('dashboard');
    Route::get('/users', [AdminController::class, 'users'])->name('users');
    Route::get('/settings', [AdminController::class, 'settings'])->name('settings');
    Route::get('/reports', [AdminController::class, 'reports'])->name('reports');
});

// DubSync Routes - Temporarily accessible without auth
Route::prefix('dubsync')->name('dubsync.')->group(function () {
    Route::get('/', [DubSyncController::class, 'index'])->name('index');
    Route::post('/process-youtube', [DubSyncController::class, 'processYouTube'])->name('process.youtube');
    Route::post('/check-ai-progress', [DubSyncController::class, 'checkAIProgress'])->name('check.ai.progress');
    Route::get('/projects/{projectId}', [DubSyncController::class, 'show'])->name('show');
    Route::post('/projects/{projectId}/translate', [DubSyncController::class, 'translate'])->name('translate');
    Route::post('/projects/{projectId}/fix-segments', [DubSyncController::class, 'fixSelectedSegments'])->name('fix.segments');
    Route::post('/projects/{projectId}/save-segments', [DubSyncController::class, 'saveSegments'])->name('save.segments');
    Route::post('/projects/{projectId}/generate-tts', [DubSyncController::class, 'generateTTS'])->name('generate.tts');
    Route::post('/projects/{projectId}/generate-segment-tts', [DubSyncController::class, 'generateSegmentTTS'])->name('generate.segment.tts');
    Route::post('/projects/{projectId}/tts-provider', [DubSyncController::class, 'updateTtsProvider'])->name('update.tts.provider');
    Route::post('/projects/{projectId}/audio-mode', [DubSyncController::class, 'updateAudioMode'])->name('update.audio.mode');
    Route::post('/projects/{projectId}/speakers-config', [DubSyncController::class, 'updateSpeakersConfig'])->name('update.speakers.config');
    Route::post('/projects/{projectId}/style-instruction', [DubSyncController::class, 'updateStyleInstruction'])->name('update.style.instruction');
    Route::post('/projects/{projectId}/align-timing', [DubSyncController::class, 'alignTiming'])->name('align.timing');
    Route::post('/projects/{projectId}/normalize-times', [DubSyncController::class, 'normalizeSegmentTimes'])->name('normalize.times');
    Route::post('/projects/{projectId}/merge-audio', [DubSyncController::class, 'mergeAudio'])->name('merge.audio');
    Route::post('/projects/{projectId}/export', [DubSyncController::class, 'export'])->name('export');
    Route::post('/projects/{projectId}/delete-segment-audios', [DubSyncController::class, 'deleteSegmentAudios'])->name('delete.segment.audios');
    Route::post('/projects/{projectId}/reset-to-tts-generation', [DubSyncController::class, 'resetToTtsGeneration'])->name('reset.to.tts.generation');
    Route::post('/projects/{projectId}/save-full-transcript', [DubSyncController::class, 'saveFullTranscript'])->name('save.full.transcript');
    Route::get('/projects/{projectId}/get-full-transcript', [DubSyncController::class, 'getFullTranscript'])->name('get.full.transcript');
    Route::post('/projects/{projectId}/generate-full-transcript-tts', [DubSyncController::class, 'generateFullTranscriptTTS'])->name('generate.full.transcript.tts');
    Route::get('/projects/{projectId}/full-transcript-audio-list', [DubSyncController::class, 'getFullTranscriptAudioList'])->name('get.full.transcript.audio.list');
    Route::post('/projects/{projectId}/merge-full-transcript-audio', [DubSyncController::class, 'mergeFullTranscriptAudio'])->name('merge.full.transcript.audio');
    Route::post('/projects/{projectId}/delete-full-transcript-audio', [DubSyncController::class, 'deleteFullTranscriptAudio'])->name('delete.full.transcript.audio');
    Route::post('/projects/{projectId}/delete-all-full-transcript-audio', [DubSyncController::class, 'deleteAllFullTranscriptAudio'])->name('delete.all.full.transcript.audio');
    Route::post('/projects/{projectId}/align-full-transcript-duration', [DubSyncController::class, 'alignFullTranscriptDuration'])->name('align.full.transcript.duration');
    Route::post('/projects/{projectId}/download-youtube-video', [DubSyncController::class, 'downloadYoutubeVideo'])->name('download.youtube.video');
    Route::get('/projects/{projectId}/download/{fileType}', [DubSyncController::class, 'download'])->name('download');
    Route::get('/projects/{projectId}/segments/{segmentIndex}/audio-versions', [DubSyncController::class, 'getSegmentAudioVersions'])->name('segment.audio.versions');
    Route::post('/projects/{projectId}/segments/{segmentIndex}/regenerate', [DubSyncController::class, 'regenerateSegment'])->name('regenerate.segment');
    Route::delete('/projects/{projectId}', [DubSyncController::class, 'destroy'])->name('destroy');
});

// API Usage Tracking Routes
Route::prefix('api-usage')->name('api-usage.')->middleware('auth')->group(function () {
    Route::get('/', [ApiUsageController::class, 'index'])->name('index');
    Route::get('/statistics', [ApiUsageController::class, 'statistics'])->name('statistics');
    Route::get('/{apiUsage}', [ApiUsageController::class, 'show'])->name('show');
    Route::delete('/{apiUsage}', [ApiUsageController::class, 'destroy'])->name('destroy');
});

// Get available TTS voices (must be accessible without auth prefix but with middleware)
Route::get('/get-available-voices', [DubSyncController::class, 'getAvailableVoices'])->name('get.available.voices');

// Preview voice (must be accessible without auth prefix but with middleware)
Route::post('/preview-voice', [DubSyncController::class, 'previewVoice'])->name('preview.voice');

// Coqui TTS Test Page (local CPU)
Route::get('/coqui-tts', [CoquiTtsController::class, 'index'])->name('coqui.tts.index');
Route::post('/coqui-tts/generate', [CoquiTtsController::class, 'generate'])->name('coqui.tts.generate');

// AudioBooks CRUD Routes
Route::middleware('auth')->prefix('audiobooks')->name('audiobooks.')->group(function () {
    Route::get('/', [AudioBookController::class, 'index'])->name('index');
    Route::get('/create', [AudioBookController::class, 'create'])->name('create');
    Route::post('/', [AudioBookController::class, 'store'])->name('store');
    Route::get('{audioBook}', [AudioBookController::class, 'show'])->name('show');
    Route::get('{audioBook}/edit', [AudioBookController::class, 'edit'])->name('edit');
    Route::put('{audioBook}', [AudioBookController::class, 'update'])->name('update');
    Route::delete('{audioBook}', [AudioBookController::class, 'destroy'])->name('destroy');
    Route::post('{audioBook}/update-tts-settings', [AudioBookController::class, 'updateTtsSettings'])->name('update.tts.settings');
    Route::post('{audioBook}/upload-music', [AudioBookController::class, 'uploadMusic'])->name('upload.music');
    Route::post('{audioBook}/delete-music', [AudioBookController::class, 'deleteMusic'])->name('delete.music');
    Route::post('{audioBook}/update-music-settings', [AudioBookController::class, 'updateMusicSettings'])->name('update.music.settings');
    Route::post('{audioBook}/update-wave-settings', [AudioBookController::class, 'updateWaveSettings'])->name('update.wave.settings');
    Route::post('{audioBook}/update-speaker', [AudioBookController::class, 'updateSpeaker'])->name('update.speaker');
    Route::post('{audioBook}/update-description', [AudioBookController::class, 'updateDescription'])->name('update.description');
    Route::post('{audioBook}/rewrite-description', [AudioBookController::class, 'rewriteDescription'])->name('rewrite.description');
    Route::post('{audioBook}/generate-description-audio', [AudioBookController::class, 'generateDescriptionAudio'])->name('generate.description.audio');
    Route::post('{audioBook}/generate-description-video', [AudioBookController::class, 'generateDescriptionVideo'])->name('generate.description.video');
    Route::delete('{audioBook}/delete-description-audio', [AudioBookController::class, 'deleteDescriptionAudio'])->name('delete.description.audio');
    Route::delete('{audioBook}/delete-description-video', [AudioBookController::class, 'deleteDescriptionVideo'])->name('delete.description.video');

    // YouTube Media Generation Routes (AI Image/Video)
    Route::get('{audioBook}/media', [AudioBookController::class, 'getMedia'])->name('media.index');
    Route::post('{audioBook}/media/generate-thumbnail', [AudioBookController::class, 'generateThumbnail'])->name('media.generate.thumbnail');
    Route::post('{audioBook}/media/add-text-overlay', [AudioBookController::class, 'addTextOverlay'])->name('media.add.text.overlay');
    Route::post('{audioBook}/media/generate-scenes', [AudioBookController::class, 'generateVideoScenes'])->name('media.generate.scenes');
    Route::post('{audioBook}/media/analyze-scenes', [AudioBookController::class, 'analyzeScenes'])->name('media.analyze.scenes');
    Route::post('{audioBook}/media/generate-scene-image', [AudioBookController::class, 'generateSceneImage'])->name('media.generate.scene.image');
    Route::post('{audioBook}/media/generate-scene-slideshow', [AudioBookController::class, 'generateSceneSlideshowVideo'])->name('media.generate.scene.slideshow');
    Route::delete('{audioBook}/media/delete', [AudioBookController::class, 'deleteMedia'])->name('media.delete');

    // Description Video Pipeline Routes (Chunked)
    Route::post('{audioBook}/desc-video/chunk', [AudioBookController::class, 'chunkDescription'])->name('desc.video.chunk');
    Route::get('{audioBook}/desc-video/chunks', [AudioBookController::class, 'getChunks'])->name('desc.video.chunks');
    Route::post('{audioBook}/desc-video/generate-image', [AudioBookController::class, 'generateChunkImage'])->name('desc.video.generate.image');
    Route::post('{audioBook}/desc-video/generate-tts', [AudioBookController::class, 'generateChunkTts'])->name('desc.video.generate.tts');
    Route::post('{audioBook}/desc-video/generate-srt', [AudioBookController::class, 'generateChunkSrt'])->name('desc.video.generate.srt');
    Route::post('{audioBook}/desc-video/compose', [AudioBookController::class, 'composeDescriptionVideo'])->name('desc.video.compose');

    // Kling AI Animation Routes
    Route::get('{audioBook}/animations', [AudioBookController::class, 'getAnimations'])->name('animations.index');
    Route::post('{audioBook}/animations/create', [AudioBookController::class, 'createAnimation'])->name('animations.create');
    Route::post('{audioBook}/animations/start-task', [AudioBookController::class, 'startAnimationTask'])->name('animations.start');
    Route::post('{audioBook}/animations/check-status', [AudioBookController::class, 'checkAnimationStatus'])->name('animations.status');

    // Chapter Cover Generation Routes (FFmpeg text overlay)
    Route::get('{audioBook}/chapters-for-cover', [AudioBookController::class, 'getChaptersForCover'])->name('chapters.for.cover');
    Route::post('{audioBook}/generate-chapter-covers', [AudioBookController::class, 'generateChapterCovers'])->name('generate.chapter.covers');

    // Chapter Video Generation Route (FFmpeg - combine audio + image to MP4)
    Route::post('{audioBook}/generate-chapter-video/{chapter}', [AudioBookController::class, 'generateChapterVideo'])->name('generate.chapter.video');

    // AudioBook Chapters Routes
    Route::post('{audioBook}/chapters', [AudioBookChapterController::class, 'store'])->name('chapters.store');
    Route::get('{audioBook}/chapters/create', [AudioBookChapterController::class, 'create'])->name('chapters.create');
    Route::get('{audioBook}/chapters/{chapter}/edit', [AudioBookChapterController::class, 'edit'])->name('chapters.edit');
    Route::put('{audioBook}/chapters/{chapter}', [AudioBookChapterController::class, 'update'])->name('chapters.update');
    Route::delete('{audioBook}/chapters/{chapter}', [AudioBookChapterController::class, 'destroy'])->name('chapters.destroy');
    Route::post('{audioBook}/chapters/{chapter}/generate-tts', [AudioBookChapterController::class, 'generateTts'])->name('chapters.generate-tts');
    Route::post('{audioBook}/chapters/{chapter}/generate-tts-chunks', [AudioBookChapterController::class, 'generateTtsChunks'])->name('chapters.generate-tts-chunks');

    // New chunk-by-chunk TTS generation endpoints
    Route::post('{audioBook}/chapters/{chapter}/initialize-chunks', [AudioBookChapterController::class, 'initializeChunks'])->name('chapters.initialize-chunks');
    Route::post('{audioBook}/chapters/{chapter}/chunks/{chunk}/generate', [AudioBookChapterController::class, 'generateSingleChunk'])->name('chapters.chunks.generate');
    Route::delete('{audioBook}/chapters/{chapter}/chunks/{chunk}/delete-audio', [AudioBookChapterController::class, 'deleteChunkAudio'])->name('chapters.chunks.delete-audio');
    Route::post('{audioBook}/chapters/{chapter}/merge-audio', [AudioBookChapterController::class, 'mergeChapterAudioEndpoint'])->name('chapters.merge-audio');
    Route::delete('{audioBook}/chapters/{chapter}/delete-audio', [AudioBookChapterController::class, 'deleteAudio'])->name('chapters.delete-audio');

    // Scrape chapters from book URL
    Route::post('scrape-chapters', [AudioBookController::class, 'scrapeChapters'])->name('scrape.chapters');

    // Auto Publish to YouTube Routes
    Route::get('{audioBook}/publish/data', [AudioBookController::class, 'getPublishData'])->name('publish.data');
    Route::post('{audioBook}/publish/generate-meta', [AudioBookController::class, 'generateVideoMeta'])->name('publish.generate.meta');
    Route::post('{audioBook}/publish/generate-playlist-meta', [AudioBookController::class, 'generatePlaylistMeta'])->name('publish.generate.playlist.meta');
    Route::post('{audioBook}/publish/upload', [AudioBookController::class, 'uploadToYoutube'])->name('publish.upload');
    Route::post('{audioBook}/publish/create-playlist', [AudioBookController::class, 'createPlaylistAndUpload'])->name('publish.create.playlist');
});

// Projects CRUD Routes
Route::middleware('auth')->prefix('projects')->name('projects.')->group(function () {
    Route::get('/', [ProjectController::class, 'index'])->name('index');
    Route::get('/create', [ProjectController::class, 'create'])->name('create');
    Route::post('/', [ProjectController::class, 'store'])->name('store');
    Route::post('/bulk-destroy', [ProjectController::class, 'bulkDestroy'])->name('bulk.destroy');
    Route::post('{project}/get-transcript', [DubSyncController::class, 'getTranscriptForProject'])
        ->name('get.transcript');
    Route::post('{project}/get-transcript-async', [DubSyncController::class, 'getTranscriptForProjectAsync'])
        ->name('get.transcript.async');
    Route::get('{project}', [ProjectController::class, 'show'])->name('show');
    Route::get('{project}/edit', [ProjectController::class, 'edit'])->name('edit');
    Route::put('{project}', [ProjectController::class, 'update'])->name('update');
    Route::delete('{project}', [ProjectController::class, 'destroy'])->name('destroy');
});

// YouTube Channel reference lookup
Route::middleware('auth')->post('/youtube/channel-videos', [DubSyncController::class, 'fetchChannelVideos'])
    ->name('youtube.channel.videos');

// YouTube OAuth callback — must be before CRUD routes so 'oauth' isn't matched as {youtubeChannel}
Route::middleware('auth')->get('youtube-channels/oauth/callback', [YouTubeChannelController::class, 'oauthCallback'])
    ->name('youtube-channels.oauth.callback');

// YouTube Channels CRUD Routes
Route::middleware('auth')->prefix('youtube-channels')->name('youtube-channels.')->group(function () {
    Route::get('/', [YouTubeChannelController::class, 'index'])->name('index');
    Route::get('/create', [YouTubeChannelController::class, 'create'])->name('create');
    Route::post('/', [YouTubeChannelController::class, 'store'])->name('store');
    Route::get('{youtubeChannel}', [YouTubeChannelController::class, 'show'])->name('show');
    Route::get('{youtubeChannel}/edit', [YouTubeChannelController::class, 'edit'])->name('edit');
    Route::put('{youtubeChannel}', [YouTubeChannelController::class, 'update'])->name('update');
    Route::delete('{youtubeChannel}', [YouTubeChannelController::class, 'destroy'])->name('destroy');

    Route::post('{youtubeChannel}/references', [YouTubeChannelController::class, 'storeReference'])
        ->name('references.store');
    Route::delete('{youtubeChannel}/references/{reference}', [YouTubeChannelController::class, 'destroyReference'])
        ->name('references.destroy');

    Route::post('{youtubeChannel}/fetch-videos', [YouTubeChannelController::class, 'fetchReferenceVideos'])
        ->name('fetch.videos');

    Route::match(['post', 'delete'], '{youtubeChannel}/projects/bulk-destroy', [YouTubeChannelController::class, 'bulkDestroyProjects'])
        ->name('projects.bulk.destroy');

    Route::prefix('{youtubeChannel}/contents')->name('contents.')->group(function () {
        Route::get('/', [YoutubeChannelContentController::class, 'index'])->name('index');
        Route::get('/create', [YoutubeChannelContentController::class, 'create'])->name('create');
        Route::post('/', [YoutubeChannelContentController::class, 'store'])->name('store');
        Route::get('{content}', [YoutubeChannelContentController::class, 'show'])->name('show');
        Route::get('{content}/edit', [YoutubeChannelContentController::class, 'edit'])->name('edit');
        Route::put('{content}', [YoutubeChannelContentController::class, 'update'])->name('update');
        Route::delete('{content}', [YoutubeChannelContentController::class, 'destroy'])->name('destroy');
    });

    // Speaker (MC) Management Routes
    Route::prefix('{youtubeChannel}/speakers')->name('speakers.')->group(function () {
        Route::get('/', [YouTubeChannelController::class, 'getSpeakers'])->name('index');
        Route::post('/', [YouTubeChannelController::class, 'storeSpeaker'])->name('store');
        Route::put('{speaker}', [YouTubeChannelController::class, 'updateSpeaker'])->name('update');
        Route::delete('{speaker}', [YouTubeChannelController::class, 'deleteSpeaker'])->name('destroy');
        Route::post('{speaker}/toggle-status', [YouTubeChannelController::class, 'toggleSpeakerStatus'])->name('toggle');
        Route::post('{speaker}/delete-image', [YouTubeChannelController::class, 'deleteSpeakerImage'])->name('delete.image');
    });

    // YouTube OAuth2 Routes
    Route::get('{youtubeChannel}/oauth/connect', [YouTubeChannelController::class, 'oauthRedirect'])->name('oauth.connect');
    Route::post('{youtubeChannel}/oauth/disconnect', [YouTubeChannelController::class, 'oauthDisconnect'])->name('oauth.disconnect');
    Route::get('{youtubeChannel}/oauth/status', [YouTubeChannelController::class, 'oauthStatus'])->name('oauth.status');
});

// Test route for YouTube transcript
Route::get('/test-transcript/{videoId}', function ($videoId) {
    $service = new \App\Services\YouTubeTranscriptService();
    $transcript = $service->getTranscript($videoId);
    return response()->json([
        'total' => count($transcript),
        'first_3' => array_slice($transcript, 0, 3),
        'last_3' => array_slice($transcript, -3)
    ]);
});

require __DIR__ . '/auth.php';

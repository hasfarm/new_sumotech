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
use App\Http\Controllers\BookInsightStudioController;
use App\Http\Controllers\MediaCenterController;
use App\Http\Controllers\AutomationReportController;
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
    return redirect()->route('dashboard');
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

// Queue Monitor Page
Route::get('/queue-monitor', function () {
    return view('queue.index');
})->middleware('auth')->name('queue.monitor');

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
    Route::get('/projects/{projectId}/generate-segment-tts-progress', [DubSyncController::class, 'getSegmentTtsBatchProgress'])->name('generate.segment.tts.progress');
    Route::post('/projects/{projectId}/tts-provider', [DubSyncController::class, 'updateTtsProvider'])->name('update.tts.provider');
    Route::post('/projects/{projectId}/audio-mode', [DubSyncController::class, 'updateAudioMode'])->name('update.audio.mode');
    Route::post('/projects/{projectId}/speakers-config', [DubSyncController::class, 'updateSpeakersConfig'])->name('update.speakers.config');
    Route::post('/projects/{projectId}/title-vi', [DubSyncController::class, 'updateVietnameseTitle'])->name('update.title.vi');
    Route::post('/projects/{projectId}/style-instruction', [DubSyncController::class, 'updateStyleInstruction'])->name('update.style.instruction');
    Route::post('/projects/{projectId}/align-timing', [DubSyncController::class, 'alignTiming'])->name('align.timing');
    Route::post('/projects/{projectId}/normalize-times', [DubSyncController::class, 'normalizeSegmentTimes'])->name('normalize.times');
    Route::post('/projects/{projectId}/merge-audio', [DubSyncController::class, 'mergeAudio'])->name('merge.audio');
    Route::post('/projects/{projectId}/change-status', [DubSyncController::class, 'changeStatus'])->name('change.status');
    Route::post('/projects/{projectId}/export', [DubSyncController::class, 'export'])->name('export');
    Route::post('/projects/{projectId}/delete-segment-audios', [DubSyncController::class, 'deleteSegmentAudios'])->name('delete.segment.audios');
    Route::post('/projects/{projectId}/reset-to-tts-generation', [DubSyncController::class, 'resetToTtsGeneration'])->name('reset.to.tts.generation');
    Route::post('/projects/{projectId}/save-full-transcript', [DubSyncController::class, 'saveFullTranscript'])->name('save.full.transcript');
    Route::post('/projects/{projectId}/rewrite-full-transcript', [DubSyncController::class, 'rewriteFullTranscript'])->name('rewrite.full.transcript');
    Route::get('/projects/{projectId}/get-full-transcript', [DubSyncController::class, 'getFullTranscript'])->name('get.full.transcript');
    Route::post('/projects/{projectId}/generate-full-transcript-tts', [DubSyncController::class, 'generateFullTranscriptTTS'])->name('generate.full.transcript.tts');
    Route::get('/projects/{projectId}/full-transcript-audio-list', [DubSyncController::class, 'getFullTranscriptAudioList'])->name('get.full.transcript.audio.list');
    Route::post('/projects/{projectId}/merge-full-transcript-audio', [DubSyncController::class, 'mergeFullTranscriptAudio'])->name('merge.full.transcript.audio');
    Route::post('/projects/{projectId}/delete-full-transcript-audio', [DubSyncController::class, 'deleteFullTranscriptAudio'])->name('delete.full.transcript.audio');
    Route::post('/projects/{projectId}/delete-all-full-transcript-audio', [DubSyncController::class, 'deleteAllFullTranscriptAudio'])->name('delete.all.full.transcript.audio');
    Route::post('/projects/{projectId}/align-full-transcript-duration', [DubSyncController::class, 'alignFullTranscriptDuration'])->name('align.full.transcript.duration');
    Route::post('/projects/{projectId}/download-youtube-video', [DubSyncController::class, 'downloadYoutubeVideo'])->name('download.youtube.video');
    Route::get('/projects/{projectId}/download-youtube-video-progress', [DubSyncController::class, 'getDownloadYoutubeVideoProgress'])->name('download.youtube.video.progress');
    Route::post('/projects/{projectId}/generate-thumbnail', [DubSyncController::class, 'generateThumbnail'])->name('generate.thumbnail');
    Route::get('/projects/{projectId}/download/{fileType}', [DubSyncController::class, 'download'])->name('download');
    Route::get('/projects/{projectId}/segments/{segmentIndex}/audio-versions', [DubSyncController::class, 'getSegmentAudioVersions'])->name('segment.audio.versions');
    Route::post('/projects/{projectId}/segments/{segmentIndex}/regenerate', [DubSyncController::class, 'regenerateSegment'])->name('regenerate.segment');
    Route::delete('/projects/{projectId}', [DubSyncController::class, 'destroy'])->name('destroy');
    Route::get('/queue-status', [DubSyncController::class, 'queueStatus'])->name('queue.status');
    Route::post('/queue-clear', [DubSyncController::class, 'queueClear'])->name('queue.clear');
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

// Automation Reports Routes
Route::middleware('auth')->prefix('automation-reports')->name('automation-reports.')->group(function () {
    Route::get('/', [AutomationReportController::class, 'index'])->name('index');
});

Route::middleware('auth')->prefix('media-center')->name('media-center.')->group(function () {
    Route::get('/', [MediaCenterController::class, 'index'])->name('index');
    Route::post('/projects', [MediaCenterController::class, 'store'])->name('projects.store');
    Route::put('/projects/{project}', [MediaCenterController::class, 'updateProject'])->name('projects.update');
    Route::delete('/projects/{project}', [MediaCenterController::class, 'destroy'])->name('projects.destroy');
    Route::post('/projects/{project}/analyze', [MediaCenterController::class, 'analyze'])->name('projects.analyze');
    Route::post('/projects/{project}/regenerate-weak-prompts', [MediaCenterController::class, 'regenerateWeakPrompts'])->name('projects.regenerate.weak.prompts');
    Route::post('/projects/{project}/cleanup-prompts', [MediaCenterController::class, 'cleanupProjectPrompts'])->name('projects.cleanup.prompts');
    Route::get('/projects/{project}/analyze/progress', [MediaCenterController::class, 'analyzeProgress'])->name('projects.analyze.progress');
    Route::get('/queue-health', [MediaCenterController::class, 'queueHealth'])->name('queue.health');
    Route::put('/projects/{project}/characters', [MediaCenterController::class, 'updateCharacterInfo'])->name('projects.characters.update');
    Route::post('/projects/{project}/translate-main-character-profile', [MediaCenterController::class, 'translateMainCharacterProfile'])->name('projects.main-character.profile.translate');
    Route::post('/projects/{project}/rewrite-main-character-profile-bilingual', [MediaCenterController::class, 'rewriteMainCharacterProfileBilingual'])->name('projects.main-character.profile.rewrite.bilingual');
    Route::post('/projects/{project}/generate-main-character-references', [MediaCenterController::class, 'generateMainCharacterReferences'])->name('projects.main-character.references.generate');
    Route::post('/projects/{project}/upload-main-character-references', [MediaCenterController::class, 'uploadMainCharacterReferences'])->name('projects.main-character.references.upload');
    Route::delete('/projects/{project}/main-character-references', [MediaCenterController::class, 'deleteMainCharacterReference'])->name('projects.main-character.references.delete');
    Route::put('/projects/{project}/world-profile', [MediaCenterController::class, 'updateWorldProfile'])->name('projects.world.update');
    Route::put('/projects/{project}/media-settings', [MediaCenterController::class, 'updateMediaSettings'])->name('projects.media.settings.update');
    Route::post('/projects/{project}/generate-image-style-previews', [MediaCenterController::class, 'generateImageStylePreviews'])->name('projects.image.style.previews.generate');
    Route::delete('/projects/{project}/image-style-previews', [MediaCenterController::class, 'deleteImageStylePreview'])->name('projects.image.style.previews.delete');
    Route::put('/projects/{project}/sentences/{sentence}', [MediaCenterController::class, 'updateSentence'])->name('projects.sentences.update');
    Route::post('/projects/{project}/sentences/{sentence}/generate-tts', [MediaCenterController::class, 'generateSentenceTts'])->name('projects.sentences.generate.tts');
    Route::post('/projects/{project}/sentences/{sentence}/generate-image', [MediaCenterController::class, 'generateSentenceImage'])->name('projects.sentences.generate.image');
    Route::post('/projects/{project}/sentences/{sentence}/generate-animation', [MediaCenterController::class, 'generateSentenceAnimation'])->name('projects.sentences.generate.animation');
    Route::get('/projects/{project}/sentences/{sentence}/generation-status', [MediaCenterController::class, 'sentenceGenerationStatus'])->name('projects.sentences.generation.status');
    Route::post('/projects/{project}/sentences/{sentence}/suggest-animation-plan', [MediaCenterController::class, 'suggestSentenceAnimationPlan'])->name('projects.sentences.suggest.animation.plan');
    Route::post('/projects/{project}/sentences/{sentence}/translate-vie', [MediaCenterController::class, 'translateSentenceField'])->name('projects.sentences.translate.vie');
    Route::post('/projects/{project}/sentences/{sentence}/rewrite-bilingual', [MediaCenterController::class, 'rewriteSentenceFieldBilingual'])->name('projects.sentences.rewrite.bilingual');
    Route::delete('/projects/{project}/sentences/{sentence}/images', [MediaCenterController::class, 'deleteSentenceImage'])->name('projects.sentences.images.delete');
    Route::delete('/projects/{project}/sentences/{sentence}/animations', [MediaCenterController::class, 'deleteSentenceAnimation'])->name('projects.sentences.animations.delete');
    Route::post('/projects/{project}/download-all-assets', [MediaCenterController::class, 'downloadAllSentenceAssets'])->name('projects.download.all.assets');
    Route::post('/projects/{project}/generate-all', [MediaCenterController::class, 'generateAll'])->name('projects.generate.all');
});

// AudioBooks CRUD Routes
Route::middleware('auth')->prefix('audiobooks')->name('audiobooks.')->group(function () {
    Route::get('/book-insight-studio', [BookInsightStudioController::class, 'index'])->name('insight.studio');
    Route::post('/book-insight-studio/generate-character-story', [BookInsightStudioController::class, 'generateCharacterStory'])->name('insight.studio.generate.character');
    Route::get('/', [AudioBookController::class, 'index'])->name('index');
    Route::get('/create', [AudioBookController::class, 'create'])->name('create');
    Route::post('/', [AudioBookController::class, 'store'])->name('store');
    Route::get('{audioBook}', [AudioBookController::class, 'show'])->name('show');
    Route::get('{audioBook}/edit', [AudioBookController::class, 'edit'])->name('edit');
    Route::put('{audioBook}', [AudioBookController::class, 'update'])->name('update');
    Route::delete('{audioBook}', [AudioBookController::class, 'destroy'])->name('destroy');
    Route::post('{audioBook}/update-tts-settings', [AudioBookController::class, 'updateTtsSettings'])->name('update.tts.settings');
    Route::post('{audioBook}/find-replace', [AudioBookController::class, 'findReplace'])->name('find.replace');
    Route::post('{audioBook}/fix-leading-initial-space', [AudioBookController::class, 'fixLeadingInitialSpace'])->name('fix.leading.initial.space');
    Route::post('{audioBook}/scan-tts-vietnamese-issues', [AudioBookController::class, 'scanTtsVietnameseIssues'])->name('scan.tts.vietnamese.issues');
    Route::post('{audioBook}/chunk-and-queue-embeddings', [AudioBookController::class, 'chunkAndQueueEmbeddings'])->name('chunk.queue.embeddings');
    Route::get('{audioBook}/embedding-progress', [AudioBookController::class, 'getEmbeddingProgress'])->name('embedding.progress');
    Route::post('{audioBook}/upload-music', [AudioBookController::class, 'uploadMusic'])->name('upload.music');
    Route::post('{audioBook}/select-music-file', [AudioBookController::class, 'selectMusicFile'])->name('select.music.file');
    Route::post('{audioBook}/delete-music', [AudioBookController::class, 'deleteMusic'])->name('delete.music');
    Route::post('{audioBook}/update-music-settings', [AudioBookController::class, 'updateMusicSettings'])->name('update.music.settings');
    Route::post('{audioBook}/update-wave-settings', [AudioBookController::class, 'updateWaveSettings'])->name('update.wave.settings');
    Route::post('{audioBook}/update-speaker', [AudioBookController::class, 'updateSpeaker'])->name('update.speaker');
    Route::post('{audioBook}/update-description', [AudioBookController::class, 'updateDescription'])->name('update.description');
    Route::post('{audioBook}/rewrite-description', [AudioBookController::class, 'rewriteDescription'])->name('rewrite.description');
    Route::get('{audioBook}/export-word', [AudioBookController::class, 'exportWord'])->name('export.word');
    Route::post('{audioBook}/generate-description-audio', [AudioBookController::class, 'generateDescriptionAudio'])->name('generate.description.audio');
    Route::get('{audioBook}/description-audio-progress', [AudioBookController::class, 'getDescriptionAudioProgress'])->name('description.audio.progress');
    Route::post('{audioBook}/generate-description-video', [AudioBookController::class, 'generateDescriptionVideo'])->name('generate.description.video');
    Route::post('{audioBook}/generate-description-video-async', [AudioBookController::class, 'startDescriptionVideoJob'])->name('generate.description.video.async');
    Route::get('{audioBook}/description-video-progress', [AudioBookController::class, 'getDescriptionVideoProgress'])->name('description.video.progress');
    Route::delete('{audioBook}/delete-description-audio', [AudioBookController::class, 'deleteDescriptionAudio'])->name('delete.description.audio');
    Route::delete('{audioBook}/delete-description-video', [AudioBookController::class, 'deleteDescriptionVideo'])->name('delete.description.video');

    // Book Review Video (~15 min)
    Route::post('{audioBook}/review-video/start', [AudioBookController::class, 'startBookReviewVideoJob'])->name('review.video.start');
    Route::get('{audioBook}/review-video/progress', [AudioBookController::class, 'getBookReviewVideoProgress'])->name('review.video.progress');
    Route::delete('{audioBook}/review-video', [AudioBookController::class, 'deleteBookReviewVideo'])->name('review.video.delete');
    Route::get('{audioBook}/review-video/chunks', [AudioBookController::class, 'getReviewChunks'])->name('review.video.chunks');
    Route::put('{audioBook}/review-video/chunks/{index}/prompt', [AudioBookController::class, 'updateReviewChunkPrompt'])->name('review.video.chunks.prompt');
    Route::post('{audioBook}/review-video/chunks/{index}/regenerate-image', [AudioBookController::class, 'regenerateReviewChunkImage'])->name('review.video.chunks.regenerate');
    Route::post('{audioBook}/review-video/chunks/{index}/translate-prompt', [AudioBookController::class, 'translateReviewChunkPrompt'])->name('review.video.chunks.translate');
    Route::post('{audioBook}/review-video/chunks/{index}/split', [AudioBookController::class, 'splitReviewChunk'])->name('review.video.chunks.split');
    Route::post('{audioBook}/review-video/generate-assets', [AudioBookController::class, 'startReviewAssetsJob'])->name('review.video.generate.assets');
    Route::get('{audioBook}/review-video/assets-progress', [AudioBookController::class, 'getReviewAssetsProgress'])->name('review.video.assets.progress');
    Route::post('{audioBook}/review-video/translate-all', [AudioBookController::class, 'translateAllReviewPrompts'])->name('review.video.translate.all');
    Route::post('{audioBook}/review-video/open-studio', [AudioBookController::class, 'openReviewScriptStudio'])->name('review.video.open.studio');

    // Full Book Video (merge all chapters + description into one video)
    Route::post('{audioBook}/generate-fullbook-video-async', [AudioBookController::class, 'startFullBookVideoJob'])->name('generate.fullbook.video.async');
    Route::get('{audioBook}/fullbook-video-progress', [AudioBookController::class, 'getFullBookVideoProgress'])->name('fullbook.video.progress');
    Route::delete('{audioBook}/delete-fullbook-video', [AudioBookController::class, 'deleteFullBookVideo'])->name('delete.fullbook.video');

    // Video Segments (batch: gom chương tùy chọn → nhiều video)
    Route::get('{audioBook}/video-segments', [AudioBookController::class, 'getVideoSegments'])->name('video.segments.index');
    Route::post('{audioBook}/video-segments', [AudioBookController::class, 'saveVideoSegments'])->name('video.segments.save');
    Route::post('{audioBook}/video-segments/start', [AudioBookController::class, 'startBatchVideoGeneration'])->name('video.segments.start');
    Route::get('{audioBook}/video-segments/progress', [AudioBookController::class, 'getBatchVideoProgress'])->name('video.segments.progress');
    Route::delete('{audioBook}/video-segments/{segment}', [AudioBookController::class, 'deleteVideoSegment'])->name('video.segments.delete');

    // Short Video (AI shorts up to 60s, 9:16)
    Route::get('{audioBook}/short-videos', [AudioBookController::class, 'getShortVideos'])->name('short.videos.index');
    Route::post('{audioBook}/short-videos/manual', [AudioBookController::class, 'createManualShortVideo'])->name('short.videos.manual');
    Route::post('{audioBook}/short-videos/generate-plans', [AudioBookController::class, 'generateShortVideoPlans'])->name('short.videos.generate.plans');
    Route::post('{audioBook}/short-videos/generate-assets', [AudioBookController::class, 'generateShortVideoAssets'])->name('short.videos.generate.assets');
    Route::post('{audioBook}/short-videos/generate-tts', [AudioBookController::class, 'generateShortVideoTts'])->name('short.videos.generate.tts');
    Route::post('{audioBook}/short-videos/generate-images', [AudioBookController::class, 'generateShortVideoImages'])->name('short.videos.generate.images');
    Route::post('{audioBook}/short-videos/download-resources', [AudioBookController::class, 'downloadSelectedShortResources'])->name('short.videos.download.resources');
    Route::get('{audioBook}/short-videos/{index}/workspace', [AudioBookController::class, 'getShortVideoWorkspace'])->name('short.videos.workspace.show');
    Route::post('{audioBook}/short-videos/{index}/workspace/build', [AudioBookController::class, 'buildShortVideoWorkspace'])->name('short.videos.workspace.build');
    Route::put('{audioBook}/short-videos/{index}/workspace/shots/{shotIndex}', [AudioBookController::class, 'updateShortVideoShot'])->name('short.videos.workspace.shots.update');
    Route::post('{audioBook}/short-videos/{index}/workspace/generate-shot-tts', [AudioBookController::class, 'generateShortVideoShotTts'])->name('short.videos.workspace.generate.shot.tts');
    Route::post('{audioBook}/short-videos/{index}/workspace/generate-shot-images', [AudioBookController::class, 'generateShortVideoShotImages'])->name('short.videos.workspace.generate.shot.images');
    Route::post('{audioBook}/short-videos/{index}/workspace/kling/start', [AudioBookController::class, 'startShortVideoShotKling'])->name('short.videos.workspace.kling.start');
    Route::post('{audioBook}/short-videos/{index}/workspace/kling/poll', [AudioBookController::class, 'pollShortVideoShotKling'])->name('short.videos.workspace.kling.poll');
    Route::post('{audioBook}/short-videos/{index}/workspace/compose-auto', [AudioBookController::class, 'composeShortVideoFromShots'])->name('short.videos.workspace.compose.auto');
    Route::post('{audioBook}/short-videos/{index}/workspace/download-package', [AudioBookController::class, 'downloadShortVideoWorkspacePackage'])->name('short.videos.workspace.download.package');
    Route::post('{audioBook}/short-videos/{index}/generate-image-prompt', [AudioBookController::class, 'generateShortVideoImagePrompt'])->name('short.videos.generate.image.prompt');
    Route::put('{audioBook}/short-videos/{index}', [AudioBookController::class, 'updateShortVideo'])->name('short.videos.update');
    Route::delete('{audioBook}/short-videos/{index}', [AudioBookController::class, 'deleteShortVideo'])->name('short.videos.delete');

    // Clipping Routes
    Route::get('{audioBook}/clipping/videos', [AudioBookController::class, 'listClippingVideos'])->name('clipping.videos');
    Route::get('{audioBook}/clipping/background-audios', [AudioBookController::class, 'listClippingBackgroundAudios'])->name('clipping.background.audios');
    Route::get('{audioBook}/clipping/cta-animations', [AudioBookController::class, 'listClippingCtaAnimations'])->name('clipping.cta.animations');
    Route::get('{audioBook}/clipping/clips', [AudioBookController::class, 'listClips'])->name('clipping.clips');
    Route::post('{audioBook}/clipping/generate', [AudioBookController::class, 'generateClips'])->name('clipping.generate');
    Route::post('{audioBook}/clipping/{clipId}/settings', [AudioBookController::class, 'updateClipSettings'])->name('clipping.settings');
    Route::post('{audioBook}/clipping/{clipId}/generate-title', [AudioBookController::class, 'generateClipHookTitle'])->name('clipping.generate.title');
    Route::get('{audioBook}/clipping/{clipId}/generate-title-progress', [AudioBookController::class, 'getGenerateClipTitleProgress'])->name('clipping.generate.title.progress');
    Route::post('{audioBook}/clipping/{clipId}/generate-image', [AudioBookController::class, 'generateClipImage'])->name('clipping.generate.image');
    Route::get('{audioBook}/clipping/{clipId}/generate-image-progress', [AudioBookController::class, 'getGenerateClipImageProgress'])->name('clipping.generate.image.progress');
    Route::post('{audioBook}/clipping/{clipId}/animate-image', [AudioBookController::class, 'startClipImageSeedance'])->name('clipping.animate.image');
    Route::get('{audioBook}/clipping/{clipId}/animate-image-progress', [AudioBookController::class, 'getClipImageSeedanceProgress'])->name('clipping.animate.image.progress');
    Route::post('{audioBook}/clipping/{clipId}/compose', [AudioBookController::class, 'composeClip'])->name('clipping.compose');
    Route::get('{audioBook}/clipping/{clipId}/compose-progress', [AudioBookController::class, 'getComposeClipProgress'])->name('clipping.compose.progress');
    Route::delete('{audioBook}/clipping/{clipId}', [AudioBookController::class, 'deleteClip'])->name('clipping.delete');

    // YouTube Media Generation Routes (AI Image/Video)
    Route::get('{audioBook}/media', [AudioBookController::class, 'getMedia'])->name('media.index');
    Route::post('{audioBook}/media/upload', [AudioBookController::class, 'uploadMedia'])->name('media.upload');
    Route::post('{audioBook}/media/preview-thumbnail-prompt', [AudioBookController::class, 'previewThumbnailPrompt'])->name('media.preview.thumbnail.prompt');
    Route::post('{audioBook}/media/generate-thumbnail', [AudioBookController::class, 'generateThumbnail'])->name('media.generate.thumbnail');
    Route::get('{audioBook}/media/thumbnail-progress', [AudioBookController::class, 'getThumbnailProgress'])->name('media.thumbnail.progress');
    Route::post('{audioBook}/media/add-text-overlay', [AudioBookController::class, 'addTextOverlay'])->name('media.add.text.overlay');
    Route::post('{audioBook}/media/add-logo-overlay', [AudioBookController::class, 'addLogoOverlay'])->name('media.add.logo.overlay');
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
    Route::patch('{audioBook}/chapters/{chapter}/fix-paragraph', [AudioBookChapterController::class, 'fixParagraph'])->name('chapters.fix-paragraph');
    Route::post('{audioBook}/chapters/{chapter}/generate-tts', [AudioBookChapterController::class, 'generateTts'])->name('chapters.generate-tts');
    Route::post('{audioBook}/chapters/{chapter}/tts-preview', [AudioBookChapterController::class, 'ttsPreview'])->name('chapters.tts-preview');
    Route::post('{audioBook}/chapters/{chapter}/generate-tts-chunks', [AudioBookChapterController::class, 'generateTtsChunks'])->name('chapters.generate-tts-chunks');
    Route::post('{audioBook}/chapters/tts/start', [AudioBookChapterController::class, 'startTtsBatch'])->name('chapters.tts.start');
    Route::get('{audioBook}/chapters/tts/progress', [AudioBookChapterController::class, 'getTtsBatchProgress'])->name('chapters.tts.progress');

    // New chunk-by-chunk TTS generation endpoints
    Route::post('{audioBook}/chapters/{chapter}/initialize-chunks', [AudioBookChapterController::class, 'initializeChunks'])->name('chapters.initialize-chunks');
    Route::post('{audioBook}/chapters/{chapter}/chunks/{chunk}/generate', [AudioBookChapterController::class, 'generateSingleChunk'])->name('chapters.chunks.generate');
    Route::delete('{audioBook}/chapters/{chapter}/chunks/{chunk}/delete-audio', [AudioBookChapterController::class, 'deleteChunkAudio'])->name('chapters.chunks.delete-audio');
    Route::post('{audioBook}/chapters/{chapter}/merge-audio', [AudioBookChapterController::class, 'mergeChapterAudioEndpoint'])->name('chapters.merge-audio');
    Route::delete('{audioBook}/chapters/{chapter}/delete-audio', [AudioBookChapterController::class, 'deleteAudio'])->name('chapters.delete-audio');
    Route::post('{audioBook}/chapters/{chapter}/boost-audio', [AudioBookChapterController::class, 'boostChapterAudio'])->name('chapters.boost-audio');
    Route::post('{audioBook}/chapters/boost-audio/batch', [AudioBookChapterController::class, 'boostAudioBatch'])->name('chapters.boost-audio.batch');
    Route::get('{audioBook}/chapters/boost-audio/progress', [AudioBookChapterController::class, 'getBoostAudioProgress'])->name('chapters.boost-audio.progress');

    // Scrape chapters from book URL
    Route::post('scrape-chapters', [AudioBookController::class, 'scrapeChapters'])->name('scrape.chapters');

    // Fetch book metadata from URL (for create page auto-fill)
    Route::post('fetch-book-metadata', [AudioBookController::class, 'fetchBookMetadata'])->name('fetch.book.metadata');

    // Bulk create audiobooks from multiple URLs
    Route::post('bulk-create', [AudioBookController::class, 'bulkCreate'])->name('bulk.create');

    // Auto Publish to YouTube Routes
    Route::get('{audioBook}/publish/data', [AudioBookController::class, 'getPublishData'])->name('publish.data');
    Route::get('{audioBook}/publish/playlists', [AudioBookController::class, 'getYoutubePlaylists'])->name('publish.playlists');
    Route::post('{audioBook}/publish/generate-meta', [AudioBookController::class, 'generateVideoMeta'])->name('publish.generate.meta');
    Route::post('{audioBook}/publish/generate-playlist-meta', [AudioBookController::class, 'generatePlaylistMeta'])->name('publish.generate.playlist.meta');
    Route::post('{audioBook}/publish/upload', [AudioBookController::class, 'uploadToYoutube'])->name('publish.upload');
    Route::post('{audioBook}/publish/upload-async', [AudioBookController::class, 'uploadToYoutubeAsync'])->name('publish.upload.async');
    Route::post('{audioBook}/publish/create-playlist', [AudioBookController::class, 'createPlaylistAndUpload'])->name('publish.create.playlist');
    Route::post('{audioBook}/publish/create-playlist-async', [AudioBookController::class, 'createPlaylistAndUploadAsync'])->name('publish.create.playlist.async');
    Route::post('{audioBook}/publish/add-to-playlist', [AudioBookController::class, 'addToExistingPlaylist'])->name('publish.add.to.playlist');
    Route::post('{audioBook}/publish/add-to-playlist-async', [AudioBookController::class, 'addToExistingPlaylistAsync'])->name('publish.add.to.playlist.async');
    Route::get('{audioBook}/publish/progress', [AudioBookController::class, 'getPublishProgress'])->name('publish.progress');
    Route::post('{audioBook}/publish/save-meta', [AudioBookController::class, 'savePublishMeta'])->name('publish.save.meta');
    Route::get('{audioBook}/publish/history', [AudioBookController::class, 'getPublishHistory'])->name('publish.history');
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

    Route::get('{youtubeChannel}/thumbnail-proxy', [YouTubeChannelController::class, 'thumbnailProxy'])
        ->name('thumbnail.proxy');

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

// Art Style Presets (user-level custom presets)
Route::middleware('auth')->prefix('art-style-presets')->name('art-style-presets.')->group(function () {
    Route::get('/', [\App\Http\Controllers\ArtStylePresetController::class, 'index'])->name('index');
    Route::post('/', [\App\Http\Controllers\ArtStylePresetController::class, 'store'])->name('store');
    Route::put('{preset}', [\App\Http\Controllers\ArtStylePresetController::class, 'update'])->name('update');
    Route::delete('{preset}', [\App\Http\Controllers\ArtStylePresetController::class, 'destroy'])->name('destroy');
});

require __DIR__ . '/auth.php';

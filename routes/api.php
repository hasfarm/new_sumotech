<?php

use App\Http\Controllers\Api\VideoPipelineExtensionController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// Companion Chrome extension for the AI Video Production Pipeline — lets the user hand off
// a manually-downloaded Storyblocks clip straight into the right shot.
Route::middleware('auth:sanctum')->prefix('extension')->group(function () {
    Route::get('active-target', [VideoPipelineExtensionController::class, 'activeTarget']);
    Route::get('books', [VideoPipelineExtensionController::class, 'books']);
    Route::get('books/{audioBook}/shots', [VideoPipelineExtensionController::class, 'shots']);
    Route::post('shots/{shot}/ingest', [VideoPipelineExtensionController::class, 'ingest']);

    // Same manual hand-off pattern, for audio (SFX/ambience/music) candidates instead of video
    // clips — see AudioDirectionController::setActiveAudioTargetForScene()/ForShot() for how
    // the target gets cached before the user opens a Storyblocks search tab.
    Route::get('active-audio-target', [VideoPipelineExtensionController::class, 'activeAudioTarget']);
    Route::post('audio/ingest', [VideoPipelineExtensionController::class, 'ingestAudio']);
});

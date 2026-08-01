<?php

namespace App\Http\Controllers;

use App\Jobs\AnalyzeStoryDirectionJob;
use App\Jobs\RegenerateStaleSceneDirectionJob;
use App\Jobs\ValidateStoryContinuityJob;
use App\Models\AudioBook;
use App\Models\AudiobookContinuityIssue;
use App\Models\AudiobookVideoScene;
use App\Services\ContinuityValidationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * "Continuity" report/actions for the video pipeline — counts + issue list, selective
 * regenerate, accept-warning, and "revalidate stale" — see ContinuityValidationService for
 * the actual validation/regeneration mechanics; this controller is thin plumbing only.
 */
class ContinuityValidationController extends Controller
{
    /**
     * Report data for the Continuity panel: counts by scene/shot validation status +
     * the active (open/regenerating) issues, grouped by scene.
     */
    public function status(AudioBook $audioBook)
    {
        $scenes = $audioBook->videoScenes()->with('shots')->orderBy('scene_index')->get();

        $shotCounts = ['valid' => 0, 'warning' => 0, 'invalid' => 0, 'unvalidated' => 0, 'validating' => 0, 'failed' => 0];
        foreach ($scenes as $scene) {
            foreach ($scene->shots as $shot) {
                $key = $shot->validation_status ?: 'unvalidated';
                $shotCounts[$key] = ($shotCounts[$key] ?? 0) + 1;
            }
        }

        $unresolvedBindings = AudiobookContinuityIssue::where('audio_book_id', $audioBook->id)
            ->where('issue_type', AudiobookContinuityIssue::TYPE_UNRESOLVED_BINDING)
            ->whereIn('status', [AudiobookContinuityIssue::STATUS_OPEN, AudiobookContinuityIssue::STATUS_REGENERATING])
            ->count();

        $issues = AudiobookContinuityIssue::where('audio_book_id', $audioBook->id)
            ->whereIn('status', [AudiobookContinuityIssue::STATUS_OPEN, AudiobookContinuityIssue::STATUS_REGENERATING])
            ->with(['scene:id,scene_index,title', 'shot:id,shot_index'])
            ->orderByDesc('severity')
            ->get()
            ->groupBy('video_scene_id');

        return response()->json([
            'success' => true,
            'shot_counts' => $shotCounts,
            'unresolved_bindings' => $unresolvedBindings,
            'issues_by_scene' => $issues,
            'story_bible' => $this->storyBibleStatus($audioBook, $scenes),
        ]);
    }

    /**
     * Surfaces Story Bible / scene-binding ("AI Director") progress for the pipeline UI —
     * this generation step runs as a console command today with no other UI visibility, so
     * without this the page gives no signal on whether it ever finished or is still stale.
     */
    private function storyBibleStatus(AudioBook $audioBook, $scenes): array
    {
        $active = $audioBook->activeStoryBible()->first();
        $latest = $audioBook->storyBibles()->orderByDesc('bible_version')->first();

        $scenesBound = $scenes->filter(fn($s) => $s->story_bible_version_used !== null)->count();
        $scenesStale = $active
            ? $scenes->filter(fn($s) => (int) $s->story_bible_version_used !== (int) $active->bible_version)->count()
            : $scenes->count();

        return [
            'active_version' => $active?->bible_version,
            'active_status' => $active?->status,
            'latest_version' => $latest?->bible_version,
            'latest_status' => $latest?->status,
            'latest_error' => $latest && $latest->status === 'failed' ? $latest->error_message : null,
            'timelines_count' => $active ? $active->timelines()->count() : 0,
            'locations_count' => $active ? $active->locations()->count() : 0,
            'characters_count' => $active ? $active->characters()->count() : 0,
            'scenes_total' => $scenes->count(),
            'scenes_bound' => $scenesBound,
            'scenes_stale' => $scenesStale,
            'regenerate_stale_status' => $audioBook->videoPipeline?->story_bible_regenerate_stale_status,
        ];
    }

    /**
     * Trigger a fresh full validation pass over every scene in the book.
     */
    public function runValidation(AudioBook $audioBook)
    {
        ValidateStoryContinuityJob::dispatch($audioBook->id);

        return response()->json(['success' => true]);
    }

    /**
     * Re-assign scene bindings and regenerate only the shot chunks made stale by a Story
     * Bible version change — queued, since assignSceneContext() makes a real OpenAI call per
     * stale scene and could otherwise time out the request on a book with many stale scenes.
     */
    public function regenerateStale(AudioBook $audioBook)
    {
        $pipeline = $audioBook->videoPipeline;
        $current = $pipeline?->story_bible_regenerate_stale_status;

        if ($current && ($current['status'] ?? null) === 'running') {
            return response()->json(['success' => true, 'already_running' => true]);
        }

        RegenerateStaleSceneDirectionJob::dispatch($audioBook->id);

        return response()->json(['success' => true, 'already_running' => false]);
    }

    /**
     * Kicks off Phase 2 (Story Bible generation) for a book that doesn't have an active bible
     * yet — the "🧠 Tạo Story Bible" button shown in the AI Director panel when
     * story_bible.active_version is null. Needs Bước 1-3 (chapters summarized) to already
     * exist; AnalyzeStoryDirectionJob itself validates that and fails gracefully if not.
     */
    public function generateStoryBible(AudioBook $audioBook)
    {
        AnalyzeStoryDirectionJob::dispatch($audioBook->id);

        return response()->json(['success' => true]);
    }

    /**
     * Full active-bible content (timelines/locations/characters+phases) for the "AI
     * Director" detail modal — the pipeline page's summary badges only show counts
     * (Timelines: 1, Locations: 1, Characters: 10), this is what backs "which timeline?
     * where is location 1? who are the 10 characters?" when the user clicks one.
     */
    public function storyBibleDetails(AudioBook $audioBook)
    {
        $bible = $audioBook->activeStoryBible()->first();
        if (!$bible) {
            return response()->json(['success' => false, 'message' => 'Chưa có Story Bible active.'], 404);
        }

        return response()->json([
            'success' => true,
            'timelines' => $bible->timelines()->orderBy('chronological_order')->get(),
            'locations' => $bible->locations()->get(),
            'characters' => $bible->characters()->with(['phases' => fn($q) => $q->orderBy('chronological_order')])->get(),
        ]);
    }

    /**
     * Re-validate only scenes/shots whose continuity_validator_version is stale (i.e. an
     * older validator logic version) — never a blanket full re-run.
     */
    public function revalidateStale(AudioBook $audioBook)
    {
        $staleSceneIds = AudiobookVideoScene::where('audio_book_id', $audioBook->id)
            ->where(function ($q) {
                $q->whereNull('continuity_validator_version')
                    ->orWhere('continuity_validator_version', '!=', ContinuityValidationService::VALIDATOR_VERSION);
            })
            ->pluck('id')
            ->all();

        if (empty($staleSceneIds)) {
            return response()->json(['success' => true, 'message' => 'Không có scene nào dùng continuity_validator_version cũ.']);
        }

        ValidateStoryContinuityJob::dispatch($audioBook->id, $staleSceneIds);

        return response()->json(['success' => true, 'scenes_queued' => count($staleSceneIds)]);
    }

    /**
     * Regenerate only the shots backing the selected error+auto_regenerate issues, then
     * revalidate exactly those shots (+ immediate neighbors) — see
     * ContinuityValidationService::regenerateAndRevalidate().
     */
    public function regenerateSelected(Request $request, AudioBook $audioBook, ContinuityValidationService $service)
    {
        $issueIds = array_map('intval', (array) $request->input('issue_ids', []));
        if (empty($issueIds)) {
            return response()->json(['success' => false, 'message' => 'Chưa chọn issue nào để regenerate.'], 422);
        }

        $result = $service->regenerateAndRevalidate($audioBook, $issueIds);

        return response()->json(['success' => true] + $result);
    }

    /**
     * A human explicitly accepts a warning-tier issue — the ONLY way an issue's status ever
     * becomes 'accepted'; the system never assigns this automatically.
     */
    public function accept(AudioBook $audioBook, AudiobookContinuityIssue $issue)
    {
        if ($issue->audio_book_id !== $audioBook->id) {
            abort(404);
        }

        $issue->update([
            'status' => AudiobookContinuityIssue::STATUS_ACCEPTED,
            'accepted_at' => now(),
            'accepted_by' => Auth::id(),
        ]);

        if ($issue->video_shot_id) {
            $service = app(ContinuityValidationService::class);
            $service->recomputeShotValidationStatus($issue->shot);
        }
        if ($issue->video_scene_id) {
            app(ContinuityValidationService::class)->recomputeSceneContinuityStatus($issue->scene);
        }

        return response()->json(['success' => true]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateAudiobookSummaryClustersJob;
use App\Models\AudioBook;
use App\Models\AudiobookSummary;
use App\Services\AudiobookSummaryService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class AudioBookSummaryController extends Controller
{
    public function __construct(private readonly AudiobookSummaryService $service) {}

    /**
     * Current state of the summary (clusters / timelines / retells / outro) for the tabs UI.
     */
    public function status(AudioBook $audioBook)
    {
        return response()->json([
            'success' => true,
            'summary' => $audioBook->summary,
            'levels' => AudiobookSummaryService::levels(),
        ]);
    }

    /**
     * Pick which compression level (Bước 3) to generate with. Switching levels clears
     * any retell/outro already generated for the previous level, since they'd be at a
     * different target length and shouldn't be mixed together.
     */
    public function setLevel(Request $request, AudioBook $audioBook)
    {
        $level = (string) $request->input('level');
        $levels = AudiobookSummaryService::levels();

        if (!isset($levels[$level])) {
            return response()->json(['success' => false, 'message' => 'Mức tóm tắt không hợp lệ.'], 422);
        }

        $summary = $audioBook->summary;
        if (!$summary) {
            return response()->json(['success' => false, 'message' => 'Cần hoàn thành Bước 1 trước.'], 422);
        }

        $summary->update([
            'target_level' => $level,
            'retells' => [],
            'outro' => null,
        ]);

        return response()->json(['success' => true, 'summary' => $summary]);
    }

    /**
     * Snapshot the current retells/outro (Bước 3) into a named saved version, so multiple
     * compression levels can coexist without overwriting each other.
     */
    public function saveVersion(Request $request, AudioBook $audioBook)
    {
        $summary = $audioBook->summary;

        if (!$summary || empty($summary->retells)) {
            return response()->json(['success' => false, 'message' => 'Chưa có nội dung Bước 3 để lưu.'], 422);
        }

        $levels = AudiobookSummaryService::levels();
        $levelKey = $summary->target_level ?: 'detailed';
        $levelInfo = $levels[$levelKey] ?? $levels['detailed'];

        $label = trim((string) $request->input('label')) ?: $levelInfo['label'];

        $versions = $summary->versions ?? [];
        $versions[] = [
            'id' => (string) Str::uuid(),
            'label' => $label,
            'level' => $levelKey,
            'retells' => $summary->retells,
            'outro' => $summary->outro,
            'created_at' => now()->toIso8601String(),
        ];

        $summary->update(['versions' => $versions]);

        return response()->json(['success' => true, 'summary' => $summary]);
    }

    /**
     * Delete a previously saved version.
     */
    public function deleteVersion(AudioBook $audioBook, string $versionId)
    {
        $summary = $audioBook->summary;

        if (!$summary) {
            return response()->json(['success' => false, 'message' => 'Không có dữ liệu tóm tắt.'], 404);
        }

        $versions = (new Collection($summary->versions ?? []))
            ->reject(fn($v) => ($v['id'] ?? null) === $versionId)
            ->values()
            ->all();

        $summary->update(['versions' => $versions]);

        return response()->json(['success' => true, 'summary' => $summary]);
    }

    /**
     * Step 1: kick off clustering of the whole book transcript in the background.
     */
    public function start(AudioBook $audioBook)
    {
        $hasContent = $audioBook->chapters()
            ->whereNotNull('content')
            ->where('content', '!=', '')
            ->exists();

        if (!$hasContent) {
            return response()->json([
                'success' => false,
                'message' => 'Sách chưa có nội dung chương để tóm tắt.',
            ], 422);
        }

        $summary = AudiobookSummary::updateOrCreate(
            ['audio_book_id' => $audioBook->id],
            [
                'status' => 'queued',
                'clusters' => [],
                'timelines' => [],
                'retells' => [],
                'outro' => null,
                'total_batches' => null,
                'processed_batches' => 0,
                'error_message' => null,
            ]
        );

        GenerateAudiobookSummaryClustersJob::dispatch($audioBook->id);

        return response()->json(['success' => true, 'summary' => $summary]);
    }

    /**
     * Step 2: generate the timeline table for a single cluster.
     */
    public function generateTimeline(AudioBook $audioBook, int $clusterIndex)
    {
        $summary = $audioBook->summary;
        $cluster = $this->findCluster($summary, $clusterIndex);

        if (!$cluster) {
            return response()->json(['success' => false, 'message' => 'Không tìm thấy cụm sự kiện này.'], 404);
        }

        try {
            $rows = $this->service->generateTimelineForCluster($cluster);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }

        $timelines = $summary->timelines ?? [];
        $timelines[(string) $clusterIndex] = $rows;
        $summary->update(['timelines' => $timelines]);

        $maxIndex = collect($summary->clusters)->max('index');

        return response()->json([
            'success' => true,
            'cluster_index' => $clusterIndex,
            'timeline' => $rows,
            'has_next' => $clusterIndex < $maxIndex,
            'next_index' => $clusterIndex < $maxIndex ? $clusterIndex + 1 : null,
        ]);
    }

    /**
     * Step 3: retell the story for a single cluster, guided by its timeline.
     */
    public function generateRetell(AudioBook $audioBook, int $clusterIndex)
    {
        $summary = $audioBook->summary;
        $cluster = $this->findCluster($summary, $clusterIndex);

        if (!$cluster) {
            return response()->json(['success' => false, 'message' => 'Không tìm thấy cụm sự kiện này.'], 404);
        }

        $timelineRows = $summary->timelines[(string) $clusterIndex] ?? null;
        if (!$timelineRows) {
            return response()->json(['success' => false, 'message' => 'Cần tạo timeline (Bước 2) cho cụm này trước.'], 422);
        }

        $minIndex = collect($summary->clusters)->min('index');
        $maxIndex = collect($summary->clusters)->max('index');
        $isFirst = $clusterIndex === $minIndex;

        $levels = AudiobookSummaryService::levels();
        $levelKey = $summary->target_level ?: 'detailed';
        $levelInfo = $levels[$levelKey] ?? $levels['detailed'];

        try {
            $text = $this->service->generateRetellForCluster(
                $audioBook,
                $cluster,
                $timelineRows,
                $isFirst,
                $levelInfo['min'],
                $levelInfo['max']
            );
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }

        $retells = $summary->retells ?? [];
        $retells[(string) $clusterIndex] = $text;
        $summary->update(['retells' => $retells]);

        return response()->json([
            'success' => true,
            'cluster_index' => $clusterIndex,
            'retell' => $text,
            'has_next' => $clusterIndex < $maxIndex,
            'next_index' => $clusterIndex < $maxIndex ? $clusterIndex + 1 : null,
            'is_last' => $clusterIndex >= $maxIndex,
        ]);
    }

    /**
     * Optional closing outro, offered after the last cluster's retell is done.
     */
    public function generateOutro(AudioBook $audioBook)
    {
        $summary = $audioBook->summary;

        if (!$summary || empty($summary->retells)) {
            return response()->json(['success' => false, 'message' => 'Cần hoàn tất kể chuyện (Bước 3) trước khi tạo outro.'], 422);
        }

        $maxIndex = collect($summary->clusters)->max('index');
        $lastRetell = (string) ($summary->retells[(string) $maxIndex] ?? end($summary->retells) ?: '');

        try {
            $outro = $this->service->generateOutro($audioBook, $lastRetell);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }

        $summary->update(['outro' => $outro]);

        return response()->json(['success' => true, 'outro' => $outro]);
    }

    /**
     * Discard the current summary so the user can restart from scratch.
     */
    public function reset(AudioBook $audioBook)
    {
        $audioBook->summary()->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Clear only the retell/outro text (Bước 3) so the user can redo it, keeping
     * the clusters (Bước 1) and timelines (Bước 2) already generated.
     */
    public function resetRetells(AudioBook $audioBook)
    {
        $summary = $audioBook->summary;

        if (!$summary) {
            return response()->json(['success' => false, 'message' => 'Chưa có dữ liệu tóm tắt.'], 404);
        }

        $summary->update(['retells' => [], 'outro' => null]);

        return response()->json(['success' => true, 'summary' => $summary]);
    }

    /**
     * @return array{index:int,title:string,content:string}|null
     */
    private function findCluster(?AudiobookSummary $summary, int $clusterIndex): ?array
    {
        if (!$summary || !is_array($summary->clusters)) {
            return null;
        }

        $cluster = (new Collection($summary->clusters))->first(function ($row) use ($clusterIndex) {
            return (int) ($row['index'] ?? 0) === $clusterIndex;
        });

        return $cluster;
    }
}

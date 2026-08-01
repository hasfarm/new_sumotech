<?php

namespace App\Services\AssetLibrary;

use App\Models\AudiobookVideoShot;
use App\Models\VideoAssetLibrary;
use App\Services\ClipScoringService;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrates the "check the reusable library before searching/generating from scratch"
 * flow: embed the shot's AI Director request -> Qdrant nearest-neighbor search -> hydrate
 * MySQL metadata -> AI Vision score the top candidates (reusing ClipScoringService) ->
 * threshold gate. Also handles archiving newly-won assets (from any source) into the
 * library so future shots can find them.
 */
class AssetLibraryService
{
    private const CANDIDATES_TO_SCORE = 5;

    public function __construct(
        private readonly QdrantAssetIndexService $qdrant,
        private readonly R2StorageService $r2,
        private readonly ClipScoringService $scoringService
    ) {}

    /**
     * @return array{asset: VideoAssetLibrary, score_final: float}|null
     */
    public function findMatch(AudiobookVideoShot $shot, string $contextHint = ''): ?array
    {
        $queryText = trim((string) $shot->image_request) ?: implode(', ', $shot->keywords ?? []);
        if ($queryText === '') {
            return null;
        }

        try {
            $hits = $this->qdrant->searchAssets($queryText, 8);
        } catch (\Throwable $e) {
            Log::warning('AssetLibraryService: Qdrant search failed, skipping library lookup', ['error' => $e->getMessage()]);
            return null;
        }

        if (empty($hits)) {
            return null;
        }

        $ids = array_column($hits, 'id');
        $assets = VideoAssetLibrary::whereIn('id', $ids)->get()->keyBy('id');
        if ($assets->isEmpty()) {
            return null;
        }

        $scene = [
            'title' => $shot->scene->title ?? '',
            'scene_type' => $shot->scene->scene_type ?? '',
            'keywords' => $shot->keywords ?? [],
            'shot_text' => $shot->sentence_text,
        ];

        $items = [];
        $assetByIndex = [];
        $i = 0;
        foreach ($ids as $id) {
            if ($i >= self::CANDIDATES_TO_SCORE) {
                break;
            }
            $asset = $assets->get($id);
            if (!$asset) {
                continue;
            }

            $thumbPath = $asset->media_type === 'video' ? $asset->thumbnail_r2_path : $asset->r2_path;
            if (!$thumbPath) {
                continue;
            }

            try {
                $bytes = $this->r2->getBytes($thumbPath);
            } catch (\Throwable $e) {
                continue;
            }

            $items[$i] = [
                'bytes' => $bytes,
                'mime_type' => 'image/jpeg',
                'width' => $asset->width,
                'height' => $asset->height,
                // Use the ORIGINAL source (pexels/pixabay/ai_image/...), not the literal
                // string "library" — scoreLicense() checks this against its known-free
                // sources list, so the license score must reflect where the asset actually
                // came from, not the fact that it's now being served from the library.
                'source' => $asset->origin_source,
                'license_label' => $asset->license_label,
            ];
            $assetByIndex[$i] = $asset;
            $i++;
        }

        if (empty($items)) {
            return null;
        }

        $scores = $this->scoringService->scoreByBytes($items, $scene, $contextHint);

        $bestIndex = null;
        $bestScore = -1;
        foreach ($scores as $idx => $score) {
            if ($score['score_final'] > $bestScore) {
                $bestScore = $score['score_final'];
                $bestIndex = $idx;
            }
        }

        if ($bestIndex === null || $bestScore < ClipScoringService::SCORE_THRESHOLD) {
            return null;
        }

        return [
            'asset' => $assetByIndex[$bestIndex],
            'score_final' => $bestScore,
        ];
    }

    public function archive(
        AudiobookVideoShot $shot,
        string $localAssetAbsolutePath,
        string $originSource,
        float $scoreFinal,
        ?string $externalId = null,
        ?string $licenseLabel = null
    ): VideoAssetLibrary {
        if (!file_exists($localAssetAbsolutePath)) {
            throw new \RuntimeException("Không tìm thấy file local để archive: {$localAssetAbsolutePath}");
        }

        $ext = strtolower(pathinfo($localAssetAbsolutePath, PATHINFO_EXTENSION)) ?: 'bin';
        $mediaType = in_array($ext, ['mp4', 'mov', 'webm'], true) ? 'video' : 'image';

        $uuid = (string) Str::uuid();
        $year = date('Y');
        $r2Path = "library/{$year}/{$uuid}.{$ext}";

        $keywords = $shot->keywords ?? [];
        $description = trim((string) $shot->image_request)
            ?: trim(($shot->scene->title ?? '') . '. ' . implode(', ', $keywords));

        $contextHint = (string) ($shot->scene->audioBook->videoPipeline->context_hint ?? '');

        $metadata = $this->buildAssetMetadata($shot, $mediaType, $originSource, $keywords, $description, $licenseLabel);

        $this->r2->putFile($localAssetAbsolutePath, $r2Path, $metadata);

        $thumbnailR2Path = null;
        if ($mediaType === 'video') {
            $thumbnailLocalPath = sys_get_temp_dir() . '/' . $uuid . '_thumb.jpg';
            if ($this->extractVideoThumbnail($localAssetAbsolutePath, $thumbnailLocalPath)) {
                $thumbnailR2Path = "library/{$year}/{$uuid}_thumb.jpg";
                $this->r2->putFile($thumbnailLocalPath, $thumbnailR2Path, $metadata);
                @unlink($thumbnailLocalPath);
            }
        }

        [$width, $height] = $this->probeDimensions($localAssetAbsolutePath, $mediaType);
        $duration = $mediaType === 'video' ? $this->probeDuration($localAssetAbsolutePath) : null;

        $asset = VideoAssetLibrary::create([
            'media_type' => $mediaType,
            'origin_source' => $originSource,
            'external_id' => $externalId,
            'r2_path' => $r2Path,
            'thumbnail_r2_path' => $thumbnailR2Path,
            'width' => $width,
            'height' => $height,
            'duration_seconds' => $duration,
            'license_label' => $licenseLabel,
            'description' => $description ?: 'video pipeline asset',
            'keywords' => $keywords,
            'culture_context' => $contextHint !== '' ? $contextHint : null,
            'score_final' => $scoreFinal,
            'source_book_id' => $shot->scene->audio_book_id ?? null,
            'usage_count' => 1,
        ]);

        try {
            $this->qdrant->indexAsset($asset);
        } catch (\Throwable $e) {
            Log::warning('AssetLibraryService: failed to index asset into Qdrant (asset row still saved)', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $asset;
    }

    public function recordReuse(VideoAssetLibrary $asset): void
    {
        $asset->increment('usage_count');
    }

    /**
     * Custom S3/R2 object metadata — makes an archived asset identifiable/searchable
     * directly from any S3 tool/console (e.g. the R2 dashboard, `aws s3api head-object`),
     * not just via this app's own MySQL row + Qdrant index, which was the ONLY place this
     * context lived before (the R2 object itself was an opaque blob).
     *
     * Values are URL-encoded — S3 metadata becomes literal HTTP headers, which must be
     * ASCII-safe; Vietnamese/UTF-8 book titles and descriptions would otherwise risk the
     * upload being rejected or the bytes getting corrupted. Decode with rawurldecode() when
     * reading a value back out.
     *
     * @param array<int,string> $keywords
     * @return array<string,string>
     */
    private function buildAssetMetadata(AudiobookVideoShot $shot, string $mediaType, string $originSource, array $keywords, string $description, ?string $licenseLabel): array
    {
        $scene = $shot->scene;
        $audioBook = $scene?->audioBook;

        $characterNames = $shot->shotCharacters()->with('character')->get()
            ->pluck('character.canonical_name')->filter()->unique()->implode(', ');
        if ($characterNames === '' && $scene) {
            $characterNames = $scene->sceneCharacters()->with('character')->get()
                ->pluck('character.canonical_name')->filter()->unique()->implode(', ');
        }

        $raw = array_filter([
            'book_id' => $audioBook?->id !== null ? (string) $audioBook->id : null,
            'book_title' => $audioBook?->title,
            'scene_index' => $scene?->scene_index !== null ? (string) $scene->scene_index : null,
            'scene_title' => $scene?->title,
            'shot_index' => (string) $shot->shot_index,
            'shot_id' => (string) $shot->id,
            'media_type' => $mediaType,
            'origin_source' => $originSource,
            'license_label' => $licenseLabel,
            'keywords' => implode(', ', $keywords),
            'description' => mb_substr($description, 0, 500),
            'characters' => $characterNames !== '' ? $characterNames : null,
        ], fn($v) => $v !== null && $v !== '');

        return array_map(fn($v) => rawurlencode((string) $v), $raw);
    }

    private function extractVideoThumbnail(string $videoPath, string $outputJpgPath): bool
    {
        $ffmpegPath = (string) config('services.ffmpeg.path', 'ffmpeg');
        $command = sprintf(
            '%s -y -ss 00:00:01 -i %s -vframes 1 -q:v 2 %s 2>&1',
            escapeshellarg($ffmpegPath),
            escapeshellarg($videoPath),
            escapeshellarg($outputJpgPath)
        );

        exec($command, $output, $returnCode);

        return $returnCode === 0 && file_exists($outputJpgPath);
    }

    /**
     * @return array{0:?int,1:?int}
     */
    private function probeDimensions(string $path, string $mediaType): array
    {
        if ($mediaType === 'image') {
            $size = @getimagesize($path);
            return $size ? [(int) $size[0], (int) $size[1]] : [null, null];
        }

        $ffprobePath = (string) config('services.ffmpeg.ffprobe_path', 'ffprobe');
        $command = sprintf(
            '%s -v error -select_streams v:0 -show_entries stream=width,height -of csv=p=0 %s 2>&1',
            escapeshellarg($ffprobePath),
            escapeshellarg($path)
        );
        exec($command, $output, $returnCode);
        if ($returnCode === 0 && !empty($output[0])) {
            $parts = explode(',', trim($output[0]));
            if (count($parts) === 2) {
                return [(int) $parts[0], (int) $parts[1]];
            }
        }

        return [null, null];
    }

    private function probeDuration(string $path): ?int
    {
        $ffprobePath = (string) config('services.ffmpeg.ffprobe_path', 'ffprobe');
        $command = sprintf(
            '%s -v error -show_entries format=duration -of csv=p=0 %s 2>&1',
            escapeshellarg($ffprobePath),
            escapeshellarg($path)
        );
        exec($command, $output, $returnCode);
        if ($returnCode === 0 && !empty($output[0]) && is_numeric(trim($output[0]))) {
            return (int) round((float) trim($output[0]));
        }

        return null;
    }
}

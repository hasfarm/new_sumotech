<?php

namespace App\Services;

use App\Models\AudiobookAudioAsset;
use App\Services\AssetLibrary\QdrantAudioAssetIndexService;
use App\Services\AssetLibrary\R2StorageService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Orchestrates the "check the reusable audio library before generating from scratch" flow —
 * mirrors AssetLibraryService's shape (search -> hydrate -> score -> threshold gate, plus
 * archive()/recordReuse()) but for AudiobookAudioAsset instead of VideoAssetLibrary, and using
 * AudioRelevanceScoringService's text-only comparison instead of vision scoring (audio can't
 * be "seen"). Implements the full search-first dedup chain: fingerprint pre-check -> Qdrant
 * semantic search -> candidate review (searchCandidates(), for the approval-first UI) ->
 * generation on a miss or explicit user request (generateAndArchive()). Manual Storyblocks
 * hand-off is a separate ingest path (see VideoPipelineExtensionController::ingestAudio()) that
 * archives through the same archive() method.
 */
class AudioAssetLibraryService
{
    private const CANDIDATES_TO_SCORE = 5;

    public function __construct(
        private readonly QdrantAudioAssetIndexService $qdrant,
        private readonly AudioRelevanceScoringService $scoringService,
        private readonly R2StorageService $r2,
        private readonly ElevenLabsSoundEffectsService $sfxService,
        private readonly ElevenLabsMusicService $musicService
    ) {}

    /**
     * Automated path (bulk jobs): fingerprint hit, or best semantic hit ABOVE the score
     * threshold — otherwise null (caller falls through to generation). For the approval-first
     * UI, prefer searchCandidates() instead, which returns everything ungated for human review.
     *
     * @param array<int,string> $keywords
     * @return array{asset:AudiobookAudioAsset,score_final:float,match_type:string}|null
     */
    public function findMatch(string $category, string $prompt, array $keywords = [], ?float $durationSeconds = null, string $contextHint = ''): ?array
    {
        $candidates = $this->searchCandidates($category, $prompt, $keywords, $durationSeconds, $contextHint);
        if (empty($candidates)) {
            return null;
        }

        $best = $candidates[0];
        if ($best['match_type'] !== 'fingerprint' && $best['score_final'] < AudioRelevanceScoringService::SCORE_THRESHOLD) {
            return null;
        }

        return $best;
    }

    /**
     * Human-review path (approval-first UI): fingerprint hit (if any, always first/score 100)
     * followed by up to CANDIDATES_TO_SCORE semantic hits, EACH with its full score breakdown —
     * never threshold-gated, so the user can see and choose from every plausible option
     * instead of only ever being shown an auto-picked "best" one.
     *
     * @param array<int,string> $keywords
     * @return array<int,array{asset:AudiobookAudioAsset,score_final:float,score_content?:float,score_mood?:float,match_type:string}>
     */
    public function searchCandidates(string $category, string $prompt, array $keywords = [], ?float $durationSeconds = null, string $contextHint = '', int $limit = 5): array
    {
        $prompt = trim($prompt);
        if ($prompt === '') {
            return [];
        }

        $results = [];
        $seenIds = [];

        $fingerprint = $this->computeFingerprint($category, $prompt, $durationSeconds);
        $exact = AudiobookAudioAsset::where('audio_category', $category)->where('fingerprint', $fingerprint)->first();
        if ($exact) {
            $results[] = ['asset' => $exact, 'score_final' => 100.0, 'match_type' => 'fingerprint'];
            $seenIds[$exact->id] = true;
        }

        try {
            $hits = $this->qdrant->searchAssets($prompt, 8, ['audio_category' => $category]);
        } catch (\Throwable $e) {
            Log::warning('AudioAssetLibraryService: Qdrant search failed, skipping library lookup', ['error' => $e->getMessage()]);
            $hits = [];
        }

        if (empty($hits)) {
            return $results;
        }

        $ids = array_column($hits, 'id');
        $assets = AudiobookAudioAsset::whereIn('id', $ids)->get()->keyBy('id');

        $candidates = [];
        $assetByIndex = [];
        $i = 0;
        foreach ($ids as $id) {
            if (isset($seenIds[$id]) || $i >= self::CANDIDATES_TO_SCORE) {
                continue;
            }
            $asset = $assets->get($id);
            if (!$asset) {
                continue;
            }
            $candidates[$i] = ['prompt' => $asset->prompt, 'keywords' => $asset->keywords ?? []];
            $assetByIndex[$i] = $asset;
            $i++;
        }

        if (empty($candidates)) {
            return $results;
        }

        $target = ['prompt' => $prompt, 'keywords' => $keywords, 'audio_category' => $category];
        $scores = $this->scoringService->scoreCandidates($candidates, $target, $contextHint);

        $scored = [];
        foreach ($scores as $idx => $score) {
            $scored[] = array_merge($score, ['asset' => $assetByIndex[$idx], 'match_type' => 'semantic']);
        }

        usort($scored, fn($a, $b) => $b['score_final'] <=> $a['score_final']);

        return array_merge($results, array_slice($scored, 0, max(0, $limit - count($results))));
    }

    /**
     * @param array<int,string> $keywords
     */
    public function archive(
        string $category,
        string $provider,
        string $originSource,
        string $r2Path,
        string $prompt,
        array $keywords = [],
        ?float $durationSeconds = null,
        bool $isLoopable = false,
        ?string $licenseLabel = null,
        ?string $attribution = null,
        ?string $externalId = null,
        ?int $sourceBookId = null,
        ?float $scoreFinal = null,
        ?string $audioPromptVersion = null,
        ?float $requestedDurationSeconds = null,
        ?int $generationLatencyMs = null,
        ?int $creditsUsed = null,
        ?string $providerRequestId = null
    ): AudiobookAudioAsset {
        $fingerprint = $this->computeFingerprint($category, $prompt, $durationSeconds);

        $asset = AudiobookAudioAsset::create([
            'audio_category' => $category,
            'provider' => $provider,
            'origin_source' => $originSource,
            'external_id' => $externalId,
            'r2_path' => $r2Path,
            'duration_seconds' => $durationSeconds,
            'is_loopable' => $isLoopable,
            'license_label' => $licenseLabel,
            'attribution' => $attribution,
            'prompt' => $prompt,
            'keywords' => $keywords,
            'audio_prompt_version' => $audioPromptVersion,
            'fingerprint' => $fingerprint,
            'score_final' => $scoreFinal,
            'source_book_id' => $sourceBookId,
            'usage_count' => 1,
            'requested_duration_seconds' => $requestedDurationSeconds,
            'generation_latency_ms' => $generationLatencyMs,
            'credits_used' => $creditsUsed,
            'provider_request_id' => $providerRequestId,
        ]);

        try {
            $this->qdrant->indexAsset($asset);
        } catch (\Throwable $e) {
            Log::warning('AudioAssetLibraryService: failed to index asset into Qdrant (asset row still saved)', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $asset;
    }

    public function recordReuse(AudiobookAudioAsset $asset): void
    {
        $asset->increment('usage_count');
    }

    /**
     * Step 4 of the search-first chain: generate a brand-new clip via ElevenLabs (called only
     * when searchCandidates()/findMatch() found nothing suitable, OR the user explicitly asked
     * to regenerate) and archive it into the library in one shot. `$targetDurationSeconds` is
     * REQUIRED for music (ElevenLabs Music has no "auto" duration) and optional for sfx/ambience
     * (ElevenLabs picks a natural length if omitted).
     *
     * @param array<int,string> $keywords
     */
    public function generateAndArchive(
        string $category,
        string $prompt,
        array $keywords = [],
        ?float $targetDurationSeconds = null,
        ?int $sourceBookId = null,
        ?string $audioPromptVersion = null
    ): AudiobookAudioAsset {
        if (!in_array($category, ['sfx', 'ambience', 'music'], true)) {
            throw new \InvalidArgumentException("Unknown audio_category: {$category}");
        }

        if ($category === 'music') {
            $lengthMs = (int) round(($targetDurationSeconds ?? 8.0) * 1000);
            $result = $this->musicService->generate($prompt, $lengthMs);
            $provider = 'elevenlabs';
            $originSource = 'elevenlabs_music';
            $isLoopable = false;
        } else {
            $result = $this->sfxService->generate($prompt, $targetDurationSeconds, $category === 'ambience');
            $provider = 'elevenlabs';
            $originSource = 'elevenlabs_sfx';
            $isLoopable = $category === 'ambience';
        }

        $tempPath = sys_get_temp_dir() . '/' . Str::uuid() . '.mp3';
        file_put_contents($tempPath, $result['bytes']);

        try {
            $actualDuration = $this->probeDurationSeconds($tempPath);

            $year = date('Y');
            $r2Path = "audio_library/{$category}/{$year}/" . Str::uuid() . '.mp3';
            $this->r2->putFile($tempPath, $r2Path);

            return $this->archive(
                category: $category,
                provider: $provider,
                originSource: $originSource,
                r2Path: $r2Path,
                prompt: $prompt,
                keywords: $keywords,
                durationSeconds: $actualDuration,
                isLoopable: $isLoopable,
                sourceBookId: $sourceBookId,
                audioPromptVersion: $audioPromptVersion,
                requestedDurationSeconds: $result['requested_duration_seconds'] ?? $targetDurationSeconds,
                generationLatencyMs: $result['latency_ms'] ?? null,
                creditsUsed: $result['credits_used'] ?? null,
                providerRequestId: $result['provider_request_id'] ?? null
            );
        } finally {
            @unlink($tempPath);
        }
    }

    private function probeDurationSeconds(string $path): ?float
    {
        $ffprobePath = (string) config('services.ffmpeg.ffprobe_path', 'ffprobe');
        $command = sprintf(
            '%s -v error -show_entries format=duration -of csv=p=0 %s 2>&1',
            escapeshellarg($ffprobePath),
            escapeshellarg($path)
        );
        exec($command, $output, $returnCode);
        if ($returnCode === 0 && !empty($output[0]) && is_numeric(trim($output[0]))) {
            return round((float) trim($output[0]), 3);
        }

        return null;
    }

    /**
     * Cheap normalized-text hash (category + cleaned prompt + duration bucket) that catches
     * literal/near-literal repeat requests instantly without a Qdrant round-trip. This IS the
     * dedup mechanism (step 1 of the search-first chain) — real acoustic fingerprinting
     * (chromaprint-style matching) is a much bigger, separate undertaking and deliberately out
     * of scope; see the Audio Direction Pipeline plan's architecture notes.
     */
    private function computeFingerprint(string $category, string $prompt, ?float $durationSeconds): string
    {
        $normalized = mb_strtolower(trim(preg_replace('/\s+/', ' ', $prompt) ?? $prompt));
        $durationBucket = $durationSeconds !== null ? (string) ((int) round($durationSeconds / 5) * 5) : 'na';

        return hash('sha256', $category . '|' . $normalized . '|' . $durationBucket);
    }
}

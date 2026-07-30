<?php

namespace App\Services;

use App\Jobs\EnrichVideoShotsJob;
use App\Jobs\ValidateStoryContinuityJob;
use App\Models\AudioBook;
use App\Models\AudiobookContinuityIssue;
use App\Models\AudiobookContinuityValidationRun;
use App\Models\AudiobookStoryBible;
use App\Models\AudiobookVideoScene;
use App\Models\AudiobookVideoShot;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Validates already-resolved scene/shot context against the active Story Bible — never
 * re-reads/re-analyzes the whole work (that's Phase 2's job). Two check layers, kept
 * strictly separate so code-checkable facts never cost an API call:
 *
 *   deterministic checks: pure PHP/DB introspection of already-persisted binding data
 *                         (timeline_binding/location_binding + bible version comparison) —
 *                         produces `unresolved_binding` issues with a resolution_reason.
 *   AI semantic checks:   one call per scene, comparing each shot's narration/keywords/
 *                         image_request against the scene's resolved stable context
 *                         (VideoSceneAnalysisService::buildStableContextBlock()).
 *
 * Severity is derived from confidence in a fixed table (never trusted from the AI directly);
 * confidence=unknown never produces an issue. recommended_action is derived from severity +
 * whether the issue is shot-scoped and regenerable — the system never assigns 'accept', only
 * a user action does.
 *
 * Issues are upserted by a stable fingerprint (scene/shot + issue_type [+ binding_key for
 * unresolved_binding]) so re-validating never creates a duplicate row for "the same slot".
 */
class ContinuityValidationService
{
    public const VALIDATOR_VERSION = 'v1';

    public function __construct(
        private readonly OpenAiService $openAiService,
        private readonly VideoSceneAnalysisService $sceneAnalysisService
    ) {}

    // ------------------------------------------------------------------
    // Severity / action derivation (deterministic, never trusted from AI)
    // ------------------------------------------------------------------

    /** @return ?string null means "do not create an issue" (confidence=unknown). */
    public function deriveSeverity(string $issueType, string $confidence): ?string
    {
        if ($confidence === 'unknown') {
            return null;
        }

        $highImpact = in_array($issueType, AudiobookContinuityIssue::HIGH_IMPACT_TYPES, true);

        return match ($confidence) {
            'confirmed' => AudiobookContinuityIssue::SEVERITY_ERROR,
            'inferred' => $highImpact ? AudiobookContinuityIssue::SEVERITY_ERROR : AudiobookContinuityIssue::SEVERITY_WARNING,
            'inferred_low_confidence' => AudiobookContinuityIssue::SEVERITY_NEEDS_REVIEW,
            default => null,
        };
    }

    public function deriveAction(string $issueType, string $severity, bool $shotScoped): string
    {
        if ($severity === AudiobookContinuityIssue::SEVERITY_ERROR
            && $shotScoped
            && !in_array($issueType, AudiobookContinuityIssue::NOT_REGENERABLE_TYPES, true)
        ) {
            return AudiobookContinuityIssue::ACTION_AUTO_REGENERATE;
        }

        return AudiobookContinuityIssue::ACTION_MANUAL_REVIEW;
    }

    /** unresolved_binding bypasses the generic confidence table — the reason itself IS the classification. */
    public function severityForUnresolvedReason(string $reason): string
    {
        return match ($reason) {
            AudiobookContinuityIssue::REASON_ENTITY_MISSING, AudiobookContinuityIssue::REASON_AMBIGUOUS_MATCH => AudiobookContinuityIssue::SEVERITY_ERROR,
            default => AudiobookContinuityIssue::SEVERITY_WARNING, // alias_not_found, no_evidence, binding_stale
        };
    }

    public function fingerprint(?int $sceneId, ?int $shotId, string $issueType, ?string $bindingKey = null): string
    {
        return sha1(implode('|', [$sceneId ?? '', $shotId ?? '', $issueType, $bindingKey ?? '']));
    }

    // ------------------------------------------------------------------
    // Deterministic checks — no AI call, pure introspection of persisted bindings
    // ------------------------------------------------------------------

    /**
     * @return array<int,array{binding_key:string,message:string,confidence:string,resolution_reason:string}>
     */
    public function runDeterministicChecks(AudiobookVideoScene $scene, AudiobookStoryBible $activeBible): array
    {
        $drafts = [];

        foreach (['timeline' => $scene->timeline_binding, 'location' => $scene->location_binding] as $bindingKey => $binding) {
            if (!$binding) {
                continue;
            }

            $status = $binding['status'] ?? null;
            $reason = null;

            if ($status === 'unresolved') {
                $reason = $binding['unresolved_reason'] ?? AudiobookContinuityIssue::REASON_ENTITY_MISSING;
            } elseif ($status === 'resolved' && $scene->story_bible_version_used !== $activeBible->bible_version) {
                // The binding resolved successfully once, but the active Story Bible has
                // since moved on — the referenced timeline/location row may no longer exist
                // or may have changed; treat as needing re-verification rather than trusting it.
                $reason = AudiobookContinuityIssue::REASON_BINDING_STALE;
            } elseif ($status === 'resolved' && ($binding['unresolved_reason'] ?? null) === AudiobookContinuityIssue::REASON_NO_EVIDENCE) {
                $reason = AudiobookContinuityIssue::REASON_NO_EVIDENCE;
            }

            if ($reason === null) {
                continue;
            }

            $drafts[] = [
                'binding_key' => $bindingKey,
                'message' => "Cảnh #{$scene->id}: {$bindingKey}_binding chưa xác minh được (resolution_reason={$reason}).",
                'confidence' => 'confirmed', // this is a deterministic fact about persisted data, not an inference
                'resolution_reason' => $reason,
            ];
        }

        return $drafts;
    }

    // ------------------------------------------------------------------
    // AI semantic check — one call per scene
    // ------------------------------------------------------------------

    /**
     * @param array<int,int>|null $onlyShotIndices
     * @param array<string,mixed> $logContext
     */
    public function runSemanticCheck(AudiobookVideoScene $scene, Collection $shots, ?array $onlyShotIndices, array $logContext = []): array
    {
        $stableContext = $this->sceneAnalysisService->buildStableContextBlock($scene);

        // Each shot's OWN resolved local context (chunk-level, may differ shot-to-shot within
        // one scene — see VideoSceneAnalysisService::persistChunkContext()) is surfaced
        // inline so the model can compare a shot directly against its IMMEDIATE NEIGHBOR, not
        // just against the scene-wide binding — this is what a whole-scene-only comparison
        // structurally cannot catch (a scene spanning a 17-year journey can legitimately
        // mention "desert crossing" as one of its incidents, so a single desert shot never
        // looks wrong against that broad description; only comparing it to the shot right
        // before/after it reveals an unexplained jump).
        $shotsText = $shots->map(function ($s) use ($scene) {
            $mode = $s->narrative_mode ?: 'story';
            if ($mode === 'host_narration') {
                return "[{$s->shot_index}] (HOST/NARRATION — không thuộc bối cảnh câu chuyện, bỏ qua mọi so sánh lịch sử/văn hóa/nhân vật cho shot này) narration: {$s->sentence_text}";
            }

            $location = $s->resolvedLocation()?->canonical_name ?? $scene->resolvedLocation()?->canonical_name;
            $phase = $s->shot_story_phase ?: $scene->story_phase;
            $chars = $s->shotCharacters()->with('character')->get()->map(fn($sc) => $sc->character?->canonical_name)->filter()->implode(', ');

            return "[{$s->shot_index}] narration: {$s->sentence_text} | keywords: " . implode(', ', $s->keywords ?? [])
                . ' | image_request: ' . ($s->image_request ?? '(chưa có)')
                . ' | local_location: ' . ($location ?: '(không xác định)')
                . ' | local_story_phase: ' . ($phase ?: '(không xác định)')
                . ' | local_characters: ' . ($chars !== '' ? $chars : '(không xác định)');
        })->implode("\n");

        $targetLine = $onlyShotIndices !== null
            ? 'CHỈ được trả issue có scope="shot" cho các target_shot_indices sau: [' . implode(', ', $onlyShotIndices)
                . '] — TUYỆT ĐỐI KHÔNG trả issue scope="shot" cho shot ngoài danh sách này (issue scope="scene" vẫn được phép nếu có).'
            : '';

        $prompt = "Bạn kiểm tra continuity (tính nhất quán) cho các SHOT của MỘT CẢNH trong video, dựa TRÊN bối cảnh đã "
            . "được xác lập sẵn (Story Bible/Character Bible đã resolve) bên dưới — KHÔNG đọc lại toàn bộ tác phẩm, chỉ "
            . "so sánh narration/keywords/image_request của từng shot với bối cảnh này VÀ với shot LIỀN KỀ (trước/sau) của nó.\n\n"
            . "BỐI CẢNH ĐÃ RESOLVE CHO CẢNH NÀY (bối cảnh chung, có thể trải dài nhiều thời điểm/địa điểm khác nhau — "
            . "mỗi shot còn có local_location/local_story_phase/local_characters RIÊNG, xem danh sách shot bên dưới):\n"
            . ($stableContext !== '' ? $stableContext : '(không có dữ liệu bối cảnh)') . "\n\n"
            . "DANH SÁCH SHOT (theo đúng thứ tự xuất hiện trong video):\n{$shotsText}\n\n"
            . $targetLine . "\n\n"
            . "Kiểm tra các nhóm sau, CHỈ báo cáo khi thực sự có mâu thuẫn RÕ RÀNG (không suy đoán thêm ngoài bối cảnh đã "
            . "cho, không tự bịa chi tiết). Shot có mode HOST/NARRATION KHÔNG bao giờ bị báo historical_period_mismatch/"
            . "anachronism/geography_mismatch/cultural_group_mismatch — shot đó không thuộc thế giới câu chuyện.\n"
            . "- character_mismatch, phase_mismatch, identity_anchor_mismatch, baseline_trait_mismatch\n"
            . "- wardrobe_mismatch, hairstyle_mismatch, injury_mismatch, physique_mismatch, social_role_mismatch\n"
            . "- timeline_mismatch, story_phase_mismatch, location_mismatch, geography_mismatch\n"
            . "- cultural_group_mismatch, architecture_mismatch, clothing_mismatch, religion_mismatch, transportation_mismatch, historical_period_mismatch\n"
            . "- anachronism, narration_image_mismatch\n"
            . "- unexpected_location_change: local_location của shot này KHÁC local_location của shot LIỀN TRƯỚC (không "
            . "phải host/narration), VÀ narration của CHÍNH shot này KHÔNG có từ ngữ chỉ chuyển cảnh/thời gian/địa điểm "
            . "rõ ràng (vd \"sau đó\", \"nhiều năm sau\", \"tại một nơi khác\", \"trở về\", \"ít lâu sau\", \"tiếp theo là\"). "
            . "Nếu narration CÓ dấu hiệu chuyển cảnh, đây KHÔNG phải issue dù local_location đổi khác — chỉ báo khi thay "
            . "đổi diễn ra MÀ KHÔNG có bằng chứng chuyển cảnh.\n"
            . "- wardrobe_change: MỘT nhân vật xuất hiện ở CẢ shot này và shot liền trước/sau (cùng local_characters), "
            . "nhưng image_request mô tả trang phục/diện mạo khác nhau RÕ RỆT giữa hai shot đó MÀ KHÔNG có bằng chứng "
            . "chuyển đổi (bị thương, thay đồ, đổi vai trò...) trong narration của cả hai shot.\n"
            . "- role_costume_mismatch: image_request/keywords gán một VAI TRÒ/TRANG PHỤC CỤ THỂ (vd \"quân lính\", "
            . "\"quan chức\", \"nhà sư\") cho một người KHÔNG có trong local_characters/không được Story Bible xác nhận "
            . "vai trò đó, và bản thân narration của shot đó KHÔNG mô tả rõ vai trò/trang phục ấy — dấu hiệu của việc tự "
            . "bịa đặc điểm cho một nhân vật chưa được nhận diện.\n"
            . "Nếu bối cảnh không có đủ dữ liệu để kiểm tra một nhóm nào đó, BỎ QUA nhóm đó — đừng suy đoán.\n"
            . "Mỗi issue: scope (\"shot\" hoặc \"scene\"), shot_index (bắt buộc nếu scope=shot, null nếu scope=scene), "
            . "issue_type, message, expected_value, actual_value, confidence, source_type, evidence (quote NGUYÊN VĂN từ "
            . "narration/keywords/image_request), rationale.\n"
            . "confidence=\"unknown\" nếu không đủ căn cứ để kết luận chắc chắn — hệ thống sẽ KHÔNG tạo lỗi cho issue có "
            . "confidence=unknown, vì vậy chỉ báo cáo issue khi thực sự có căn cứ rõ ràng.\n";

        return $this->openAiService->completeJson($prompt, [
            'reasoning_effort' => 'medium',
            'json_schema' => $this->semanticCheckSchema(),
            'max_tokens' => 6000,
            'purpose' => 'continuity_validate',
            'retry' => false,
            'log_context' => $logContext,
        ]);
    }

    private function semanticCheckSchema(): array
    {
        return [
            'name' => 'continuity_validation',
            'strict' => true,
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'issues' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'scope' => ['type' => 'string', 'enum' => ['shot', 'scene']],
                                'shot_index' => ['type' => ['integer', 'null']],
                                'issue_type' => ['type' => 'string', 'enum' => AudiobookContinuityIssue::ALL_TYPES],
                                'message' => ['type' => 'string'],
                                'expected_value' => ['type' => ['string', 'null']],
                                'actual_value' => ['type' => ['string', 'null']],
                                'confidence' => ['type' => 'string', 'enum' => ['confirmed', 'inferred', 'inferred_low_confidence', 'unknown']],
                                'source_type' => ['type' => 'string', 'enum' => ['explicit_text', 'inferred_from_text', 'director_choice', 'user_override', 'unknown']],
                                'evidence' => [
                                    'type' => 'array',
                                    'items' => [
                                        'type' => 'object',
                                        'properties' => ['quote' => ['type' => 'string']],
                                        'required' => ['quote'],
                                        'additionalProperties' => false,
                                    ],
                                ],
                                'rationale' => ['type' => ['string', 'null']],
                            ],
                            'required' => ['scope', 'shot_index', 'issue_type', 'message', 'expected_value', 'actual_value', 'confidence', 'source_type', 'evidence', 'rationale'],
                            'additionalProperties' => false,
                        ],
                    ],
                ],
                'required' => ['issues'],
                'additionalProperties' => false,
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Orchestration for one scene: deterministic + AI checks, upsert, resolve, recompute
    // ------------------------------------------------------------------

    /**
     * @param array<int,int>|null $onlyShotIndices local shot_index values to restrict AI-check persistence to (null = whole scene)
     */
    public function validateScene(AudiobookVideoScene $scene, AudiobookStoryBible $bible, AudiobookContinuityValidationRun $run, ?array $onlyShotIndices = null): void
    {
        $allShots = $scene->shots()->orderBy('shot_index')->get();
        $targetShots = $onlyShotIndices !== null
            ? $allShots->filter(fn($s) => in_array($s->shot_index, $onlyShotIndices, true))->values()
            : $allShots;

        // Snapshot BEFORE this pass: only a 'regenerating' issue on a target shot may be
        // auto-resolved by this pass — never a plain 'open' one (that would be resolving
        // "because the AI happened not to mention it this time", not because a confirmed
        // regeneration fixed it).
        $targetShotIds = $targetShots->pluck('id')->all();
        $previouslyRegenerating = AudiobookContinuityIssue::whereIn('video_shot_id', $targetShotIds)
            ->where('status', AudiobookContinuityIssue::STATUS_REGENERATING)
            ->pluck('issue_fingerprint')
            ->all();

        $reproducedFingerprints = [];

        // 1. Deterministic checks — scene-level, always run regardless of onlyShotIndices,
        //    never cost an API call.
        foreach ($this->runDeterministicChecks($scene, $bible) as $draft) {
            $reason = $draft['resolution_reason'];
            $severity = $this->severityForUnresolvedReason($reason);
            $issue = $this->upsertIssue($scene->audio_book_id, $scene->id, null, [
                'issue_type' => AudiobookContinuityIssue::TYPE_UNRESOLVED_BINDING,
                'binding_key' => $draft['binding_key'],
                'severity' => $severity,
                'message' => $draft['message'],
                'expected_value' => null,
                'actual_value' => null,
                'confidence' => $draft['confidence'],
                'source_type' => 'explicit_text',
                'evidence' => [],
                'rationale' => 'Phát hiện qua kiểm tra dữ liệu đã lưu (deterministic), không qua AI.',
                'resolution_reason' => $reason,
                'recommended_action' => AudiobookContinuityIssue::ACTION_MANUAL_REVIEW,
            ], $run->id, $draft['binding_key']);
            $reproducedFingerprints[] = $issue->issue_fingerprint;
        }

        // 2. AI semantic check — one call per scene (skip entirely if nothing to check).
        if ($targetShots->isNotEmpty()) {
            $raw = $this->runSemanticCheck($scene, $allShots, $onlyShotIndices, [
                'book_id' => $scene->audio_book_id,
                'scene_id' => $scene->id,
            ]);

            foreach ((array) ($raw['issues'] ?? []) as $item) {
                $isShotScoped = ($item['scope'] ?? 'scene') === 'shot';
                $shotId = null;

                if ($isShotScoped) {
                    $shotIndex = $item['shot_index'] ?? null;
                    if ($onlyShotIndices !== null && !in_array($shotIndex, $onlyShotIndices, true)) {
                        continue; // model ignored the target restriction — drop it defensively
                    }
                    $shot = $allShots->firstWhere('shot_index', $shotIndex);
                    if (!$shot) {
                        continue;
                    }
                    $shotId = $shot->id;
                }

                $confidence = $item['confidence'] ?? 'unknown';
                if ($confidence === 'unknown') {
                    continue; // never conclude a factual error from unknown confidence
                }

                $issueType = $item['issue_type'] ?? null;
                if (!in_array($issueType, AudiobookContinuityIssue::ALL_TYPES, true)) {
                    continue;
                }

                $severity = $this->deriveSeverity($issueType, $confidence);
                if ($severity === null) {
                    continue;
                }
                $action = $this->deriveAction($issueType, $severity, $isShotScoped);

                $issue = $this->upsertIssue($scene->audio_book_id, $scene->id, $shotId, [
                    'issue_type' => $issueType,
                    'binding_key' => null,
                    'severity' => $severity,
                    'message' => (string) ($item['message'] ?? ''),
                    'expected_value' => $item['expected_value'] ?? null,
                    'actual_value' => $item['actual_value'] ?? null,
                    'confidence' => $confidence,
                    'source_type' => $item['source_type'] ?? 'unknown',
                    'evidence' => $item['evidence'] ?? [],
                    'rationale' => $item['rationale'] ?? null,
                    'resolution_reason' => null,
                    'recommended_action' => $action,
                ], $run->id);

                $reproducedFingerprints[] = $issue->issue_fingerprint;
            }
        }

        // 3. Any issue that was 'regenerating' for a target shot and wasn't reproduced this
        //    pass is now confirmed fixed.
        $toResolve = array_diff($previouslyRegenerating, $reproducedFingerprints);
        if (!empty($toResolve)) {
            AudiobookContinuityIssue::whereIn('issue_fingerprint', $toResolve)
                ->where('status', AudiobookContinuityIssue::STATUS_REGENERATING)
                ->update(['status' => AudiobookContinuityIssue::STATUS_RESOLVED, 'resolved_at' => now()]);
        }

        // 4. Recompute rollups for whatever we actually touched.
        foreach ($targetShots as $shot) {
            $this->recomputeShotValidationStatus($shot);
        }
        $this->recomputeSceneContinuityStatus($scene);
    }

    /**
     * Upsert-by-fingerprint: a re-validation pass never creates a duplicate row for "the
     * same slot" (scene/shot + issue_type [+ binding_key]). A user-accepted issue is left
     * untouched by routine re-validation; a resolved issue that recurs is reopened.
     *
     * @param array<string,mixed> $data
     */
    public function upsertIssue(int $audioBookId, ?int $sceneId, ?int $shotId, array $data, int $validatorRunId, ?string $bindingKey = null): AudiobookContinuityIssue
    {
        $fingerprint = $this->fingerprint($sceneId, $shotId, $data['issue_type'], $bindingKey);
        $existing = AudiobookContinuityIssue::where('issue_fingerprint', $fingerprint)->first();

        $payload = array_merge($data, [
            'audio_book_id' => $audioBookId,
            'video_scene_id' => $sceneId,
            'video_shot_id' => $shotId,
            'issue_fingerprint' => $fingerprint,
            'continuity_validator_version' => self::VALIDATOR_VERSION,
            'validator_run_id' => $validatorRunId,
        ]);

        if (!$existing) {
            $payload['status'] = AudiobookContinuityIssue::STATUS_OPEN;
            return AudiobookContinuityIssue::create($payload);
        }

        if ($existing->status === AudiobookContinuityIssue::STATUS_ACCEPTED) {
            // Don't silently reopen a user-accepted issue just because routine
            // re-validation still reproduces it — only bump provenance metadata.
            $existing->update(['continuity_validator_version' => self::VALIDATOR_VERSION, 'validator_run_id' => $validatorRunId]);
            return $existing->fresh();
        }

        // Reproduced: whether it was open, regenerating, or previously (incorrectly)
        // resolved and has now recurred, it's confirmed still-a-problem — normalize to open.
        $payload['status'] = AudiobookContinuityIssue::STATUS_OPEN;
        $existing->update($payload);
        return $existing->fresh();
    }

    public function recomputeShotValidationStatus(AudiobookVideoShot $shot): void
    {
        $openIssues = AudiobookContinuityIssue::where('video_shot_id', $shot->id)
            ->whereIn('status', [AudiobookContinuityIssue::STATUS_OPEN, AudiobookContinuityIssue::STATUS_REGENERATING])
            ->get();

        $status = 'valid';
        if ($openIssues->contains(fn($i) => $i->severity === AudiobookContinuityIssue::SEVERITY_ERROR)) {
            $status = 'invalid';
        } elseif ($openIssues->isNotEmpty()) {
            $status = 'warning';
        }

        $shot->update([
            'validation_status' => $status,
            'continuity_error' => $openIssues->map(fn($i) => [
                'issue_type' => $i->issue_type,
                'severity' => $i->severity,
                'message' => $i->message,
                'recommended_action' => $i->recommended_action,
            ])->values()->all(),
            'validated_at' => now(),
            'continuity_validator_version' => self::VALIDATOR_VERSION,
        ]);
    }

    public function recomputeSceneContinuityStatus(AudiobookVideoScene $scene): void
    {
        $shotStatuses = $scene->shots()->pluck('validation_status');
        $sceneLevelOpen = AudiobookContinuityIssue::where('video_scene_id', $scene->id)
            ->whereNull('video_shot_id')
            ->whereIn('status', [AudiobookContinuityIssue::STATUS_OPEN, AudiobookContinuityIssue::STATUS_REGENERATING])
            ->get();

        $status = 'valid';
        if ($shotStatuses->contains('invalid') || $sceneLevelOpen->contains(fn($i) => $i->severity === AudiobookContinuityIssue::SEVERITY_ERROR)) {
            $status = 'invalid';
        } elseif ($shotStatuses->contains('warning') || $sceneLevelOpen->isNotEmpty()) {
            $status = 'warning';
        } elseif ($shotStatuses->isEmpty() || $shotStatuses->contains('unvalidated')) {
            $status = 'unvalidated';
        }

        $scene->update([
            'continuity_status' => $status,
            'continuity_validator_version' => self::VALIDATOR_VERSION,
        ]);
    }

    // ------------------------------------------------------------------
    // Selective regeneration + confirmation revalidation
    // ------------------------------------------------------------------

    /**
     * Regenerates ONLY the shots backing the given open error+auto_regenerate issues, then
     * revalidates ONLY those shots (+ immediate scene neighbors, since continuity can depend
     * on shot sequence). An issue only becomes `resolved` once the regeneration actually
     * succeeded AND the follow-up revalidation confirms it's no longer reproduced — a failed
     * regeneration reverts its issues to `open`; a revalidation that itself throws leaves
     * them at `regenerating` (never silently resolved).
     *
     * @param array<int,int> $issueIds
     * @return array{regenerated_shot_ids:array<int,int>,failed_shot_ids:array<int,int>,chunk_indices:array<int,int>}
     */
    public function regenerateAndRevalidate(AudioBook $audioBook, array $issueIds): array
    {
        $issues = AudiobookContinuityIssue::whereIn('id', $issueIds)
            ->where('severity', AudiobookContinuityIssue::SEVERITY_ERROR)
            ->where('recommended_action', AudiobookContinuityIssue::ACTION_AUTO_REGENERATE)
            ->where('status', AudiobookContinuityIssue::STATUS_OPEN)
            ->get();

        if ($issues->isEmpty()) {
            return ['regenerated_shot_ids' => [], 'failed_shot_ids' => [], 'chunk_indices' => []];
        }

        $batchId = (string) Str::uuid();
        $shotIds = $issues->pluck('video_shot_id')->filter()->unique()->values();

        AudiobookContinuityIssue::whereIn('id', $issues->pluck('id'))
            ->update(['status' => AudiobookContinuityIssue::STATUS_REGENERATING, 'regeneration_batch_id' => $batchId]);

        $pipeline = $audioBook->videoPipeline;
        $shotChunks = collect($pipeline?->shot_chunks ?? []);
        $chunkIndices = $shotChunks
            ->filter(fn($c) => collect($c['shot_ids'] ?? [])->intersect($shotIds)->isNotEmpty())
            ->pluck('index')
            ->values()
            ->all();

        if (!empty($chunkIndices)) {
            EnrichVideoShotsJob::dispatch($audioBook->id, $chunkIndices);
        }

        $shots = AudiobookVideoShot::whereIn('id', $shotIds)->get();
        $succeededShotIds = $shots->where('enrichment_status', 'enriched')->pluck('id')->values()->all();
        $failedShotIds = $shots->where('enrichment_status', 'failed')->pluck('id')->values()->all();

        // Regeneration itself failed for these shots — nothing changed, so revert straight
        // to 'open' rather than attempting a pointless revalidation call.
        if (!empty($failedShotIds)) {
            AudiobookContinuityIssue::whereIn('video_shot_id', $failedShotIds)
                ->where('status', AudiobookContinuityIssue::STATUS_REGENERATING)
                ->update(['status' => AudiobookContinuityIssue::STATUS_OPEN]);
        }

        if (empty($succeededShotIds)) {
            return ['regenerated_shot_ids' => [], 'failed_shot_ids' => $failedShotIds, 'chunk_indices' => $chunkIndices];
        }

        $targetShotIds = $this->expandWithNeighbors($succeededShotIds);

        // ValidateStoryContinuityJob is internally resilient: a scene it fails to check
        // leaves that scene's shots at validation_status='failed' and does NOT touch their
        // issues (still 'regenerating') — so no try/catch is needed here for the "revalidation
        // failed" requirement, the job's own per-scene resilience already guarantees it.
        (new ValidateStoryContinuityJob($audioBook->id, onlyShotIds: $targetShotIds))->handle($this);

        return ['regenerated_shot_ids' => $succeededShotIds, 'failed_shot_ids' => $failedShotIds, 'chunk_indices' => $chunkIndices];
    }

    /**
     * @param array<int,int> $shotIds
     * @return array<int,int>
     */
    private function expandWithNeighbors(array $shotIds): array
    {
        $shots = AudiobookVideoShot::whereIn('id', $shotIds)->get();
        $expanded = collect($shotIds);

        foreach ($shots as $shot) {
            $neighbors = AudiobookVideoShot::where('video_scene_id', $shot->video_scene_id)
                ->whereIn('shot_index', [$shot->shot_index - 1, $shot->shot_index + 1])
                ->pluck('id');
            $expanded = $expanded->merge($neighbors);
        }

        return $expanded->unique()->values()->all();
    }
}

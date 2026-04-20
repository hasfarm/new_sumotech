<?php

namespace App\Jobs;

use App\Models\DubSyncProject;
use App\Services\TTSService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class GenerateDubSyncBatchTtsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $projectId;
    public array $segmentIndices;
    public array $segmentTexts;
    public array $voiceSettings;
    public ?string $fallbackVoiceGender;
    public ?string $fallbackVoiceName;
    public string $provider;
    public ?string $styleInstruction;

    public int $timeout = 14400; // 4 hours

    public function __construct(
        int $projectId,
        array $segmentIndices,
        array $segmentTexts,
        array $voiceSettings,
        ?string $fallbackVoiceGender,
        ?string $fallbackVoiceName,
        string $provider,
        ?string $styleInstruction
    ) {
        $this->projectId = $projectId;
        $this->segmentIndices = array_values(array_unique(array_map('intval', $segmentIndices)));
        $this->segmentTexts = $segmentTexts;
        $this->voiceSettings = $voiceSettings;
        $this->fallbackVoiceGender = $fallbackVoiceGender;
        $this->fallbackVoiceName = $fallbackVoiceName;
        $this->provider = $provider;
        $this->styleInstruction = $styleInstruction;

        $this->onQueue('default');
    }

    public function handle(TTSService $ttsService): void
    {
        $lockKey = $this->getBatchLockKey();
        $lockToken = (string) Str::uuid();

        if (!Cache::add($lockKey, $lockToken, now()->addHours(8))) {
            $this->updateProgress([
                'status' => 'processing',
                'message' => 'Dang co batch TTS khac cho project nay. Bo qua job trung lap.',
            ]);
            return;
        }

        try {
            $project = DubSyncProject::find($this->projectId);
            if (!$project) {
                $this->updateProgress([
                    'status' => 'error',
                    'percent' => 100,
                    'message' => 'Khong tim thay project.',
                ]);
                return;
            }

            $segments = $project->segments ?? [];
            $total = count($this->segmentIndices);
            $processed = 0;
            $success = 0;
            $failed = 0;
            $errors = [];
            $segmentsData = [];

            if ($total === 0) {
                $this->updateProgress([
                    'status' => 'error',
                    'percent' => 100,
                    'message' => 'Khong co segment nao de tao TTS.',
                ]);
                return;
            }

            $this->updateProgress([
                'status' => 'processing',
                'percent' => 1,
                'message' => "Dang tao TTS cho {$total} segment...",
                'total' => $total,
                'processed' => 0,
                'success_count' => 0,
                'failed_count' => 0,
                'errors' => [],
                'segments_data' => [],
            ]);

            foreach ($this->segmentIndices as $segmentIndex) {
                $processed++;
                $humanIndex = $segmentIndex + 1;

                $this->updateProgress([
                    'status' => 'processing',
                    'percent' => $this->calcPercent(max(0, $processed - 1), $total),
                    'message' => "Dang xu ly segment {$humanIndex} ({$processed}/{$total})...",
                    'current_segment_index' => $segmentIndex,
                    'processed' => $processed - 1,
                    'total' => $total,
                    'success_count' => $success,
                    'failed_count' => $failed,
                    'errors' => $errors,
                    'segments_data' => $segmentsData,
                ]);

                try {
                    if (!isset($segments[$segmentIndex])) {
                        throw new \Exception("Segment {$segmentIndex} not found");
                    }

                    $segment = $segments[$segmentIndex];
                    $textFromRequest = $this->segmentTexts[$segmentIndex] ?? null;
                    $text = is_string($textFromRequest) && trim($textFromRequest) !== ''
                        ? $textFromRequest
                        : ($segment['text'] ?? '');

                    $styleInstruction = $this->styleInstruction ?? '';
                    $textToSend = $styleInstruction !== '' ? "{$styleInstruction}\n\n{$text}" : $text;

                    $voiceGender = $this->voiceSettings[$segmentIndex]['voice_gender'] ?? $this->fallbackVoiceGender;
                    $voiceName = $this->voiceSettings[$segmentIndex]['voice_name'] ?? $this->fallbackVoiceName;

                    if (!$voiceGender || !$voiceName) {
                        throw new \Exception("Segment {$segmentIndex}: missing voice settings");
                    }

                    $audioPath = $ttsService->generateAudio(
                        $textToSend,
                        $segmentIndex,
                        $voiceGender,
                        $voiceName,
                        $this->provider,
                        $this->styleInstruction,
                        $project->id
                    );

                    $segments[$segmentIndex]['audio_path'] = $audioPath;
                    $segments[$segmentIndex]['voice_gender'] = $voiceGender;
                    $segments[$segmentIndex]['voice_name'] = $voiceName;
                    $segments[$segmentIndex]['tts_provider'] = $this->provider;
                    $segments[$segmentIndex]['audio_url'] = Storage::url($audioPath);

                    $segmentsData[(string) $segmentIndex] = [
                        'audio_path' => $audioPath,
                        'audio_url' => Storage::url($audioPath),
                        'voice_gender' => $voiceGender,
                        'voice_name' => $voiceName,
                        'tts_provider' => $this->provider,
                    ];

                    $success++;
                } catch (\Exception $e) {
                    $failed++;
                    $errors[] = "Segment {$segmentIndex}: " . $e->getMessage();
                    if (count($errors) > 100) {
                        $errors = array_slice($errors, -100);
                    }
                }

                $this->updateProgress([
                    'status' => 'processing',
                    'percent' => $this->calcPercent($processed, $total),
                    'message' => "Da xu ly {$processed}/{$total} segment (success {$success}, failed {$failed}).",
                    'current_segment_index' => $segmentIndex,
                    'processed' => $processed,
                    'total' => $total,
                    'success_count' => $success,
                    'failed_count' => $failed,
                    'errors' => $errors,
                    'segments_data' => $segmentsData,
                ]);
            }

            $project->segments = $segments;
            $project->save();

            $finalStatus = $failed > 0 ? 'completed_with_errors' : 'completed';
            $finalMessage = $failed > 0
                ? "Hoan tat voi loi. Success {$success}/{$total}, Failed {$failed}."
                : "Hoan tat tao TTS {$success}/{$total} segment.";

            $this->updateProgress([
                'status' => $finalStatus,
                'percent' => 100,
                'message' => $finalMessage,
                'processed' => $total,
                'total' => $total,
                'success_count' => $success,
                'failed_count' => $failed,
                'errors' => $errors,
                'segments_data' => $segmentsData,
            ]);
        } catch (\Exception $e) {
            $this->updateProgress([
                'status' => 'error',
                'percent' => 100,
                'message' => 'Loi batch TTS: ' . $e->getMessage(),
            ]);

            throw $e;
        } finally {
            $this->releaseBatchLock($lockKey, $lockToken);
        }
    }

    private function calcPercent(int $processed, int $total): int
    {
        if ($total <= 0) {
            return 0;
        }

        return (int) min(100, max(0, floor(($processed / $total) * 100)));
    }

    private function updateProgress(array $data): void
    {
        $payload = array_merge([
            'status' => 'processing',
            'percent' => 0,
            'message' => '',
            'project_id' => $this->projectId,
            'current_segment_index' => null,
            'processed' => 0,
            'total' => count($this->segmentIndices),
            'success_count' => 0,
            'failed_count' => 0,
            'errors' => [],
            'segments_data' => [],
            'updated_at' => now()->toIso8601String(),
        ], $data);

        Cache::put($this->getBatchProgressKey(), $payload, now()->addHours(6));
    }

    private function getBatchProgressKey(): string
    {
        return "dubsync_tts_batch_progress_{$this->projectId}";
    }

    private function getBatchLockKey(): string
    {
        return "dubsync_tts_batch_lock_{$this->projectId}";
    }

    private function releaseBatchLock(string $lockKey, string $lockToken): void
    {
        if (Cache::get($lockKey) === $lockToken) {
            Cache::forget($lockKey);
        }
    }
}

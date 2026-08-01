<?php

namespace App\Console\Commands;

use App\Http\Controllers\MotionDirectionController;
use App\Models\AudioBook;
use App\Models\AudiobookVideoScene;
use App\Models\AudiobookVideoShot;
use App\Services\MotionPilotShotSelector;
use Illuminate\Console\Command;
use Illuminate\Http\Request;

/**
 * Required stop-and-review point before any bulk motion/transition job is written (see
 * calm-sniffing-rainbow.md's "Pilot" section). Runs the REAL MotionDirectionController
 * endpoints — the exact same code the UI's "Tạo" buttons call, not a separate test path —
 * against a deliberately varied handful of real shots, and prints a report a human must
 * actually watch every clip from before approving any bulk rollout. This command renders
 * real files and makes real Gemini vision calls; it is not free to run repeatedly.
 */
class MotionDirectionPilot extends Command
{
    protected $signature = 'motion-direction:pilot {audioBookId} {shotIds?}';
    protected $description = 'Pilot AI-selected motion + transition on 10-15 varied real shots before any bulk rollout';

    public function handle(MotionDirectionController $controller, MotionPilotShotSelector $selector): int
    {
        $audioBookId = (int) $this->argument('audioBookId');
        $audioBook = AudioBook::find($audioBookId);
        if (!$audioBook) {
            $this->error("Audiobook #{$audioBookId} không tồn tại.");
            return self::FAILURE;
        }

        $shotIdsArg = $this->argument('shotIds');
        $shots = $shotIdsArg
            ? AudiobookVideoShot::whereIn('id', array_map('intval', explode(',', $shotIdsArg)))->orderBy('id')->get()
            : $selector->select($audioBookId);

        if ($shots->isEmpty()) {
            $this->error('Không tìm được shot nào để chạy pilot.');
            return self::FAILURE;
        }

        $this->info("Chạy pilot trên {$shots->count()} shot (audiobook #{$audioBookId})...");
        $this->newLine();

        $rows = [];
        foreach ($shots as $shot) {
            $scene = $shot->scene;
            $this->line("── Shot #{$shot->id} (scene #{$scene->id}, idx {$shot->shot_index}, {$scene->scene_type}" . ($scene->is_emotional_climax ? ', climax' : '') . ($shot->is_avatar_segment ? ', avatar' : '') . ") ──");

            $motionResult = $this->runMotion($controller, $audioBook, $scene, $shot);
            $this->printResult('  Motion', $motionResult);

            $transitionResult = $this->runTransition($controller, $audioBook, $scene, $shot);
            $this->printResult('  Transition', $transitionResult);

            $rows[] = [
                'shot_id' => $shot->id,
                'scene_id' => $scene->id,
                'motion' => $motionResult,
                'transition' => $transitionResult,
            ];
            $this->newLine();
        }

        $motionFailures = collect($rows)->where('motion.outcome', 'error')->count();
        $transitionFailures = collect($rows)->where('transition.outcome', 'error')->count();

        $this->info('=== Tổng kết ===');
        $this->info("Shot đã chạy: {$shots->count()}");
        $this->info('Motion: ' . collect($rows)->where('motion.outcome', 'success')->count() . ' thành công, '
            . collect($rows)->where('motion.outcome', 'skipped')->count() . ' bỏ qua, '
            . $motionFailures . ' lỗi');
        $this->info('Transition: ' . collect($rows)->where('transition.outcome', 'success')->count() . ' thành công, '
            . collect($rows)->where('transition.outcome', 'skipped')->count() . ' bỏ qua, '
            . $transitionFailures . ' lỗi');
        $this->newLine();
        $this->warn('⚠️  Bắt buộc: XEM THẬT từng clip vừa render trước khi duyệt hàng loạt — không có điểm số tự động nào đảm bảo chất lượng thị giác. Preset đúng theo whitelist không có nghĩa là hình ảnh động trông đẹp.');

        return ($motionFailures + $transitionFailures) > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array{outcome:string,preset?:string,reason?:string,focus?:string,intensity?:float,path?:string,size_kb?:int,duration?:?float,error?:string}
     */
    private function runMotion(MotionDirectionController $controller, AudioBook $audioBook, AudiobookVideoScene $scene, AudiobookVideoShot $shot): array
    {
        if ($shot->is_avatar_segment || $shot->isResolvedAssetVideo()) {
            return ['outcome' => 'skipped', 'reason' => 'avatar hoặc nguồn đã là video — motion không áp dụng'];
        }

        try {
            $response = $controller->generateMotion(new Request(), $audioBook, $scene, $shot);
            $data = json_decode($response->getContent(), true);
            if (!($data['success'] ?? false)) {
                return ['outcome' => 'error', 'error' => $data['message'] ?? 'unknown error'];
            }

            $shot->refresh();
            return $this->describeRender('motion', $shot->motion_preset, $shot->motion_reason, $shot->motion_focus_x, $shot->motion_focus_y, $shot->motion_intensity, $shot->motion_asset_path);
        } catch (\Throwable $e) {
            return ['outcome' => 'error', 'error' => $e->getMessage()];
        }
    }

    /**
     * @return array{outcome:string,preset?:string,reason?:string,intensity?:float,path?:string,size_kb?:int,duration?:?float,error?:string}
     */
    private function runTransition(MotionDirectionController $controller, AudioBook $audioBook, AudiobookVideoScene $scene, AudiobookVideoShot $shot): array
    {
        try {
            $response = $controller->generateTransition(new Request(), $audioBook, $scene, $shot);
            $data = json_decode($response->getContent(), true);
            if (!($data['success'] ?? false)) {
                // The 422 "first shot of book" case is an EXPECTED skip, not a failure.
                $isFirstShotSkip = $response->getStatusCode() === 422 && str_contains($data['message'] ?? '', 'đầu tiên');
                return $isFirstShotSkip
                    ? ['outcome' => 'skipped', 'reason' => $data['message']]
                    : ['outcome' => 'error', 'error' => $data['message'] ?? 'unknown error'];
            }

            $shot->refresh();
            return $this->describeRender('transition', $shot->transition_preset, $shot->transition_reason, null, null, $shot->transition_intensity, $shot->transition_asset_path);
        } catch (\Throwable $e) {
            return ['outcome' => 'error', 'error' => $e->getMessage()];
        }
    }

    private function describeRender(string $kind, ?string $preset, ?string $reason, ?float $focusX, ?float $focusY, ?float $intensity, ?string $assetPath): array
    {
        $absolutePath = $assetPath ? storage_path('app/public/' . $assetPath) : null;
        $sizeKb = $absolutePath && file_exists($absolutePath) ? (int) round(filesize($absolutePath) / 1024) : null;
        $duration = $absolutePath && file_exists($absolutePath) ? $this->probeDuration($absolutePath) : null;

        return [
            'outcome' => 'success',
            'preset' => $preset,
            'reason' => $reason,
            'focus' => $focusX !== null ? sprintf('%.2f,%.2f', $focusX, $focusY) : null,
            'intensity' => $intensity,
            'path' => $assetPath,
            'size_kb' => $sizeKb,
            'duration' => $duration,
        ];
    }

    private function probeDuration(string $path): ?float
    {
        $ffprobePath = (string) config('services.ffmpeg.ffprobe_path', 'ffprobe');
        $command = sprintf('%s -v error -show_entries format=duration -of csv=p=0 %s 2>&1', escapeshellarg($ffprobePath), escapeshellarg($path));
        exec($command, $output, $returnCode);
        if ($returnCode === 0 && !empty($output[0]) && is_numeric(trim($output[0]))) {
            return round((float) trim($output[0]), 2);
        }
        return null;
    }

    private function printResult(string $label, array $result): void
    {
        if ($result['outcome'] === 'skipped') {
            $this->line("{$label}: ⏭️  bỏ qua — {$result['reason']}");
            return;
        }
        if ($result['outcome'] === 'error') {
            $this->error("{$label}: ❌ LỖI — {$result['error']}");
            return;
        }

        $focusTxt = $result['focus'] ? " · focus {$result['focus']}" : '';
        $this->line("{$label}: ✅ preset={$result['preset']}{$focusTxt} · intensity=" . number_format((float) $result['intensity'], 2)
            . " · {$result['size_kb']}KB · " . ($result['duration'] !== null ? "{$result['duration']}s" : '?s'));
        $this->line("{$label}   lý do: \"{$result['reason']}\"");
        $this->line("{$label}   file: storage/{$result['path']}");
    }
}

<?php

namespace App\Jobs;

use App\Models\AudioBook;
use App\Models\AudioBookVideoSegment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GenerateBatchVideoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $audioBookId;
    public array $segmentIds;
    public $timeout = 14400; // 4 hours

    public function __construct(int $audioBookId, array $segmentIds = [])
    {
        $this->audioBookId = $audioBookId;
        $this->segmentIds = $segmentIds;
    }

    public function handle(): void
    {
        $lockKey = $this->getBatchLockKey();
        $lockToken = (string) Str::uuid();

        if (!Cache::add($lockKey, $lockToken, now()->addHours(8))) {
            $this->addLog("Bo qua batch video: audiobook {$this->audioBookId} dang duoc xu ly boi mot job khac.");
            $this->updateProgress([
                'status' => 'processing',
                'message' => 'Dang co batch video khac chay cho audiobook nay. Bo qua job trung lap.',
            ]);
            return;
        }

        try {
        $audioBook = AudioBook::find($this->audioBookId);
        if (!$audioBook) return;

        $query = AudioBookVideoSegment::where('audio_book_id', $this->audioBookId)
            ->orderBy('sort_order');

        if (!empty($this->segmentIds)) {
            $query->whereIn('id', $this->segmentIds);
        } else {
            $query->where('status', '!=', 'completed');
        }

        $segments = $query->get();

        if ($segments->isEmpty()) {
            $this->updateProgress(['status' => 'completed', 'percent' => 100, 'message' => 'Khong co segment nao can xu ly.']);
            return;
        }

        $totalSegments = $segments->count();
        $ffmpeg = env('FFMPEG_PATH', 'ffmpeg');
        $ffprobe = env('FFPROBE_PATH', 'ffprobe');
        $bookDir = storage_path('app/public/books/' . $audioBook->id);
        $mp4Dir = $bookDir . '/mp4';
        if (!is_dir($mp4Dir)) mkdir($mp4Dir, 0755, true);

        foreach ($segments as $index => $segment) {
            $segmentLabel = "Segment " . ($index + 1) . "/{$totalSegments}: {$segment->name}";

            try {
                $segment->update(['status' => 'processing', 'error_message' => null]);

                $this->updateProgress([
                    'status' => 'processing',
                    'current_segment_id' => $segment->id,
                    'current_segment_index' => $index,
                    'total_segments' => $totalSegments,
                    'percent' => 0,
                    'message' => "{$segmentLabel} - Dang kiem tra audio..."
                ]);
                $this->addLog("========== Bat dau: {$segmentLabel} ==========");

                // Validate image
                $imagePath = null;
                if ($segment->image_path && $segment->image_type) {
                    $imagePath = storage_path('app/public/books/' . $audioBook->id . '/' . $segment->image_type . '/' . $segment->image_path);
                    if (!file_exists($imagePath)) {
                        throw new \Exception("File anh khong ton tai: {$segment->image_path}");
                    }
                } else {
                    throw new \Exception("Segment chua chon anh.");
                }

                // Collect chapter audio files
                $chapters = $segment->chapters ?? [];
                if (empty($chapters)) {
                    throw new \Exception("Segment khong co chuong nao.");
                }

                $audioFiles = [];
                $missingChapters = [];
                sort($chapters);

                foreach ($chapters as $chapterNum) {
                    if ($chapterNum === 0) {
                        // Introduction audio
                        if ($audioBook->description_audio) {
                            $introPath = storage_path('app/public/' . $audioBook->description_audio);
                            if (file_exists($introPath)) {
                                $audioFiles[] = $introPath;
                            } else {
                                $missingChapters[] = 'Giới thiệu';
                            }
                        } else {
                            $missingChapters[] = 'Giới thiệu';
                        }
                    } else {
                        $padded = str_pad($chapterNum, 3, '0', STR_PAD_LEFT);
                        $fullAudioPath = $bookDir . "/c_{$padded}_full.mp3";
                        if (file_exists($fullAudioPath)) {
                            $audioFiles[] = $fullAudioPath;
                        } else {
                            $missingChapters[] = $chapterNum;
                        }
                    }
                }

                if (!empty($missingChapters)) {
                    throw new \Exception("Thieu file TTS full cho chuong: " . implode(', ', $missingChapters));
                }

                $this->addLog("So file audio: " . count($audioFiles));

                // Create temp dir
                $tempDir = storage_path('app/temp/batch_seg_' . $segment->id . '_' . time());
                if (!is_dir($tempDir)) mkdir($tempDir, 0755, true);

                // Step 1: Concatenate audio files
                $this->updateProgress([
                    'status' => 'processing',
                    'current_segment_id' => $segment->id,
                    'current_segment_index' => $index,
                    'total_segments' => $totalSegments,
                    'percent' => 5,
                    'message' => "{$segmentLabel} - Dang ghep audio..."
                ]);

                $concatListPath = $tempDir . '/concat_list.txt';
                $concatContent = '';
                foreach ($audioFiles as $af) {
                    // FFmpeg concat demuxer expects file entries, not shell-escaped args.
                    $normalized = str_replace('\\', '/', $af);
                    $escaped = str_replace("'", "'\\''", $normalized);
                    $concatContent .= "file '{$escaped}'\n";
                }
                file_put_contents($concatListPath, $concatContent);

                $mergedVoicePath = $tempDir . '/merged_voice.mp3';
                $concatCmd = sprintf(
                    '%s -y -f concat -safe 0 -i %s -c:a libmp3lame -b:a 192k %s 2>&1',
                    escapeshellarg($ffmpeg), escapeshellarg($concatListPath), escapeshellarg($mergedVoicePath)
                );

                $this->addLog("FFmpeg: ghep audio...");
                exec($concatCmd, $concatOutput, $concatReturnCode);

                if ($concatReturnCode !== 0 || !file_exists($mergedVoicePath)) {
                    $this->cleanupDir($tempDir);
                    throw new \Exception("FFmpeg khong the ghep audio.");
                }

                // Get voice duration
                $dCmd = sprintf('%s -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 %s',
                    escapeshellarg($ffprobe), escapeshellarg($mergedVoicePath));
                $dOut = [];
                exec($dCmd, $dOut);
                $voiceDuration = !empty($dOut) ? (float) $dOut[0] : 0;
                $this->addLog("Thoi luong voice: " . round($voiceDuration, 1) . "s");

                // Step 2: Mix music (if available)
                $introMusicPath = $audioBook->intro_music
                    ? storage_path('app/public/' . $audioBook->intro_music) : null;
                $hasMusic = $introMusicPath && file_exists($introMusicPath);

                $introFadeDuration = $hasMusic ? (float) ($audioBook->intro_fade_duration ?? 3) : 0;
                $outroExtendDuration = $hasMusic ? (float) ($audioBook->outro_extend_duration ?? 5) : 0;
                $outroFadeDuration = $hasMusic ? (float) ($audioBook->outro_fade_duration ?? 10) : 0;
                $totalDuration = $introFadeDuration + $voiceDuration + $outroExtendDuration;

                $mixedAudioPath = $tempDir . '/mixed_audio.mp3';

                if ($hasMusic) {
                    $this->updateProgress([
                        'status' => 'processing',
                        'current_segment_id' => $segment->id,
                        'current_segment_index' => $index,
                        'total_segments' => $totalSegments,
                        'percent' => 15,
                        'message' => "{$segmentLabel} - Dang tron nhac nen..."
                    ]);

                    $voiceStartTime = $introFadeDuration;
                    $voiceEndTime = $voiceStartTime + $voiceDuration;
                    $introFadeOutDuration = min(1.5, max(0.2, $introFadeDuration * 0.5));
                    $introFadeOutStart = max(0, $voiceStartTime - $introFadeOutDuration);
                    $outroDuration = max(0, $outroExtendDuration);
                    $outroFadeOutDuration = $outroDuration > 0
                        ? min($outroFadeDuration, max(0.2, $outroDuration)) : 0;
                    $outroFadeOutStart = $voiceEndTime + max(0, $outroDuration - $outroFadeOutDuration);

                    if ($outroDuration > 0) {
                        $musicVolumeExpr = sprintf(
                            'if(lt(t,%s),1,if(lt(t,%s),1-(t-%s)/%s,if(lt(t,%s),0,if(lt(t,%s),1,if(lt(t,%s),1-(t-%s)/%s,0)))))',
                            round($introFadeOutStart, 2), round($voiceStartTime, 2),
                            round($introFadeOutStart, 2), round($introFadeOutDuration, 2),
                            round($voiceEndTime, 2), round($outroFadeOutStart, 2),
                            round($voiceEndTime + $outroDuration, 2),
                            round($outroFadeOutStart, 2), round($outroFadeOutDuration, 2)
                        );
                    } else {
                        $musicVolumeExpr = sprintf(
                            'if(lt(t,%s),1,if(lt(t,%s),1-(t-%s)/%s,0))',
                            round($introFadeOutStart, 2), round($voiceStartTime, 2),
                            round($introFadeOutStart, 2), round($introFadeOutDuration, 2)
                        );
                    }

                    $audioFilterComplex = sprintf(
                        '[0:a]aloop=loop=-1:size=2e+09,atrim=0:%s,' .
                        'volume=eval=frame:volume=\'%s\',aformat=sample_fmts=fltp[music];' .
                        '[1:a]adelay=%d|%d,aformat=sample_fmts=fltp[voice];' .
                        '[music][voice]amix=inputs=2:duration=first:dropout_transition=3[mixout]',
                        round($totalDuration, 2), $musicVolumeExpr,
                        (int)($voiceStartTime * 1000), (int)($voiceStartTime * 1000)
                    );

                    $mixCmd = sprintf(
                        '%s -y -i %s -i %s -filter_complex "%s" -map "[mixout]" -c:a libmp3lame -b:a 192k %s 2>&1',
                        escapeshellarg($ffmpeg), escapeshellarg($introMusicPath),
                        escapeshellarg($mergedVoicePath), $audioFilterComplex,
                        escapeshellarg($mixedAudioPath)
                    );

                    $this->addLog("FFmpeg: tron nhac nen...");
                    exec($mixCmd, $mixOutput, $mixReturnCode);

                    if ($mixReturnCode !== 0 || !file_exists($mixedAudioPath)) {
                        $this->cleanupDir($tempDir);
                        throw new \Exception("FFmpeg khong the tron nhac nen.");
                    }
                } else {
                    copy($mergedVoicePath, $mixedAudioPath);
                    $totalDuration = $voiceDuration;
                }

                // Step 3: Create video
                $this->updateProgress([
                    'status' => 'processing',
                    'current_segment_id' => $segment->id,
                    'current_segment_index' => $index,
                    'total_segments' => $totalSegments,
                    'percent' => 25,
                    'message' => "{$segmentLabel} - Dang tao video..."
                ]);

                $outputPath = $mp4Dir . "/segment_{$segment->id}.mp4";
                if (file_exists($outputPath)) {
                    // On Windows, the file may still be held by a previous FFmpeg process.
                    // Rename first (works even with open handles), then delete.
                    $oldPath = $outputPath . '.old_' . time();
                    if (@rename($outputPath, $oldPath)) {
                        @unlink($oldPath);
                    } else {
                        // Retry unlink with brief delay
                        for ($retry = 0; $retry < 3; $retry++) {
                            if (@unlink($outputPath)) break;
                            usleep(500000); // 0.5s
                        }
                    }
                }

                $waveEnabled = $audioBook->wave_enabled ?? false;
                $videoWidth = 1280;
                $videoHeight = 720;
                $baseFilter = "scale={$videoWidth}:{$videoHeight}:force_original_aspect_ratio=decrease,pad={$videoWidth}:{$videoHeight}:(ow-iw)/2:(oh-ih)/2";

                if ($waveEnabled) {
                    $rawWaveType = $audioBook->wave_type ?? 'cline';
                    $waveTypeMap = ['point'=>'point','line'=>'line','p2p'=>'p2p','cline'=>'cline','bar'=>'line'];
                    $waveType = $waveTypeMap[$rawWaveType] ?? 'cline';
                    $wavePosition = $audioBook->wave_position ?? 'bottom';
                    $waveHeight = $audioBook->wave_height ?? 100;
                    $waveColor = ltrim($audioBook->wave_color ?? '#00ff00', '#');
                    $waveOpacity = $audioBook->wave_opacity ?? 0.8;

                    switch ($wavePosition) {
                        case 'top': $waveY = 20; break;
                        case 'center': $waveY = ($videoHeight - $waveHeight) / 2; break;
                        default: $waveY = $videoHeight - $waveHeight - 20; break;
                    }

                    $filterComplex = sprintf(
                        '[0:v]%s[bg];[1:a]showwaves=s=%dx%d:mode=%s:colors=0x%s@%.1f:rate=15[wave];[bg][wave]overlay=0:%d:format=auto[out]',
                        $baseFilter, $videoWidth, $waveHeight, $waveType, $waveColor, $waveOpacity, $waveY
                    );

                    $videoCmd = sprintf(
                        '%s -y -loop 1 -framerate 15 -i %s -i %s -filter_complex "%s" -map "[out]" -map 1:a ' .
                        '-c:v libx264 -preset ultrafast -tune stillimage -crf 28 -c:a aac -b:a 128k ' .
                        '-pix_fmt yuv420p -shortest -threads 0 -progress pipe:1 -stats %s',
                        escapeshellarg($ffmpeg), escapeshellarg($imagePath), escapeshellarg($mixedAudioPath),
                        $filterComplex, escapeshellarg($outputPath)
                    );
                } else {
                    $videoCmd = sprintf(
                        '%s -y -loop 1 -framerate 1 -i %s -i %s -c:v libx264 -preset ultrafast -tune stillimage -crf 28 ' .
                        '-c:a aac -b:a 128k -pix_fmt yuv420p -r 1 -shortest -threads 0 -vf "%s" -progress pipe:1 -stats %s',
                        escapeshellarg($ffmpeg), escapeshellarg($imagePath), escapeshellarg($mixedAudioPath),
                        $baseFilter, escapeshellarg($outputPath)
                    );
                }

                $this->addLog("FFmpeg: tao video...");
                Log::info("Batch segment video cmd", ['segment_id' => $segment->id, 'cmd' => $videoCmd]);

                $videoResult = $this->runFfmpegWithProgress($videoCmd, $totalDuration, $index, $totalSegments);
                $returnCode = (int) ($videoResult['return_code'] ?? 1);
                $outputExists = file_exists($outputPath);
                $outputSize = $outputExists ? (int) filesize($outputPath) : 0;

                // On Windows, proc_close() can return -1 despite FFmpeg completing successfully.
                $isWindowsFalseNegative = $returnCode === -1 && $outputExists && $outputSize > 102400;
                $hasValidOutput = $outputExists && $outputSize > 1024;

                if ((!$hasValidOutput && !$isWindowsFalseNegative) || ($returnCode !== 0 && !$isWindowsFalseNegative)) {
                    $tail = array_slice($videoResult['output'] ?? [], -20);
                    if (!empty($tail)) {
                        Log::error('Batch segment FFmpeg video output (tail)', [
                            'segment_id' => $segment->id,
                            'return_code' => $returnCode,
                            'output_size' => $outputSize,
                            'tail' => $tail,
                        ]);
                    }
                    $this->cleanupDir($tempDir);
                    throw new \Exception("FFmpeg khong the tao video. Code: {$returnCode}");
                }

                $this->cleanupDir($tempDir);

                // Get video duration
                $durCmd = sprintf('%s -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 %s',
                    escapeshellarg($ffprobe), escapeshellarg($outputPath));
                $durOut = [];
                exec($durCmd, $durOut);
                $videoDuration = !empty($durOut) ? (float) $durOut[0] : $totalDuration;

                $relativePath = 'books/' . $audioBook->id . '/mp4/segment_' . $segment->id . '.mp4';
                $segment->update([
                    'video_path' => $relativePath,
                    'video_duration' => $videoDuration,
                    'status' => 'completed',
                    'error_message' => null,
                ]);

                $this->addLog("Hoan tat {$segmentLabel}: " . gmdate('H:i:s', (int)$videoDuration));
                Log::info("Batch segment completed", ['segment_id' => $segment->id, 'duration' => $videoDuration]);

            } catch (\Exception $e) {
                $segment->update([
                    'status' => 'error',
                    'error_message' => $e->getMessage(),
                ]);
                $this->addLog("LOI {$segmentLabel}: " . $e->getMessage());
                Log::error("Batch segment failed", ['segment_id' => $segment->id, 'error' => $e->getMessage()]);
                // Continue to next segment
            }
        }

        // All done
        $this->updateProgress([
            'status' => 'completed',
            'current_segment_id' => null,
            'current_segment_index' => $totalSegments,
            'total_segments' => $totalSegments,
            'percent' => 100,
            'message' => "Hoan tat tat ca {$totalSegments} segments!"
        ]);
        $this->addLog("========== HOAN TAT TAT CA ==========");
        } finally {
            $this->releaseBatchLock($lockKey, $lockToken);
        }
    }

    private function getBatchLockKey(): string
    {
        return "batch_video_lock_{$this->audioBookId}";
    }

    private function releaseBatchLock(string $lockKey, string $lockToken): void
    {
        if (Cache::get($lockKey) === $lockToken) {
            Cache::forget($lockKey);
        }
    }

    private function updateProgress(array $data): void
    {
        $payload = array_merge([
            'status' => 'processing', 'percent' => 0, 'message' => '',
            'current_segment_id' => null, 'current_segment_index' => 0,
            'total_segments' => 0, 'updated_at' => now()->toIso8601String()
        ], $data);
        Cache::put("batch_video_progress_{$this->audioBookId}", $payload, now()->addHours(5));
    }

    private function addLog(string $line): void
    {
        $key = "batch_video_log_{$this->audioBookId}";
        $logs = Cache::get($key, []);
        $logs[] = "[" . now()->format('H:i:s') . "] {$line}";
        if (count($logs) > 500) $logs = array_slice($logs, -500);
        Cache::put($key, $logs, now()->addHours(5));
    }

    private function runFfmpegWithProgress(string $command, float $totalDuration, int $segIndex, int $totalSegs): array
    {
        $output = [];
        $process = proc_open($command, [
            0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']
        ], $pipes);

        if (!is_resource($process)) {
            $this->addLog("FFmpeg: khong the khoi tao process");
            return ['return_code' => 1, 'output' => []];
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $lastPercent = 0;
        $lastLogAt = time();
        $buffer = '';

        while (true) {
            $read = [$pipes[1], $pipes[2]];
            $write = null;
            $except = null;
            stream_select($read, $write, $except, 1);

            foreach ($read as $stream) {
                $data = stream_get_contents($stream);
                if ($data === false || $data === '') continue;
                $buffer .= $data;
                $lines = explode("\n", $buffer);
                $buffer = array_pop($lines);

                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '') continue;
                    $output[] = $line;

                    if (str_starts_with($line, 'out_time_ms=') && $totalDuration > 0) {
                        $value = (int) str_replace('out_time_ms=', '', $line);
                        $segPercent = min(95, max(25, 25 + (int) round(($value / ($totalDuration * 1000000)) * 70)));
                        if ($segPercent !== $lastPercent) {
                            $lastPercent = $segPercent;
                            $this->updateProgress([
                                'status' => 'processing',
                                'current_segment_id' => null, // will be set by caller context
                                'current_segment_index' => $segIndex,
                                'total_segments' => $totalSegs,
                                'percent' => $segPercent,
                                'message' => "Dang tao video segment " . ($segIndex + 1) . "/{$totalSegs}..."
                            ]);
                        }
                    }

                    if ((str_starts_with($line, 'frame=') || str_starts_with($line, 'speed=')) && time() - $lastLogAt >= 5) {
                        $this->addLog("FFmpeg: {$line}");
                        $lastLogAt = time();
                    }
                }
            }

            $status = proc_get_status($process);
            if (!$status['running']) break;
        }

        $remaining = trim($buffer);
        if ($remaining !== '') $output[] = $remaining;

        foreach ([$pipes[1], $pipes[2]] as $pipe) {
            $leftover = stream_get_contents($pipe);
            if ($leftover) {
                foreach (explode("\n", $leftover) as $l) {
                    $l = trim($l);
                    if ($l !== '') $output[] = $l;
                }
            }
            fclose($pipe);
        }

        return ['return_code' => proc_close($process), 'output' => $output];
    }

    private function cleanupDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getRealPath()) : @unlink($item->getRealPath());
        }
        @rmdir($dir);
    }
}

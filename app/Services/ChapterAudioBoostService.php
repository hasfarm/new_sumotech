<?php

namespace App\Services;

use App\Models\AudioBook;
use App\Models\AudioBookChapter;
use Illuminate\Support\Facades\Log;

class ChapterAudioBoostService
{
    private string $ffmpegPath;
    private string $ffprobePath;

    public function __construct()
    {
        $this->ffmpegPath = config('services.ffmpeg.path', 'ffmpeg');
        $this->ffprobePath = config('services.ffmpeg.ffprobe_path', 'ffprobe');
    }

    public function boostChapter(AudioBook $audioBook, AudioBookChapter $chapter, int $db = 16): array
    {
        if (!$chapter->audio_file) {
            return [
                'success' => false,
                'error' => 'Chương chưa có file audio.'
            ];
        }

        $fullPath = storage_path('app/public/' . $chapter->audio_file);
        if (!file_exists($fullPath)) {
            return [
                'success' => false,
                'error' => 'File audio chương không tồn tại trên server.'
            ];
        }

        [$introPath, $outroPath, $hasIntro, $hasOutro] = $this->resolveMusicPaths($audioBook);

        if (!$hasIntro && !$hasOutro) {
            return $this->boostFullTrackOnly($chapter, $fullPath, $db);
        }

        $chunks = $chapter->chunks()
            ->where('status', 'completed')
            ->whereNotNull('audio_file')
            ->orderBy('chunk_number')
            ->get();

        $chunkPaths = [];
        foreach ($chunks as $chunk) {
            $chunkPath = storage_path('app/public/' . $chunk->audio_file);
            if (file_exists($chunkPath)) {
                $chunkPaths[] = $chunkPath;
            }
        }

        if (empty($chunkPaths)) {
            return $this->boostMiddleKeepMusicSegments($audioBook, $chapter, $fullPath, $db, $introPath, $outroPath, $hasIntro, $hasOutro);
        }

        $workDir = storage_path('app/temp/chapter_boost_' . $chapter->id . '_' . uniqid('', true));
        if (!is_dir($workDir)) {
            mkdir($workDir, 0755, true);
        }

        try {
            $voiceSourcePath = $workDir . '/voice_source.mp3';
            if (!$this->concatChunkVoice($chunkPaths, $voiceSourcePath)) {
                return [
                    'success' => false,
                    'error' => 'Không thể ghép voice từ chunks để boost.'
                ];
            }

            $voiceBoostedPath = $workDir . '/voice_boosted.mp3';
            if (!$this->boostVoiceOnly($voiceSourcePath, $voiceBoostedPath, $db)) {
                return [
                    'success' => false,
                    'error' => 'FFmpeg boost voice-only thất bại.'
                ];
            }

            $voiceDuration = $this->getAudioDuration($voiceBoostedPath);
            if (!$voiceDuration || $voiceDuration <= 0) {
                return [
                    'success' => false,
                    'error' => 'Không thể đọc duration voice sau khi boost.'
                ];
            }

            $mixedPath = $workDir . '/mixed_output.mp3';
            $mixedOk = $this->mixIntroOutroMusic(
                $voiceBoostedPath,
                $mixedPath,
                $audioBook,
                $voiceDuration,
                $introPath,
                $outroPath,
                $hasIntro,
                $hasOutro
            );

            if (!$mixedOk || !file_exists($mixedPath)) {
                return [
                    'success' => false,
                    'error' => 'Không thể remix nhạc intro/outro sau khi boost giọng.'
                ];
            }

            if (file_exists($fullPath)) {
                unlink($fullPath);
            }

            if (!@rename($mixedPath, $fullPath)) {
                if (!@copy($mixedPath, $fullPath)) {
                    return [
                        'success' => false,
                        'error' => 'Không thể ghi đè file audio chương sau khi boost.'
                    ];
                }
                @unlink($mixedPath);
            }

            $chapter->audio_boosted_at = now();
            $chapter->save();

            Log::info("Boosted chapter voice +{$db}dB with preserved music", [
                'chapter_id' => $chapter->id,
                'audio_file' => $chapter->audio_file,
                'has_intro' => $hasIntro,
                'has_outro' => $hasOutro,
                'chunk_count' => count($chunkPaths),
            ]);

            return [
                'success' => true,
                'mode' => 'voice-remix',
                'message' => 'Đã boost giọng và giữ nguyên mức nhạc intro/outro.'
            ];
        } catch (\Throwable $e) {
            Log::error('Boost chapter voice-remix failed: ' . $e->getMessage(), [
                'chapter_id' => $chapter->id,
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        } finally {
            $this->deleteDirectory($workDir);
        }
    }

    private function resolveMusicPaths(AudioBook $audioBook): array
    {
        $introPath = $audioBook->intro_music ? storage_path('app/public/' . $audioBook->intro_music) : null;
        $hasIntro = $introPath && file_exists($introPath);

        $outroUseIntro = $audioBook->outro_use_intro ?? false;
        if ($outroUseIntro && $hasIntro) {
            $outroPath = $introPath;
        } else {
            $outroPath = $audioBook->outro_music ? storage_path('app/public/' . $audioBook->outro_music) : null;
        }

        $hasOutro = $outroPath && file_exists($outroPath);

        return [$introPath, $outroPath, $hasIntro, $hasOutro];
    }

    private function boostFullTrackOnly(AudioBookChapter $chapter, string $fullPath, int $db): array
    {
        $tempPath = $fullPath . '.boost_tmp.mp3';
        $cmd = "{$this->ffmpegPath} -y -i " . escapeshellarg($fullPath) . " -af " . escapeshellarg("volume={$db}dB") . " -c:a libmp3lame -b:a 192k " . escapeshellarg($tempPath) . " 2>&1";
        exec($cmd, $out, $code);

        if ($code !== 0 || !file_exists($tempPath)) {
            if (file_exists($tempPath)) {
                @unlink($tempPath);
            }

            return [
                'success' => false,
                'error' => 'FFmpeg boost thất bại: ' . implode("\n", $out)
            ];
        }

        @unlink($fullPath);
        @rename($tempPath, $fullPath);

        $chapter->audio_boosted_at = now();
        $chapter->save();

        Log::info("Boosted chapter full-track +{$db}dB (no intro/outro music configured)", [
            'chapter_id' => $chapter->id,
            'audio_file' => $chapter->audio_file,
        ]);

        return [
            'success' => true,
            'mode' => 'full-track',
            'message' => 'Chương không có nhạc intro/outro, đã boost toàn track.'
        ];
    }

    private function boostMiddleKeepMusicSegments(
        AudioBook $audioBook,
        AudioBookChapter $chapter,
        string $fullPath,
        int $db,
        ?string $introPath,
        ?string $outroPath,
        bool $hasIntro,
        bool $hasOutro
    ): array {
        $totalDuration = $this->getAudioDuration($fullPath);
        if (!$totalDuration || $totalDuration <= 0) {
            return [
                'success' => false,
                'error' => 'Không đọc được duration file audio chương.'
            ];
        }

        $introFadeDuration = (float) ($audioBook->intro_fade_duration ?? 3);
        $outroFadeDuration = (float) ($audioBook->outro_fade_duration ?? 10);
        $outroExtendDuration = (float) ($audioBook->outro_extend_duration ?? 5);

        $introProtected = 0.0;
        if ($hasIntro && $introPath) {
            $introMusicDuration = $this->getAudioDuration($introPath) ?? 5.0;
            $introDuration = min($introMusicDuration, 5.0);
            $introProtected = max(0.0, $introDuration + $introFadeDuration);
        }

        $outroProtected = 0.0;
        if ($hasOutro && $outroPath) {
            $outroProtected = max(0.0, $outroFadeDuration + $outroExtendDuration);
        }

        $middleStart = min($totalDuration, $introProtected);
        $middleEnd = max($middleStart, $totalDuration - $outroProtected);
        $middleDuration = $middleEnd - $middleStart;

        if ($middleDuration < 0.5) {
            return [
                'success' => false,
                'error' => 'Không đủ phần voice ở giữa để boost tách nhạc. Vui lòng tạo lại TTS chunks để boost chính xác.'
            ];
        }

        $tmpPath = $fullPath . '.boost_mid_tmp.mp3';
        $filterParts = [];
        $concatInputs = [];

        if ($middleStart > 0.05) {
            $filterParts[] = "[0:a]atrim=0:{$middleStart},asetpts=PTS-STARTPTS[pintro]";
            $concatInputs[] = '[pintro]';
        }

        $voiceFilter = "volume={$db}dB,acompressor=threshold=-18dB:ratio=3:attack=5:release=80,alimiter=limit=0.95";
        $filterParts[] = "[0:a]atrim={$middleStart}:{$middleEnd},asetpts=PTS-STARTPTS,{$voiceFilter}[pmid]";
        $concatInputs[] = '[pmid]';

        if (($totalDuration - $middleEnd) > 0.05) {
            $filterParts[] = "[0:a]atrim={$middleEnd}:{$totalDuration},asetpts=PTS-STARTPTS[poutro]";
            $concatInputs[] = '[poutro]';
        }

        $concatCount = count($concatInputs);
        $filterParts[] = implode('', $concatInputs) . "concat=n={$concatCount}:v=0:a=1[out]";
        $filterComplex = implode(';', $filterParts);

        $cmd = "{$this->ffmpegPath} -y -i " . escapeshellarg($fullPath) . " -filter_complex " . escapeshellarg($filterComplex) . " -map \"[out]\" -c:a libmp3lame -b:a 192k " . escapeshellarg($tmpPath) . " 2>&1";
        exec($cmd, $out, $code);

        if ($code !== 0 || !file_exists($tmpPath)) {
            if (file_exists($tmpPath)) {
                @unlink($tmpPath);
            }

            return [
                'success' => false,
                'error' => 'FFmpeg boost đoạn voice giữa thất bại: ' . implode("\n", $out)
            ];
        }

        @unlink($fullPath);
        @rename($tmpPath, $fullPath);

        $chapter->audio_boosted_at = now();
        $chapter->save();

        Log::info("Boosted chapter middle voice +{$db}dB while preserving intro/outro segments", [
            'chapter_id' => $chapter->id,
            'audio_file' => $chapter->audio_file,
            'total_duration' => $totalDuration,
            'middle_start' => $middleStart,
            'middle_end' => $middleEnd,
            'middle_duration' => $middleDuration,
        ]);

        return [
            'success' => true,
            'mode' => 'segment-preserve',
            'message' => 'Đã giữ nguyên đoạn nhạc intro/outro và chỉ boost phần voice ở giữa.'
        ];
    }

    private function concatChunkVoice(array $chunkPaths, string $outputPath): bool
    {
        $listFile = dirname($outputPath) . '/concat_list.txt';
        $listContent = '';

        foreach ($chunkPaths as $path) {
            $normalized = str_replace('\\', '/', $path);
            $escaped = str_replace("'", "'\\''", $normalized);
            $listContent .= "file '{$escaped}'\n";
        }

        if ($listContent === '') {
            return false;
        }

        file_put_contents($listFile, $listContent);

        $cmd = "{$this->ffmpegPath} -f concat -safe 0 -i " . escapeshellarg($listFile) . " -ar 44100 -ac 2 -c:a libmp3lame -b:a 192k " . escapeshellarg($outputPath) . " -y 2>&1";
        exec($cmd, $out, $code);

        @unlink($listFile);

        if ($code !== 0 || !file_exists($outputPath)) {
            Log::warning('concatChunkVoice failed', [
                'output' => implode("\n", $out),
            ]);
            return false;
        }

        return true;
    }

    private function boostVoiceOnly(string $sourcePath, string $outputPath, int $db): bool
    {
        $filter = "volume={$db}dB,acompressor=threshold=-18dB:ratio=3:attack=5:release=80,alimiter=limit=0.95";
        $cmd = "{$this->ffmpegPath} -y -i " . escapeshellarg($sourcePath) . " -af " . escapeshellarg($filter) . " -c:a libmp3lame -b:a 192k " . escapeshellarg($outputPath) . " 2>&1";
        exec($cmd, $out, $code);

        if ($code !== 0 || !file_exists($outputPath)) {
            Log::warning('boostVoiceOnly failed', [
                'output' => implode("\n", $out),
            ]);
            return false;
        }

        return true;
    }

    private function mixIntroOutroMusic(
        string $voicePath,
        string $outputPath,
        AudioBook $audioBook,
        float $voiceDuration,
        ?string $introPath,
        ?string $outroPath,
        bool $hasIntro,
        bool $hasOutro
    ): bool {
        $introFadeDuration = (float) ($audioBook->intro_fade_duration ?? 3);
        $outroFadeDuration = (float) ($audioBook->outro_fade_duration ?? 10);
        $outroExtendDuration = (float) ($audioBook->outro_extend_duration ?? 5);

        $filterComplex = [];
        $inputs = ["-i " . escapeshellarg($voicePath)];
        $inputIndex = 1;

        $voiceInput = "[0:a]";
        $introDuration = 0.0;

        if ($hasIntro && $introPath) {
            $inputs[] = "-i " . escapeshellarg($introPath);
            $introInput = "[{$inputIndex}:a]";
            $inputIndex++;

            $introMusicDuration = $this->getAudioDuration($introPath);
            $introDuration = min($introMusicDuration ?? 5.0, 5.0);
            $introFadeStart = max(0, $introDuration - 0.5);

            $filterComplex[] = "{$introInput}atrim=0:" . ($introDuration + $introFadeDuration) . ",afade=t=out:st={$introFadeStart}:d={$introFadeDuration}[intro]";
        }

        if ($hasOutro && $outroPath) {
            $inputs[] = "-i " . escapeshellarg($outroPath);
            $outroInput = "[{$inputIndex}:a]";
            $inputIndex++;

            $outroTotalDuration = $outroFadeDuration + $outroExtendDuration;
            $outroFadeOutStart = max(0, $outroTotalDuration - 2);
            $filterComplex[] = "{$outroInput}atrim=0:{$outroTotalDuration},afade=t=in:st=0:d={$outroFadeDuration},afade=t=out:st={$outroFadeOutStart}:d=2[outro]";
        }

        if ($hasIntro && $hasOutro) {
            $voiceDelay = (int) round($introDuration * 1000);
            $filterComplex[] = "{$voiceInput}adelay={$voiceDelay}|{$voiceDelay}[voicedelayed]";

            $outroDelay = (int) round(($introDuration + max(0, $voiceDuration - $outroFadeDuration)) * 1000);
            $filterComplex[] = "[outro]adelay={$outroDelay}|{$outroDelay}[outrodelayed]";

            $filterComplex[] = "[intro][voicedelayed]amix=inputs=2:duration=longest:normalize=0[premix]";
            $filterComplex[] = "[premix][outrodelayed]amix=inputs=2:duration=longest:normalize=0[final]";
        } elseif ($hasIntro) {
            $voiceDelay = (int) round($introDuration * 1000);
            $filterComplex[] = "{$voiceInput}adelay={$voiceDelay}|{$voiceDelay}[voicedelayed]";
            $filterComplex[] = "[intro][voicedelayed]amix=inputs=2:duration=longest:normalize=0[final]";
        } elseif ($hasOutro) {
            $outroDelay = (int) round(max(0, $voiceDuration - $outroFadeDuration) * 1000);
            $filterComplex[] = "[outro]adelay={$outroDelay}|{$outroDelay}[outrodelayed]";
            $filterComplex[] = "{$voiceInput}[outrodelayed]amix=inputs=2:duration=longest:normalize=0[final]";
        } else {
            return @copy($voicePath, $outputPath);
        }

        $cmd = "{$this->ffmpegPath} -y " . implode(' ', $inputs) . " -filter_complex " . escapeshellarg(implode(';', $filterComplex)) . " -map \"[final]\" -c:a libmp3lame -b:a 192k " . escapeshellarg($outputPath) . " 2>&1";
        exec($cmd, $out, $code);

        if ($code !== 0 || !file_exists($outputPath)) {
            Log::warning('mixIntroOutroMusic failed', [
                'output' => implode("\n", $out),
                'has_intro' => $hasIntro,
                'has_outro' => $hasOutro,
            ]);
            return false;
        }

        return true;
    }

    private function getAudioDuration(string $filePath): ?float
    {
        if (!file_exists($filePath)) {
            return null;
        }

        $cmd = "{$this->ffprobePath} -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 " . escapeshellarg($filePath) . " 2>&1";
        exec($cmd, $output, $code);

        if ($code === 0 && isset($output[0]) && is_numeric($output[0])) {
            return (float) $output[0];
        }

        return null;
    }

    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}

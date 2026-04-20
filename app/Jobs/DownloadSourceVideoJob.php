<?php

namespace App\Jobs;

use App\Models\DubSyncProject;
use App\Services\TranslationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class DownloadSourceVideoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600; // 1 hour max
    public int $tries   = 1;    // No retry — partial files may exist

    public function __construct(
        public readonly int   $projectId,
        public readonly array $source   // ['platform' => '...', 'url' => '...']
    ) {}

    public function handle(): void
    {
        $projectId = $this->projectId;
        $source    = $this->source;
        $isBilibili = $source['platform'] === 'bilibili';

        try {
            $project = DubSyncProject::findOrFail($projectId);

            $videoDir = Storage::path("public/projects/{$projectId}/video");
            if (!file_exists($videoDir)) {
                mkdir($videoDir, 0755, true);
            }

            $ytDlpPath = env('YTDLP_PATH', 'python -m yt_dlp');
            $outputTemplate = "{$videoDir}/%(title)s.%(ext)s";

            $ffmpegPath     = env('FFMPEG_PATH', '');
            $ffmpegLocation = '';
            if ($ffmpegPath) {
                if (is_file($ffmpegPath)) {
                    $ffmpegLocation = dirname($ffmpegPath);
                } elseif (is_dir($ffmpegPath)) {
                    $ffmpegLocation = $ffmpegPath;
                }
            }

            $args = [];
            if ($isBilibili) {
                $args[] = '-f "bv*+ba/b"';
                $args[] = '--concurrent-fragments 4';
                $args[] = '--add-header "Referer: https://www.bilibili.com/"';
                $sessdata = env('BILIBILI_SESSDATA', '');
                if ($sessdata !== '') {
                    $args[] = '--add-header ' . escapeshellarg('Cookie: SESSDATA=' . $sessdata);
                }
            } else {
                $args[] = '-f "best[ext=mp4]/best"';
            }

            if ($ffmpegLocation !== '') {
                $args[] = '--ffmpeg-location ' . escapeshellarg($ffmpegLocation);
            }

            $args[] = '--merge-output-format mp4';
            $args[] = '--no-part';
            $args[] = '--no-continue';
            $args[] = '--force-overwrites';
            $args[] = '--newline';
            $args[] = '--no-colors';
            $args[] = '-o ' . '"' . $outputTemplate . '"';
            $args[] = escapeshellarg($source['url']);

            $this->cleanupStaleDownloadArtifacts($videoDir);
            $this->setProgress($projectId, 'processing', 5, 'Đang khởi động tải xuống...');

            $command = trim($ytDlpPath . ' ' . implode(' ', $args)) . ' 2>&1';

            \Log::info('[DownloadSourceVideoJob] Executing yt-dlp', [
                'project_id' => $projectId,
                'platform'   => $source['platform'],
                'url'        => $source['url'],
                'command'    => $command,
            ]);

            set_time_limit(0);
            $progressRanges = $isBilibili ? [[5, 58], [58, 80]] : [[5, 88]];
            $descriptors    = [0 => ['pipe', 'r'], 1 => ['pipe', 'w']];
            $process        = proc_open($command, $descriptors, $pipes);

            if (!is_resource($process)) {
                throw new \Exception('Không thể khởi động yt-dlp');
            }

            fclose($pipes[0]);
            stream_set_blocking($pipes[1], false);

            $outputLines = [];
            $lineBuffer  = '';
            $streamIndex = 0;
            $inMerger    = false;

            while (true) {
                $status = proc_get_status($process);
                $chunk  = fread($pipes[1], 8192);

                if ($chunk !== false && $chunk !== '') {
                    $lineBuffer .= $chunk;
                    $parts      = preg_split('/\r\n|\r|\n/', $lineBuffer);
                    $lineBuffer = array_pop($parts);

                    foreach ($parts as $line) {
                        $line = trim($line);
                        if ($line === '') continue;
                        $outputLines[] = $line;

                        if (stripos($line, '[download] Destination:') !== false) {
                            $streamIndex++;
                            $inMerger = false;
                        }

                        if (!$inMerger && preg_match('/\[Merger\]|\[ffmpeg\]/i', $line)) {
                            $inMerger = true;
                            $this->setProgress($projectId, 'processing', 83, 'Đang ghép video + âm thanh...');
                        }

                        if (!$inMerger && preg_match(
                            '/\[download\]\s+([\d.]+)%\s+of\s+~?([\d.]+\s*\S+)\s+at\s+([\d.]+\s*\S+\/s)\s+ETA\s+(\S+)/i',
                            $line, $m
                        )) {
                            $ytPercent = (float) $m[1];
                            $speed     = trim($m[3]);
                            $eta       = $m[4];

                            $rangeIdx      = max(0, min($streamIndex - 1, count($progressRanges) - 1));
                            [$rMin, $rMax] = $progressRanges[$rangeIdx];
                            $mapped        = (int) ($rMin + ($ytPercent / 100) * ($rMax - $rMin));

                            $etaText = ($eta !== 'Unknown' && $eta !== '00:00') ? " • Còn: {$eta}" : '';
                            $this->setProgress($projectId, 'processing', $mapped, "Đang tải {$ytPercent}% • {$speed}{$etaText}", [
                                'speed'      => $speed,
                                'eta'        => $eta,
                                'yt_percent' => $ytPercent,
                            ]);
                        }
                    }
                }

                if (!$status['running']) {
                    while (!feof($pipes[1])) {
                        $chunk = fread($pipes[1], 8192);
                        if ($chunk === false || $chunk === '') break;
                        $lineBuffer .= $chunk;
                    }
                    if ($lineBuffer !== '') {
                        $outputLines[] = trim($lineBuffer);
                    }
                    break;
                }

                usleep(50000);
            }

            fclose($pipes[1]);
            $returnCode = proc_close($process);

            if ($returnCode !== 0) {
                $this->setProgress($projectId, 'error', 100, 'Tải video thất bại. Vui lòng thử lại.');
                \Log::error('[DownloadSourceVideoJob] yt-dlp failed', [
                    'return_code' => $returnCode,
                    'platform'    => $source['platform'],
                    'output'      => implode("\n", array_slice($outputLines, -10)),
                ]);
                return;
            }

            $downloadedFiles = $this->findMergedVideoFiles($videoDir);
            if (empty($downloadedFiles)) {
                $this->setProgress($projectId, 'error', 100, 'Không tìm thấy file video sau khi tải.');
                \Log::error('[DownloadSourceVideoJob] No merged video file found', ['dir' => $videoDir]);
                return;
            }

            $videoFile = $downloadedFiles[0];

            $this->setProgress($projectId, 'processing', 90, 'Đang đổi tên file...');

            $renamed = $this->renameWithLocalizedTitle($project, $videoFile, $videoDir, $source['platform']);
            if ($renamed) {
                $videoFile = $renamed;
            }

            $filename = basename($videoFile);

            \Log::info('[DownloadSourceVideoJob] Done', [
                'project_id' => $projectId,
                'platform'   => $source['platform'],
                'filename'   => $filename,
                'size'       => filesize($videoFile),
            ]);

            $this->setProgress($projectId, 'completed', 100, 'Tải xong video nguồn!', [
                'success'  => true,
                'platform' => $source['platform'],
                'filename' => $filename,
                'path'     => "public/projects/{$projectId}/video/{$filename}",
                'url'      => Storage::url("public/projects/{$projectId}/video/{$filename}"),
                'size'     => filesize($videoFile),
            ]);

            $currentStatus = (string) ($project->status ?? '');
            $allowedToSetSourceDownloaded = in_array($currentStatus, ['', 'new', 'pending', 'error', 'source_downloaded'], true);
            if ($allowedToSetSourceDownloaded && $currentStatus !== 'source_downloaded') {
                $project->status = 'source_downloaded';
                $project->save();
            }
        } catch (\Throwable $e) {
            $this->setProgress($projectId, 'error', 100, 'Lỗi: ' . $e->getMessage());
            \Log::error('[DownloadSourceVideoJob] Exception', [
                'project_id' => $projectId,
                'error'      => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
            ]);
        }
    }

    // ── Progress helpers ──────────────────────────────────────────────────────

    private function progressKey(int $projectId): string
    {
        return "source_video_download_progress_{$projectId}";
    }

    private function setProgress(int $projectId, string $status, int $percent, string $message, array $extra = []): void
    {
        Cache::put($this->progressKey($projectId), array_merge([
            'status'     => $status,
            'percent'    => max(0, min(100, $percent)),
            'message'    => $message,
            'updated_at' => now()->toIso8601String(),
        ], $extra), now()->addHours(2));
    }

    // ── File helpers ──────────────────────────────────────────────────────────

    private function findMergedVideoFiles(string $videoDir): array
    {
        $files  = glob("{$videoDir}/*.mp4") ?: [];
        $merged = array_values(array_filter($files, fn($p) => !preg_match('/\.f\d+\.mp4$/i', basename($p))));
        usort($merged, fn($a, $b) => filemtime($b) <=> filemtime($a));
        return $merged;
    }

    private function cleanupStaleDownloadArtifacts(string $videoDir): void
    {
        $patterns = [
            "{$videoDir}/*.part",
            "{$videoDir}/*.ytdl",
            "{$videoDir}/*.f*.mp4",
            "{$videoDir}/*.f*.m4a",
            "{$videoDir}/*.f*.webm",
        ];
        foreach ($patterns as $pattern) {
            foreach (glob($pattern) ?: [] as $file) {
                if (is_file($file)) @unlink($file);
            }
        }
    }

    private function renameWithLocalizedTitle(DubSyncProject $project, string $videoFile, string $videoDir, string $platform): ?string
    {
        try {
            $titleVi = trim((string) ($project->youtube_title_vi ?? ''));

            if ($titleVi === '' && $platform === 'bilibili') {
                $sourceTitle = trim((string) ($project->youtube_title ?? ''));
                if ($sourceTitle !== '' && preg_match('/\p{Han}/u', $sourceTitle) === 1) {
                    $translated = trim((string) app(TranslationService::class)->translateText($sourceTitle, 'zh-CN', 'vi', 'google'));
                    if ($translated !== '') {
                        $titleVi = $translated;
                        $project->youtube_title_vi = $translated;
                        $project->save();
                    }
                }
            }

            if ($titleVi === '') return null;

            $safeBase = $this->sanitizeFilename($titleVi);
            if ($safeBase === '') return null;

            $ext        = pathinfo($videoFile, PATHINFO_EXTENSION) ?: 'mp4';
            $targetPath = $videoDir . DIRECTORY_SEPARATOR . $safeBase . '.' . $ext;
            $suffix     = 1;
            while (file_exists($targetPath) && realpath($targetPath) !== realpath($videoFile)) {
                $targetPath = $videoDir . DIRECTORY_SEPARATOR . $safeBase . ' (' . $suffix . ').' . $ext;
                $suffix++;
            }

            if (realpath($targetPath) === realpath($videoFile)) return $videoFile;
            return @rename($videoFile, $targetPath) ? $targetPath : null;
        } catch (\Throwable $e) {
            \Log::warning('[DownloadSourceVideoJob] Rename failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function sanitizeFilename(string $name): string
    {
        $sanitized = preg_replace('/[<>:"\/\\|?*]+/u', ' ', $name) ?? '';
        $sanitized = trim(preg_replace('/\s+/u', ' ', $sanitized) ?? '');
        return mb_substr($sanitized, 0, 120);
    }
}

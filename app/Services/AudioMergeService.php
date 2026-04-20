<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Exception;

class AudioMergeService
{
    private string $ffmpegPath;

    public function __construct()
    {
        $this->ffmpegPath = config('services.ffmpeg.path', env('FFMPEG_PATH', 'ffmpeg'));
    }

    /**
     * Merge all audio segments into a single timeline based on timestamps
     * 
     * @param array $segments Array of segments with start_time/start, end_time, audio_path
     * @param int $projectId
     * @return string Path to final merged audio
     */
    public function mergeSegments(array $segments, int $projectId): string
    {
        $tempFiles = [];

        try {
            // Normalize segment keys (support both 'start' and 'start_time')
            $segments = array_map(function ($seg) {
                $seg['start_time'] = $seg['start_time'] ?? $seg['start'] ?? 0;
                $seg['end_time'] = $seg['end_time'] ?? 0;
                return $seg;
            }, $segments);

            // Filter out segments without audio
            $segments = array_filter($segments, function ($seg) {
                return !empty($seg['audio_path']) && Storage::exists($seg['audio_path']);
            });

            if (empty($segments)) {
                throw new Exception('No valid audio segments found to merge');
            }

            // Sort segments by start time
            usort($segments, function ($a, $b) {
                return $a['start_time'] <=> $b['start_time'];
            });

            // First, normalize all audio to same format (44100Hz, stereo, mp3)
            $normalizedSegments = [];
            foreach ($segments as $i => $segment) {
                $normalizedPath = "dubsync/temp/norm_{$projectId}_{$i}_" . time() . ".mp3";
                $normalizedFullPath = Storage::path($normalizedPath);

                $this->ensureDirectory($normalizedFullPath);

                $inputPath = Storage::path($segment['audio_path']);
                $cmd = "\"{$this->ffmpegPath}\" -y -i \"{$inputPath}\" -ar 44100 -ac 2 -b:a 192k -acodec libmp3lame \"{$normalizedFullPath}\" 2>&1";

                exec($cmd, $output, $returnCode);

                if ($returnCode === 0 && file_exists($normalizedFullPath)) {
                    $segment['normalized_path'] = $normalizedPath;
                    $normalizedSegments[] = $segment;
                    $tempFiles[] = $normalizedFullPath;
                } else {
                    // Use original if normalization fails
                    $segment['normalized_path'] = $segment['audio_path'];
                    $normalizedSegments[] = $segment;
                    Log::warning("AudioMerge: Failed to normalize segment {$i}", ['output' => implode("\n", $output ?? [])]);
                }
            }

            // Create concat file with silence padding for gaps
            $concatFilePath = storage_path("app/dubsync/temp/concat_{$projectId}.txt");
            $this->ensureDirectory($concatFilePath);
            $concatList = '';
            $lastEndTime = 0;

            foreach ($normalizedSegments as $segment) {
                $audioFullPath = Storage::path($segment['normalized_path']);

                // Add silence if there's a gap from last segment
                $gap = $segment['start_time'] - $lastEndTime;
                if ($gap > 0.05) { // If gap is more than 50ms
                    $silencePath = $this->generateSilence($gap, $projectId);
                    $silenceFullPath = Storage::path($silencePath);
                    $concatList .= "file '" . str_replace("'", "'\\''", $silenceFullPath) . "'\n";
                    $tempFiles[] = $silenceFullPath;
                }

                $concatList .= "file '" . str_replace("'", "'\\''", $audioFullPath) . "'\n";

                // Use actual audio duration for end time calculation
                $actualDuration = $this->getAudioDuration($audioFullPath);
                $lastEndTime = $segment['start_time'] + ($actualDuration > 0 ? $actualDuration : ($segment['end_time'] - $segment['start_time']));
            }

            file_put_contents($concatFilePath, $concatList);
            $tempFiles[] = $concatFilePath;

            // Merge using ffmpeg concat with re-encoding for compatibility
            $outputPath = "public/projects/{$projectId}/audio/merged_" . time() . ".mp3";
            $outputFullPath = Storage::path($outputPath);
            $this->ensureDirectory($outputFullPath);

            $cmd = "\"{$this->ffmpegPath}\" -y -f concat -safe 0 -i \"{$concatFilePath}\" -ar 44100 -ac 2 -b:a 192k -acodec libmp3lame \"{$outputFullPath}\" 2>&1";

            Log::info("AudioMerge: Running merge command", ['command' => $cmd, 'segments' => count($normalizedSegments)]);

            exec($cmd, $mergeOutput, $returnCode);

            if ($returnCode !== 0 || !file_exists($outputFullPath)) {
                Log::error("AudioMerge: ffmpeg merge failed", ['return_code' => $returnCode, 'output' => implode("\n", $mergeOutput ?? [])]);
                throw new Exception('FFmpeg merge failed: ' . implode("\n", array_slice($mergeOutput ?? [], -5)));
            }

            Log::info("AudioMerge: Successfully merged {$projectId}", ['output' => $outputPath, 'segments' => count($normalizedSegments)]);

            return $outputPath;
        } catch (Exception $e) {
            Log::error("AudioMerge error: " . $e->getMessage());
            throw $e;
        } finally {
            // Clean up temp files
            foreach ($tempFiles as $tempFile) {
                if (file_exists($tempFile)) {
                    @unlink($tempFile);
                }
            }
        }
    }

    /**
     * Merge TTS audio segments following the "catch-up" timeline rule:
     *
     *   - current_time  : actual end position in the output after the previous segment.
     *   - original_start: the timestamp this segment should ideally begin at.
     *
     *   For each segment:
     *     • current_time < original_start  → insert silence to reach original_start,
     *                                        then place audio.  actual_start = original_start
     *     • current_time >= original_start → place audio immediately (we are already late,
     *                                        cannot rewind).         actual_start = current_time
     *
     *   After placing:  current_time = actual_start + tts_duration
     *
     * @param array $segments Array of segments with start_time and audio_path
     * @param int   $projectId
     * @return string Storage-relative path to the final merged MP3
     */
    public function mergeByTimeline(array $segments, int $projectId): string
    {
        $tempFiles = [];

        try {
            // ── 1. Normalise segment keys ────────────────────────────────────
            $segments = array_map(function ($seg) {
                $seg['start_time'] = $seg['start_time'] ?? $seg['start'] ?? 0;
                $seg['end_time']   = $seg['end_time']   ?? 0;
                return $seg;
            }, $segments);

            // Keep only segments that have a valid audio file
            $segments = array_values(array_filter($segments, function ($seg) {
                return !empty($seg['audio_path']) && Storage::exists($seg['audio_path']);
            }));

            if (empty($segments)) {
                throw new Exception('No valid audio segments found to merge');
            }

            // Sort ascending by original start_time
            usort($segments, fn($a, $b) => $a['start_time'] <=> $b['start_time']);

            // ── 2. Normalise every audio file to 44 100 Hz / stereo / MP3 ───
            $normalizedPaths = [];
            foreach ($segments as $i => $segment) {
                $normPath     = "dubsync/temp/tl_norm_{$projectId}_{$i}_" . time() . ".mp3";
                $normFullPath = Storage::path($normPath);
                $this->ensureDirectory($normFullPath);

                $inputFull = Storage::path($segment['audio_path']);
                $cmd = "\"{$this->ffmpegPath}\" -y -i \"{$inputFull}\" -ar 44100 -ac 2 -b:a 192k -acodec libmp3lame \"{$normFullPath}\" 2>&1";
                exec($cmd, $out, $rc);

                if ($rc === 0 && file_exists($normFullPath)) {
                    $normalizedPaths[] = $normFullPath;
                    $tempFiles[]       = $normFullPath;
                } else {
                    $normalizedPaths[] = $inputFull; // fallback to original
                    Log::warning("AudioMerge/Timeline: normalize failed for segment {$i}", [
                        'out' => implode("\n", $out ?? []),
                    ]);
                }
            }

            // ── 3. Build concat list applying the catch-up rule ──────────────
            $concatFilePath = storage_path("app/dubsync/temp/tl_concat_{$projectId}.txt");
            $this->ensureDirectory($concatFilePath);

            $concatList  = '';
            $currentTime = 0.0; // tracks actual end position in the output so far

            foreach ($segments as $i => $segment) {
                $originalStart = (float) $segment['start_time'];
                $audioFullPath = $normalizedPaths[$i];
                $ttsDuration   = $this->getAudioDuration($audioFullPath);

                if ($currentTime < $originalStart) {
                    // We are ahead of the original timestamp → insert silence
                    $silenceDuration = $originalStart - $currentTime;
                    $silencePath     = $this->generateSilence($silenceDuration, $projectId);
                    $silenceFullPath = Storage::path($silencePath);
                    $concatList     .= "file '" . str_replace("'", "'\\''", $silenceFullPath) . "'\n";
                    $tempFiles[]     = $silenceFullPath;

                    $actualStart = $originalStart;
                } else {
                    // We are late (or exactly on time) → start immediately, no silence
                    $actualStart = $currentTime;
                }

                $concatList  .= "file '" . str_replace("'", "'\\''", $audioFullPath) . "'\n";
                $currentTime  = $actualStart + ($ttsDuration > 0 ? $ttsDuration : max(0, $segment['end_time'] - $originalStart));

                Log::debug("AudioMerge/Timeline: segment {$i}", [
                    'original_start' => $originalStart,
                    'actual_start'   => round($actualStart, 3),
                    'tts_duration'   => round($ttsDuration, 3),
                    'current_time'   => round($currentTime, 3),
                ]);
            }

            file_put_contents($concatFilePath, $concatList);
            $tempFiles[] = $concatFilePath;

            // ── 4. Merge with FFmpeg concat demuxer ──────────────────────────
            $outputPath     = "public/projects/{$projectId}/audio/merged_" . time() . ".mp3";
            $outputFullPath = Storage::path($outputPath);
            $this->ensureDirectory($outputFullPath);

            $cmd = "\"{$this->ffmpegPath}\" -y -f concat -safe 0 -i \"{$concatFilePath}\" -ar 44100 -ac 2 -b:a 192k -acodec libmp3lame \"{$outputFullPath}\" 2>&1";

            Log::info("AudioMerge/Timeline: running merge for project {$projectId}", [
                'segments'     => count($segments),
                'total_output' => round($currentTime, 2) . 's',
            ]);

            exec($cmd, $mergeOutput, $returnCode);

            if ($returnCode !== 0 || !file_exists($outputFullPath)) {
                Log::error("AudioMerge/Timeline: ffmpeg failed", [
                    'return_code' => $returnCode,
                    'output'      => implode("\n", $mergeOutput ?? []),
                ]);
                throw new Exception('FFmpeg timeline merge failed: ' . implode("\n", array_slice($mergeOutput ?? [], -5)));
            }

            Log::info("AudioMerge/Timeline: success for project {$projectId}", [
                'output'   => $outputPath,
                'segments' => count($segments),
            ]);

            return $outputPath;
        } catch (Exception $e) {
            Log::error("AudioMerge/Timeline error: " . $e->getMessage());
            throw $e;
        } finally {
            foreach ($tempFiles as $f) {
                if (file_exists($f)) {
                    @unlink($f);
                }
            }
        }
    }

    /**
     * Get the actual duration of an audio file in seconds
     */
    private function getAudioDuration(string $filePath): float
    {
        $cmd = "\"{$this->ffmpegPath}\" -i \"{$filePath}\" 2>&1";
        exec($cmd, $output, $returnCode);
        $outputStr = implode("\n", $output);

        if (preg_match('/Duration:\s*(\d+):(\d+):(\d+)\.(\d+)/', $outputStr, $matches)) {
            return (int)$matches[1] * 3600 + (int)$matches[2] * 60 + (int)$matches[3] + (int)$matches[4] / 100;
        }

        return 0;
    }

    /**
     * Generate silence audio of specified duration
     */
    private function generateSilence(float $duration, int $projectId): string
    {
        $silencePath = "dubsync/temp/silence_{$projectId}_" . uniqid() . ".mp3";
        $silenceFullPath = Storage::path($silencePath);

        $this->ensureDirectory($silenceFullPath);

        $duration = round($duration, 3);
        $cmd = "\"{$this->ffmpegPath}\" -y -f lavfi -i anullsrc=r=44100:cl=stereo -t {$duration} -b:a 192k -acodec libmp3lame \"{$silenceFullPath}\" 2>&1";

        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0 || !file_exists($silenceFullPath)) {
            Log::warning("AudioMerge: Failed to generate silence of {$duration}s");
        }

        return $silencePath;
    }

    /**
     * Ensure the directory for a file path exists
     */
    private function ensureDirectory(string $filePath): void
    {
        $directory = dirname($filePath);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
    }
}

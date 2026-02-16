<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Exception;

/**
 * FFmpeg Standard Service - Chuẩn hóa tạo MP3 và MP4 theo chuẩn YouTube + WMP + AI
 * 
 * Tiêu chuẩn áp dụng:
 * - YouTube: H.264, AAC, 1920x1080/1280x720, 30fps, yuv420p
 * - Windows Media Player: MP4 container, AAC audio
 * - AI Processing: Metadata đầy đủ, chất lượng cao, format chuẩn
 */
class FFmpegStandardService
{
    private $ffmpegPath;
    private $ffprobePath;

    // YouTube & WMP Standard Settings
    private const VIDEO_STANDARDS = [
        '1080p' => [
            'resolution' => '1920x1080',
            'bitrate' => '10M',
            'maxrate' => '12M',
            'bufsize' => '20M',
            'fps' => 30,
            'description' => 'Full HD - Recommended for YouTube'
        ],
        '720p' => [
            'resolution' => '1280x720',
            'bitrate' => '6M',
            'maxrate' => '8M',
            'bufsize' => '12M',
            'fps' => 30,
            'description' => 'HD - Good quality'
        ],
        '480p' => [
            'resolution' => '854x480',
            'bitrate' => '3M',
            'maxrate' => '4M',
            'bufsize' => '6M',
            'fps' => 30,
            'description' => 'SD - Smaller file size'
        ]
    ];

    private const AUDIO_STANDARDS = [
        'premium' => [
            'codec' => 'aac',
            'bitrate' => '192k',
            'sample_rate' => 48000,
            'channels' => 2,
            'description' => 'Premium quality - YouTube recommended'
        ],
        'high' => [
            'codec' => 'aac',
            'bitrate' => '128k',
            'sample_rate' => 48000,
            'channels' => 2,
            'description' => 'High quality - Standard'
        ],
        'standard' => [
            'codec' => 'aac',
            'bitrate' => '96k',
            'sample_rate' => 44100,
            'channels' => 2,
            'description' => 'Standard quality'
        ]
    ];

    public function __construct()
    {
        $this->ffmpegPath = env('FFMPEG_PATH', 'ffmpeg');
        $this->ffprobePath = env('FFPROBE_PATH', 'ffprobe');
    }

    /**
     * Tạo MP3 audio chuẩn YouTube/WMP
     * 
     * @param string $inputPath Input audio file path
     * @param string $outputPath Output MP3 file path
     * @param array $options Options: quality (premium/high/standard), metadata
     * @return array Result with path, duration, size
     */
    public function createStandardMP3(string $inputPath, string $outputPath, array $options = []): array
    {
        $quality = $options['quality'] ?? 'high';
        $audioStd = self::AUDIO_STANDARDS[$quality] ?? self::AUDIO_STANDARDS['high'];

        // Metadata
        $metadata = $options['metadata'] ?? [];
        $metadataArgs = $this->buildMetadataArgs($metadata);

        // Normalize audio: stereo, remove silence, normalize volume
        $filterComplex = [];

        // Convert to stereo if needed
        $filterComplex[] = 'aformat=channel_layouts=stereo';

        // Normalize audio levels (prevent clipping, consistent volume)
        $filterComplex[] = 'loudnorm=I=-16:TP=-1.5:LRA=11';

        // Remove silence at start and end
        if ($options['remove_silence'] ?? true) {
            $filterComplex[] = 'silenceremove=start_periods=1:start_duration=0.1:start_threshold=-50dB:detection=peak';
            $filterComplex[] = 'areverse';
            $filterComplex[] = 'silenceremove=start_periods=1:start_duration=0.1:start_threshold=-50dB:detection=peak';
            $filterComplex[] = 'areverse';
        }

        $audioFilter = implode(',', $filterComplex);

        // Build FFmpeg command
        $command = sprintf(
            '%s -i %s -af %s -c:a %s -b:a %s -ar %d -ac %d %s -y %s 2>&1',
            escapeshellarg($this->ffmpegPath),
            escapeshellarg($inputPath),
            escapeshellarg($audioFilter),
            $audioStd['codec'],
            $audioStd['bitrate'],
            $audioStd['sample_rate'],
            $audioStd['channels'],
            $metadataArgs,
            escapeshellarg($outputPath)
        );

        Log::info('Creating standard MP3', [
            'input' => $inputPath,
            'output' => $outputPath,
            'quality' => $quality,
            'settings' => $audioStd
        ]);

        exec($command, $output, $returnCode);

        if ($returnCode !== 0 || !file_exists($outputPath)) {
            Log::error('MP3 creation failed', [
                'command' => $command,
                'output' => implode("\n", $output),
                'return_code' => $returnCode
            ]);
            throw new Exception('Failed to create standard MP3: ' . implode("\n", $output));
        }

        $duration = $this->getAudioDuration($outputPath);
        $fileSize = filesize($outputPath);

        Log::info('MP3 created successfully', [
            'output' => $outputPath,
            'duration' => $duration,
            'size' => $this->formatBytes($fileSize)
        ]);

        return [
            'success' => true,
            'path' => $outputPath,
            'duration' => $duration,
            'size' => $fileSize,
            'size_formatted' => $this->formatBytes($fileSize),
            'quality' => $quality
        ];
    }

    /**
     * Tạo MP4 video chuẩn YouTube/WMP với audio
     * 
     * @param string $inputVideoOrImage Input video or image path
     * @param string $audioPath Audio file path
     * @param string $outputPath Output MP4 file path
     * @param array $options Options: resolution (1080p/720p/480p), video_quality, audio_quality, metadata, thumbnail, wave_effect
     * @return array Result with path, duration, size
     */
    public function createStandardMP4(
        string $inputVideoOrImage,
        string $audioPath,
        string $outputPath,
        array $options = []
    ): array {
        $resolution = $options['resolution'] ?? '1080p';
        $videoStd = self::VIDEO_STANDARDS[$resolution] ?? self::VIDEO_STANDARDS['1080p'];

        $audioQuality = $options['audio_quality'] ?? 'premium';
        $audioStd = self::AUDIO_STANDARDS[$audioQuality] ?? self::AUDIO_STANDARDS['premium'];

        $isImage = $this->isImageFile($inputVideoOrImage);
        $duration = $this->getAudioDuration($audioPath);

        // Build video filter complex
        $videoFilters = $this->buildVideoFilters($inputVideoOrImage, $videoStd, $isImage, $options);

        // Build audio filter
        $audioFilters = $this->buildAudioFilters($audioStd, $options);

        // Metadata
        $metadata = $options['metadata'] ?? [];
        $metadataArgs = $this->buildMetadataArgs($metadata);

        // Build FFmpeg command
        if ($isImage) {
            // Image to video with audio
            $command = sprintf(
                '%s -loop 1 -i %s -i %s -filter_complex %s -filter:a %s ' .
                    '-map "[vout]" -map "[aout]" ' .
                    '-c:v libx264 -preset slow -profile:v high -level 4.2 ' .
                    '-b:v %s -maxrate %s -bufsize %s -g %d -pix_fmt yuv420p ' .
                    '-c:a %s -b:a %s -ar %d -ac %d ' .
                    '-t %.2f %s -movflags +faststart -y %s 2>&1',
                escapeshellarg($this->ffmpegPath),
                escapeshellarg($inputVideoOrImage),
                escapeshellarg($audioPath),
                escapeshellarg($videoFilters),
                escapeshellarg($audioFilters),
                $videoStd['bitrate'],
                $videoStd['maxrate'],
                $videoStd['bufsize'],
                $videoStd['fps'] * 2, // GOP size (keyframe interval)
                $audioStd['codec'],
                $audioStd['bitrate'],
                $audioStd['sample_rate'],
                $audioStd['channels'],
                $duration,
                $metadataArgs,
                escapeshellarg($outputPath)
            );
        } else {
            // Video with audio replacement
            $command = sprintf(
                '%s -i %s -i %s -filter_complex %s -filter:a %s ' .
                    '-map "[vout]" -map "[aout]" ' .
                    '-c:v libx264 -preset slow -profile:v high -level 4.2 ' .
                    '-b:v %s -maxrate %s -bufsize %s -g %d -pix_fmt yuv420p ' .
                    '-c:a %s -b:a %s -ar %d -ac %d ' .
                    '-shortest %s -movflags +faststart -y %s 2>&1',
                escapeshellarg($this->ffmpegPath),
                escapeshellarg($inputVideoOrImage),
                escapeshellarg($audioPath),
                escapeshellarg($videoFilters),
                escapeshellarg($audioFilters),
                $videoStd['bitrate'],
                $videoStd['maxrate'],
                $videoStd['bufsize'],
                $videoStd['fps'] * 2,
                $audioStd['codec'],
                $audioStd['bitrate'],
                $audioStd['sample_rate'],
                $audioStd['channels'],
                $metadataArgs,
                escapeshellarg($outputPath)
            );
        }

        Log::info('Creating standard MP4', [
            'input_video' => $inputVideoOrImage,
            'input_audio' => $audioPath,
            'output' => $outputPath,
            'resolution' => $resolution,
            'is_image' => $isImage,
            'duration' => $duration
        ]);

        exec($command, $output, $returnCode);

        if ($returnCode !== 0 || !file_exists($outputPath)) {
            Log::error('MP4 creation failed', [
                'command' => $command,
                'output' => implode("\n", $output),
                'return_code' => $returnCode
            ]);
            throw new Exception('Failed to create standard MP4: ' . implode("\n", $output));
        }

        $videoDuration = $this->getVideoDuration($outputPath);
        $fileSize = filesize($outputPath);

        Log::info('MP4 created successfully', [
            'output' => $outputPath,
            'duration' => $videoDuration,
            'size' => $this->formatBytes($fileSize)
        ]);

        return [
            'success' => true,
            'path' => $outputPath,
            'duration' => $videoDuration,
            'size' => $fileSize,
            'size_formatted' => $this->formatBytes($fileSize),
            'resolution' => $resolution,
            'video_quality' => $videoStd,
            'audio_quality' => $audioStd
        ];
    }

    /**
     * Merge nhiều audio files thành một MP3 chuẩn
     */
    public function mergeAudioFiles(array $audioPaths, string $outputPath, array $options = []): array
    {
        $quality = $options['quality'] ?? 'high';
        $crossfade = $options['crossfade'] ?? 0.5; // seconds

        // Create concat file
        $concatFile = sys_get_temp_dir() . '/concat_' . uniqid() . '.txt';
        $concatContent = '';
        foreach ($audioPaths as $path) {
            $concatContent .= "file '" . str_replace("'", "'\\''", $path) . "'\n";
        }
        file_put_contents($concatFile, $concatContent);

        try {
            if ($crossfade > 0 && count($audioPaths) > 1) {
                // Merge with crossfade
                return $this->mergeWithCrossfade($audioPaths, $outputPath, $crossfade, $quality);
            } else {
                // Simple concat
                $audioStd = self::AUDIO_STANDARDS[$quality] ?? self::AUDIO_STANDARDS['high'];

                $command = sprintf(
                    '%s -f concat -safe 0 -i %s -c:a %s -b:a %s -ar %d -ac %d -y %s 2>&1',
                    escapeshellarg($this->ffmpegPath),
                    escapeshellarg($concatFile),
                    $audioStd['codec'],
                    $audioStd['bitrate'],
                    $audioStd['sample_rate'],
                    $audioStd['channels'],
                    escapeshellarg($outputPath)
                );

                exec($command, $output, $returnCode);

                if ($returnCode !== 0 || !file_exists($outputPath)) {
                    throw new Exception('Failed to merge audio files: ' . implode("\n", $output));
                }

                $duration = $this->getAudioDuration($outputPath);

                return [
                    'success' => true,
                    'path' => $outputPath,
                    'duration' => $duration,
                    'count' => count($audioPaths)
                ];
            }
        } finally {
            if (file_exists($concatFile)) {
                unlink($concatFile);
            }
        }
    }

    /**
     * Merge audio với crossfade effect
     */
    private function mergeWithCrossfade(array $audioPaths, string $outputPath, float $crossfade, string $quality): array
    {
        $audioStd = self::AUDIO_STANDARDS[$quality] ?? self::AUDIO_STANDARDS['high'];

        // Build filter complex for crossfade
        $inputs = '';
        $filters = [];

        foreach ($audioPaths as $i => $path) {
            $inputs .= " -i " . escapeshellarg($path);
        }

        // Create crossfade chain
        $current = '[0:a]';
        for ($i = 0; $i < count($audioPaths) - 1; $i++) {
            $next = $i + 1;
            $output = $i === count($audioPaths) - 2 ? '[aout]' : "[a{$next}]";
            $filters[] = sprintf(
                "%s[%d:a]acrossfade=d=%.2f%s",
                $current,
                $next,
                $crossfade,
                $output
            );
            $current = "[a{$next}]";
        }

        $filterComplex = implode(';', $filters);

        $command = sprintf(
            '%s %s -filter_complex %s -map "[aout]" -c:a %s -b:a %s -ar %d -ac %d -y %s 2>&1',
            escapeshellarg($this->ffmpegPath),
            $inputs,
            escapeshellarg($filterComplex),
            $audioStd['codec'],
            $audioStd['bitrate'],
            $audioStd['sample_rate'],
            $audioStd['channels'],
            escapeshellarg($outputPath)
        );

        exec($command, $output, $returnCode);

        if ($returnCode !== 0 || !file_exists($outputPath)) {
            throw new Exception('Failed to merge with crossfade: ' . implode("\n", $output));
        }

        $duration = $this->getAudioDuration($outputPath);

        return [
            'success' => true,
            'path' => $outputPath,
            'duration' => $duration,
            'count' => count($audioPaths),
            'crossfade' => $crossfade
        ];
    }

    /**
     * Build video filters cho image/video input
     */
    private function buildVideoFilters(string $input, array $videoStd, bool $isImage, array $options): string
    {
        $filters = [];
        $resolution = $videoStd['resolution'];
        list($width, $height) = explode('x', $resolution);

        if ($isImage) {
            // Image input: scale + ken burns effect (zoom/pan)
            $zoomEffect = $options['zoom_effect'] ?? true;

            if ($zoomEffect) {
                // Ken Burns effect (slow zoom)
                $filters[] = sprintf(
                    "[0:v]scale=%s:force_original_aspect_ratio=increase,crop=%s," .
                        "zoompan=z='min(zoom+0.001,1.2)':d=1:x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)':s=%s:fps=%d",
                    $resolution,
                    $resolution,
                    $resolution,
                    $videoStd['fps']
                );
            } else {
                // Static image
                $filters[] = sprintf(
                    "[0:v]scale=%s:force_original_aspect_ratio=increase,crop=%s,fps=%d",
                    $resolution,
                    $resolution,
                    $videoStd['fps']
                );
            }
        } else {
            // Video input: scale + crop to target resolution
            $filters[] = sprintf(
                "[0:v]fps=%d,scale=%s:force_original_aspect_ratio=increase,crop=%s",
                $videoStd['fps'],
                $resolution,
                $resolution
            );
        }

        // Add wave effect if specified
        if ($options['wave_effect'] ?? false) {
            $waveSettings = $options['wave_settings'] ?? [];
            $filters[] = $this->buildWaveFilter($waveSettings, $width, $height);
        }

        // Output tag
        $filters[] = "format=yuv420p[vout]";

        return implode(',', $filters);
    }

    /**
     * Build audio filters
     */
    private function buildAudioFilters(array $audioStd, array $options): string
    {
        $filters = [];

        // Stereo conversion
        $filters[] = 'aformat=channel_layouts=stereo';

        // Normalize volume
        if ($options['normalize_audio'] ?? true) {
            $filters[] = 'loudnorm=I=-16:TP=-1.5:LRA=11';
        }

        // Output tag
        $filters[] = 'aformat=sample_fmts=fltp:sample_rates=' . $audioStd['sample_rate'] . '[aout]';

        return implode(',', $filters);
    }

    /**
     * Build wave effect filter (for audio visualization)
     */
    private function buildWaveFilter(array $settings, int $width, int $height): string
    {
        $waveType = $settings['type'] ?? 'line';
        $waveColor = $settings['color'] ?? 'white';
        $wavePosition = $settings['position'] ?? 'bottom';
        $waveHeight = $settings['height'] ?? 100;
        $waveWidthPercent = (int) ($settings['width_percent'] ?? 100);
        $opacity = $settings['opacity'] ?? 0.8;

        $wavePixelWidth = (int) ($width * $waveWidthPercent / 100);

        // Position calculation
        $y = match ($wavePosition) {
            'top' => '10',
            'middle' => '(h-' . $waveHeight . ')/2',
            'bottom' => 'h-' . ($waveHeight + 10),
            default => 'h-' . ($waveHeight + 10)
        };

        return sprintf(
            "showwaves=s=%dx%d:mode=%s:colors=%s@%.2f:scale=sqrt:y=%s",
            $wavePixelWidth,
            $waveHeight,
            $waveType,
            $waveColor,
            $opacity,
            $y
        );
    }

    /**
     * Build metadata arguments for FFmpeg
     */
    private function buildMetadataArgs(array $metadata): string
    {
        $args = [];

        $metadataMap = [
            'title' => 'title',
            'artist' => 'artist',
            'album' => 'album',
            'author' => 'author',
            'description' => 'description',
            'comment' => 'comment',
            'year' => 'date',
            'genre' => 'genre',
            'copyright' => 'copyright'
        ];

        foreach ($metadataMap as $key => $ffmpegKey) {
            if (!empty($metadata[$key])) {
                $args[] = "-metadata {$ffmpegKey}=" . escapeshellarg($metadata[$key]);
            }
        }

        return implode(' ', $args);
    }

    /**
     * Get audio duration using ffprobe
     */
    public function getAudioDuration(string $audioPath): float
    {
        $command = sprintf(
            '%s -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 %s',
            escapeshellarg($this->ffprobePath),
            escapeshellarg($audioPath)
        );

        exec($command, $output);
        return !empty($output) ? (float)$output[0] : 0;
    }

    /**
     * Get video duration using ffprobe
     */
    public function getVideoDuration(string $videoPath): float
    {
        return $this->getAudioDuration($videoPath); // Same command
    }

    /**
     * Check if file is an image
     */
    private function isImageFile(string $path): bool
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp']);
    }

    /**
     * Format bytes to human readable
     */
    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, $precision) . ' ' . $units[$i];
    }

    /**
     * Get available quality settings
     */
    public static function getVideoQualities(): array
    {
        return self::VIDEO_STANDARDS;
    }

    /**
     * Get available audio qualities
     */
    public static function getAudioQualities(): array
    {
        return self::AUDIO_STANDARDS;
    }
}

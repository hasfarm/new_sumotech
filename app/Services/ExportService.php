<?php

namespace App\Services;

use App\Models\DubSyncProject;
use Illuminate\Support\Facades\Storage;
use Exception;

class ExportService
{
    /**
     * Generate SRT subtitle file
     * 
     * @param DubSyncProject $project
     * @return string Path to SRT file
     */
    public function generateSRT(DubSyncProject $project): string
    {
        // Prefer translated_segments (Vietnamese), fallback to aligned/segments (original)
        $translatedSegments = $project->translated_segments ?: [];
        $timingSegments     = $project->aligned_segments ?: $project->segments ?: [];

        if (empty($timingSegments)) {
            throw new Exception('No segments found for SRT export');
        }

        // Build a lookup: index => translated text
        $translatedMap = [];
        foreach ($translatedSegments as $i => $ts) {
            $translatedMap[$i] = trim($ts['text'] ?? '');
        }

        $srtContent = '';
        $seq = 1;

        foreach ($timingSegments as $i => $segment) {
            $startTime = $this->formatSRTTime($segment['start_time'] ?? $segment['start'] ?? 0);
            $endTime   = $this->formatSRTTime($segment['end_time']   ?? $segment['end']   ?? 0);
            // Use translated text if available, otherwise fall back to segment text
            $text      = $translatedMap[$i] ?? trim($segment['text'] ?? '');
            if ($text === '') continue;

            $srtContent .= "{$seq}\n{$startTime} --> {$endTime}\n{$text}\n\n";
            $seq++;
        }

        $filename = "dubsync/exports/project_{$project->id}_" . time() . ".srt";
        Storage::put($filename, $srtContent);

        return $filename;
    }

    /**
     * Generate VTT subtitle file
     * 
     * @param DubSyncProject $project
     * @return string Path to VTT file
     */
    public function generateVTT(DubSyncProject $project): string
    {
        $translatedSegments = $project->translated_segments ?: [];
        $timingSegments     = $project->aligned_segments ?: $project->segments ?: [];

        if (empty($timingSegments)) {
            throw new Exception('No segments found for VTT export');
        }

        $translatedMap = [];
        foreach ($translatedSegments as $i => $ts) {
            $translatedMap[$i] = trim($ts['text'] ?? '');
        }

        $vttContent = "WEBVTT\n\n";

        foreach ($timingSegments as $i => $segment) {
            $startTime = $this->formatVTTTime($segment['start_time'] ?? $segment['start'] ?? 0);
            $endTime   = $this->formatVTTTime($segment['end_time']   ?? $segment['end']   ?? 0);
            $text      = $translatedMap[$i] ?? trim($segment['text'] ?? '');
            if ($text === '') continue;

            $vttContent .= "{$startTime} --> {$endTime}\n{$text}\n\n";
        }

        $filename = "dubsync/exports/project_{$project->id}_" . time() . ".vtt";
        Storage::put($filename, $vttContent);

        return $filename;
    }

    /**
     * Export final audio as WAV
     * 
     * @param DubSyncProject $project
     * @return string Path to WAV file
     */
    public function exportAudioAsWAV(DubSyncProject $project): string
    {
        if (!$project->final_audio_path) {
            throw new Exception('No final audio found');
        }

        $inputPath = Storage::path($project->final_audio_path);
        $outputPath = "dubsync/exports/project_{$project->id}_" . time() . ".wav";
        $outputFullPath = Storage::path($outputPath);

        // Convert to WAV using ffmpeg
        $command = "ffmpeg -i \"{$inputPath}\" -acodec pcm_s16le -ar 44100 \"{$outputFullPath}\" 2>&1";

        exec($command, $output, $returnCode);

        if ($returnCode === 0 && file_exists($outputFullPath)) {
            return $outputPath;
        }

        // Fallback: copy the original file
        Storage::copy($project->final_audio_path, $outputPath);
        return $outputPath;
    }

    /**
     * Export final audio as MP3
     * 
     * @param DubSyncProject $project
     * @return string Path to MP3 file
     */
    public function exportAudioAsMP3(DubSyncProject $project): string
    {
        if (!$project->final_audio_path) {
            throw new Exception('No final audio found');
        }

        // If already MP3, just copy it
        if (str_ends_with($project->final_audio_path, '.mp3')) {
            $outputPath = "dubsync/exports/project_{$project->id}_" . time() . ".mp3";
            Storage::copy($project->final_audio_path, $outputPath);
            return $outputPath;
        }

        // Otherwise convert to MP3
        $inputPath = Storage::path($project->final_audio_path);
        $outputPath = "dubsync/exports/project_{$project->id}_" . time() . ".mp3";
        $outputFullPath = Storage::path($outputPath);

        $command = "ffmpeg -i \"{$inputPath}\" -acodec libmp3lame -b:a 192k \"{$outputFullPath}\" 2>&1";

        exec($command, $output, $returnCode);

        if ($returnCode === 0 && file_exists($outputFullPath)) {
            return $outputPath;
        }

        // Fallback
        Storage::copy($project->final_audio_path, $outputPath);
        return $outputPath;
    }

    /**
     * Generate JSON project file for re-running/editing
     * 
     * @param DubSyncProject $project
     * @return string Path to JSON file
     */
    public function generateProjectJSON(DubSyncProject $project): string
    {
        $projectData = [
            'project_id' => $project->id,
            'video_id' => $project->video_id,
            'youtube_url' => $project->youtube_url,
            'created_at' => $project->created_at->toISOString(),
            'original_transcript' => json_decode($project->original_transcript, true),
            'segments' => json_decode($project->segments, true),
            'translated_segments' => json_decode($project->translated_segments, true),
            'audio_segments' => json_decode($project->audio_segments, true),
            'aligned_segments' => json_decode($project->aligned_segments, true),
            'final_audio_path' => $project->final_audio_path,
            'status' => $project->status,
            'metadata' => [
                'version' => '1.0',
                'export_date' => now()->toISOString(),
                'total_segments' => count(json_decode($project->segments, true) ?? []),
                'total_duration' => $this->calculateTotalDuration($project)
            ]
        ];

        $jsonContent = json_encode($projectData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $filename = "dubsync/exports/project_{$project->id}_" . time() . ".json";
        Storage::put($filename, $jsonContent);

        return $filename;
    }

    /**
     * Format time for SRT (HH:MM:SS,mmm)
     */
    private function formatSRTTime(float $seconds): string
    {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = floor($seconds % 60);
        $milliseconds = floor(($seconds - floor($seconds)) * 1000);

        return sprintf('%02d:%02d:%02d,%03d', $hours, $minutes, $secs, $milliseconds);
    }

    /**
     * Format time for VTT (HH:MM:SS.mmm)
     */
    private function formatVTTTime(float $seconds): string
    {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = floor($seconds % 60);
        $milliseconds = floor(($seconds - floor($seconds)) * 1000);

        return sprintf('%02d:%02d:%02d.%03d', $hours, $minutes, $secs, $milliseconds);
    }

    /**
     * Calculate total duration of all segments
     */
    private function calculateTotalDuration(DubSyncProject $project): float
    {
        $segments = json_decode($project->segments, true);

        if (!$segments || empty($segments)) {
            return 0;
        }

        $lastSegment = end($segments);
        return $lastSegment['end_time'] ?? 0;
    }
}

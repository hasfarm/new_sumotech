<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class YouTubeTranscriptService
{
    /**
     * Get transcript from YouTube video using Python script (updated with HTML entity fix)
     * 
     * @param string $videoId
     * @return array
     */
    public function getTranscript(string $videoId): array
    {
        try {
            Log::info('YouTubeTranscriptService: Starting to fetch transcript for video: ' . $videoId);

            $pythonScript = storage_path('scripts/get_youtube_transcript.py');

            // Check if Python script exists
            if (!file_exists($pythonScript)) {
                Log::error('Python script not found at: ' . $pythonScript);
                throw new Exception('Python script not found');
            }

            // Find Python executable
            $pythonCmd = env('PYTHON_PATH', 'python');

            // Verify Python is available
            $checkProcess = new Process([$pythonCmd, '--version']);
            $checkProcess->run();
            if (!$checkProcess->isSuccessful()) {
                Log::error('Python executable not found or not working', [
                    'python_cmd' => $pythonCmd,
                    'error' => $checkProcess->getErrorOutput()
                ]);
                throw new Exception('Python not found in system. Tried: ' . $pythonCmd);
            }

            // Use Symfony Process for reliable execution
            // Redirect stderr to null to avoid DEBUG messages mixing with JSON output
            $process = new Process([$pythonCmd, $pythonScript, $videoId]);
            $process->setTimeout(120); // 120 seconds timeout
            $process->setIdleTimeout(60);

            Log::info('YouTubeTranscriptService: Executing Python script', [
                'script' => $pythonScript,
                'videoId' => $videoId,
                'python_cmd' => $pythonCmd,
                'timeout' => 120,
                'idle_timeout' => 60
            ]);

            $process->run();

            // Get output (stdout) and error output (stderr)
            $output = $process->getOutput();
            $errorOutput = $process->getErrorOutput();

            Log::info('YouTubeTranscriptService: Script execution completed', [
                'exitCode' => $process->getExitCode(),
                'hasOutput' => !empty($output),
                'outputLength' => strlen($output),
                'errorLength' => strlen($errorOutput)
            ]);

            if (!$process->isSuccessful()) {
                // If stdout has valid JSON, ignore stderr warnings (e.g. Python installer messages)
                $trimmedOutput = trim($output);
                if (!empty($trimmedOutput) && json_decode($trimmedOutput, true) !== null) {
                    Log::warning('YouTubeTranscriptService: Transcript script had non-zero exit but produced valid JSON, ignoring stderr', [
                        'exitCode' => $process->getExitCode(),
                        'stderr' => substr($errorOutput, 0, 200)
                    ]);
                } else {
                Log::error('YouTubeTranscriptService: Python script failed', [
                    'exitCode' => $process->getExitCode(),
                    'stdout' => substr($output, 0, 500),
                    'stderr' => substr($errorOutput, 0, 500),
                    'python_script' => $pythonScript,
                    'video_id' => $videoId,
                    'process_timeout' => $process->getTimeout(),
                    'process_idle_timeout' => $process->getIdleTimeout()
                ]);

                // Try to extract meaningful error from stderr
                $errorMsg = 'Python script failed';
                if (!empty($errorOutput)) {
                    // Extract JSON error if present
                    if (strpos($errorOutput, '{') !== false) {
                        $jsonStart = strpos($errorOutput, '{');
                        $jsonPart = substr($errorOutput, $jsonStart);
                        $errorData = json_decode($jsonPart, true);
                        if ($errorData && isset($errorData['error'])) {
                            $errorMsg = $errorData['error'];
                        }
                    } else {
                        // Use first line of stderr
                        $lines = explode("\n", $errorOutput);
                        foreach ($lines as $line) {
                            $line = trim($line);
                            if (!empty($line) && strpos($line, 'DEBUG:') === false) {
                                $errorMsg = $line;
                                break;
                            }
                        }
                    }
                }

                throw new Exception('Không thể lấy transcript: ' . $errorMsg);
                }
            }

            if (empty($output)) {
                Log::warning('YouTubeTranscriptService: Empty output from Python script', [
                    'stderr' => substr($errorOutput, 0, 500)
                ]);

                // Check if there's useful info in stderr
                $errorMsg = 'Không có dữ liệu transcript';
                if (!empty($errorOutput)) {
                    $lines = explode("\n", $errorOutput);
                    foreach ($lines as $line) {
                        $line = trim($line);
                        if (!empty($line) && strpos($line, 'DEBUG:') === false) {
                            $errorMsg = $line;
                            break;
                        }
                    }
                }

                throw new Exception($errorMsg);
            }

            // Clean output - remove DEBUG lines and other stderr content
            $lines = explode("\n", $output);
            $jsonLines = [];
            foreach ($lines as $line) {
                $line = trim($line);
                // Skip empty lines and DEBUG lines
                if (!empty($line) && strpos($line, 'DEBUG:') === false) {
                    $jsonLines[] = $line;
                }
            }
            $output = implode("\n", $jsonLines);

            // If output starts with '[' or '{', it's likely the JSON we need
            if (empty($output)) {
                throw new Exception('Không tìm thấy dữ liệu JSON trong output');
            }

            // Trim output and clean UTF-8
            $output = trim($output);

            // Fix encoding issues - remove any BOM markers
            if (substr($output, 0, 3) === "\xef\xbb\xbf") {
                $output = substr($output, 3);
            }

            // Force UTF-8 encoding and normalize
            if (!mb_check_encoding($output, 'UTF-8')) {
                Log::warning('YouTubeTranscriptService: Invalid UTF-8 detected, converting...');
                // Try multiple encoding sources
                $output = mb_convert_encoding($output, 'UTF-8', 'UTF-8,ISO-8859-1,ASCII,GBK,GB2312');
            }

            // Ensure proper UTF-8 without replacement characters
            $output = mb_convert_encoding($output, 'UTF-8', 'UTF-8');

            // Decode JSON
            $transcript = json_decode($output, true, 512, JSON_BIGINT_AS_STRING);

            if (!$transcript) {
                Log::error('YouTubeTranscriptService: Failed to decode JSON', [
                    'jsonError' => json_last_error_msg(),
                    'jsonErrorCode' => json_last_error(),
                    'outputSample' => substr($output, 0, 500),
                    'stderr' => substr($errorOutput, 0, 300)
                ]);
                throw new Exception('Không thể parse transcript JSON: ' . json_last_error_msg() . '. Output: ' . substr($output, 0, 100));
            }

            Log::info('YouTubeTranscriptService: Successfully fetched transcript', [
                'segment_count' => count($transcript),
                'first_text' => isset($transcript[0]['text']) ? substr($transcript[0]['text'], 0, 50) : 'N/A',
                'first_start' => $transcript[0]['start'] ?? 'N/A'
            ]);

            return $transcript;
        } catch (Exception $e) {
            Log::error('YouTubeTranscriptService: Error getting transcript', [
                'message' => $e->getMessage(),
                'videoId' => $videoId
            ]);

            // Throw the error to let the user know - NEVER use mock data
            throw new Exception('Không thể lấy transcript từ YouTube: ' . $e->getMessage());
        }
    }


    /**
     * Get metadata from YouTube video (title, description, duration, thumbnail)
     * 
     * @param string $videoId
     * @return array
     */
    public function getMetadata(string $videoId): array
    {
        try {
            Log::info('YouTubeTranscriptService: Starting to fetch metadata for video: ' . $videoId);

            $pythonScript = storage_path('scripts/get_youtube_metadata.py');

            // Check if Python script exists
            if (!file_exists($pythonScript)) {
                Log::error('Python script not found at: ' . $pythonScript);
                throw new Exception('Python metadata script không tìm thấy');
            }

            // Use Symfony Process for reliable execution
            $pythonCmd = env('PYTHON_PATH', 'python');
            $process = new Process([$pythonCmd, $pythonScript, $videoId]);
            $process->setTimeout(30); // 30 seconds timeout
            $process->setIdleTimeout(30);

            Log::info('YouTubeTranscriptService: Executing metadata Python script', [
                'script' => $pythonScript,
                'videoId' => $videoId
            ]);

            $process->run();

            // Get output (stdout) and error output (stderr)
            $output = $process->getOutput();
            $errorOutput = $process->getErrorOutput();

            Log::info('YouTubeTranscriptService: Metadata script execution completed', [
                'exitCode' => $process->getExitCode(),
                'hasOutput' => !empty($output)
            ]);

            if (!$process->isSuccessful()) {
                // If stdout has valid JSON, ignore stderr warnings (e.g. Python installer messages)
                $trimmedOutput = trim($output);
                if (!empty($trimmedOutput) && json_decode($trimmedOutput, true) !== null) {
                    Log::warning('YouTubeTranscriptService: Script had non-zero exit but produced valid JSON, ignoring stderr', [
                        'exitCode' => $process->getExitCode(),
                        'stderr' => substr($errorOutput, 0, 200)
                    ]);
                } else {
                    Log::error('YouTubeTranscriptService: Python metadata script failed', [
                        'exitCode' => $process->getExitCode(),
                        'error' => substr($errorOutput, 0, 500)
                    ]);
                    throw new Exception('Không thể lấy metadata từ YouTube. Lỗi: ' . substr($errorOutput, 0, 200));
                }
            }

            if (empty($output)) {
                Log::warning('YouTubeTranscriptService: Empty output from metadata Python script');
                throw new Exception('Không nhận được metadata từ YouTube (output trống)');
            }

            // Trim output and clean UTF-8
            $output = trim($output);

            // Fix encoding issues - remove any BOM markers
            if (substr($output, 0, 3) === "\xef\xbb\xbf") {
                $output = substr($output, 3);
            }

            // Force UTF-8 encoding and normalize
            if (!mb_check_encoding($output, 'UTF-8')) {
                Log::warning('YouTubeTranscriptService: Invalid UTF-8 detected in metadata, converting...');
                $output = mb_convert_encoding($output, 'UTF-8', 'UTF-8,ISO-8859-1,ASCII,GBK,GB2312');
            }

            // Ensure proper UTF-8
            $output = mb_convert_encoding($output, 'UTF-8', 'UTF-8');

            // Decode JSON
            $metadata = json_decode($output, true, 512, JSON_BIGINT_AS_STRING);

            if (!$metadata) {
                Log::error('YouTubeTranscriptService: Failed to decode metadata JSON', [
                    'jsonError' => json_last_error_msg(),
                    'jsonErrorCode' => json_last_error(),
                    'outputSample' => substr($output, 0, 500)
                ]);
                throw new Exception('Không thể parse metadata JSON: ' . json_last_error_msg());
            }

            Log::info('YouTubeTranscriptService: Successfully fetched metadata', [
                'title' => $metadata['title'] ?? 'N/A',
                'hasThumbnail' => !empty($metadata['thumbnail'])
            ]);

            return $metadata;
        } catch (Exception $e) {
            Log::error('YouTubeTranscriptService: Error fetching metadata', [
                'message' => $e->getMessage(),
                'videoId' => $videoId
            ]);

            // Throw the error to let the user know
            throw new Exception('Không thể lấy metadata từ YouTube: ' . $e->getMessage());
        }
    }
}

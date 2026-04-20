<?php
$ffmpegPath = 'C:/Users/Admin/AppData/Local/Microsoft/WinGet/Packages/Gyan.FFmpeg_Microsoft.Winget.Source_8wekyb3d8bbwe/ffmpeg-8.1-full_build/bin/ffmpeg.exe';
$fontPath = 'C:/Windows/Fonts/arial.ttf';
$inputPath = sys_get_temp_dir() . '/test_input.png';
$outputPath = sys_get_temp_dir() . '/test_output_vn.png';

$escapedFontPath = str_replace(['\\', ':'], ['/', '\\:'], $fontPath);
// Vietnamese text
$text = 'Thần Điêu Đại Hiệp';
$escaped = str_replace(['\\', "'", ':', '%'], ['\\\\', "\\'", '\\:', '\\%'], $text);

echo "Text: " . $text . "\n";
echo "Escaped: " . $escaped . "\n";

$filterStr = "drawtext=fontfile='" . $escapedFontPath . "':text='" . $escaped . "':fontsize=40:fontcolor=ffffff:borderw=2:bordercolor=000000:x=(w-text_w)/2:y=h-50";

$command = sprintf(
    '%s -y -i %s -vf "%s" -q:v 2 %s 2>&1',
    escapeshellarg($ffmpegPath),
    escapeshellarg($inputPath),
    $filterStr,
    escapeshellarg($outputPath)
);

echo "Command: " . $command . "\n\n";

exec($command, $output, $returnCode);
echo "Return code: " . $returnCode . "\n";
echo "Last lines: " . implode("\n", array_slice($output, -5)) . "\n";
echo "Output exists: " . (file_exists($outputPath) ? 'YES' : 'NO') . "\n";

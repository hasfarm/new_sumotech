<?php

namespace App\Services\StockSources;

use Illuminate\Support\Facades\Http;

class PexelsSearchService
{
    public const SOURCE = 'pexels';

    /**
     * @return array<int,array<string,mixed>>
     */
    public function search(string $keyword, int $perPage = 6): array
    {
        $apiKey = trim((string) config('services.stock_sources.pexels.api_key', ''));
        if ($apiKey === '') {
            return [];
        }

        try {
            $response = Http::withHeaders(['Authorization' => $apiKey])
                ->timeout(15)
                ->get('https://api.pexels.com/videos/search', [
                    'query' => $keyword,
                    'per_page' => $perPage,
                    'orientation' => 'landscape',
                ]);
        } catch (\Throwable $e) {
            return [];
        }

        if (!$response->successful()) {
            return [];
        }

        $videos = $response->json('videos') ?? [];
        $candidates = [];

        foreach ($videos as $video) {
            $file = $this->pickBestVideoFile($video['video_files'] ?? []);
            if (!$file) {
                continue;
            }

            $candidates[] = [
                'source' => self::SOURCE,
                'external_id' => (string) ($video['id'] ?? ''),
                'thumbnail_url' => (string) ($video['image'] ?? ''),
                'download_url' => (string) ($file['link'] ?? ''),
                'media_type' => 'video',
                'width' => (int) ($file['width'] ?? $video['width'] ?? 0) ?: null,
                'height' => (int) ($file['height'] ?? $video['height'] ?? 0) ?: null,
                'duration_seconds' => isset($video['duration']) ? (float) $video['duration'] : null,
                'license_label' => 'Pexels License (free for commercial use, no attribution required)',
                'raw_metadata' => [
                    'pexels_url' => $video['url'] ?? null,
                    'user' => $video['user']['name'] ?? null,
                ],
            ];
        }

        return $candidates;
    }

    /**
     * @param array<int,array<string,mixed>> $files
     */
    private function pickBestVideoFile(array $files): ?array
    {
        $mp4Files = array_values(array_filter($files, fn($f) => ($f['file_type'] ?? '') === 'video/mp4'));
        if (empty($mp4Files)) {
            return $files[0] ?? null;
        }

        usort($mp4Files, function ($a, $b) {
            $targetA = abs((int) ($a['width'] ?? 0) - 1920);
            $targetB = abs((int) ($b['width'] ?? 0) - 1920);
            return $targetA <=> $targetB;
        });

        return $mp4Files[0];
    }
}

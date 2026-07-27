<?php

namespace App\Services\StockSources;

use Illuminate\Support\Facades\Http;

class PixabaySearchService
{
    public const SOURCE = 'pixabay';

    /**
     * @return array<int,array<string,mixed>>
     */
    public function search(string $keyword, int $perPage = 6): array
    {
        $apiKey = trim((string) config('services.stock_sources.pixabay.api_key', ''));
        if ($apiKey === '') {
            return [];
        }

        // Pixabay requires per_page >= 3.
        $perPage = max(3, $perPage);

        try {
            $response = Http::timeout(15)->get('https://pixabay.com/api/videos/', [
                'key' => $apiKey,
                'q' => $keyword,
                'per_page' => $perPage,
                'safesearch' => 'true',
            ]);
        } catch (\Throwable $e) {
            return [];
        }

        if (!$response->successful()) {
            return [];
        }

        $hits = $response->json('hits') ?? [];
        $candidates = [];

        foreach ($hits as $hit) {
            $variant = $this->pickBestVariant($hit['videos'] ?? []);
            if (!$variant) {
                continue;
            }

            $candidates[] = [
                'source' => self::SOURCE,
                'external_id' => (string) ($hit['id'] ?? ''),
                'thumbnail_url' => (string) ($variant['thumbnail'] ?? ''),
                'download_url' => (string) ($variant['url'] ?? ''),
                'media_type' => 'video',
                'width' => (int) ($variant['width'] ?? 0) ?: null,
                'height' => (int) ($variant['height'] ?? 0) ?: null,
                'duration_seconds' => isset($hit['duration']) ? (float) $hit['duration'] : null,
                'license_label' => 'Pixabay Content License (free for commercial use, no attribution required)',
                'raw_metadata' => [
                    'pageURL' => $hit['pageURL'] ?? null,
                    'tags' => $hit['tags'] ?? null,
                    'user' => $hit['user'] ?? null,
                ],
            ];
        }

        return $candidates;
    }

    /**
     * @param array<string,array<string,mixed>> $variants
     */
    private function pickBestVariant(array $variants): ?array
    {
        foreach (['large', 'medium', 'small', 'tiny'] as $quality) {
            if (!empty($variants[$quality]['url'])) {
                return $variants[$quality];
            }
        }

        return null;
    }
}

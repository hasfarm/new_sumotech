<?php

namespace App\Services\StockSources;

use Illuminate\Support\Facades\Http;

class SmithsonianSearchService
{
    public const SOURCE = 'smithsonian';

    /**
     * @return array<int,array<string,mixed>>
     */
    public function search(string $keyword, int $rows = 8): array
    {
        $apiKey = trim((string) config('services.stock_sources.smithsonian.api_key', ''));
        if ($apiKey === '') {
            return [];
        }

        try {
            $response = Http::timeout(15)->get('https://api.si.edu/openaccess/api/v1.0/search', [
                'q' => $keyword . ' AND online_media_type:Images',
                'api_key' => $apiKey,
                'rows' => $rows,
            ]);
        } catch (\Throwable $e) {
            return [];
        }

        if (!$response->successful()) {
            return [];
        }

        $items = $response->json('response.rows') ?? [];
        $candidates = [];

        foreach ($items as $item) {
            $mediaList = $item['content']['descriptiveNonRepeating']['online_media']['media'] ?? [];

            foreach ($mediaList as $media) {
                if (($media['type'] ?? '') !== 'Images') {
                    continue;
                }

                // Only surface openly-licensed items; skip anything not explicitly CC0.
                if (($media['usage']['access'] ?? '') !== 'CC0') {
                    continue;
                }

                $resources = $media['resources'] ?? [];
                $downloadResource = $this->findResourceByLabel($resources, 'Screen Image')
                    ?? $this->findResourceByLabel($resources, 'High-resolution JPEG')
                    ?? null;
                $thumbnailResource = $this->findResourceByLabel($resources, 'Thumbnail Image') ?? $downloadResource;
                $sizedResource = $this->findResourceByLabel($resources, 'High-resolution JPEG');

                if (!$downloadResource) {
                    continue;
                }

                $candidates[] = [
                    'source' => self::SOURCE,
                    'external_id' => (string) ($media['id'] ?? $media['idsId'] ?? ''),
                    'thumbnail_url' => (string) ($thumbnailResource['url'] ?? $media['thumbnail'] ?? ''),
                    'download_url' => (string) ($downloadResource['url'] ?? ''),
                    'media_type' => 'image',
                    'width' => (int) ($sizedResource['width'] ?? 0) ?: null,
                    'height' => (int) ($sizedResource['height'] ?? 0) ?: null,
                    'duration_seconds' => null,
                    'license_label' => 'CC0 (Smithsonian Open Access)',
                    'raw_metadata' => [
                        'title' => $item['title'] ?? null,
                        'unit' => $item['unitCode'] ?? null,
                    ],
                ];

                break; // one media item per catalog record is enough
            }
        }

        return $candidates;
    }

    /**
     * @param array<int,array<string,mixed>> $resources
     */
    private function findResourceByLabel(array $resources, string $label): ?array
    {
        foreach ($resources as $resource) {
            if (($resource['label'] ?? '') === $label && !empty($resource['url'])) {
                return $resource;
            }
        }

        return null;
    }
}

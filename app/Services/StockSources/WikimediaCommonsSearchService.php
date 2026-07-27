<?php

namespace App\Services\StockSources;

use Illuminate\Support\Facades\Http;

class WikimediaCommonsSearchService
{
    public const SOURCE = 'wikimedia';

    /**
     * @return array<int,array<string,mixed>>
     */
    public function search(string $keyword, int $limit = 6): array
    {
        try {
            $response = Http::withHeaders(['User-Agent' => 'SumoTech-VideoPipeline/1.0'])
                ->timeout(15)
                ->get('https://commons.wikimedia.org/w/api.php', [
                    'action' => 'query',
                    'generator' => 'search',
                    'gsrsearch' => $keyword,
                    'gsrnamespace' => 6, // File: namespace
                    'gsrlimit' => $limit,
                    'prop' => 'imageinfo',
                    'iiprop' => 'url|size|mime|extmetadata',
                    'iiurlwidth' => 800,
                    'format' => 'json',
                ]);
        } catch (\Throwable $e) {
            return [];
        }

        if (!$response->successful()) {
            return [];
        }

        $pages = $response->json('query.pages') ?? [];
        $candidates = [];

        foreach ($pages as $page) {
            $info = $page['imageinfo'][0] ?? null;
            if (!$info) {
                continue;
            }

            $mime = (string) ($info['mime'] ?? '');
            $mediaType = str_starts_with($mime, 'video/') ? 'video' : 'image';

            $candidates[] = [
                'source' => self::SOURCE,
                'external_id' => (string) ($page['pageid'] ?? $page['title'] ?? ''),
                'thumbnail_url' => (string) ($info['thumburl'] ?? $info['url'] ?? ''),
                'download_url' => (string) ($info['url'] ?? ''),
                'media_type' => $mediaType,
                'width' => (int) ($info['width'] ?? 0) ?: null,
                'height' => (int) ($info['height'] ?? 0) ?: null,
                'duration_seconds' => null,
                'license_label' => (string) ($info['extmetadata']['LicenseShortName']['value'] ?? 'Unknown license'),
                'raw_metadata' => [
                    'title' => $page['title'] ?? null,
                    'artist' => strip_tags((string) ($info['extmetadata']['Artist']['value'] ?? '')),
                ],
            ];
        }

        return $candidates;
    }
}

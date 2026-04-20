<?php

require __DIR__ . '/../../vendor/autoload.php';

$app = require __DIR__ . '/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$limit = isset($argv[1]) ? max(1, (int) $argv[1]) : 300;
$rows = Illuminate\Support\Facades\DB::table('dub_sync_projects')
    ->where('video_id', 'like', 'bili:%')
    ->orderBy('id')
    ->limit($limit)
    ->get(['id', 'video_id', 'youtube_thumbnail', 'youtube_title', 'youtube_title_vi']);

$updated = 0;
$skipped = 0;
$translated = 0;

$translator = app(App\Services\TranslationService::class);

function normalizeThumbUrl(?string $url): ?string {
    $url = trim((string) $url);
    if ($url === '') {
        return null;
    }
    if (str_starts_with($url, '//')) {
        return 'https:' . $url;
    }
    if (str_starts_with($url, 'http://')) {
        return 'https://' . substr($url, 7);
    }
    return $url;
}

foreach ($rows as $row) {
    $videoId = (string) $row->video_id;
    if (!str_starts_with($videoId, 'bili:')) {
        $skipped++;
        continue;
    }

    $bvid = substr($videoId, 5);
    if ($bvid === '') {
        $skipped++;
        continue;
    }

    $url = normalizeThumbUrl($row->youtube_thumbnail);

    // If url is already a local /storage path, force refresh from Bilibili metadata.
    if (!$url || str_starts_with($url, '/storage/')) {
        try {
            $meta = Illuminate\Support\Facades\Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0',
                'Referer' => 'https://www.bilibili.com/',
            ])->timeout(20)->get('https://api.bilibili.com/x/web-interface/view', [
                'bvid' => $bvid,
            ]);

            if ($meta->ok() && (int) $meta->json('code', -1) === 0) {
                $url = normalizeThumbUrl((string) $meta->json('data.pic'));

                if (empty($row->youtube_title_vi) && preg_match('/\p{Han}/u', (string) $row->youtube_title)) {
                    $vi = trim((string) $translator->translateText((string) $row->youtube_title, 'zh-CN', 'vi', 'google'));
                    if ($vi !== '') {
                        Illuminate\Support\Facades\DB::table('dub_sync_projects')
                            ->where('id', $row->id)
                            ->update([
                                'youtube_title_vi' => $vi,
                                'updated_at' => now(),
                            ]);
                        $translated++;
                    }
                }
            }
        } catch (Throwable $e) {
            // continue with existing url fallback.
        }
    }

    if (!$url || str_starts_with($url, '/storage/')) {
        $skipped++;
        continue;
    }

    try {
        $resp = Illuminate\Support\Facades\Http::withHeaders([
            'User-Agent' => 'Mozilla/5.0',
            'Referer' => 'https://www.bilibili.com/',
        ])->timeout(20)->get($url);

        if (!$resp->ok()) {
            $skipped++;
            continue;
        }

        $safeVideoId = preg_replace('/[^a-zA-Z0-9_\-]/', '_', (string) $row->video_id);
        $path = "bilibili_thumbnails/{$safeVideoId}.jpg";

        Illuminate\Support\Facades\Storage::disk('public')->put($path, $resp->body());

        Illuminate\Support\Facades\DB::table('dub_sync_projects')
            ->where('id', $row->id)
            ->update([
                'youtube_thumbnail' => Illuminate\Support\Facades\Storage::url($path),
                'updated_at' => now(),
            ]);

        $updated++;
    } catch (Throwable $e) {
        $skipped++;
    }
}

echo "Done. updated={$updated}, translated={$translated}, skipped={$skipped}" . PHP_EOL;

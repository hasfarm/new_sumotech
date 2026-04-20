<?php

require __DIR__ . '/../../vendor/autoload.php';

$app = require __DIR__ . '/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$limit = isset($argv[1]) ? max(1, (int) $argv[1]) : 200;
$translator = app(App\Services\TranslationService::class);

$rows = Illuminate\Support\Facades\DB::table('dub_sync_projects')
    ->where('video_id', 'like', 'bili:%')
    ->whereNull('youtube_title_vi')
    ->whereNotNull('youtube_title')
    ->orderBy('id')
    ->limit($limit)
    ->get(['id', 'youtube_title']);

$updated = 0;

foreach ($rows as $row) {
    if (!preg_match('/\p{Han}/u', $row->youtube_title)) {
        continue;
    }

    $translated = trim((string) $translator->translateText($row->youtube_title, 'zh-CN', 'vi', 'google'));
    if ($translated === '') {
        continue;
    }

    Illuminate\Support\Facades\DB::table('dub_sync_projects')
        ->where('id', $row->id)
        ->update([
            'youtube_title_vi' => $translated,
            'updated_at' => now(),
        ]);

    $updated++;
}

echo "Done. translated={$updated}" . PHP_EOL;

<?php

namespace App\Console\Commands;

use App\Models\AudioBook;
use App\Models\AudiobookVideoShot;
use App\Services\MotionPilotShotSelector;
use App\Services\MotionRenderService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Pilot ACCEPTANCE deliverable, run after `motion-direction:pilot` has already generated the
 * shots' motion/transition data — this command never calls the AI or re-renders per-shot clips,
 * it only STITCHES what already exists (read-only over motion_asset_path/transition_asset_path)
 * so it's free to re-run. Produces exactly what a human reviewer needs to approve the pilot
 * before any bulk job gets written: a continuous "with effects" preview, a plain-cut "no
 * effects" A/B baseline, and an HTML report with the full per-shot decision table (source
 * thumbnail, presets, focus point, duration, AI reason). Still video-only (no narration audio
 * muxed in) — same limitation as every other render in this pipeline so far.
 */
class MotionDirectionPilotExport extends Command
{
    protected $signature = 'motion-direction:pilot-export {audioBookId} {shotIds?}';
    protected $description = 'Stitch the pilot shots into a continuous with-effects preview + a no-effects A/B baseline, plus an HTML review report';

    public function handle(MotionRenderService $renderService, MotionPilotShotSelector $selector): int
    {
        $audioBookId = (int) $this->argument('audioBookId');
        $audioBook = AudioBook::find($audioBookId);
        if (!$audioBook) {
            $this->error("Audiobook #{$audioBookId} không tồn tại.");
            return self::FAILURE;
        }

        $shotIdsArg = $this->argument('shotIds');
        $shots = $shotIdsArg
            ? AudiobookVideoShot::whereIn('id', array_map('intval', explode(',', $shotIdsArg)))->get()
                ->sortBy(fn ($s) => [$s->scene->scene_index, $s->shot_index])->values()
            : $selector->select($audioBookId);

        if ($shots->isEmpty()) {
            $this->error('Không tìm được shot nào để xuất preview.');
            return self::FAILURE;
        }

        $missing = $shots->filter(fn (AudiobookVideoShot $s) => !$s->motion_asset_path && !$s->is_avatar_segment && !$s->isResolvedAssetVideo() && !$s->transition_asset_path);
        if ($missing->isNotEmpty()) {
            $this->warn('Lưu ý: các shot sau chưa có motion/transition — sẽ dùng fallback tĩnh/cắt thẳng khi ghép: '
                . $missing->pluck('id')->implode(', '));
        }

        $stamp = now()->format('Y-m-d_His');
        $relDir = "audiobooks/{$audioBookId}/video-pipeline/pilot-review/{$stamp}";

        $this->info('Ghép bản CÓ hiệu ứng (motion + transition thật)...');
        $withEffectsPath = $renderService->stitchPilotPreview($shots, true, "{$relDir}/with_effects.mp4");
        $this->info('  ✅ ' . $withEffectsPath);

        $this->info('Ghép bản KHÔNG hiệu ứng (tĩnh + cắt thẳng, để so sánh A/B)...');
        $noEffectsPath = $renderService->stitchPilotPreview($shots, false, "{$relDir}/no_effects.mp4");
        $this->info('  ✅ ' . $noEffectsPath);

        $this->info('Tạo báo cáo HTML...');
        $reportRelative = "{$relDir}/report.html";
        $reportAbsolute = storage_path('app/public/' . $reportRelative);
        file_put_contents($reportAbsolute, $this->buildReportHtml($audioBook, $shots, $withEffectsPath, $noEffectsPath));

        $baseUrl = rtrim(config('app.url'), '/');
        $this->newLine();
        $this->info('=== Hoàn tất — mở link sau để xem thủ công trước khi duyệt ===');
        $this->line("Báo cáo:        {$baseUrl}/storage/{$reportRelative}");
        $this->line("Video có hiệu ứng: {$baseUrl}/storage/{$withEffectsPath}");
        $this->line("Video không hiệu ứng: {$baseUrl}/storage/{$noEffectsPath}");

        return self::SUCCESS;
    }

    private function buildReportHtml(AudioBook $audioBook, \Illuminate\Support\Collection $shots, string $withEffectsPath, string $noEffectsPath): string
    {
        $baseUrl = rtrim(config('app.url'), '/');
        $title = htmlspecialchars('Pilot Motion & Transition — ' . $audioBook->title, ENT_QUOTES, 'UTF-8');
        $generatedAt = now()->format('d/m/Y H:i:s');

        $rows = $shots->map(fn (AudiobookVideoShot $shot) => $this->buildRow($shot, $baseUrl))->implode('');

        return <<<HTML
<!doctype html>
<html lang="vi">
<head>
<meta charset="utf-8">
<title>{$title}</title>
<style>
  body { font-family: -apple-system, Segoe UI, Arial, sans-serif; background: #14151a; color: #e6e6e6; margin: 0; padding: 24px; }
  h1 { font-size: 20px; margin: 0 0 4px; }
  .meta { color: #9a9aa5; font-size: 13px; margin-bottom: 20px; }
  .videos { display: flex; gap: 16px; flex-wrap: wrap; margin-bottom: 28px; }
  .video-card { flex: 1 1 420px; background: #1d1e25; border: 1px solid #2c2d36; border-radius: 8px; padding: 12px; }
  .video-card h2 { font-size: 14px; margin: 0 0 8px; color: #cfcfe0; }
  video { width: 100%; border-radius: 4px; background: #000; }
  table { width: 100%; border-collapse: collapse; font-size: 12px; }
  th, td { border: 1px solid #2c2d36; padding: 6px 8px; text-align: left; vertical-align: top; }
  th { background: #1d1e25; position: sticky; top: 0; }
  td.thumb img, td.thumb video { width: 140px; height: 80px; object-fit: cover; border-radius: 4px; display: block; }
  .reason { color: #b7b7c9; font-style: italic; }
  .reason.fallback { color: #d99a3a; }
  .badge { display: inline-block; padding: 1px 6px; border-radius: 10px; background: #2c2d36; font-size: 11px; margin-bottom: 3px; }
  .table-wrap { overflow-x: auto; }
</style>
</head>
<body>
  <h1>{$title}</h1>
  <div class="meta">Xuất lúc {$generatedAt} · {$shots->count()} shot</div>

  <div class="videos">
    <div class="video-card">
      <h2>✅ Có hiệu ứng (motion + transition thật)</h2>
      <video controls preload="metadata" src="{$baseUrl}/storage/{$withEffectsPath}"></video>
    </div>
    <div class="video-card">
      <h2>⬛ Không hiệu ứng (A/B baseline — tĩnh + cắt thẳng)</h2>
      <video controls preload="metadata" src="{$baseUrl}/storage/{$noEffectsPath}"></video>
    </div>
  </div>

  <div class="table-wrap">
  <table>
    <thead><tr>
      <th>Shot</th><th>Ảnh nguồn</th><th>Motion</th><th>Transition</th>
    </tr></thead>
    <tbody>
      {$rows}
    </tbody>
  </table>
  </div>
</body>
</html>
HTML;
    }

    private function buildRow(AudiobookVideoShot $shot, string $baseUrl): string
    {
        $isVideoSourced = $shot->is_avatar_segment || $shot->isResolvedAssetVideo();
        $thumbPath = $shot->is_avatar_segment ? $shot->avatar_video_path : $shot->resolved_asset_path;
        $thumbHtml = $thumbPath
            ? ($isVideoSourced
                ? sprintf('<video preload="metadata" muted src="%s/storage/%s"></video>', $baseUrl, htmlspecialchars($thumbPath, ENT_QUOTES, 'UTF-8'))
                : sprintf('<img src="%s/storage/%s" loading="lazy" alt="">', $baseUrl, htmlspecialchars($thumbPath, ENT_QUOTES, 'UTF-8')))
            : '<span style="color:#666">(không có)</span>';

        $motionCell = $isVideoSourced
            ? '<span style="color:#666">— không áp dụng (avatar/video) —</span>'
            : $this->presetCell($shot->motion_preset, $shot->motion_status, sprintf('focus %.2f,%.2f · intensity %.2f · %ss',
                (float) ($shot->motion_focus_x ?? 0.5), (float) ($shot->motion_focus_y ?? 0.5),
                (float) ($shot->motion_intensity ?? 0), (string) ($shot->motion_duration_seconds ?? '?')), $shot->motion_reason);

        $transitionCell = $this->presetCell($shot->transition_preset, $shot->transition_status,
            sprintf('intensity %.2f · %ss', (float) ($shot->transition_intensity ?? 0), (string) ($shot->transition_duration_seconds ?? '?')),
            $shot->transition_reason);

        return sprintf(
            '<tr><td>#%d</td><td class="thumb">%s</td><td>%s</td><td>%s</td></tr>',
            $shot->id,
            $thumbHtml,
            $motionCell,
            $transitionCell
        );
    }

    private function presetCell(?string $preset, ?string $status, string $detail, ?string $reason): string
    {
        if (!$preset) {
            return '<span style="color:#666">(chưa tạo)</span>';
        }

        $isFallbackReason = $reason === \App\Services\MotionDirectionService::REASON_FALLBACK;
        $reasonHtml = $reason
            ? sprintf('<div class="reason%s">"%s"</div>', $isFallbackReason ? ' fallback' : '', htmlspecialchars($reason, ENT_QUOTES, 'UTF-8'))
            : '';

        return sprintf(
            '<span class="badge">%s</span> <span style="color:#9a9aa5">(%s)</span><br><span style="color:#666;font-size:11px">%s</span>%s',
            htmlspecialchars((string) $preset, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars((string) ($status ?? 'pending'), ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($detail, ENT_QUOTES, 'UTF-8'),
            $reasonHtml
        );
    }
}

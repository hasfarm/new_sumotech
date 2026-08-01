<?php

namespace App\Services;

use App\Models\AudiobookAudioAsset;
use App\Models\AudiobookVideoShot;
use App\Services\AssetLibrary\R2StorageService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Builds and executes the ACTUAL ffmpeg command for AI-selected motion — the safety-critical
 * half of the pipeline. Every filter string here is built via sprintf with ONLY numeric
 * substitutions (all pre-clamped by the caller AND re-clamped here — defense in depth) and a
 * hardcoded template selected by a strict `match()` on the preset name. The AI's choice can
 * never reach this class as anything other than one of
 * MotionDirectionService::MOTION_PRESETS, and even if it somehow did, `match()` with no default
 * arm throws rather than silently building an unknown filter. Follows the exact
 * escapeshellarg()+sprintf()+exec() convention used everywhere else in this codebase (see
 * AssetLibrary\AssetLibraryService::extractVideoThumbnail()).
 */
class MotionRenderService
{
    private const WIDTH = 1280;
    private const HEIGHT = 720;
    private const FPS = 30;
    // zoompan needs the SOURCE scaled well above the output before zooming in, or the crop
    // window visibly pixelates once zoom exceeds ~1.1 — a well-known ffmpeg zoompan gotcha.
    private const PRESCALE_WIDTH = 2560;
    private const PRESCALE_HEIGHT = 1440;

    /** asset_id => local temp path, so a scene-level ambience/music shared by many shots in one stitchPilotPreview() run is only ever downloaded from R2 once. */
    private array $audioAssetCache = [];

    /** asset_id => {scene_id, offset}: tracks how far INTO an ambience/music track playback has reached, so consecutive shots in the same scene continue the track instead of each restarting it from 0 — see nextLoopOffset(). */
    private array $audioLoopOffsets = [];

    public function __construct(private readonly R2StorageService $r2Storage) {}

    /**
     * Renders a shot's still image into a short motion clip. Returns the PUBLIC-DISK-RELATIVE
     * output path (same convention as resolved_asset_path — no leading "public/").
     */
    public function renderShotMotion(AudiobookVideoShot $shot, string $preset, float $focusX, float $focusY, float $intensity, float $durationSeconds): string
    {
        if (!in_array($preset, MotionDirectionService::MOTION_PRESETS, true)) {
            throw new \InvalidArgumentException("Unknown motion preset: {$preset}");
        }

        $focusX = max(0.0, min(1.0, $focusX));
        $focusY = max(0.0, min(1.0, $focusY));
        $intensity = max(0.0, min(1.0, $intensity));
        $durationSeconds = max(1.0, min(15.0, $durationSeconds));

        $localImagePath = storage_path('app/public/' . $shot->resolved_asset_path);
        if (!file_exists($localImagePath)) {
            throw new \RuntimeException("Không tìm thấy ảnh nguồn: {$shot->resolved_asset_path}");
        }

        $filter = $this->buildMotionFilter($preset, $focusX, $focusY, $intensity, $durationSeconds);

        $uuid = (string) Str::uuid();
        $relativeDir = "audiobooks/{$shot->scene->audio_book_id}/video-pipeline/scenes/{$shot->video_scene_id}/shots/{$shot->id}";
        $outputRelative = "{$relativeDir}/motion_{$uuid}.mp4";
        $outputAbsolute = storage_path('app/public/' . $outputRelative);
        if (!is_dir(dirname($outputAbsolute))) {
            mkdir(dirname($outputAbsolute), 0755, true);
        }

        $ffmpegPath = (string) config('services.ffmpeg.path', 'ffmpeg');
        $command = sprintf(
            '%s -y -loop 1 -i %s -vf %s -t %.2f -r %d -pix_fmt yuv420p -c:v libx264 -preset veryfast -crf 20 -movflags +faststart %s 2>&1',
            escapeshellarg($ffmpegPath),
            escapeshellarg($localImagePath),
            escapeshellarg($filter),
            $durationSeconds,
            self::FPS,
            escapeshellarg($outputAbsolute)
        );

        exec($command, $output, $returnCode);

        if ($returnCode !== 0 || !file_exists($outputAbsolute) || filesize($outputAbsolute) === 0) {
            Log::error('MotionRenderService: render failed', [
                'shot_id' => $shot->id,
                'preset' => $preset,
                'command' => $command,
                'output' => implode("\n", $output),
            ]);
            throw new \RuntimeException('Render motion thất bại: ' . implode(' | ', array_slice($output, -5)));
        }

        return $outputRelative;
    }

    /**
     * Every case here is a hardcoded sprintf TEMPLATE — only numeric, pre-clamped values are
     * ever substituted in. This is the exact boundary where "AI picks a name" becomes "PHP
     * decides the real ffmpeg expression".
     */
    private function buildMotionFilter(string $preset, float $focusX, float $focusY, float $intensity, float $durationSeconds): string
    {
        $totalFrames = max(1, (int) round($durationSeconds * self::FPS));
        $prescale = sprintf(
            'scale=%d:%d:force_original_aspect_ratio=increase,crop=%d:%d',
            self::PRESCALE_WIDTH,
            self::PRESCALE_HEIGHT,
            self::PRESCALE_WIDTH,
            self::PRESCALE_HEIGHT
        );
        $outSize = self::WIDTH . 'x' . self::HEIGHT;

        return match ($preset) {
            'static' => sprintf(
                'scale=%d:%d:force_original_aspect_ratio=increase,crop=%d:%d,fps=%d',
                self::WIDTH, self::HEIGHT, self::WIDTH, self::HEIGHT, self::FPS
            ),
            'micro_zoom' => $this->zoomFilter($prescale, $outSize, $totalFrames, $focusX, $focusY, 1.0, 1.0 + $intensity * 0.05),
            'zoom_in' => $this->zoomFilter($prescale, $outSize, $totalFrames, $focusX, $focusY, 1.0, 1.0 + $intensity * 0.4),
            'zoom_out' => $this->zoomFilter($prescale, $outSize, $totalFrames, $focusX, $focusY, 1.0 + $intensity * 0.4, 1.0),
            'push' => $this->zoomFilter($prescale, $outSize, $totalFrames, $focusX, $focusY, 1.0, 1.05 + $intensity * 0.35),
            'pull' => $this->zoomFilter($prescale, $outSize, $totalFrames, $focusX, $focusY, 1.05 + $intensity * 0.35, 1.0),
            'pan_left' => $this->panFilter($prescale, $outSize, $totalFrames, $focusX, $focusY, $intensity, -1),
            'pan_right' => $this->panFilter($prescale, $outSize, $totalFrames, $focusX, $focusY, $intensity, 1),
            'shake' => $this->shakeFilter($prescale, $outSize, $focusX, $focusY, $intensity),
            default => throw new \InvalidArgumentException("Unhandled motion preset: {$preset}"),
        };
    }

    /** Absolute-frame-number ramp (not a recursive zoom+=step) — deterministic, no float drift. */
    private function zoomFilter(string $prescale, string $outSize, int $totalFrames, float $focusX, float $focusY, float $zoomStart, float $zoomEnd): string
    {
        $zoomExpr = sprintf('%.4f+(%.4f-%.4f)*min(on/%d\,1)', $zoomStart, $zoomEnd, $zoomStart, $totalFrames);
        $xExpr = sprintf('%.4f*iw-(iw/zoom/2)', $focusX);
        $yExpr = sprintf('%.4f*ih-(ih/zoom/2)', $focusY);

        return sprintf("%s,zoompan=z='%s':x='%s':y='%s':d=1:s=%s:fps=%d", $prescale, $zoomExpr, $xExpr, $yExpr, $outSize, self::FPS);
    }

    /**
     * The crop window's horizontal travel is bounded by the slack available at the chosen zoom
     * level (iw - iw/zoom, in normalized units 1 - 1/zoom): requesting more spread than that
     * slack means ffmpeg silently clamps x mid-pan and the motion barely shows on screen, even
     * though the filter graph is "technically valid" — caught by actually diffing rendered
     * frames (a single visual glance at busy scenery wasn't enough to catch it). Zoom is picked
     * here to guarantee enough slack for the requested spread BEFORE start/end are computed,
     * and start/end are clamped to the zoom-dependent valid CENTER range (not [0,1]) so the
     * window itself never gets pinned to an edge mid-pan.
     */
    private function panFilter(string $prescale, string $outSize, int $totalFrames, float $focusX, float $focusY, float $intensity, int $direction): string
    {
        $spread = 0.14 + $intensity * 0.14;
        $zoom = max(1.3, 1 / (1 - min($spread + 0.08, 0.5)));

        $centerMin = 1 / (2 * $zoom);
        $centerMax = 1 - $centerMin;
        $startX = max($centerMin, min($centerMax, $focusX - $direction * $spread / 2));
        $endX = max($centerMin, min($centerMax, $focusX + $direction * $spread / 2));
        $clampedFocusY = max($centerMin, min($centerMax, $focusY));

        $xExpr = sprintf('(%.4f+(%.4f-%.4f)*min(on/%d\,1))*iw-(iw/zoom/2)', $startX, $endX, $startX, $totalFrames);
        $yExpr = sprintf('%.4f*ih-(ih/zoom/2)', $clampedFocusY);

        return sprintf("%s,zoompan=z='%.4f':x='%s':y='%s':d=1:s=%s:fps=%d", $prescale, $zoom, $xExpr, $yExpr, $outSize, self::FPS);
    }

    private function shakeFilter(string $prescale, string $outSize, float $focusX, float $focusY, float $intensity): string
    {
        $zoom = 1.12;
        $amplitude = 4 + $intensity * 14;
        $xExpr = sprintf('%.4f*iw-(iw/zoom/2)+sin(on*0.6)*%.2f', $focusX, $amplitude);
        $yExpr = sprintf('%.4f*ih-(ih/zoom/2)+cos(on*0.7)*%.2f', $focusY, $amplitude);

        return sprintf("%s,zoompan=z='%.4f':x='%s':y='%s':d=1:s=%s:fps=%d", $prescale, $zoom, $xExpr, $yExpr, $outSize, self::FPS);
    }

    /**
     * Maps OUR whitelisted transition name to ffmpeg's OWN fixed `xfade` transition= vocabulary
     * (confirmed valid values already in use by VideoCompositionService::concatenateWithTransitions()
     * in this codebase). `null` for 'cut' means "no crossfade" — handled as a plain concat by
     * renderTransitionPreview() instead. This mapping is the entire trust boundary for
     * transitions: the AI's enum choice never becomes anything other than one of these 5 fixed
     * strings, or throws.
     */
    public function buildTransitionFilter(string $preset): ?string
    {
        if (!in_array($preset, MotionDirectionService::TRANSITION_PRESETS, true)) {
            throw new \InvalidArgumentException("Unknown transition preset: {$preset}");
        }

        return match ($preset) {
            'cut' => null,
            'fade' => 'fade',
            'dissolve' => 'dissolve',
            'fadeblack' => 'fadeblack',
            'slide' => 'slideleft',
            'blur' => 'hblur',
            default => throw new \InvalidArgumentException("Unhandled transition preset: {$preset}"),
        };
    }

    /**
     * Renders a short preview spanning the boundary between $previousShot and $shot: the tail
     * of the previous clip, the chosen transition (or a plain cut), then the head of this
     * shot's clip. Neither shot needs an already-approved motion render — if one isn't
     * available yet, a throwaway 'static' clip is rendered on the fly purely for this preview
     * (not persisted as the shot's own motion choice). Returns the PUBLIC-DISK-RELATIVE output
     * path.
     */
    public function renderTransitionPreview(AudiobookVideoShot $previousShot, AudiobookVideoShot $shot, string $preset, float $durationSeconds): string
    {
        $xfadeName = $this->buildTransitionFilter($preset); // validates preset as a side effect
        $durationSeconds = max(0.2, min(2.0, $durationSeconds));

        $prevClipPath = $this->resolvePreviewClipPath($previousShot);
        $currClipPath = $this->resolvePreviewClipPath($shot);

        $tailSeconds = min(2.0, max(0.5, $this->probeDuration($prevClipPath) ?? 2.0));
        $headSeconds = min(2.0, max(0.5, $this->probeDuration($currClipPath) ?? 2.0));
        $offset = max(0.0, $tailSeconds - $durationSeconds);

        $outSize = self::WIDTH . 'x' . self::HEIGHT;
        $normalize = sprintf('scale=%d:%d:force_original_aspect_ratio=decrease,pad=%d:%d:(ow-iw)/2:(oh-ih)/2,setsar=1,fps=%d', self::WIDTH, self::HEIGHT, self::WIDTH, self::HEIGHT, self::FPS);

        if ($xfadeName === null) {
            // 'cut' — plain concat of the two trimmed segments, no crossfade at all.
            $filterComplex = sprintf(
                '[0:v]%s[v0];[1:v]%s[v1];[v0][v1]concat=n=2:v=1:a=0[outv]',
                $normalize, $normalize
            );
        } else {
            $filterComplex = sprintf(
                '[0:v]%s[v0];[1:v]%s[v1];[v0][v1]xfade=transition=%s:duration=%.2f:offset=%.2f[outv]',
                $normalize, $normalize, $xfadeName, $durationSeconds, $offset
            );
        }

        $uuid = (string) Str::uuid();
        $relativeDir = "audiobooks/{$shot->scene->audio_book_id}/video-pipeline/scenes/{$shot->video_scene_id}/shots/{$shot->id}";
        $outputRelative = "{$relativeDir}/transition_{$uuid}.mp4";
        $outputAbsolute = storage_path('app/public/' . $outputRelative);
        if (!is_dir(dirname($outputAbsolute))) {
            mkdir(dirname($outputAbsolute), 0755, true);
        }

        $ffmpegPath = (string) config('services.ffmpeg.path', 'ffmpeg');
        $command = sprintf(
            '%s -y -sseof -%.2f -i %s -t %.2f -i %s -filter_complex %s -map %s -pix_fmt yuv420p -c:v libx264 -preset veryfast -crf 20 -movflags +faststart %s 2>&1',
            escapeshellarg($ffmpegPath),
            $tailSeconds,
            escapeshellarg($prevClipPath),
            $headSeconds,
            escapeshellarg($currClipPath),
            escapeshellarg($filterComplex),
            escapeshellarg('[outv]'),
            escapeshellarg($outputAbsolute)
        );

        exec($command, $output, $returnCode);

        if ($returnCode !== 0 || !file_exists($outputAbsolute) || filesize($outputAbsolute) === 0) {
            Log::error('MotionRenderService: transition render failed', [
                'shot_id' => $shot->id,
                'preset' => $preset,
                'command' => $command,
                'output' => implode("\n", $output),
            ]);
            throw new \RuntimeException('Render transition thất bại: ' . implode(' | ', array_slice($output, -5)));
        }

        return $outputRelative;
    }

    private function resolvePreviewClipPath(AudiobookVideoShot $shot): string
    {
        if ($shot->is_avatar_segment && $shot->avatar_video_path) {
            return storage_path('app/public/' . $shot->avatar_video_path);
        }
        if ($shot->isResolvedAssetVideo() && $shot->resolved_asset_path) {
            return storage_path('app/public/' . $shot->resolved_asset_path);
        }
        if ($shot->motion_asset_path) {
            return storage_path('app/public/' . $shot->motion_asset_path);
        }

        // No motion rendered/approved yet — render a throwaway static clip purely so a
        // transition preview is always possible; deliberately NOT persisted as the shot's own
        // motion_asset_path (that stays the user's/AI's actual decision to make separately).
        $tempRelative = $this->renderShotMotion($shot, 'static', 0.5, 0.5, 0.3, 3.0);

        return storage_path('app/public/' . $tempRelative);
    }

    /**
     * Concatenates an ORDERED sequence of shots into ONE continuous clip for manual pilot
     * review — motion + transition applied at the correct point in the timeline when
     * $withEffects, or every shot flattened to a plain static clip joined by hard cuts only
     * when not (the A/B comparison baseline). This is a review export only, not the general
     * bulk stitching pipeline (deliberately deferred until the pilot is approved) — it reads
     * whatever motion_asset_path/transition_asset_path already exist rather than generating
     * anything itself, so it makes no AI calls and can be re-run freely. Carries each shot's
     * narration/avatar audio along the SAME timeline as the video (crossfades audio at the
     * same boundary + duration as the visual transition) — a shot with no audio available gets
     * silence rather than breaking the chain. Every intermediate file this method creates is a
     * throwaway OS temp copy; it never touches a shot's own persisted assets.
     *
     * @param Collection<int,AudiobookVideoShot> $shots already in book order, 0-indexed
     */
    public function stitchPilotPreview(Collection $shots, bool $withEffects, string $outputRelativePath): string
    {
        $shots = $shots->values();
        if ($shots->isEmpty()) {
            throw new \InvalidArgumentException('Không có shot nào để ghép preview.');
        }
        $this->audioLoopOffsets = [];

        $outputAbsolute = storage_path('app/public/' . $outputRelativePath);
        if (!is_dir(dirname($outputAbsolute))) {
            mkdir(dirname($outputAbsolute), 0755, true);
        }

        try {
            // Mux every shot into its own (video+audio) temp clip FIRST, so the sequential merge
            // below only ever has to reason about 2-input files that both already carry a
            // matching audio stream — same simplification renderTransitionPreview() doesn't need
            // since it's only ever 2 shots, not an arbitrary-length chain. Audio (voice + sfx/
            // ambience/music) is identical between $withEffects true/false — only the VIDEO
            // differs, so the A/B comparison isolates motion/transition, not audio.
            $clipPaths = $shots->map(fn (AudiobookVideoShot $shot) => $this->muxShotWithAudio($shot, $withEffects))->all();

            $lastIndex = count($clipPaths) - 1;
            if ($lastIndex === 0) {
                copy($clipPaths[0], $outputAbsolute);
                @unlink($clipPaths[0]);
            } else {
                $current = $clipPaths[0];
                $tempToCleanup = [$clipPaths[0]];
                for ($i = 1; $i <= $lastIndex; $i++) {
                    $shot = $shots->get($i);
                    $preset = $withEffects ? ($shot->transition_preset ?? 'cut') : 'cut';
                    $duration = $withEffects ? (float) ($shot->transition_duration_seconds ?? 0.6) : 0.6;

                    $stepOutput = $i === $lastIndex ? $outputAbsolute : sys_get_temp_dir() . '/vp_stitch_' . Str::uuid() . '.mp4';
                    $this->mergeTwoClipsWithAudio($current, $clipPaths[$i], $preset, $duration, $stepOutput);

                    $tempToCleanup[] = $clipPaths[$i];
                    foreach ($tempToCleanup as $temp) {
                        @unlink($temp);
                    }
                    $tempToCleanup = [];
                    if ($i !== $lastIndex) {
                        $tempToCleanup[] = $stepOutput;
                    }
                    $current = $stepOutput;
                }
            }
        } finally {
            $this->clearAudioAssetCache();
        }

        if (!file_exists($outputAbsolute) || filesize($outputAbsolute) === 0) {
            throw new \RuntimeException('Ghép preview thất bại — file output rỗng hoặc không tồn tại.');
        }

        return $outputRelativePath;
    }

    /**
     * Produces a throwaway (video+audio) temp clip for ONE shot: the shot's own visual (see
     * resolveFullClipPath()) paired with its FULL audio mix — voice (narration, or the avatar
     * video's own already-synced track) plus whatever sfx/ambience/music are currently selected
     * for it (see buildShotAudioMix()). Audio is padded with silence or trimmed to match the
     * video's own duration exactly, so a mismatched TTS/music length never desyncs the cut.
     */
    private function muxShotWithAudio(AudiobookVideoShot $shot, bool $withEffects): string
    {
        $videoPath = $this->resolveFullClipPath($shot, $withEffects);
        $videoDuration = $this->probeDuration($videoPath) ?? 4.0;
        $outputAbsolute = sys_get_temp_dir() . '/vp_mux_' . Str::uuid() . '.mp4';
        $ffmpegPath = (string) config('services.ffmpeg.path', 'ffmpeg');

        $audioPath = $this->buildShotAudioMix($shot, $videoDuration);

        if ($audioPath) {
            $filter = sprintf('[1:a]aformat=sample_rates=44100:channel_layouts=stereo,apad,atrim=0:%.2f[a]', $videoDuration);
            $command = sprintf(
                '%s -y -i %s -i %s -filter_complex %s -map 0:v -map %s -c:v copy -c:a aac -b:a 160k -shortest -movflags +faststart %s 2>&1',
                escapeshellarg($ffmpegPath), escapeshellarg($videoPath), escapeshellarg($audioPath),
                escapeshellarg($filter), escapeshellarg('[a]'), escapeshellarg($outputAbsolute)
            );
        } else {
            $filter = '[1:a]aformat=sample_rates=44100:channel_layouts=stereo[a]';
            $command = sprintf(
                '%s -y -i %s -f lavfi -i %s -filter_complex %s -map 0:v -map %s -c:v copy -c:a aac -b:a 160k -shortest -movflags +faststart %s 2>&1',
                escapeshellarg($ffmpegPath), escapeshellarg($videoPath),
                escapeshellarg(sprintf('anullsrc=r=44100:cl=stereo:d=%.2f', $videoDuration)),
                escapeshellarg($filter), escapeshellarg('[a]'), escapeshellarg($outputAbsolute)
            );
        }

        exec($command, $output, $returnCode);
        if ($audioPath) {
            @unlink($audioPath); // one-off per-shot mix output, never reused
        }
        if ($returnCode !== 0 || !file_exists($outputAbsolute) || filesize($outputAbsolute) === 0) {
            Log::error('MotionRenderService: audio mux failed', [
                'shot_id' => $shot->id, 'video' => $videoPath, 'command' => $command, 'output' => implode("\n", $output),
            ]);
            throw new \RuntimeException('Ghép audio cho shot #' . $shot->id . ' thất bại: ' . implode(' | ', array_slice($output, -5)));
        }

        return $outputAbsolute;
    }

    /**
     * The shot's full intended audio picture for pilot review: voice + whatever sfx/ambience/
     * music are CURRENTLY SELECTED (any status — this is a review tool, not a "only show
     * approved" gate). Returns null only when literally nothing is available, so the caller
     * falls back to silence. Dialogue-forward mix levels (voice 0dB, sfx -6dB, music -18dB,
     * ambience -20dB) — a reasonable default, not a tuned final mix.
     */
    private function buildShotAudioMix(AudiobookVideoShot $shot, float $videoDuration): ?string
    {
        $layers = [];

        if ($shot->is_avatar_segment && $shot->avatar_video_path) {
            $voice = $this->extractAudioTrack(storage_path('app/public/' . $shot->avatar_video_path));
            if ($voice) {
                $layers[] = ['path' => $voice, 'db' => 0.0, 'loop' => false, 'seek' => 0.0, 'temp' => true, 'role' => 'voice'];
            }
        } else {
            $voice = $this->resolveNarrationAudioPath($shot);
            if ($voice) {
                $layers[] = ['path' => $voice, 'db' => 0.0, 'loop' => false, 'seek' => 0.0, 'temp' => false, 'role' => 'voice'];
            }
        }

        if ($shot->sfxAsset) {
            $sfx = $this->downloadAudioAsset($shot->sfxAsset);
            if ($sfx) {
                $layers[] = ['path' => $sfx, 'db' => -6.0, 'loop' => false, 'seek' => 0.0, 'temp' => false, 'role' => 'sfx'];
            }
        }

        $ambienceAsset = $shot->resolvedAmbience()['asset'] ?? null;
        if ($ambienceAsset) {
            $ambience = $this->downloadAudioAsset($ambienceAsset);
            if ($ambience) {
                $seek = $this->nextLoopOffset($ambienceAsset->id, $shot->video_scene_id, $ambience, $videoDuration);
                $layers[] = ['path' => $ambience, 'db' => -20.0, 'loop' => true, 'seek' => $seek, 'temp' => false, 'role' => 'ambience'];
            }
        }

        $musicAsset = $shot->resolvedMusic()['asset'] ?? null;
        if ($musicAsset) {
            $music = $this->downloadAudioAsset($musicAsset);
            if ($music) {
                $seek = $this->nextLoopOffset($musicAsset->id, $shot->video_scene_id, $music, $videoDuration);
                $layers[] = ['path' => $music, 'db' => -18.0, 'loop' => true, 'seek' => $seek, 'temp' => false, 'role' => 'music'];
            }
        }

        if (empty($layers)) {
            return null;
        }

        $mixed = $this->mixAudioLayers($layers, $videoDuration);

        // Only the one-off voice extraction is ours to delete — sfx/ambience/music downloads
        // are cached (a scene's ambience/music is shared by many shots) and cleaned up once at
        // the end of stitchPilotPreview() instead.
        foreach ($layers as $layer) {
            if ($layer['temp']) {
                @unlink($layer['path']);
            }
        }

        return $mixed;
    }

    /**
     * Mixes voice + sfx + ambience + music into one track. Ambience/music are SIDECHAIN-DUCKED
     * against the voice layer when one exists — real ducking (ffmpeg's `sidechaincompress`,
     * keyed off the voice stream), not just a constant dB offset: they automatically recede
     * further while the voice is actually speaking and recover during pauses. sfx is
     * deliberately NOT ducked — it's a short, intentional story beat meant to be heard at its
     * own level regardless of narration.
     *
     * @param array<int,array{path:string,db:float,loop:bool,seek:float,temp:bool,role:string}> $layers
     */
    private function mixAudioLayers(array $layers, float $videoDuration): string
    {
        $ffmpegPath = (string) config('services.ffmpeg.path', 'ffmpeg');
        $inputArgs = '';
        $filterParts = [];
        $voiceLabel = null;
        $duckableLabels = [];
        $finalLabels = [];

        foreach (array_values($layers) as $i => $layer) {
            // -ss BEFORE -i seeks this input's start position — continues an ambience/music
            // track from where the previous shot in the same scene left off (see
            // nextLoopOffset()) instead of every shot restarting it at 0:00. Voice/sfx always
            // have seek=0.0, so this is a no-op for them.
            $inputArgs .= ($layer['seek'] > 0.0 ? sprintf('-ss %.2f ', $layer['seek']) : '')
                . ($layer['loop'] ? '-stream_loop -1 ' : '') . '-i ' . escapeshellarg($layer['path']) . ' ';
            $label = "a{$i}";
            $filterParts[] = sprintf(
                '[%d:a]aformat=sample_rates=44100:channel_layouts=stereo,volume=%.1fdB,apad,atrim=0:%.2f[%s]',
                $i, $layer['db'], $videoDuration, $label
            );

            if ($layer['role'] === 'voice') {
                $voiceLabel = $label;
                $finalLabels[] = "[{$label}]";
            } elseif ($layer['role'] === 'ambience' || $layer['role'] === 'music') {
                $duckableLabels[] = $label;
            } else {
                $finalLabels[] = "[{$label}]"; // sfx — never ducked
            }
        }

        foreach ($duckableLabels as $label) {
            if ($voiceLabel !== null) {
                $duckedLabel = $label . 'd';
                // threshold/ratio tuned for "recede while voice is present, recover in pauses"
                // rather than a hard gate — attack fast enough to duck before the voice's first
                // syllable, release slow enough not to pump between words.
                $filterParts[] = sprintf(
                    '[%s][%s]sidechaincompress=threshold=0.05:ratio=8:attack=5:release=400:makeup=1[%s]',
                    $label, $voiceLabel, $duckedLabel
                );
                $finalLabels[] = "[{$duckedLabel}]";
            } else {
                $finalLabels[] = "[{$label}]"; // no voice this shot — nothing to duck against
            }
        }

        $filterComplex = implode(';', $filterParts) . ';' . implode('', $finalLabels)
            // normalize=0: amix's default auto-normalize divides EVERY input by the input count,
            // which would additionally attenuate the voice layer on top of its own explicit
            // dB gain above — fighting the per-layer levels instead of respecting them. A light
            // alimiter is the safety net against clipping instead, since voice+sfx can
            // occasionally overlap at full volume.
            . sprintf('amix=inputs=%d:duration=first:dropout_transition=0:normalize=0,atrim=0:%.2f,alimiter=limit=0.95[mixed]', count($finalLabels), $videoDuration);

        $outputAbsolute = sys_get_temp_dir() . '/vp_layermix_' . Str::uuid() . '.m4a';
        $command = sprintf(
            '%s -y %s-filter_complex %s -map %s -c:a aac -b:a 192k %s 2>&1',
            escapeshellarg($ffmpegPath), $inputArgs, escapeshellarg($filterComplex), escapeshellarg('[mixed]'), escapeshellarg($outputAbsolute)
        );

        exec($command, $output, $returnCode);
        if ($returnCode !== 0 || !file_exists($outputAbsolute) || filesize($outputAbsolute) === 0) {
            Log::error('MotionRenderService: audio layer mix failed', ['command' => $command, 'output' => implode("\n", $output)]);
            throw new \RuntimeException('Trộn các lớp audio thất bại: ' . implode(' | ', array_slice($output, -5)));
        }

        return $outputAbsolute;
    }

    /** Standalone AAC audio track pulled out of a video that already has one (e.g. an avatar/HeyGen clip) — null (not thrown) if the source has no audio stream, so a silent source just yields no voice layer instead of failing the whole mix. */
    private function extractAudioTrack(string $videoPath): ?string
    {
        $outputAbsolute = sys_get_temp_dir() . '/vp_voice_' . Str::uuid() . '.m4a';
        $ffmpegPath = (string) config('services.ffmpeg.path', 'ffmpeg');
        $command = sprintf(
            '%s -y -i %s -vn -c:a aac -b:a 192k %s 2>&1',
            escapeshellarg($ffmpegPath), escapeshellarg($videoPath), escapeshellarg($outputAbsolute)
        );
        exec($command, $output, $returnCode);

        return ($returnCode === 0 && file_exists($outputAbsolute) && filesize($outputAbsolute) > 0) ? $outputAbsolute : null;
    }

    /** Same "public/" stripping convention as AudiobookVideoShot::ttsStorageUrl() — null if the shot has no narration or the file is missing on disk. */
    private function resolveNarrationAudioPath(AudiobookVideoShot $shot): ?string
    {
        if (!$shot->narration_audio_path) {
            return null;
        }
        $stripped = preg_replace('#^public/#', '', $shot->narration_audio_path);
        $absolute = storage_path('app/public/' . $stripped);

        return file_exists($absolute) ? $absolute : null;
    }

    /** Downloads an R2-backed audio asset to a local temp file ONCE per stitchPilotPreview() run (cached by asset id) — a scene's ambience/music is shared by every shot in that scene, so this avoids redundant R2 egress. Returns null (logs a warning, never throws) if the object is missing — a preview mix should degrade gracefully, not fail the whole export over one bad asset. */
    private function downloadAudioAsset(AudiobookAudioAsset $asset): ?string
    {
        if (!$asset->r2_path) {
            return null;
        }
        if (isset($this->audioAssetCache[$asset->id])) {
            return $this->audioAssetCache[$asset->id];
        }

        $ext = pathinfo($asset->r2_path, PATHINFO_EXTENSION) ?: 'mp3';
        $local = sys_get_temp_dir() . '/vp_asset_' . $asset->id . '_' . Str::uuid() . '.' . $ext;

        try {
            $this->r2Storage->download($asset->r2_path, $local);
        } catch (\Throwable $e) {
            Log::warning('MotionRenderService: could not download audio asset for pilot mix', [
                'asset_id' => $asset->id, 'r2_path' => $asset->r2_path, 'error' => $e->getMessage(),
            ]);
            return null;
        }

        return $this->audioAssetCache[$asset->id] = $local;
    }

    /** Clears every R2 asset downloaded during one stitchPilotPreview() run — called once at the end, not per-shot, since the cache's whole point is sharing one download across many shots. */
    private function clearAudioAssetCache(): void
    {
        foreach ($this->audioAssetCache as $path) {
            @unlink($path);
        }
        $this->audioAssetCache = [];
    }

    /**
     * Returns where in $localPath playback should START for THIS shot, so an ambience/music
     * track shared by consecutive shots in the same scene keeps advancing instead of every shot
     * restarting it from 0:00 — without this, a scene with 5 shots would replay the same first
     * few seconds of its music 5 times instead of playing through it once, continuously.
     * Wrapped modulo the track's own duration (via fmod) so a scene longer than one loop of the
     * source just wraps around rather than seeking past EOF. Resets to 0 whenever either the
     * scene changes or this exact asset hasn't been used yet — a per-shot OVERRIDE that
     * temporarily swaps in a different track does NOT advance the original track's position
     * while it's not playing (resuming later picks up where it left off, not fast-forwarded).
     */
    private function nextLoopOffset(int $assetId, int $sceneId, string $localPath, float $consumeDuration): float
    {
        $state = $this->audioLoopOffsets[$assetId] ?? null;
        $offset = ($state && $state['scene_id'] === $sceneId) ? $state['offset'] : 0.0;

        $sourceDuration = $this->probeDuration($localPath) ?? 0.0;
        $seek = $sourceDuration > 0.0 ? fmod($offset, $sourceDuration) : 0.0;

        $this->audioLoopOffsets[$assetId] = ['scene_id' => $sceneId, 'offset' => $offset + $consumeDuration];

        return $seek;
    }

    /**
     * Same shape as mergeTwoClips() but also crossfades (or concatenates, for 'cut') the audio
     * stream at the identical boundary/duration as the video — every input here already carries
     * both streams (see muxShotWithAudio()), so this is the one place that actually needs [0:a]/[1:a].
     */
    private function mergeTwoClipsWithAudio(string $aPath, string $bPath, string $preset, float $transitionDuration, string $outputAbsolute): void
    {
        $xfadeName = $this->buildTransitionFilter($preset); // validates preset as a side effect

        $outSize = self::WIDTH . 'x' . self::HEIGHT;
        $normalize = sprintf('scale=%d:%d:force_original_aspect_ratio=decrease,pad=%d:%d:(ow-iw)/2:(oh-ih)/2,setsar=1,fps=%d', self::WIDTH, self::HEIGHT, self::WIDTH, self::HEIGHT, self::FPS);

        if ($xfadeName === null) {
            $filterComplex = sprintf(
                '[0:v]%s[v0];[1:v]%s[v1];[v0][v1]concat=n=2:v=1:a=0[outv];[0:a][1:a]concat=n=2:v=0:a=1[outa]',
                $normalize, $normalize
            );
        } else {
            $aDuration = $this->probeDuration($aPath) ?? 4.0;
            $transitionDuration = max(0.2, min(2.0, $transitionDuration > 0 ? $transitionDuration : 0.6));
            $offset = max(0.0, $aDuration - $transitionDuration);
            $filterComplex = sprintf(
                '[0:v]%s[v0];[1:v]%s[v1];[v0][v1]xfade=transition=%s:duration=%.2f:offset=%.2f[outv];[0:a][1:a]acrossfade=d=%.2f[outa]',
                $normalize, $normalize, $xfadeName, $transitionDuration, $offset, $transitionDuration
            );
        }

        $ffmpegPath = (string) config('services.ffmpeg.path', 'ffmpeg');
        $command = sprintf(
            '%s -y -i %s -i %s -filter_complex %s -map %s -map %s -pix_fmt yuv420p -c:v libx264 -preset veryfast -crf 20 -c:a aac -b:a 160k -movflags +faststart %s 2>&1',
            escapeshellarg($ffmpegPath),
            escapeshellarg($aPath),
            escapeshellarg($bPath),
            escapeshellarg($filterComplex),
            escapeshellarg('[outv]'),
            escapeshellarg('[outa]'),
            escapeshellarg($outputAbsolute)
        );

        exec($command, $output, $returnCode);

        if ($returnCode !== 0 || !file_exists($outputAbsolute) || filesize($outputAbsolute) === 0) {
            Log::error('MotionRenderService: audio-aware stitch merge failed', [
                'a' => $aPath, 'b' => $bPath, 'preset' => $preset, 'command' => $command, 'output' => implode("\n", $output),
            ]);
            throw new \RuntimeException('Ghép clip (kèm audio) thất bại: ' . implode(' | ', array_slice($output, -5)));
        }
    }

    /**
     * Full (untrimmed) clip for ONE shot in the stitched timeline — deliberately different from
     * resolvePreviewClipPath()'s tail/head-trimmed 2-3s snippet, which exists only for the
     * boundary preview, not a full-length stitch. $withEffects=false always flattens still-image
     * shots to a plain static render, ignoring any motion_asset_path, so the A/B baseline never
     * shows Ken Burns movement regardless of what's already been approved.
     */
    private function resolveFullClipPath(AudiobookVideoShot $shot, bool $withEffects): string
    {
        if ($withEffects && $shot->motion_asset_path) {
            return storage_path('app/public/' . $shot->motion_asset_path);
        }
        if ($shot->is_avatar_segment && $shot->avatar_video_path) {
            return storage_path('app/public/' . $shot->avatar_video_path);
        }
        if ($shot->isResolvedAssetVideo() && $shot->resolved_asset_path) {
            return storage_path('app/public/' . $shot->resolved_asset_path);
        }

        $duration = max(2.0, min(8.0, (float) ($shot->estimated_duration_seconds ?: 4.0)));
        $tempRelative = $this->renderShotMotion($shot, 'static', 0.5, 0.5, 0.3, $duration);

        return storage_path('app/public/' . $tempRelative);
    }

    /**
     * Merges two FULL clips (not trimmed) at their shared boundary — 'cut' is a plain concat
     * (output duration = a + b exactly), any other preset crossfades the last $transitionDuration
     * seconds of $aPath into the first $transitionDuration seconds of $bPath via xfade (output
     * duration = a + b - transitionDuration). Same normalize+xfade/concat shape as
     * renderTransitionPreview(), just without the tail/head trim.
     */
    private function mergeTwoClips(string $aPath, string $bPath, string $preset, float $transitionDuration, string $outputAbsolute): void
    {
        $xfadeName = $this->buildTransitionFilter($preset); // validates preset as a side effect

        $outSize = self::WIDTH . 'x' . self::HEIGHT;
        $normalize = sprintf('scale=%d:%d:force_original_aspect_ratio=decrease,pad=%d:%d:(ow-iw)/2:(oh-ih)/2,setsar=1,fps=%d', self::WIDTH, self::HEIGHT, self::WIDTH, self::HEIGHT, self::FPS);

        if ($xfadeName === null) {
            $filterComplex = sprintf('[0:v]%s[v0];[1:v]%s[v1];[v0][v1]concat=n=2:v=1:a=0[outv]', $normalize, $normalize);
        } else {
            $aDuration = $this->probeDuration($aPath) ?? 4.0;
            $transitionDuration = max(0.2, min(2.0, $transitionDuration > 0 ? $transitionDuration : 0.6));
            $offset = max(0.0, $aDuration - $transitionDuration);
            $filterComplex = sprintf(
                '[0:v]%s[v0];[1:v]%s[v1];[v0][v1]xfade=transition=%s:duration=%.2f:offset=%.2f[outv]',
                $normalize, $normalize, $xfadeName, $transitionDuration, $offset
            );
        }

        $ffmpegPath = (string) config('services.ffmpeg.path', 'ffmpeg');
        $command = sprintf(
            '%s -y -i %s -i %s -filter_complex %s -map %s -pix_fmt yuv420p -c:v libx264 -preset veryfast -crf 20 -movflags +faststart %s 2>&1',
            escapeshellarg($ffmpegPath),
            escapeshellarg($aPath),
            escapeshellarg($bPath),
            escapeshellarg($filterComplex),
            escapeshellarg('[outv]'),
            escapeshellarg($outputAbsolute)
        );

        exec($command, $output, $returnCode);

        if ($returnCode !== 0 || !file_exists($outputAbsolute) || filesize($outputAbsolute) === 0) {
            Log::error('MotionRenderService: stitch merge failed', [
                'a' => $aPath, 'b' => $bPath, 'preset' => $preset, 'command' => $command, 'output' => implode("\n", $output),
            ]);
            throw new \RuntimeException('Ghép clip thất bại: ' . implode(' | ', array_slice($output, -5)));
        }
    }

    private function probeDuration(string $path): ?float
    {
        $ffprobePath = (string) config('services.ffmpeg.ffprobe_path', 'ffprobe');
        $command = sprintf(
            '%s -v error -show_entries format=duration -of csv=p=0 %s 2>&1',
            escapeshellarg($ffprobePath),
            escapeshellarg($path)
        );
        exec($command, $output, $returnCode);
        if ($returnCode === 0 && !empty($output[0]) && is_numeric(trim($output[0]))) {
            return round((float) trim($output[0]), 3);
        }

        return null;
    }
}

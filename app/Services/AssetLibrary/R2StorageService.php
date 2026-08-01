<?php

namespace App\Services\AssetLibrary;

use Illuminate\Support\Facades\Storage;

/**
 * Thin wrapper around the 'r2' filesystem disk (Cloudflare R2, S3-compatible). This is the
 * permanent reusable asset library archive — never read directly by the browser. Local
 * storage/app/public stays the working copy each shot actually serves/downloads from;
 * library hits are pulled back down via download() into that same local layout so the
 * existing UI/modal/zip code never has to know R2 exists.
 */
class R2StorageService
{
    private function disk()
    {
        return Storage::disk('r2');
    }

    /**
     * @param array<string,string> $metadata Custom S3 object metadata (x-amz-meta-* headers)
     *   — string values only. Lets the object itself be identifiable/searchable from any S3
     *   tool/console, not just via the app's own MySQL row + Qdrant index (which are the only
     *   place this context lived before — the R2 object itself was an opaque blob).
     */
    public function putFile(string $localAbsolutePath, string $r2Key, array $metadata = []): void
    {
        $stream = fopen($localAbsolutePath, 'r');
        if ($stream === false) {
            throw new \RuntimeException("Không đọc được file để upload lên R2: {$localAbsolutePath}");
        }

        try {
            $options = [];
            if (!empty($metadata)) {
                $options['Metadata'] = $metadata;
            }
            $this->disk()->put($r2Key, $stream, $options);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    public function getBytes(string $r2Key): string
    {
        $contents = $this->disk()->get($r2Key);
        if ($contents === null) {
            throw new \RuntimeException("Không tìm thấy file trên R2: {$r2Key}");
        }

        return $contents;
    }

    public function download(string $r2Key, string $localAbsolutePath): void
    {
        $dir = dirname($localAbsolutePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $stream = $this->disk()->readStream($r2Key);
        if ($stream === null) {
            throw new \RuntimeException("Không tìm thấy file trên R2: {$r2Key}");
        }

        try {
            $target = fopen($localAbsolutePath, 'w');
            if ($target === false) {
                throw new \RuntimeException("Không ghi được file local: {$localAbsolutePath}");
            }
            stream_copy_to_stream($stream, $target);
            fclose($target);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    public function exists(string $r2Key): bool
    {
        return $this->disk()->exists($r2Key);
    }

    /**
     * A presigned, time-limited public URL for an object — lets an external API (e.g. HeyGen)
     * fetch a file directly from R2 without making the bucket itself public. R2 is
     * S3-compatible and supports presigned URLs the same way Laravel's S3 driver does.
     */
    public function temporaryUrl(string $r2Key, int $minutes = 30): string
    {
        return $this->disk()->temporaryUrl($r2Key, now()->addMinutes($minutes));
    }

    public function delete(string $r2Key): void
    {
        $this->disk()->delete($r2Key);
    }
}

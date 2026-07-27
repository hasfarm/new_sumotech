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

    public function putFile(string $localAbsolutePath, string $r2Key): void
    {
        $stream = fopen($localAbsolutePath, 'r');
        if ($stream === false) {
            throw new \RuntimeException("Không đọc được file để upload lên R2: {$localAbsolutePath}");
        }

        try {
            $this->disk()->put($r2Key, $stream);
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

    public function delete(string $r2Key): void
    {
        $this->disk()->delete($r2Key);
    }
}

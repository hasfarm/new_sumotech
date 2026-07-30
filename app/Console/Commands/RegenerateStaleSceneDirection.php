<?php

namespace App\Console\Commands;

use App\Jobs\RegenerateStaleSceneDirectionJob;
use App\Services\VideoSceneAnalysisService;
use Illuminate\Console\Command;

/**
 * CLI entry point for RegenerateStaleSceneDirectionJob — see that class for the actual
 * staleness predicate and re-bind/re-enrich logic (also used by the pipeline UI's
 * "Regenerate stale scenes" button, so both stay in sync automatically).
 */
class RegenerateStaleSceneDirection extends Command
{
    protected $signature = 'story-direction:regenerate-stale {audioBookId}';
    protected $description = 'Re-assign scene bindings and regenerate only the shot chunks made stale by a Story Bible version change';

    public function handle(VideoSceneAnalysisService $service): int
    {
        $audioBookId = (int) $this->argument('audioBookId');

        $result = (new RegenerateStaleSceneDirectionJob($audioBookId))->handle($service);

        if ($result['status'] === 'not_found') {
            $this->error("Audiobook #{$audioBookId} không tồn tại.");
            return self::FAILURE;
        }

        if ($result['status'] === 'no_active_bible') {
            $this->error('Sách này chưa có Story Bible active.');
            return self::FAILURE;
        }

        $this->info("Đã gán lại bối cảnh cho {$result['reassigned']}/{$result['total']} cảnh.");

        if (empty($result['stale_chunk_indices'])) {
            if ($result['reassigned'] === 0) {
                $this->info('Không có cảnh nào bị stale — không cần regenerate shot nào.');
            } else {
                $this->info('Cảnh đã được gán lại nhưng chưa có shot/chunk nào để regenerate (có thể chưa chạy Stage B).');
            }
            return self::SUCCESS;
        }

        $this->info('Regenerate ' . count($result['stale_chunk_indices']) . ' chunk stale: ' . implode(', ', $result['stale_chunk_indices']));

        return self::SUCCESS;
    }
}

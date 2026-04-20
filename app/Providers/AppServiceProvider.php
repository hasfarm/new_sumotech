<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobFailed;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Schema::defaultStringLength(191);

        $locale = Session::get('app_locale', config('app.locale'));
        App::setLocale($locale);

        // Enable query logging in development
        if (config('app.debug')) {
            DB::listen(function ($query) {
                \Log::debug('SQL Query', [
                    'query' => $query->sql,
                    'bindings' => $query->bindings,
                    'time' => $query->time . 'ms'
                ]);
            });
        }

        // Track job history
        $this->registerQueueListeners();
    }

    private function registerQueueListeners(): void
    {
        $queue = app('queue');

        $queue->before(function (JobProcessing $event) {
            $payload = json_decode($event->job->getRawBody(), true);
            $displayName = class_basename($payload['displayName'] ?? 'Unknown');
            $targetId = null;
            $targetType = null;
            try {
                $command = unserialize($payload['data']['command'] ?? '');
                if (isset($command->audioBookId)) { $targetId = $command->audioBookId; $targetType = 'audiobook'; }
                elseif (isset($command->projectId)) { $targetId = $command->projectId; $targetType = 'project'; }
            } catch (\Throwable $e) {}

            // Store start time in cache keyed by job ID
            \Cache::put('job_start_' . $event->job->getJobId(), [
                'job_name' => $displayName,
                'target_id' => $targetId,
                'target_type' => $targetType,
                'started_at' => now(),
            ], now()->addHours(6));
        });

        $queue->after(function (JobProcessed $event) {
            $this->recordJobHistory($event->job, 'completed');
        });

        $queue->failing(function (JobFailed $event) {
            $msg = mb_substr($event->exception->getMessage(), 0, 500);
            $this->recordJobHistory($event->job, 'failed', $msg);
        });
    }

    private function recordJobHistory($job, string $status, ?string $message = null): void
    {
        try {
            $startData = \Cache::pull('job_start_' . $job->getJobId());
            if (!$startData) {
                $payload = json_decode($job->getRawBody(), true);
                $startData = ['job_name' => class_basename($payload['displayName'] ?? 'Unknown'), 'target_id' => null, 'target_type' => null, 'started_at' => now()];
            }

            $startedAt = $startData['started_at'];
            $finishedAt = now();
            $duration = $startedAt ? (int) $finishedAt->diffInSeconds($startedAt) : null;

            DB::table('job_histories')->insert([
                'job_name' => $startData['job_name'],
                'target_id' => $startData['target_id'],
                'target_type' => $startData['target_type'],
                'status' => $status,
                'duration_seconds' => $duration,
                'message' => $message,
                'started_at' => $startedAt,
                'finished_at' => $finishedAt,
            ]);

            // Keep only last 200 entries
            $count = DB::table('job_histories')->count();
            if ($count > 200) {
                $cutoff = DB::table('job_histories')->orderByDesc('id')->skip(200)->value('id');
                if ($cutoff) DB::table('job_histories')->where('id', '<=', $cutoff)->delete();
            }
        } catch (\Throwable $e) {
            \Log::warning('Failed to record job history: ' . $e->getMessage());
        }
    }
}

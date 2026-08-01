<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Reusable audio clip (SFX, ambience, or music) — mirrors VideoAssetLibrary's shape/purpose
 * but as its own table since audio needs fields (audio_category, is_loopable, fingerprint)
 * that don't apply to images/video, and none of the video table's width/height/thumbnail
 * fields apply here.
 */
class AudiobookAudioAsset extends Model
{
    protected $fillable = [
        'audio_category',
        'provider',
        'origin_source',
        'external_id',
        'r2_path',
        'duration_seconds',
        'is_loopable',
        'license_label',
        'attribution',
        'prompt',
        'keywords',
        'audio_prompt_version',
        'fingerprint',
        'score_final',
        'source_book_id',
        'usage_count',
        'requested_duration_seconds',
        'generation_latency_ms',
        'credits_used',
        'cost_usd',
        'provider_request_id',
    ];

    protected $casts = [
        'keywords' => 'array',
        'is_loopable' => 'boolean',
        'duration_seconds' => 'float',
        'score_final' => 'float',
        'requested_duration_seconds' => 'float',
        'cost_usd' => 'float',
    ];

    protected $appends = ['preview_url'];

    public function sourceBook()
    {
        return $this->belongsTo(AudioBook::class, 'source_book_id');
    }

    /**
     * A presigned R2 URL for direct playback/preview — computed locally (S3-style presigning
     * is a local HMAC signature, not a network round-trip), so safe to append on every
     * serialization even under frequent polling from the video-pipeline status feed.
     */
    public function getPreviewUrlAttribute(): ?string
    {
        if (!$this->r2_path) {
            return null;
        }

        return app(\App\Services\AssetLibrary\R2StorageService::class)->temporaryUrl($this->r2_path, 120);
    }
}

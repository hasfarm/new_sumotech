<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VideoAssetLibrary extends Model
{
    protected $table = 'video_asset_library';

    protected $fillable = [
        'media_type',
        'origin_source',
        'external_id',
        'r2_path',
        'thumbnail_r2_path',
        'width',
        'height',
        'duration_seconds',
        'license_label',
        'description',
        'keywords',
        'culture_context',
        'score_final',
        'source_book_id',
        'usage_count',
    ];

    protected $casts = [
        'keywords' => 'array',
        'score_final' => 'float',
    ];

    public function sourceBook()
    {
        return $this->belongsTo(AudioBook::class, 'source_book_id');
    }
}

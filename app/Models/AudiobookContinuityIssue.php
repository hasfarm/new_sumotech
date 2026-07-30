<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * issue_type is a plain string column — this class holds the allowed-value list in PHP
 * (application enum) rather than a DB enum, so adding a new continuity-check category never
 * needs a migration.
 */
class AudiobookContinuityIssue extends Model
{
    // issue_type values
    public const TYPE_CHARACTER_MISMATCH = 'character_mismatch';
    public const TYPE_PHASE_MISMATCH = 'phase_mismatch';
    public const TYPE_IDENTITY_ANCHOR_MISMATCH = 'identity_anchor_mismatch';
    public const TYPE_BASELINE_TRAIT_MISMATCH = 'baseline_trait_mismatch';
    public const TYPE_WARDROBE_MISMATCH = 'wardrobe_mismatch';
    public const TYPE_HAIRSTYLE_MISMATCH = 'hairstyle_mismatch';
    public const TYPE_INJURY_MISMATCH = 'injury_mismatch';
    public const TYPE_PHYSIQUE_MISMATCH = 'physique_mismatch';
    public const TYPE_SOCIAL_ROLE_MISMATCH = 'social_role_mismatch';
    public const TYPE_TIMELINE_MISMATCH = 'timeline_mismatch';
    public const TYPE_STORY_PHASE_MISMATCH = 'story_phase_mismatch';
    public const TYPE_LOCATION_MISMATCH = 'location_mismatch';
    public const TYPE_GEOGRAPHY_MISMATCH = 'geography_mismatch';
    public const TYPE_CULTURAL_GROUP_MISMATCH = 'cultural_group_mismatch';
    public const TYPE_ARCHITECTURE_MISMATCH = 'architecture_mismatch';
    public const TYPE_CLOTHING_MISMATCH = 'clothing_mismatch';
    public const TYPE_RELIGION_MISMATCH = 'religion_mismatch';
    public const TYPE_TRANSPORTATION_MISMATCH = 'transportation_mismatch';
    public const TYPE_HISTORICAL_PERIOD_MISMATCH = 'historical_period_mismatch';
    public const TYPE_ANACHRONISM = 'anachronism';
    public const TYPE_NARRATION_IMAGE_MISMATCH = 'narration_image_mismatch';
    public const TYPE_UNRESOLVED_BINDING = 'unresolved_binding';
    // Adjacent-shot comparison types — compare a shot against the shot immediately before it
    // (same event/chunk), not against the scene-wide bible binding. Only ever raised when
    // there's NO transition evidence (time/place-skip wording) in the narration between the
    // two shots — a shot that legitimately narrates a scene change is never flagged.
    public const TYPE_UNEXPECTED_LOCATION_CHANGE = 'unexpected_location_change';
    public const TYPE_WARDROBE_CHANGE = 'wardrobe_change';
    public const TYPE_ROLE_COSTUME_MISMATCH = 'role_costume_mismatch';

    public const ALL_TYPES = [
        self::TYPE_CHARACTER_MISMATCH, self::TYPE_PHASE_MISMATCH, self::TYPE_IDENTITY_ANCHOR_MISMATCH,
        self::TYPE_BASELINE_TRAIT_MISMATCH, self::TYPE_WARDROBE_MISMATCH, self::TYPE_HAIRSTYLE_MISMATCH,
        self::TYPE_INJURY_MISMATCH, self::TYPE_PHYSIQUE_MISMATCH, self::TYPE_SOCIAL_ROLE_MISMATCH,
        self::TYPE_TIMELINE_MISMATCH, self::TYPE_STORY_PHASE_MISMATCH, self::TYPE_LOCATION_MISMATCH,
        self::TYPE_GEOGRAPHY_MISMATCH, self::TYPE_CULTURAL_GROUP_MISMATCH, self::TYPE_ARCHITECTURE_MISMATCH,
        self::TYPE_CLOTHING_MISMATCH, self::TYPE_RELIGION_MISMATCH, self::TYPE_TRANSPORTATION_MISMATCH,
        self::TYPE_HISTORICAL_PERIOD_MISMATCH, self::TYPE_ANACHRONISM, self::TYPE_NARRATION_IMAGE_MISMATCH,
        self::TYPE_UNRESOLVED_BINDING, self::TYPE_UNEXPECTED_LOCATION_CHANGE, self::TYPE_WARDROBE_CHANGE,
        self::TYPE_ROLE_COSTUME_MISMATCH,
    ];

    /** Confirmed/inferred confidence is treated as "error" even at the lower "inferred" tier for these — a wrong WHO/WHERE/WHEN is high-impact even when only inferred. */
    public const HIGH_IMPACT_TYPES = [
        self::TYPE_CHARACTER_MISMATCH, self::TYPE_PHASE_MISMATCH, self::TYPE_IDENTITY_ANCHOR_MISMATCH,
        self::TYPE_TIMELINE_MISMATCH, self::TYPE_LOCATION_MISMATCH, self::TYPE_UNRESOLVED_BINDING,
        self::TYPE_ANACHRONISM, self::TYPE_UNEXPECTED_LOCATION_CHANGE, self::TYPE_ROLE_COSTUME_MISMATCH,
    ];

    /** Fixable only by re-running scene assignment (Phase 3) or fixing the Story Bible (Phase 2) — never by regenerating a shot's enrichment. */
    public const NOT_REGENERABLE_TYPES = [
        self::TYPE_TIMELINE_MISMATCH, self::TYPE_LOCATION_MISMATCH, self::TYPE_UNRESOLVED_BINDING,
    ];

    // severity values
    public const SEVERITY_ERROR = 'error';
    public const SEVERITY_WARNING = 'warning';
    public const SEVERITY_NEEDS_REVIEW = 'needs_review';

    // recommended_action values (the system only ever assigns the first two — 'accept' is user-driven only, never auto-assigned)
    public const ACTION_AUTO_REGENERATE = 'auto_regenerate';
    public const ACTION_MANUAL_REVIEW = 'manual_review';
    public const ACTION_ACCEPT = 'accept';

    // status values
    public const STATUS_OPEN = 'open';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_REGENERATING = 'regenerating';
    public const STATUS_RESOLVED = 'resolved';

    // resolution_reason values (unresolved_binding only)
    public const REASON_ENTITY_MISSING = 'entity_missing';
    public const REASON_AMBIGUOUS_MATCH = 'ambiguous_match';
    public const REASON_NO_EVIDENCE = 'no_evidence';
    public const REASON_ALIAS_NOT_FOUND = 'alias_not_found';
    public const REASON_BINDING_STALE = 'binding_stale';

    protected $fillable = [
        'audio_book_id',
        'video_scene_id',
        'video_shot_id',
        'issue_type',
        'binding_key',
        'severity',
        'message',
        'expected_value',
        'actual_value',
        'confidence',
        'source_type',
        'evidence',
        'rationale',
        'resolution_reason',
        'recommended_action',
        'status',
        'issue_fingerprint',
        'continuity_validator_version',
        'validator_run_id',
        'regeneration_batch_id',
        'accepted_at',
        'accepted_by',
        'resolved_at',
    ];

    protected $casts = [
        'expected_value' => 'array',
        'actual_value' => 'array',
        'evidence' => 'array',
        'accepted_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function audioBook()
    {
        return $this->belongsTo(AudioBook::class);
    }

    public function scene()
    {
        return $this->belongsTo(AudiobookVideoScene::class, 'video_scene_id');
    }

    public function shot()
    {
        return $this->belongsTo(AudiobookVideoShot::class, 'video_shot_id');
    }

    public function validatorRun()
    {
        return $this->belongsTo(AudiobookContinuityValidationRun::class, 'validator_run_id');
    }

    public function acceptedByUser()
    {
        return $this->belongsTo(User::class, 'accepted_by');
    }

    /** True for the fixed set of open-ish statuses a continuity report should still surface. */
    public function isActive(): bool
    {
        return in_array($this->status, [self::STATUS_OPEN, self::STATUS_REGENERATING], true);
    }
}

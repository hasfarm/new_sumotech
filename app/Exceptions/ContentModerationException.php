<?php

namespace App\Exceptions;

/**
 * Thrown when an AI image provider (Flux/Grok/etc.) rejects a generation request due to its
 * own content-moderation policy — distinct from a generic technical failure so callers can
 * mark the shot with a status the UI treats differently ('content_blocked' vs 'failed'):
 * retrying with the exact same prompt will just fail the same way again, so the fix is
 * editing image_request's text, not re-clicking "retry".
 */
class ContentModerationException extends \RuntimeException
{
}

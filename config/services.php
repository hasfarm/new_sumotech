<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'youtube' => [
        'api_key' => env('YOUTUBE_API_KEY'),
        'client_id' => env('YOUTUBE_CLIENT_ID'),
        'client_secret' => env('YOUTUBE_CLIENT_SECRET'),
        'redirect_uri' => env('YOUTUBE_REDIRECT_URI', '/youtube-channels/oauth/callback'),
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'embedding_model' => env('OPENAI_EMBEDDING_MODEL', 'text-embedding-3-small'),
    ],

    'qdrant' => [
        'url' => env('QDRANT_URL', 'http://127.0.0.1:6333'),
        'api_key' => env('QDRANT_API_KEY'),
        'collection' => env('QDRANT_COLLECTION', 'audiobook_chapter_chunks'),
        'distance' => env('QDRANT_DISTANCE', 'Cosine'),
        'timeout' => (int) env('QDRANT_TIMEOUT', 30),
        'openai_timeout' => (int) env('QDRANT_OPENAI_TIMEOUT', 60),
        'enrich_with_llm' => (bool) env('QDRANT_ENRICH_WITH_LLM', false),
        'enrich_low_confidence_min_character_tags' => (int) env('QDRANT_ENRICH_MIN_CHARACTER_TAGS', 2),
        'enrich_low_confidence_min_importance_score' => (float) env('QDRANT_ENRICH_MIN_IMPORTANCE_SCORE', 0.55),
        'enrich_low_confidence_min_text_length' => (int) env('QDRANT_ENRICH_MIN_TEXT_LENGTH', 120),
        'enrich_low_confidence_required_signals' => (int) env('QDRANT_ENRICH_LOW_CONF_REQUIRED_SIGNALS', 2),
    ],

    'google_tts' => [
        'api_key' => env('GOOGLE_TTS_API_KEY'),
    ],

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'tts_api_key' => env('GEMINI_TTS_API_KEY'),
    ],

    'aiml' => [
        'api_key' => env('AIML_API_KEY', env('AIMLAPI_KEY')),
        'base_url' => env('AIML_BASE_URL', 'https://api.aimlapi.com'),
        'flux_model' => env('AIML_FLUX_MODEL', 'flux/schnell'),
        'flux_pro_model' => env('AIML_FLUX_PRO_MODEL', 'flux-pro'),
        'flux_1_1_pro_model' => env('AIML_FLUX_1_1_PRO_MODEL', 'flux-1.1-pro'),
    ],

    'seedance' => [
        'api_key' => env('SEEDANCE_API_KEY', env('ARK_API_KEY', '')),
        'base_url' => env('SEEDANCE_BASE_URL', env('ARK_BASE_URL', 'https://ark.ap-southeast.bytepluses.com/api/v3')),
        'model' => env('SEEDANCE_MODEL', 'seedance-1-5-pro-251215'),
    ],

    'stable_diffusion' => [
        'base_url' => env('SD_BASE_URL', 'http://127.0.0.1:7860'),
        'negative_prompt' => env('SD_NEGATIVE_PROMPT', 'lowres, bad anatomy, bad hands, text, error, missing fingers, extra digit, fewer digits, cropped, worst quality, low quality, jpeg artifacts, signature, watermark, blurry, deformed'),
        'steps' => (int) env('SD_STEPS', 28),
        'cfg_scale' => (float) env('SD_CFG_SCALE', 7),
        'sampler' => env('SD_SAMPLER', 'DPM++ 2M'),
        'scheduler' => env('SD_SCHEDULER', 'Karras'),
    ],

    'vbee' => [
        'app_id' => env('VBEE_TTS_APP_ID'),
        'token' => env('VBEE_TTS_TOKEN'),
    ],

    'ffmpeg' => [
        'path' => env('FFMPEG_PATH', 'ffmpeg'),
        'ffprobe_path' => env('FFPROBE_PATH', 'ffprobe'),
    ],

];

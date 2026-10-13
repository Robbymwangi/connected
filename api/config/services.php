<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    /* The Anthropic batch API, for report-card comments (ADR 0007, build plan 4.3). Without a
       key, as in development and CI, comment drafts are templated and labelled [Stand-in],
       and nothing is sent anywhere. The key lives in the environment, never in the repo or
       the frontend. */
    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
        'version' => '2023-06-01',
        'comment_model' => env('ANTHROPIC_COMMENT_MODEL', 'claude-haiku-5-5'),
        'comment_max_tokens' => 1024,
        // The report assistant (ADR 0013): Sonnet answers, Haiku judges whether a question is
        // about the report at all. Live calls, so a short timeout.
        'assistant_model' => env('ANTHROPIC_ASSISTANT_MODEL', 'claude-sonnet-5-5'),
        'gate_model' => env('ANTHROPIC_GATE_MODEL', 'claude-haiku-5-5'),
        'assistant_max_tokens' => 2048,
        'assistant_timeout' => 30,
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];

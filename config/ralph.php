<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Loop
    |--------------------------------------------------------------------------
    */

    'loop' => [
        'default_iterations' => (int) env('RALPH_LOOP_ITERATIONS', 30),
        'permission_mode' => env('RALPH_PERMISSION_MODE', 'acceptEdits'),
        'model' => env('RALPH_MODEL'),
        'completion_marker' => '<promise>COMPLETE</promise>',
        'max_consecutive_failures' => (int) env('RALPH_MAX_CONSECUTIVE_FAILURES', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Prompt
    |--------------------------------------------------------------------------
    */

    'prompt' => [
        'default_file' => env('RALPH_PROMPT_FILE'),
        'prd_path' => 'prd/backlog',
        'suffix' => 'Focus on one task at a time. Run tests after changes. Commit when done. Output <promise>COMPLETE</promise> when all tasks are finished.',
        'continuation' => 'Continue working. Check the PRD and progress files for remaining tasks. If all tasks are complete, output the completion marker.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Session manager
    |--------------------------------------------------------------------------
    |
    | Which terminal multiplexer backs ralph's detached sessions.
    | Supported: 'screen' (default — GNU screen) | 'tmux'.
    |
    */

    'session' => [
        'manager' => env('RALPH_SESSION_MANAGER', 'screen'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Screen Sessions
    |--------------------------------------------------------------------------
    */

    'screen' => [
        'prefix' => 'ralph',
        'shell' => env('RALPH_SCREEN_SHELL', 'zsh'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tmux Sessions
    |--------------------------------------------------------------------------
    |
    | Only consulted when `session.manager` is 'tmux'. Standalone detached
    | sessions are created (one per ralph session); shell selection is
    | controlled by tmux's own `default-shell` setting.
    |
    */

    'tmux' => [
        'prefix' => 'ralph',
    ],

    /*
    |--------------------------------------------------------------------------
    | Session Tracking
    |--------------------------------------------------------------------------
    */

    'tracking' => [
        'file' => env('RALPH_TRACKING_FILE', '.live-agents'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    */

    'logging' => [
        'directory' => storage_path('ralph-logs'),
        'non_json_warn_threshold' => 50,
    ],

    /*
    |--------------------------------------------------------------------------
    | Script Path
    |--------------------------------------------------------------------------
    |
    | Override the path to the ralph-loop.js script. When null, the
    | package-bundled script is used.
    |
    */

    'script_path' => null,

    /*
    |--------------------------------------------------------------------------
    | Spec Kit
    |--------------------------------------------------------------------------
    */

    'speckit' => [
        'specs_path' => 'specs',
    ],
];

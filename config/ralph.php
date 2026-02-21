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
    | Screen Sessions
    |--------------------------------------------------------------------------
    */

    'screen' => [
        'prefix' => 'ralph',
        'shell' => env('RALPH_SCREEN_SHELL', 'zsh'),
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
];

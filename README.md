# Laravel Ralph

Claude Code agent loop runner for Laravel.

Run Claude Code in iterative loops via GNU screen sessions, with session tracking, resume support, and GitHub issue integration.

## Installation

```bash
composer require woda/laravel-ralph
```

Publish the config (optional):

```bash
php artisan vendor:publish --tag=ralph-config
```

## Configuration

```php
// config/ralph.php
return [
    'loop' => [
        'default_iterations' => 30,
        'permission_mode' => 'acceptEdits',
        'model' => null,
        'completion_marker' => '<promise>COMPLETE</promise>',
    ],

    'prompt' => [
        'default_file' => null,
        'prd_path' => 'prd/backlog',
        'suffix' => '...',
        'continuation' => '...',
    ],

    'screen' => [
        'prefix' => 'ralph',
        'shell' => 'zsh',
    ],

    'tracking' => [
        'file' => '.live-agents',
    ],

    'logging' => [
        'directory' => storage_path('ralph-logs'),
    ],

    'script_path' => null, // Override path to ralph-loop.js
];
```

## Commands

### `ralph:start`

Start an agent loop session.

```bash
php artisan ralph:start my-feature
php artisan ralph:start --issue=42              # From GitHub issue
php artisan ralph:start --prompt="Fix the bug"  # Inline prompt
php artisan ralph:start --prompt=path/to/file   # Prompt from file
php artisan ralph:start                         # Interactive mode
```

Options: `--issue`, `--prompt`, `--iterations`, `--model`, `--budget`, `--fresh`, `--resume`, `--attach`, `--once`

### `ralph:status`

```bash
php artisan ralph:status
php artisan ralph:status --json
php artisan ralph:status --clean    # Remove dead entries
```

### `ralph:attach`

```bash
php artisan ralph:attach my-feature
php artisan ralph:attach            # Interactive selection
```

### `ralph:kill`

```bash
php artisan ralph:kill my-feature
php artisan ralph:kill --all
php artisan ralph:kill --all --force
```

## Requirements

- PHP 8.2+
- Laravel 11 or 12
- Node.js
- `claude` CLI
- GNU `screen`

## License

MIT

<?php

namespace Woda\Ralph;

use Woda\Ralph\Contracts\SessionManager;

class SessionRouter
{
    /** @param array<string, mixed> $record */
    public function forRecord(array $record): SessionManager
    {
        $path = is_string($record['working_path'] ?? null) ? $record['working_path'] : base_path();
        $runner = ! empty($record['docker'])
            ? new DockerCommandRunner(
                service: is_string($record['service'] ?? null) ? $record['service'] : 'agent',
                containerWorkingDir: is_string($record['container_working_dir'] ?? null) ? $record['container_working_dir'] : '/var/www/html',
                composeProjectPath: $path,
            ) : new NativeCommandRunner;
        $prefix = is_string($record['prefix'] ?? null) ? $record['prefix'] : 'ralph';

        return ($record['manager'] ?? 'tmux') === 'screen'
            ? new ScreenManager(prefix: $prefix, shell: is_string($record['shell'] ?? null) ? $record['shell'] : 'zsh', runner: $runner)
            : new TmuxManager(prefix: $prefix, runner: $runner);
    }
}

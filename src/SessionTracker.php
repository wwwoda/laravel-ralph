<?php

namespace Woda\Ralph;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use RuntimeException;

class SessionTracker
{
    public function __construct(
        private readonly string $trackingFile,
        private readonly ScreenManager $screenManager,
    ) {}

    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        /** @var array<string, array<string, mixed>> */
        return $this->read();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function running(): array
    {
        $agents = $this->all();

        return array_filter($agents, fn (array $agent): bool => isset($agent['name']) && is_string($agent['name']) && $this->screenManager->isRunning($agent['name']));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function track(string $key, array $data): void
    {
        $this->withLock(function () use ($key, $data): void {
            $agents = $this->read();
            $agents[$key] = [
                ...$data,
                'started_at' => CarbonImmutable::now()->toIso8601String(),
            ];
            $this->write($agents);
        });
    }

    public function untrack(string $key): void
    {
        $this->withLock(function () use ($key): void {
            $agents = $this->read();
            unset($agents[$key]);
            $this->write($agents);
        });
    }

    /**
     * Remove entries whose screen sessions are no longer running.
     *
     * @return list<string> Keys that were cleaned
     */
    public function clean(): array
    {
        $cleaned = [];

        $this->withLock(function () use (&$cleaned): void {
            $agents = $this->read();

            foreach ($agents as $key => $agent) {
                $agentName = isset($agent['name']) && is_string($agent['name']) ? $agent['name'] : null;
                if ($agentName === null || ! $this->screenManager->isRunning($agentName)) {
                    unset($agents[$key]);
                    $cleaned[] = (string) $key;
                }
            }

            $this->write($agents);
        });

        return $cleaned;
    }

    public function isRunning(string $key): bool
    {
        $agents = $this->all();

        if (! isset($agents[$key])) {
            return false;
        }

        $name = $agents[$key]['name'] ?? null;

        return is_string($name) && $this->screenManager->isRunning($name);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $key): ?array
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function read(): array
    {
        if (! File::exists($this->trackingFile)) {
            return [];
        }

        $content = File::get($this->trackingFile);

        $data = json_decode($content, true);

        /** @var array<string, array<string, mixed>> */
        return is_array($data) ? $data : [];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function write(array $data): void
    {
        File::put(
            $this->trackingFile,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
        );
    }

    private function withLock(callable $callback): void
    {
        $lockFile = $this->trackingFile.'.lock';
        $handle = fopen($lockFile, 'c');

        if ($handle === false) {
            throw new RuntimeException("Cannot open lock file: {$lockFile}");
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                throw new RuntimeException('Cannot acquire lock on tracking file.');
            }

            $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}

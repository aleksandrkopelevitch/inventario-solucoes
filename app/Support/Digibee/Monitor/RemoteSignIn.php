<?php

namespace App\Support\Digibee\Monitor;

use App\Enums\RemoteSignInStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The one remote Digibee sign-in the server may be running, as the screen and
 * the job share it.
 *
 * In the cache rather than a table: it lives for minutes, there is only ever
 * one (the server's browser profile admits one Chromium at a time), and
 * nothing about it is worth keeping once it ends — the session it captures is
 * what is kept (`DigibeeMonitorSession`). The bridge secret travels here
 * because the web request that forwards a click and the job that launched the
 * browser are different processes; it only opens a loopback port, for the
 * minutes the browser lives.
 */
final class RemoteSignIn
{
    private const KEY = 'digibee-monitor:remote-sign-in';

    /** Outlives the job's own ceiling, so a finished run is still there to be read. */
    private const TTL_SECONDS = 1800;

    public function __construct(
        public readonly string $id,
        public readonly ?int $userId,
        public readonly RemoteSignInStatus $status,
        public readonly CarbonImmutable $startedAt,
        #[\SensitiveParameter] public readonly string $secret,
        public readonly ?int $port = null,
        public readonly ?string $message = null,
    ) {}

    public static function current(): ?self
    {
        $data = Cache::get(self::KEY);

        if (! is_array($data)) {
            return null;
        }

        return new self(
            $data['id'],
            $data['user_id'],
            RemoteSignInStatus::from($data['status']),
            CarbonImmutable::parse($data['started_at']),
            $data['secret'],
            $data['port'],
            $data['message'],
        );
    }

    /**
     * Starts a run unless one is active — under a lock, so two admins pressing
     * the button at once cannot both launch a browser on the same profile.
     * Answers null when one is already running.
     */
    public static function begin(?int $userId): ?self
    {
        return Cache::lock(self::KEY . ':begin', 10)->block(5, function () use ($userId) {
            if (self::current()?->status->active()) {
                return null;
            }

            return (new self(
                (string) Str::uuid(),
                $userId,
                RemoteSignInStatus::Starting,
                CarbonImmutable::now(),
                Str::random(48),
            ))->save();
        });
    }

    public function with(RemoteSignInStatus $status, ?int $port = null, ?string $message = null): self
    {
        return (new self($this->id, $this->userId, $status, $this->startedAt, $this->secret, $port ?? $this->port, $message))->save();
    }

    /** The bridge to this run's browser, once it has one. */
    public function browser(): ?RemoteBrowser
    {
        return $this->status === RemoteSignInStatus::Waiting && $this->port !== null
            ? new RemoteBrowser($this->port, $this->secret)
            : null;
    }

    /** Whether this run is still the current one — a cancelled or replaced run is not. */
    public function stillCurrent(): bool
    {
        $now = self::current();

        return $now !== null && $now->id === $this->id && $now->status === $this->status;
    }

    private function save(): self
    {
        Cache::put(self::KEY, [
            'id'         => $this->id,
            'user_id'    => $this->userId,
            'status'     => $this->status->value,
            'started_at' => $this->startedAt->toIso8601String(),
            'secret'     => $this->secret,
            'port'       => $this->port,
            'message'    => $this->message,
        ], self::TTL_SECONDS);

        return $this;
    }
}

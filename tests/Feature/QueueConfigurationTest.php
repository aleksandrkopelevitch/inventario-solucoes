<?php

use Illuminate\Contracts\Queue\ShouldQueue;
use Symfony\Component\Finder\Finder;

/*
|--------------------------------------------------------------------------
| Supervisor conf vs. the PHP that feeds it
|--------------------------------------------------------------------------
|
| deploy/supervisor/isol-queue.conf and app/Jobs/ are written in different
| languages, and nothing in the stack compares them. A job dispatched to a
| queue no program drains does not error — it simply sits in redis forever,
| and the UI polls until it gives up. A `stopwaitsecs` below a job's $timeout
| does not error either — the worker is SIGKILLed mid-job on the next deploy,
| so no failed() runs, no failed_jobs row appears, and the record stays
| `pending` until retry_after hands it to somebody else.
|
| Ported from the akop-pro project, where a stopwaitsecs of 130 against a
| 220-second job killed one generation on every deploy that landed mid-flight.
|
*/

/** @return array<string, array{command: string, stopwaitsecs: int|null}> */
function supervisorPrograms(): array
{
    $conf = file_get_contents(base_path('deploy/supervisor/isol-queue.conf'));

    // Strip `;` comments first: they legitimately contain the words and numbers
    // this parser looks for, and a commented-out program must not count as one.
    $conf = preg_replace('/^\s*;.*$/m', '', $conf);

    $programs = [];

    foreach (preg_split('/^\[/m', $conf) as $chunk) {
        if (! preg_match('/^program:([^\]]+)\]/', $chunk, $name)) {
            continue;
        }

        preg_match('/^command=(.*)$/m', $chunk, $command);
        preg_match('/^stopwaitsecs=(\d+)$/m', $chunk, $wait);

        $programs[$name[1]] = [
            'command'      => trim($command[1] ?? ''),
            'stopwaitsecs' => isset($wait[1]) ? (int) $wait[1] : null,
        ];
    }

    return $programs;
}

/** Queue names a program's `queue:work --queue=a,b` actually drains. */
function drainedQueues(string $command): array
{
    if (! preg_match('/--queue=([^\s]+)/', $command, $m)) {
        return ['default'];
    }

    return explode(',', $m[1]);
}

/** @return array<class-string, array{timeout: int|null, queue: string}> */
function queueableJobs(): array
{
    $jobs = [];

    foreach (Finder::create()->files()->in(app_path('Jobs'))->name('*.php') as $file) {
        $class = 'App\\Jobs\\' . $file->getFilenameWithoutExtension();

        if (! class_exists($class) || ! is_subclass_of($class, ShouldQueue::class)) {
            continue;
        }

        $defaults = (new ReflectionClass($class))->getDefaultProperties();

        $jobs[$class] = [
            'timeout' => $defaults['timeout'] ?? null,
            'queue'   => $defaults['queue'] ?? 'default',
        ];
    }

    return $jobs;
}

it('finds the supervisor conf and the jobs it is supposed to serve', function () {
    expect(supervisorPrograms())->not->toBeEmpty()
        ->and(queueableJobs())->not->toBeEmpty();
});

// Each of these collects the offenders and asserts the list is empty, so a
// failure names what is wrong instead of just reporting a false boolean.

it('drains every queue a job dispatches to', function () {
    $drained = collect(supervisorPrograms())
        ->flatMap(fn (array $program) => drainedQueues($program['command']))
        ->unique();

    $orphaned = collect(queueableJobs())
        ->reject(fn (array $job) => $drained->contains($job['queue']))
        ->map(fn (array $job, string $class) => "{$class} dispatches to \"{$job['queue']}\", which no program drains")
        ->values()
        ->all();

    expect($orphaned)->toBe([]);
});

it('waits longer than the slowest job before SIGKILL on every program', function () {
    $slowest = collect(queueableJobs())->pluck('timeout')->filter()->max();

    expect($slowest)->toBeGreaterThan(0);

    $tooImpatient = collect(supervisorPrograms())
        ->filter(fn (array $program) => $program['stopwaitsecs'] === null || $program['stopwaitsecs'] <= $slowest)
        ->map(fn (array $program, string $name) => "{$name}: stopwaitsecs=" . ($program['stopwaitsecs'] ?? 'unset') . " against a {$slowest}s job — a deploy landing mid-job SIGKILLs it")
        ->values()
        ->all();

    expect($tooImpatient)->toBe([]);
});

// The rule AGENTS.md § Queue & Jobs states, checked against both connections
// this app can run on. This guards the DEFAULTS in config/queue.php; an
// environment can still override the key (production set
// REDIS_QUEUE_RETRY_AFTER=300 once, which is exactly this bug), so it is a
// floor under the repo, not a guarantee about any given server.
it('gives every queue connection a retry_after above the slowest job', function () {
    $slowest = collect(queueableJobs())->pluck('timeout')->filter()->max();

    $tooShort = collect(['database', 'redis'])
        ->mapWithKeys(fn (string $c) => [$c => (int) config("queue.connections.{$c}.retry_after")])
        ->filter(fn (int $retryAfter) => $retryAfter <= $slowest)
        ->map(fn (int $retryAfter, string $c) => "queue.connections.{$c}.retry_after={$retryAfter} against a {$slowest}s job — it gets handed to a second worker mid-run")
        ->values()
        ->all();

    expect($tooShort)->toBe([]);
});

// A worker's own --timeout must sit under retry_after, or the queue re-serves a
// job the worker still considers live.
it('keeps each worker --timeout under the connection retry_after', function () {
    $retryAfter = (int) config('queue.connections.redis.retry_after');

    $overrun = collect(supervisorPrograms())
        ->filter(fn (array $program) => preg_match('/--timeout=(\d+)/', $program['command'], $m) && (int) $m[1] >= $retryAfter)
        ->map(fn (array $program, string $name) => "{$name}: --timeout at or above retry_after ({$retryAfter}s)")
        ->values()
        ->all();

    expect($overrun)->toBe([]);
});

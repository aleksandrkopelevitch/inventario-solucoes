<?php

namespace App\Support\Archify;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessException;
use Symfony\Component\Process\Process;

/**
 * The Archify CLI, as a sidecar.
 *
 * Same split `RenderSubmissionDeck` keeps with python-pptx: whatever decides
 * CONTENT produces JSON, the JSON is validated, and only then does something
 * else write the file. Here the validator is Archify's own — it knows its five
 * schemas and its geometry rules, and re-implementing a quarter of that in PHP
 * would be a second opinion that drifts.
 *
 * Vendored under `scripts/archify/` (MIT, tt-a1i/archify), pinned rather than
 * installed: it is a single-maintainer project on a `-dev` version, and a
 * renderer whose output we store is not something to let float. Only the
 * runtime is vendored — its own tests, examples and authoring docs are not
 * part of the copy.
 *
 * `ARCHIFY_UPDATE_CHECK_DISABLED=1` on every call. The packaged skill otherwise
 * makes an HTTP GET to show an update notice, which is reasonable for somebody
 * running it in a terminal and not for a web request: it would put a third
 * party in the latency path of a page that is only rendering a picture.
 */
class ArchifyRunner
{
    /**
     * Checks a spec without writing anything. `--quality` is passed on every
     * call because the profile changes which checks RUN, not merely how the
     * result is labelled — validating at one profile and delivering at another
     * is how a spec passes and then fails.
     */
    public function validate(string $type, string $specPath): ArchifyResult
    {
        return $this->run(['validate', $type, $specPath, '--quality', $this->quality(), '--json']);
    }

    /**
     * Renders the artifact. `deliver` rather than `render`: it is the command
     * that runs the artifact checks after writing and reports a non-zero exit
     * when they fail, so a broken file can never be quietly stored as a good
     * one.
     */
    public function deliver(string $type, string $specPath, string $outPath): ArchifyResult
    {
        return $this->run(['deliver', $type, $specPath, $outPath, '--quality', $this->quality(), '--json']);
    }

    /**
     * Before / Delta / After for two architecture specs, plus the machine
     * receipt that says what changed.
     */
    public function compare(string $basePath, string $headPath, string $outPath, string $receiptPath): ArchifyResult
    {
        return $this->run([
            'compare', 'architecture', $basePath, $headPath, $outPath,
            '--receipt', $receiptPath,
            '--quality', $this->quality(),
            '--json',
        ]);
    }

    /** Whether the sidecar can run at all — Node present, bin in place. */
    public function available(): bool
    {
        return is_file((string) config('services.archify.bin')) && $this->run(['--help'])->ok;
    }

    /**
     * @param  list<string>  $arguments
     */
    private function run(array $arguments): ArchifyResult
    {
        $process = new Process(
            [(string) config('services.archify.node'), (string) config('services.archify.bin'), ...$arguments],
            base_path(),
            ['ARCHIFY_UPDATE_CHECK_DISABLED' => '1'],
        );

        $process->setTimeout((float) config('services.archify.timeout'));

        try {
            $process->run();
        } catch (ProcessException $e) {
            // Symfony THROWS rather than returning a failing exit code for the
            // two ways a process can fail to produce a verdict: it could not be
            // launched (`ProcessStartFailedException` — no Node on the box, or
            // not on the web user's PATH) and it was killed on timeout. Both
            // reached the browser as a 500 before this, which is the one answer
            // this class must never give: `available()` exists to ANSWER the
            // question "can the sidecar run", so it cannot be allowed to
            // explode while doing it.
            Log::error('Archify: CLI could not run', [
                'arguments' => $arguments,
                'reason'    => $e->getMessage(),
            ]);

            return ArchifyResult::couldNotRun($e->getMessage());
        }

        $stdout = trim($process->getOutput());

        // `--help` and a few other paths answer in plain text; everything this
        // class actually depends on passes `--json`. A non-JSON answer to a
        // JSON request is a failure, and a loud one — it usually means Node
        // itself refused (missing binary, unsupported version) and the message
        // is on stderr.
        $payload = $stdout !== '' ? json_decode($stdout, true) : null;

        if (! is_array($payload)) {
            if (! $process->isSuccessful()) {
                Log::error('Archify: CLI call failed', [
                    'arguments' => $arguments,
                    'exit'      => $process->getExitCode(),
                    'stderr'    => mb_substr($process->getErrorOutput(), 0, 2000),
                ]);
            }

            return new ArchifyResult(
                ok: $process->isSuccessful(),
                problems: $process->isSuccessful() ? [] : [trim($process->getErrorOutput()) ?: 'archify: exit ' . $process->getExitCode()],
                stderr: $process->getErrorOutput(),
                // A FAILING exit with no JSON produced no verdict either, and
                // that is the shape a missing binary actually takes: `proc_open`
                // succeeds and the child exits 127, so nothing is thrown and the
                // only evidence is on stderr. Reporting that as "your spec is
                // invalid" sent an operator looking at the diagram.
                ran: $process->isSuccessful(),
            );
        }

        return ArchifyResult::fromPayload($payload, $process->getErrorOutput());
    }

    private function quality(): string
    {
        return (string) config('services.archify.quality');
    }
}

<?php

namespace App\Support\Digibee\Monitor\Mcp;

use App\Console\Commands\DigibeeLogsCommand;
use App\Mcp\InvalidToolArguments;
use App\Support\Digibee\Monitor\LogQuery;

/** Reports as 0600 files under the workstation's private storage, as `--collect` writes them. */
final class FileReportStore implements ReportStore
{
    public function save(string $redactedJson, LogQuery $query, array $summary): string
    {
        $name = $this->name($query->pipeline, $query->term);
        $path = DigibeeLogsCommand::reportPath($name);
        file_put_contents($path, $redactedJson);
        chmod($path, 0600);

        return $name;
    }

    /**
     * By its bare file name, from the reports directory only — the name comes
     * from a chat, and a path in it would read any file this user can.
     */
    public function load(string $name): array
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*\.json$/', $name) !== 1 || str_contains($name, '..')) {
            throw new InvalidToolArguments('report deve ser o nome devolvido por search_digibee_logs (ex.: mcp-20261104-101500-evt-send-estoque-vtex.json).');
        }

        $path = DigibeeLogsCommand::reportPath($name);

        if (! is_file($path)) {
            throw new \InvalidArgumentException("Relatório \"{$name}\" não encontrado nesta máquina.");
        }

        return (array) json_decode((string) file_get_contents($path), true);
    }

    public function where(): string
    {
        return 'nesta máquina';
    }

    /**
     * Named after the pipeline and a HASH of the term: the term may be a CPF,
     * and a file name is read by more things than its contents are.
     */
    private function name(?string $pipeline, ?string $term): string
    {
        $slug = preg_replace('/[^a-z0-9-]+/', '-', strtolower($pipeline ?? 'todas')) ?: 'todas';

        return 'mcp-' . now()->format('Ymd-His') . '-' . trim(substr($slug, 0, 40), '-')
            . ($term !== null ? '-' . substr(sha1($term), 0, 8) : '') . '.json';
    }
}

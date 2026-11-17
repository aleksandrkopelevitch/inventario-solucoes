<?php

namespace App\Mcp\Tools\Write;

use App\Actions\Catalog\SaveSolution;
use App\Mcp\Actor;
use App\Mcp\Support\ImageArgument;
use App\Mcp\ToolResult;
use App\Mcp\Tools\GetSolution;
use App\Models\Solution;
use App\Support\Catalog\SolutionRules;

class CreateSolution extends SolutionWriteTool implements CreatesRecords
{
    public function __construct(GetSolution $reader, private readonly SaveSolution $save)
    {
        parent::__construct($reader);
    }

    public function name(): string
    {
        return 'create_solution';
    }

    public function title(): string
    {
        return 'Cadastrar uma solução';
    }

    public function description(): string
    {
        return <<<'TXT'
        Cadastra uma solução nova no catálogo, em nome da pessoa conectada.

        Procure antes com search_solutions: se já existir uma com o mesmo nome, a
        chamada é recusada e aponta a existente. Confirme com a pessoa antes de criar.
        Responde com o cadastro salvo, no formato de get_solution.
        TXT;
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                ...$this->fieldSchema(),
                'slug'            => ['type' => 'string', 'description' => 'Opcional. Gerado a partir do nome quando omitido.'],
                'allow_duplicate' => ['type' => 'boolean', 'description' => 'Só com confirmação da pessoa: cria mesmo que já exista uma solução com o mesmo nome.'],
            ],
            'required' => ['name', 'category', 'status'],
        ];
    }

    protected function write(array $arguments, Actor $actor): ToolResult
    {
        if (! $actor->user?->can('create', Solution::class)) {
            return $this->forbidden('cadastrar soluções');
        }

        ['data' => $data, 'errors' => $errors] = $this->columns($arguments, $this->spec());
        $image = ImageArgument::read($arguments, 'logo', $this->logoRule());

        if ($image->error) {
            $errors[] = $image->error;
        }

        if ($errors !== []) {
            return $this->refused($errors);
        }

        if (filled($arguments['slug'] ?? null) && is_scalar($arguments['slug'])) {
            $data['slug'] = trim((string) $arguments['slug']);
        }

        if ($invalid = $this->invalid($data, $this->rules(), $this->names())) {
            return $invalid;
        }

        return $this->createOnce(
            $actor,
            Solution::class,
            $data['name'],
            $data,
            $this->names(),
            (bool) ($arguments['allow_duplicate'] ?? false),
            fn ()                                  => $this->save->handle($data + ($image->file ? ['logo_path' => $image->store('solution-logos')] : [])),
            fn (Solution $solution, ?string $note) => $this->answer($solution, $actor, $this->imageNote($note, $image, 'logo', 'update_solution') ?? 'Solução cadastrada.'),
        );
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return array_diff_key(SolutionRules::for(), array_flip(SolutionRules::UPLOAD_KEYS));
    }
}

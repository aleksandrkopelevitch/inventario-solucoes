<?php

namespace App\Enums;

use App\Support\Documentation\BeforeAfterScene;
use App\Support\Documentation\StepScene;
use App\Support\Documentation\TreeScene;

/**
 * The kinds of animated SCENE a documentation page can carry — the `type` of a
 * `{% scene %}` block, and the one switch the whole pipeline turns on: which
 * prompt the model gets, which IR validates the answer, which form the editor
 * shows and which figure docs-scene.js draws.
 *
 * A new kind is a new case here plus its IR, its prompt section, its form and
 * its renderer — never a new endpoint (`notebooks.pages.scene` takes the type).
 * Each IR stays a separate class because the contracts genuinely differ: a
 * sequence of steps and a set of before/after pairs fail in different ways,
 * and a validator that tried to serve both would explain neither.
 */
enum SceneType: string
{
    case Steps = 'steps';
    case BeforeAfter = 'before-after';
    case Tree = 'tree';

    public function label(): string
    {
        return match ($this) {
            self::Steps       => 'Fluxo em etapas',
            self::BeforeAfter => 'Antes → depois',
            self::Tree        => 'Árvore',
        };
    }

    /**
     * How a failure message names what was being made — "não dá para montar
     * {noun} a partir desta página" — so the sentence reads as PT-BR.
     */
    public function noun(): string
    {
        return match ($this) {
            self::Steps       => 'um fluxo em etapas',
            self::BeforeAfter => 'um antes e depois',
            self::Tree        => 'uma árvore',
        };
    }

    /** "{proposal} não passou na validação". */
    public function proposal(): string
    {
        return match ($this) {
            self::Steps       => 'O fluxo proposto',
            self::BeforeAfter => 'A comparação proposta',
            self::Tree        => 'A árvore proposta',
        };
    }

    /**
     * The key a real answer from the model carries — what tells it apart from
     * `{"error": …}`. Not always the stored list: a tree is answered nested
     * (`root`) and stored flat (`nodes`), see TreeScene.
     */
    public function answerKey(): string
    {
        return match ($this) {
            self::Steps       => 'steps',
            self::BeforeAfter => 'changes',
            self::Tree        => 'root',
        };
    }

    /**
     * @param  array<mixed>  $payload
     * @return list<string>
     */
    public function validate(array $payload): array
    {
        return match ($this) {
            self::Steps       => StepScene::validate($payload),
            self::BeforeAfter => BeforeAfterScene::validate($payload),
            self::Tree        => TreeScene::validate($payload),
        };
    }

    /**
     * The validated payload in its stored shape (defaults applied, text
     * trimmed), as the editor receives it.
     *
     * @param  array<mixed>  $payload  a payload `validate()` accepted
     * @return array<string, mixed>
     */
    public function normalize(array $payload): array
    {
        return match ($this) {
            self::Steps       => StepScene::fromArray($payload)->toArray(),
            self::BeforeAfter => BeforeAfterScene::fromArray($payload)->toArray(),
            self::Tree        => TreeScene::fromArray($payload)->toArray(),
        };
    }
}

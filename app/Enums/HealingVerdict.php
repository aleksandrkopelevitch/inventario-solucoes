<?php

namespace App\Enums;

/**
 * How a healing run ENDED — and the whole point of the enum is that most of
 * these are not failures of the flowSpec.
 *
 * The loop rewrites a pipeline only when it holds evidence that the pipeline
 * is what is wrong. Every other way a round can end has its own case here,
 * because collapsing them is how a correction loop starts rewriting a document
 * over a missing test credential, a slow deploy, or a permission it was never
 * granted — burning the model's attempts on the one thing it cannot fix, and
 * leaving a realm full of deployments behind while it does.
 */
enum HealingVerdict: string
{
    /** The battery ran, nothing failed, and the happy path itself passed. */
    case Green = 'green';

    /**
     * Nothing failed, and nothing proved it works either — the happy path was
     * blocked (a placeholder nobody filled) so only negative cases ran.
     *
     * Deliberately NOT a reason to correct: there is no failure to hand the
     * model, and re-prompting here asks it to rewrite a pipeline that, as far
     * as anyone knows, is fine.
     */
    case Unproven = 'unproven';

    /** Real cases failed and the rounds ran out. This is the honest defeat. */
    case StillFailing = 'still-failing';

    /** The platform refused: environment, permission, or a configuration it would not match. */
    case Refused = 'refused';

    /** The deploy never settled inside the ceiling — neither up nor broken. */
    case Unsettled = 'unsettled';

    /** Every case answered 404: nothing is there, which says nothing about the flowSpec. */
    case NotAnswering = 'not-answering';

    /** Every case answered 401/403 with no credential given — the door, not the pipeline. */
    case RefusedAtTheDoor = 'refused-at-the-door';

    /** The document was refused by our own validation before any write. */
    case NotIngested = 'not-ingested';

    /**
     * The platform would not accept a write at all — the pipeline is no longer
     * a draft.
     *
     * Measured 2026-09-14, and it is the constraint that shapes this whole
     * block: **deploying PUBLISHES a pipeline**. `apla-boot-01` was created
     * `draft: true`, took two upserts while staying a draft, and came out of
     * the deploy with `draft: false` — after which the design API answers
     * `409 "You cannot update a pipeline that is not on draft mode"` to every
     * write. So a healing round can write, deploy and test, and the NEXT round
     * cannot write into what it just deployed.
     *
     * Explicitly not `NotIngested`: that one is re-promptable, because the
     * errors are about the document. This one is about the pipeline's state,
     * and no rewrite the model produces can get past it — spending an attempt
     * on it is the exact failure the verdict list exists to prevent.
     *
     * `POST /design/realms/{realm}/pipelines/{id}/draft` is a real route (it
     * answers the design API's own `403 INSUFFICIENT_PERMISSIONS`, unlike the
     * neighbouring paths that answer the gateway's generic 500), so the way
     * out is a token permission, not code.
     */
    case NotWritable = 'not-writable';

    /**
     * The model answered with the same document it was asked to fix — or with
     * no document at all.
     *
     * Stopping here is not pessimism: the next round would ingest the same
     * bytes, deploy them, and collect the same evidence, so it is a deploy
     * spent to learn nothing. In a realm where nothing deletes a pipeline and
     * this token cannot delete a deployment, a loop that cannot make progress
     * has to say so instead of spinning.
     */
    case Stuck = 'stuck';

    /** Whether the pipeline is known to work. Only one case is. */
    public function healed(): bool
    {
        return $this === self::Green;
    }

    /**
     * Whether the run ended holding evidence ABOUT THE FLOWSPEC.
     *
     * This is what separates "we tried and it is still wrong" from "we never
     * got to find out", and it is the question anyone reading the report
     * actually has.
     */
    public function judgedThePipeline(): bool
    {
        return in_array($this, [self::Green, self::Unproven, self::StillFailing], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Green            => 'verde: a bateria rodou e o caminho feliz passou',
            self::Unproven         => 'nada falhou, e nada mostrou que funciona',
            self::StillFailing     => 'ainda falhando depois de esgotar as rodadas',
            self::Refused          => 'a plataforma recusou',
            self::Unsettled        => 'o deploy não estabilizou no tempo limite',
            self::NotAnswering     => 'nada respondeu nessa URL',
            self::RefusedAtTheDoor => 'o endpoint recusou na porta, por credencial',
            self::NotIngested      => 'o documento não passou na validação',
            self::NotWritable      => 'a plataforma recusou a escrita: o pipeline não está em modo rascunho',
            self::Stuck            => 'o modelo devolveu o mesmo documento',
        };
    }
}

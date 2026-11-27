<?php

namespace App\Actions\Mcp;

use App\Actions\DeleteSolution;
use App\Mcp\Support\RecordFields;
use App\Models\Company;
use App\Models\DocumentationChat;
use App\Models\DocumentationPage;
use App\Models\McpWrite;
use App\Models\Notebook;
use App\Models\Person;
use App\Models\Solution;
use App\Models\SolutionRelationship;
use App\Models\Submission;
use App\Models\UmlSolutionLink;
use App\Models\User;
use App\Services\DocumentationPageService;
use App\Support\Catalog\CompanyRules;
use App\Support\Catalog\PersonRules;
use App\Support\Catalog\SolutionRules;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Undoes one change an MCP write tool made — or says why it will not.
 *
 * **A row reverts only if nothing changed since.** The record's current values
 * must equal what the write left (`after`); otherwise the revert is SKIPPED and
 * reported, because putting `before` back blindly would undo somebody's later
 * edit — the same lost update the page `version` exists to prevent.
 *
 * **What is put back is validated like any other save.** A value that was
 * legal then may not be now: the vendor company it pointed at was deleted, an
 * attribute value was retired from the vocabulary. Writing it anyway is a
 * foreign-key error (a 500) or a record the screen cannot display, so the row
 * is skipped with the rule's own message instead.
 *
 * **Reverting a create deletes the record**, the one delete this feature can
 * cause, and only while the record is untouched since and bare — no link, no
 * contact, no page, nothing that points at it. What the create made cannot be
 * told apart from what came later cheaply; an admin who still wants the record
 * gone deletes it on its own screen, where the consequences are spelled out.
 *
 * @phpstan-type Outcome array{reverted: bool, reason: ?string}
 */
class RevertMcpWrite
{
    public function __construct(private readonly DeleteSolution $deleteSolution) {}

    /** @return Outcome */
    public function handle(McpWrite $write, User $admin): array
    {
        if ($write->isReverted()) {
            return self::skipped('já tinha sido revertida.');
        }

        $subject = $write->subject;

        if (! $subject instanceof Model) {
            return self::skipped('o registro não existe mais.');
        }

        if (! RecordFields::same(RecordFields::snapshot($subject, array_keys($write->after)), $write->after)) {
            return self::skipped('o registro foi alterado depois desta mudança' . $this->changedBy($subject) . '.');
        }

        if ($write->isCreate()) {
            $blocker = $this->createBlocker($subject, $write);

            if ($blocker !== null) {
                return self::skipped($blocker . '; exclua-o pela tela dele, se ainda for o caso.');
            }
        } elseif (($invalid = $this->invalidRestore($subject, $write->before)) !== null) {
            return self::skipped('o valor anterior não é mais válido (' . $invalid . ').');
        }

        DB::transaction(function () use ($write, $subject, $admin) {
            if ($write->isCreate()) {
                $subject instanceof Solution ? $this->deleteSolution->handle($subject) : $subject->delete();
            } else {
                RecordFields::restore($subject, $write->before);
            }

            $write->forceFill(['reverted_at' => now(), 'reverted_by_id' => $admin->getKey()])->save();
        });

        return ['reverted' => true, 'reason' => null];
    }

    /**
     * Why a created record may not be deleted, in words, or null when it is
     * untouched and bare.
     *
     * "Untouched" is `updated_at`, which every save moves — the description
     * somebody added in the app, a logo, a rename. "Bare" is every relation a
     * record of that kind can gather, including the ones the catalog screens
     * never show (a committee submission about the solution, a topology
     * approved for it).
     */
    private function createBlocker(Model $subject, McpWrite $write): ?string
    {
        if ($subject->updated_at && $subject->updated_at->gt($write->created_at->copy()->addSeconds(2))) {
            return 'o registro foi editado depois de criado' . $this->changedBy($subject);
        }

        $found = array_values(array_filter(match (true) {
            $subject instanceof Solution => [
                $subject->people()->exists() ? 'pessoas vinculadas' : null,
                $subject->notebooks()->exists() ? 'cadernos' : null,
                SolutionRelationship::query()->involving($subject)->exists() ? 'interconexões cadastradas' : null,
                UmlSolutionLink::query()->where(fn ($q) => $q->where('from_solution_id', $subject->getKey())->orWhere('to_solution_id', $subject->getKey()))->exists() ? 'relacionamentos documentados' : null,
                Submission::query()->where('solution_id', $subject->getKey())->exists() ? 'submissões ao comitê' : null,
                $subject->logo_path ? 'um logo' : null,
            ],
            $subject instanceof Person => [
                $subject->solutions()->exists() ? 'soluções vinculadas' : null,
                $subject->contacts()->exists() ? 'contatos' : null,
                $subject->user_id !== null ? 'uma conta de acesso' : null,
                Submission::query()->where('requester_person_id', $subject->getKey())->exists() ? 'submissões ao comitê' : null,
                $subject->photo_path ? 'uma foto' : null,
            ],
            $subject instanceof Company => [
                $subject->people()->exists() ? 'pessoas' : null,
                $subject->providedSolutions()->exists() ? 'soluções fornecidas' : null,
                $subject->logo_path ? 'um logo' : null,
            ],
            $subject instanceof Notebook => [
                $subject->pages()->exists() ? 'páginas' : null,
                $subject->isPublished() ? 'publicação na base' : null,
                // Neither moves the caderno's `updated_at`, so only asking
                // for them keeps a revert from deleting them along.
                $subject->getMedia(Notebook::CONTEXT_COLLECTION)->isNotEmpty() ? 'documentos de contexto do Assiste IA' : null,
                DocumentationChat::query()->where('notebook_id', $subject->getKey())->exists() ? 'conversas do Assiste IA' : null,
            ],
            $subject instanceof DocumentationPage => [
                $subject->children()->exists() ? 'subpáginas' : null,
                $subject->getMedia(DocumentationPage::DOCS_COLLECTION)->isNotEmpty() ? 'arquivos anexados' : null,
            ],
            default => [],
        }));

        return $found === [] ? null : 'o registro já tem ' . implode(', ', $found);
    }

    /**
     * The validation message putting `$before` back would fail, or null. Only
     * the catalog's columns have rules of their own; a relation field is a set
     * of slugs, re-synced with whatever of it still exists.
     *
     * @param  array<string, mixed>  $before
     */
    private function invalidRestore(Model $subject, array $before): ?string
    {
        // A placement is a spot in a tree that may have changed shape since:
        // the old parent may now sit UNDER the page (a cycle that detaches
        // both subtrees from the caderno) or the page's subtree may have grown
        // past the depth cap. Same checks `place_page` makes.
        if ($subject instanceof DocumentationPage && isset($before['placement'])) {
            $parentSlug = $before['placement']['parent'] ?? null;
            $parent = $parentSlug === null ? null : $subject->notebook()->first()?->pages()->where('slug', $parentSlug)->first();

            if ($parentSlug !== null && ! $parent) {
                return 'a página onde ela estava não existe mais';
            }

            return app(DocumentationPageService::class)->placementFailure($subject, $parent);
        }

        $rules = match (true) {
            $subject instanceof Solution => array_diff_key(SolutionRules::for($subject), array_flip(SolutionRules::UPLOAD_KEYS)),
            $subject instanceof Person   => array_diff_key(PersonRules::for($subject), array_flip(PersonRules::FORM_ONLY_KEYS)),
            $subject instanceof Company  => array_diff_key(CompanyRules::for($subject), array_flip(CompanyRules::UPLOAD_KEYS)),
            default                      => null,
        };

        if ($rules === null) {
            return null;
        }

        $data = $before + $subject->getAttributes();
        $validator = Validator::make(
            $data,
            array_intersect_key($rules, $data),
        );

        return $validator->fails() ? $validator->errors()->first() : null;
    }

    /** ", por Fulano" when the record says who saved it last — only pages do. */
    private function changedBy(Model $subject): string
    {
        if (! $subject instanceof DocumentationPage) {
            return '';
        }

        $name = $subject->updater()->value('name');

        return $name ? ", por {$name}" : '';
    }

    /** @return Outcome */
    private static function skipped(string $reason): array
    {
        return ['reverted' => false, 'reason' => $reason];
    }
}

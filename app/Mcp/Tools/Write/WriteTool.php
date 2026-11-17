<?php

namespace App\Mcp\Tools\Write;

use App\Enums\AttributeGroup;
use App\Mcp\Actor;
use App\Mcp\Support\ImageArgument;
use App\Mcp\Support\RecordFields;
use App\Mcp\Support\Vocabulary;
use App\Mcp\ToolResult;
use App\Mcp\Writes;
use App\Models\Company;
use App\Models\McpWrite;
use App\Support\Fold;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;

/**
 * What every MCP write tool shares: the rate limit, the transaction, the
 * argument-to-column mapping, validation through the app's own rules, the
 * write log, and the retry-safe create.
 *
 * Read docs-and-specs/mcp-write-tools.md before changing any of it; the short
 * version of the rules this class holds:
 *
 * - **`null` means "not sent".** Models send `null` for "I am not setting
 *   this" all the time (`Mcp\Support\Arguments` says so), so a patch only
 *   changes what it names with a value, and a field is emptied only when the
 *   call lists it in `clear`.
 * - **A reference is a slug, an attribute is a value or its label**, and one
 *   that matches nothing is REFUSED, never dropped — a dropped argument is a
 *   write the model believes happened.
 * - **A validation failure is a tool result**, listing every field's message:
 *   the model can fix it, so it has to arrive where the model reads.
 * - **Every change is logged** (`McpWrite`) with only the fields it changed,
 *   and a call that changes nothing logs nothing.
 */
abstract class WriteTool implements Writes
{
    /** Writes per minute per person. Etiquette for a shared droplet, not safety — the log is the safety. */
    public const PER_MINUTE = 20;

    /** How long a create retried after a timeout is recognised as the same create. */
    public const RETRY_WINDOW_MINUTES = 15;

    /** @param  array<string, mixed>  $arguments */
    abstract protected function write(array $arguments, Actor $actor): ToolResult;

    public function handle(array $arguments, Actor $actor): ToolResult
    {
        if (! RateLimiter::attempt('mcp-write:' . $actor->rateLimitKey(), self::PER_MINUTE, fn () => true)) {
            return ToolResult::failure('Muitas alterações em pouco tempo. Espere um minuto antes de continuar.');
        }

        // A create holds a lock on the name it is about to create, taken
        // BEFORE the transaction and released after it commits. Inside it, on
        // the `database` cache store production uses, the lock row would be
        // written in the open transaction: a competing call would block on it,
        // then fail its own insert, and on Postgres a failed statement poisons
        // the whole transaction into a 500.
        $key = $this->lockKey($arguments);
        $lock = $key === null ? null : Cache::lock('mcp-write:' . $key, 15);

        try {
            $lock?->block(5);
        } catch (LockTimeoutException) {
            return ToolResult::failure('Outra chamada está gravando um registro com esse mesmo nome agora. Espere alguns segundos e tente de novo.');
        }

        try {
            return DB::transaction(fn () => $this->write($arguments, $actor));
        } finally {
            $lock?->release();
        }
    }

    /**
     * What two simultaneous calls must not do at once — the folded NAME a
     * create is about to use — or null for a tool that needs no lock.
     *
     * @param  array<string, mixed>  $arguments
     */
    protected function lockKey(array $arguments): ?string
    {
        $name = $arguments['name'] ?? null;

        return $this instanceof CreatesRecords && is_scalar($name) && trim((string) $name) !== ''
            ? static::class . ':' . md5(Fold::text((string) $name))
            : null;
    }

    /**
     * Columns `clear` may not empty: NOT NULL columns the save fills with a
     * default when they are missing, so "cleared" would silently mean
     * "unchanged" (`SaveSolution`). Refused, with the reason.
     *
     * @return list<string> argument names
     */
    protected function unclearable(): array
    {
        return [];
    }

    public function idempotent(): bool
    {
        return false;
    }

    public function destructive(): bool
    {
        return false;
    }

    /* ------------------------------------------------------------------ */
    /*  Arguments → columns */
    /* ------------------------------------------------------------------ */

    /**
     * Maps the arguments a call SENT to column values.
     *
     * `$spec` is `argument => [column, kind, extra]`, with kind one of:
     * `text` (taken as is), `attribute` (extra: the AttributeGroup), `enum`
     * (extra: the enum class) and `company` (a company slug → its id).
     *
     * An argument that is absent or `null` is not in the answer at all; an
     * argument listed in `clear` is answered as `null`. Everything else that
     * cannot be resolved is an error, in the model's own terms.
     *
     * @param  array<string, mixed>  $arguments
     * @param  array<string, array{0: string, 1: string, 2?: mixed}>  $spec
     * @return array{data: array<string, mixed>, errors: list<string>}
     */
    protected function columns(array $arguments, array $spec, bool $allowClear = false): array
    {
        $data = [];
        $errors = [];

        foreach ($spec as $argument => [$column, $kind]) {
            $raw = $arguments[$argument] ?? null;

            if ($raw === null || (is_string($raw) && trim($raw) === '')) {
                continue;
            }

            if (! is_scalar($raw)) {
                $errors[] = "{$argument}: envie um texto.";

                continue;
            }

            $value = trim((string) $raw);
            $extra = $spec[$argument][2] ?? null;

            $resolved = match ($kind) {
                'text'      => $value,
                'attribute' => Vocabulary::resolve($extra, $value),
                'enum'      => Vocabulary::enumValue($extra, $value),
                'company'   => Company::query()->where('slug', $value)->value('id'),
            };

            if ($resolved === null) {
                $errors[] = match ($kind) {
                    'attribute' => "{$argument}: \"{$value}\" não é um valor conhecido. Valores: " . Vocabulary::legend($extra) . '.',
                    'enum'      => "{$argument}: \"{$value}\" não é um valor conhecido. Valores: " . Vocabulary::enumLegend($extra) . '.',
                    'company'   => "{$argument}: nenhuma empresa com o slug \"{$value}\". Use search_companies.",
                    default     => "{$argument}: valor inválido.",
                };

                continue;
            }

            $data[$column] = $resolved;
        }

        if (! $allowClear) {
            return ['data' => $data, 'errors' => $errors];
        }

        $clear = $arguments['clear'] ?? [];

        foreach (is_array($clear) ? $clear : [$clear] as $argument) {
            if (! is_string($argument) || ! isset($spec[$argument])) {
                $errors[] = 'clear: "' . (is_scalar($argument) ? $argument : '?') . '" não é um campo que esta ferramenta altera. Campos: ' . implode(', ', array_keys($spec)) . '.';

                continue;
            }

            if (in_array($argument, $this->unclearable(), true)) {
                $errors[] = "clear: \"{$argument}\" não pode ficar vazio. Envie o novo valor em vez de esvaziá-lo.";

                continue;
            }

            if (array_key_exists($spec[$argument][0], $data)) {
                $errors[] = "clear: \"{$argument}\" também foi enviado com um valor. Envie um ou outro.";

                continue;
            }

            $data[$spec[$argument][0]] = null;
        }

        return ['data' => $data, 'errors' => $errors];
    }

    /**
     * The whole record, validated with the app's own rules. Answers the
     * failure to return, or null when it passed.
     *
     * @param  array<string, mixed>  $data  every column the record will hold
     * @param  array<string, mixed>  $rules
     * @param  array<string, string>  $names  column => argument name, so a message names what the model sent
     */
    protected function invalid(array $data, array $rules, array $names): ?ToolResult
    {
        $validator = Validator::make($data, $rules, [], $names);

        if ($validator->passes()) {
            return null;
        }

        $lines = collect($validator->errors()->messages())
            ->map(fn (array $messages, string $column) => '- ' . ($names[$column] ?? $column) . ': ' . implode(' ', $messages))
            ->implode("\n");

        return ToolResult::failure("Nada foi salvo. Corrija e tente de novo:\n{$lines}");
    }

    /** @param  list<string>  $errors */
    protected function refused(array $errors): ToolResult
    {
        return ToolResult::failure("Nada foi salvo. Corrija e tente de novo:\n- " . implode("\n- ", $errors));
    }

    protected function forbidden(string $what): ToolResult
    {
        return ToolResult::failure("A conta conectada não tem permissão para {$what}.");
    }

    /* ------------------------------------------------------------------ */
    /*  The write log */
    /* ------------------------------------------------------------------ */

    /**
     * Records one change. Writes nothing when nothing changed, and returns
     * whether something did.
     *
     * @param  array<string, mixed>|null  $before  null for a create
     * @param  array<string, mixed>  $after
     */
    protected function log(Actor $actor, Model $subject, ?array $before, array $after): bool
    {
        if ($before !== null) {
            $changed = RecordFields::changed($before, $after);

            if ($changed === []) {
                return false;
            }

            $before = array_intersect_key($before, array_flip($changed));
            $after = array_intersect_key($after, array_flip($changed));
        }

        $write = McpWrite::create([
            'user_id'         => $actor->user?->getKey(),
            'oauth_client_id' => $actor->clientId,
            'tool'            => $this->name(),
            'subject_type'    => $subject::class,
            'subject_id'      => $subject->getKey(),
            'before'          => $before,
            'after'           => $after,
        ]);

        Log::info('MCP write', [
            'tool'         => $this->name(),
            'user_id'      => $actor->user?->getKey(),
            'subject'      => $subject::class . ':' . $subject->getKey(),
            'mcp_write_id' => $write->getKey(),
        ]);

        return true;
    }

    /* ------------------------------------------------------------------ */
    /*  Pictures */
    /* ------------------------------------------------------------------ */

    /**
     * Stores or removes a record's picture (`ImageArgument`). Answers whether
     * it changed.
     *
     * Deliberately NOT a write-log row, so not revertible (see
     * `ImageArgument`); the server log still names it, so the change traces.
     */
    protected function applyImage(Actor $actor, Model $record, string $column, ImageArgument $image, string $directory): bool
    {
        if ($image->file) {
            $record->update([$column => $image->store($directory)]);
        } elseif ($image->remove && $record->getAttribute($column) !== null) {
            $record->update([$column => null]);
        } else {
            return false;
        }

        Log::info('MCP image write', [
            'tool'    => $this->name(),
            'user_id' => $actor->user?->getKey(),
            'subject' => $record::class . ':' . $record->getKey(),
            'column'  => $column,
            'value'   => $record->getAttribute($column),
        ]);

        return true;
    }

    /**
     * A create retried after a timeout answers with the FIRST record and
     * applies nothing (`createOnce()`), the picture included — say so, as the
     * note already does for the other fields.
     */
    protected function imageNote(?string $note, ImageArgument $image, string $argument, string $updateTool): ?string
    {
        return $note !== null && $image->sent()
            ? "{$note} O {$argument} desta chamada também não foi aplicado; use {$updateTool} para enviá-lo."
            : $note;
    }

    /** The JSON Schema fragment of a picture argument. */
    protected static function imageSchema(string $what): array
    {
        return [
            'type'        => 'string',
            'description' => "{$what}: a URL https:// da imagem (o servidor baixa; prefira esta forma) "
                . 'ou o conteúdo do arquivo em base64 / data URI — só para um arquivo pequeno que você consiga ler. '
                . 'JPG, PNG ou WebP, até ' . (ImageArgument::MAX_KILOBYTES / 1024) . ' MB; SVG não é aceito. '
                . 'A troca de imagem NÃO entra no registro de alterações e não pode ser revertida por lá: confirme com a pessoa.',
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Creating, retry-safe */
    /* ------------------------------------------------------------------ */

    /**
     * Creates a record unless one with the same name exists.
     *
     * Three outcomes, and the first is the one this exists for. An MCP client
     * retries a call that timed out, and the first call usually DID finish:
     * when THIS person created a record with this name through this tool in
     * the last minutes (looked up in their own write log, never by "the first
     * record with that name", which may be somebody else's), the retry answers
     * SUCCESS with that record — and names any field it sent that differs from
     * what was saved, so a model that "corrected" something before retrying
     * does not believe the correction landed. That holds with
     * `allow_duplicate` too: a confirmed duplicate retried after a timeout is
     * still ONE duplicate.
     *
     * Otherwise a record with the name refuses the create, naming it, unless
     * `allow_duplicate` (which the model passes only after asking the person).
     *
     * Simultaneous calls are kept apart by the lock `handle()` takes on the
     * name (`lockKey()`).
     *
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $model
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $names  column => argument name
     * @param  Closure(): TModel  $create
     * @param  Closure(TModel, ?string): ToolResult  $answer  the record and a note
     * @param  (Closure(Builder): mixed)|null  $scope  narrows "same name" (a page: same parent)
     */
    protected function createOnce(
        Actor $actor,
        string $model,
        string $name,
        array $data,
        array $names,
        bool $allowDuplicate,
        Closure $create,
        Closure $answer,
        ?Closure $scope = null,
        string $nameColumn = 'name',
    ): ToolResult {
        $folded = Fold::text($name);
        $sameName = fn (Model $record) => Fold::text((string) $record->getAttribute($nameColumn)) === $folded;

        $retried = McpWrite::query()
            ->where('user_id', $actor->user?->getKey())
            ->where('tool', $this->name())
            ->where('subject_type', $model)
            ->whereNull('before')
            ->whereNull('reverted_at')
            ->where('created_at', '>=', now()->subMinutes(self::RETRY_WINDOW_MINUTES))
            ->latest('id')
            ->get()
            ->map(fn (McpWrite $write) => $model::query()
                ->when($scope, fn ($query) => $scope($query))
                ->whereKey($write->subject_id)
                ->first())
            ->filter()
            ->first($sameName);

        if ($retried) {
            $differs = collect($data)
                // The slug is derived, and the name matched FOLDED — "VTEX"
                // for "Vtex" is the same record, not a correction.
                ->reject(fn ($value, string $column) => $column === 'slug'
                    || $column === $nameColumn
                    || RecordFields::same($retried->getAttributes()[$column] ?? null, $value))
                ->keys()
                ->map(fn (string $column) => $names[$column] ?? $column)
                ->values();

            $note = 'Já criado por esta conexão às ' . $this->time($retried) . '; nada novo foi criado.'
                . ($differs->isEmpty() ? '' : ' Campos desta chamada que NÃO foram aplicados: '
                    . $differs->implode(', ') . '. Use a ferramenta update_* para alterá-los.');

            return $answer($retried, $note);
        }

        $existing = $model::query()
            ->when($scope, fn ($query) => $scope($query))
            ->whereFoldedIs($nameColumn, $name)
            ->first();

        if ($existing && ! $allowDuplicate) {
            $label = $existing->getAttribute($nameColumn);

            return ToolResult::failure(
                "Já existe um registro com o nome \"{$label}\" (slug \"{$existing->slug}\"). "
                . 'Abra esse registro em vez de criar outro. Se a pessoa confirmar que quer um registro '
                . 'separado com o mesmo nome, repita com allow_duplicate: true.',
            );
        }

        $record = $create();

        // What was STORED, not what was sent: the save fills defaults and
        // gives a protected value its id, and a revert compares the record
        // against exactly this to know nothing changed since.
        $this->log($actor, $record, null, RecordFields::snapshot($record->refresh(), [...array_keys($data), 'slug']));

        return $answer($record, null);
    }

    private function time(Model $record): string
    {
        return $record->created_at?->timezone((string) config('app.display_timezone', config('app.timezone')))->format('H:i') ?? '';
    }

    /** The JSON Schema fragment every patch tool takes for `clear`. @param list<string> $fields */
    protected static function clearSchema(array $fields): array
    {
        return [
            'type'        => 'array',
            'items'       => ['type' => 'string', 'enum' => $fields],
            'description' => 'Campos a ESVAZIAR. Um campo ausente ou null fica como está; só o que for listado aqui é apagado.',
        ];
    }

    /** @return array<string, mixed> */
    protected static function attributeSchema(AttributeGroup $group, string $description): array
    {
        return Vocabulary::schema($group, $description);
    }
}

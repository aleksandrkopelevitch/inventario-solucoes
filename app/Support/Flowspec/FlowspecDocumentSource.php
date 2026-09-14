<?php

namespace App\Support\Flowspec;

use App\Models\FlowspecMessage;
use JsonException;

/**
 * Where a lifecycle command gets the `{meta, flowSpec}` it is about to write
 * into a real pipeline.
 *
 * It exists because the three commands that take a document
 * (`digibee:flowspec:ingest`, `digibee:pipeline:heal`,
 * `digibee:pipeline:readiness`) had the same `--file` reader copied three
 * times, and because a file on disk was the ONLY way in — which meant the
 * feature could not be pointed at the documents this app itself produces
 * without somebody exporting JSON by hand first. The generator writes them to
 * `flowspec_messages.flow_spec` already.
 *
 * Three sources, and **exactly one** may be given:
 *
 * - `--file` — a document from disk, as before.
 * - `--chat` — the latest message of that conversation carrying a document.
 *   The id is the one in the browser's address bar (`/flowspec/{chat}` binds by
 *   id), which is the whole point: it is the identifier a person actually has.
 * - `--message` — that exact message, for when a conversation has several
 *   generations and an older one is the one wanted.
 *
 * **Two sources at once is refused rather than resolved by precedence.** A
 * silent preference is how somebody deploys the document they did not mean,
 * having passed both and believed the other won — and on this platform nothing
 * deletes a pipeline.
 *
 * `origin()` is not decoration either: a command that writes to a real realm
 * has to say WHICH document it took, or pointing it at the wrong conversation
 * is invisible in its own report.
 */
final readonly class FlowspecDocumentSource
{
    /** @param array<string, mixed>|null $document */
    private function __construct(
        public ?array $document,
        public ?string $error,
        public ?string $origin,
    ) {}

    public static function resolve(?string $file, ?string $chat, ?string $message): self
    {
        $given = array_filter([
            '--file'    => $file,
            '--chat'    => $chat,
            '--message' => $message,
        ], fn (?string $value) => $value !== null && $value !== '');

        if ($given === []) {
            return self::failed(
                'Passe uma fonte do documento: --file com um caminho, --chat com o id da conversa '
                . '(o mesmo da URL /flowspec/{id}) ou --message com o id da mensagem.'
            );
        }

        if (count($given) > 1) {
            return self::failed(
                'Passe apenas UMA fonte: ' . implode(' e ', array_keys($given)) . ' foram dadas. '
                . 'Escolher uma delas por precedência esconderia qual documento seria escrito.'
            );
        }

        return match (array_key_first($given)) {
            '--file'    => self::fromFile((string) $file),
            '--chat'    => self::fromChat((string) $chat),
            default     => self::fromMessage((string) $message),
        };
    }

    public function ok(): bool
    {
        return $this->document !== null;
    }

    private static function fromFile(string $path): self
    {
        if (! is_file($path)) {
            return self::failed("Não existe arquivo em \"{$path}\".");
        }

        try {
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return self::failed("O arquivo não é JSON válido: {$e->getMessage()}");
        }

        return is_array($decoded)
            ? new self($decoded, null, "arquivo {$path}")
            : self::failed('O arquivo não contém um objeto {meta, flowSpec}.');
    }

    private static function fromChat(string $chatId): self
    {
        $message = FlowspecMessage::query()
            ->where('flowspec_chat_id', $chatId)
            ->whereNotNull('flow_spec')
            ->latest('id')
            ->first();

        if ($message === null) {
            // Deliberately distinguished from "no such chat": a conversation
            // that only ever answered in prose is the ordinary case (the
            // generator has a MODO CONVERSA), and reporting it as missing sends
            // somebody looking for a typo in the id.
            return self::failed(
                "A conversa #{$chatId} não tem nenhuma mensagem com flowSpec gerado. "
                . 'Ou o id está errado, ou nada nela chegou a gerar um documento.'
            );
        }

        return self::fromModel($message);
    }

    private static function fromMessage(string $messageId): self
    {
        $message = FlowspecMessage::query()->find($messageId);

        if ($message === null) {
            return self::failed("Nenhuma mensagem #{$messageId}.");
        }

        if ($message->flow_spec === null) {
            return self::failed(
                "A mensagem #{$messageId} não carrega flowSpec — ela foi uma resposta em texto, não uma geração."
            );
        }

        return self::fromModel($message);
    }

    private static function fromModel(FlowspecMessage $message): self
    {
        $document = $message->flow_spec;

        if (! is_array($document)) {
            return self::failed("A mensagem #{$message->id} tem um flowSpec que não é um objeto.");
        }

        return new self($document, null, "mensagem #{$message->id} da conversa #{$message->flowspec_chat_id}");
    }

    private static function failed(string $error): self
    {
        return new self(null, $error, null);
    }
}

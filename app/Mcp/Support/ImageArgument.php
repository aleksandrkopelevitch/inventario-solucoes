<?php

namespace App\Mcp\Support;

use App\Rules\PublicUrl;
use App\Support\Images\PublicImage;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

/**
 * A picture an MCP write tool received — a solution's or company's logo, a
 * person's photo — turned into the same `UploadedFile` the side panel's form
 * sends, so it is validated by the SAME rule (`*Rules::for()['logo'|'photo']`)
 * and stored by the same `PublicImage::store()`.
 *
 * The argument is one string, in one of three shapes:
 *
 * - **a URL** (`https://…`): the SERVER downloads it. This is the shape a chat
 *   client can actually use — a model writes a tool call token by token, so a
 *   picture in base64 costs ~4/3 of its bytes in output tokens, and a model
 *   that only SEES an attached image has no bytes to encode at all. Guarded
 *   like the documentation editor's "paste image URL" (`PublicUrl`), with one
 *   thing that path does not do: redirects are followed BY HAND, each hop
 *   checked again, because a public URL answering 302 to an internal address
 *   is the textbook way around a check made only on the first one.
 * - **a data URI** (`data:image/png;base64,…`) or **bare base64**: for a
 *   client that reads a local file (Claude Code, Cowork). Fine for a small
 *   logo; the encoded length is checked BEFORE decoding.
 *
 * Emptying the picture is `clear: ["logo"]`, like any other field.
 *
 * A picture change is deliberately NOT in the write log (`mcp_writes`), so
 * "Alterações via MCP" cannot revert it — decided when this was added
 * (2026-11-17): the old file stays on disk anyway, and a wrong logo is put
 * right by sending the right one.
 */
final class ImageArgument
{
    /** Same ceiling as the shared `max:2048` rule — kept here to refuse a huge string before decoding it. */
    public const MAX_KILOBYTES = 2048;

    private const MAX_REDIRECTS = 3;

    private function __construct(
        public readonly ?UploadedFile $file = null,
        public readonly bool $remove = false,
        public readonly ?string $error = null,
    ) {}

    public function __destruct()
    {
        // The temporary file behind the upload; `store()` copied it already,
        // and a refused call never gets that far.
        if ($this->file && is_file($this->file->getPathname())) {
            @unlink($this->file->getPathname());
        }
    }

    /**
     * Reads `$argument` (and, when `$allowClear`, whether `clear` lists it).
     *
     * @param  array<string, mixed>  $arguments
     * @param  array<int, mixed>  $rule  the app's own rule for this upload
     */
    public static function read(array $arguments, string $argument, array $rule, bool $allowClear = false): self
    {
        $raw = $arguments[$argument] ?? null;
        $sent = is_string($raw) ? trim($raw) !== '' : $raw !== null;
        $remove = $allowClear && in_array($argument, self::clearList($arguments), true);

        if ($sent && $remove) {
            return new self(error: "clear: \"{$argument}\" também foi enviado com um valor. Envie um ou outro.");
        }

        if ($remove) {
            return new self(remove: true);
        }

        if (! $sent) {
            return new self;
        }

        if (! is_string($raw)) {
            return new self(error: "{$argument}: envie uma URL https:// da imagem ou o conteúdo em base64.");
        }

        $raw = trim($raw);
        $bytes = preg_match('#^https?://#i', $raw) ? self::download($raw) : self::decode($raw);

        if (! is_string($bytes)) {
            return new self(error: "{$argument}: " . $bytes['error']);
        }

        $path = tempnam(sys_get_temp_dir(), 'mcp-image-');
        file_put_contents($path, $bytes);

        // `test: true` because the file did not arrive through a PHP upload,
        // which is all `isValid()` would otherwise check. The type is read
        // from the CONTENT (`image`/`mimes` detect it), never from a name.
        $file = new UploadedFile($path, $argument, null, null, true);
        $validator = Validator::make([$argument => $file], [$argument => $rule], [], [$argument => $argument]);

        if ($validator->fails()) {
            @unlink($path);

            return new self(error: "{$argument}: " . implode(' ', $validator->errors()->get($argument)));
        }

        return new self(file: $file);
    }

    /**
     * The arguments without `$argument` in `clear`, for `WriteTool::columns()`,
     * which refuses a name it does not map to a column.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public static function withoutClear(array $arguments, string $argument): array
    {
        if (array_key_exists('clear', $arguments) && $arguments['clear'] !== null) {
            $arguments['clear'] = array_values(array_filter(
                self::clearList($arguments),
                fn ($field) => $field !== $argument,
            ));
        }

        return $arguments;
    }

    /** Whether the call asked for any change to the picture. */
    public function sent(): bool
    {
        return $this->file !== null || $this->remove;
    }

    /** Stores the upload as the panel does, answering the path for the column. */
    public function store(string $directory): string
    {
        return PublicImage::store($this->file, $directory);
    }

    /** @return string|array{error: string} */
    private static function decode(string $raw): string|array
    {
        if (preg_match('#^data:([^;,]*)(;[^,]*)?,#i', $raw, $match)) {
            if (! str_contains(strtolower($match[2] ?? ''), 'base64')) {
                return ['error' => 'o data URI precisa estar em base64 (data:image/png;base64,…).'];
            }

            $raw = substr($raw, strlen($match[0]));
        }

        $raw = preg_replace('/\s+/', '', $raw);

        if (strlen($raw) > (int) ceil(self::MAX_KILOBYTES * 1024 / 3) * 4) {
            return ['error' => 'a imagem passa de ' . self::megabytes() . '.'];
        }

        $bytes = base64_decode($raw, true);

        return $bytes === false || $bytes === ''
            ? ['error' => 'não é uma URL https:// nem um conteúdo base64 válido.']
            : $bytes;
    }

    /** @return string|array{error: string} */
    private static function download(string $url): string|array
    {
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $check = Validator::make(['url' => $url], ['url' => ['url:http,https', 'max:2048', new PublicUrl]]);

            if ($check->fails()) {
                return ['error' => $check->errors()->first('url')];
            }

            try {
                $response = Http::timeout(20)
                    ->connectTimeout(5)
                    ->withoutRedirecting()
                    ->withOptions(['stream' => true])
                    ->accept('image/*')
                    ->get($url);
            } catch (ConnectionException) {
                return ['error' => 'não foi possível conectar ao endereço da imagem.'];
            }

            if ($response->redirect() && filled($location = $response->header('Location'))) {
                $url = (string) UriResolver::resolve(new Uri($url), new Uri($location));

                continue;
            }

            if (! $response->successful()) {
                return ['error' => "o endereço da imagem respondeu HTTP {$response->status()}."];
            }

            // Read no more than the ceiling (plus one byte, to know it was
            // passed): a Content-Length can lie, or be missing.
            $limit = self::MAX_KILOBYTES * 1024;
            $body = $response->toPsrResponse()->getBody();
            $bytes = '';

            while (! $body->eof() && strlen($bytes) <= $limit) {
                $bytes .= $body->read(65536);
            }

            $body->close();

            if (strlen($bytes) > $limit) {
                return ['error' => 'a imagem passa de ' . self::megabytes() . '.'];
            }

            return $bytes === '' ? ['error' => 'o endereço da imagem respondeu vazio.'] : $bytes;
        }

        return ['error' => 'o endereço da imagem redireciona vezes demais.'];
    }

    /** @param  array<string, mixed>  $arguments @return list<mixed> */
    private static function clearList(array $arguments): array
    {
        $clear = $arguments['clear'] ?? [];

        return is_array($clear) ? array_values($clear) : [$clear];
    }

    private static function megabytes(): string
    {
        return (self::MAX_KILOBYTES / 1024) . ' MB';
    }
}

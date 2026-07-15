<?php

namespace App\Services\Flowspec;

use App\Models\FlowspecMessage;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Responses\AgentResponse;

use function Laravel\Ai\agent;

/**
 * Orquestra uma geração de flowSpec: resolve contexto (docs + exemplos, sem
 * RAG), monta o prompt, chama o modelo via laravel/ai (provider/model de
 * `config('services.flowspec')`) e roda o loop de correção — normaliza,
 * valida e re-prompta com os erros concretos até `max_attempts`. Sem JSON na
 * primeira resposta = resposta conversacional (dúvida/pedido de detalhe),
 * devolvida como texto sem re-prompt.
 */
class FlowspecGenerationService
{
    public function __construct(
        private readonly FlowspecContextResolver $resolver,
        private readonly FlowspecPromptBuilder $prompts,
        private readonly DigibeeFlowspecNormalizer $normalizer,
        private readonly DigibeeFlowspecValidator $validator,
    ) {}

    /**
     * Gera a resposta para uma mensagem de usuário já persistida: o pedido é
     * o `content` dela, as Solutions explícitas vêm do seu `meta.solution_ids`,
     * os documentos escolhidos na mão vêm de `meta.document_refs`, e o
     * histórico são as mensagens anteriores a ela no chat.
     */
    public function generate(FlowspecMessage $userMessage): FlowspecGenerationResult
    {
        // Explícito: o job despacha um model recém-deserializado (SerializesModels),
        // sem a relação carregada — sem isto, o acesso a ->chat abaixo é um
        // lazy-load silencioso (Eloquent só arma o guard de strict mode em
        // hydrations de mais de uma linha, então nem em ambiente não-produção
        // isso lançaria LazyLoadingViolationException).
        $userMessage->loadMissing('chat');

        $history = $userMessage->chat->messages()
            ->where('id', '<', $userMessage->id)
            ->get();

        $context = $this->resolver->resolve(
            $userMessage->content,
            array_map(intval(...), $userMessage->meta['solution_ids'] ?? []),
            $userMessage->meta['document_refs'] ?? [],
        );

        Log::debug('flowSpec: contexto resolvido', [
            'chat_id'    => $userMessage->flowspec_chat_id,
            'message_id' => $userMessage->id,
            ...$context->toMeta(),
        ]);

        $basePrompt = $this->prompts->userPrompt($context, $userMessage->content, $history);

        $maxAttempts = (int) config('services.flowspec.max_attempts');
        $attempts = [];
        $tokens = ['prompt' => 0, 'completion' => 0];

        $document = null;
        $validated = false;
        $text = '';
        $prompt = $basePrompt;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            Log::debug("flowSpec: prompt tentativa {$attempt}", [
                'message_id' => $userMessage->id,
                'prompt'     => $prompt,
            ]);

            $response = $this->prompt($prompt);
            $text = $response->text;
            $tokens['prompt'] += $response->usage->promptTokens;
            $tokens['completion'] += $response->usage->completionTokens;

            Log::debug("flowSpec: resposta tentativa {$attempt}", [
                'message_id' => $userMessage->id,
                'text'       => $text,
                'usage'      => [
                    'prompt'     => $response->usage->promptTokens,
                    'completion' => $response->usage->completionTokens,
                ],
            ]);

            $fenced = $this->fencedJsonBlock($text);

            if ($fenced !== null) {
                $candidate = json_decode($fenced, true);

                if (! is_array($candidate)) {
                    $errors = ['A resposta não é um JSON parseável: ' . json_last_error_msg() . '.'];
                    $attempts[] = ['attempt' => $attempt, 'errors' => $errors];
                    $prompt = $this->prompts->correctionPrompt($basePrompt, $text, $errors);

                    continue;
                }
            } else {
                $candidate = $this->heuristicJsonCandidate($text);

                if ($candidate === null) {
                    $attempts[] = ['attempt' => $attempt, 'errors' => [], 'conversational' => true];

                    break; // dúvida/esclarecimento — não há o que corrigir
                }
            }

            $normalization = $this->normalizer->normalize($candidate);
            $result = $this->validator->validate($normalization->document);

            $attempts[] = [
                'attempt' => $attempt,
                'errors'  => $result->errors,
                'fixes'   => $normalization->fixes,
            ];

            $document = $normalization->document; // melhor tentativa até aqui

            if ($result->passes()) {
                $validated = true;

                break;
            }

            $prompt = $this->prompts->correctionPrompt($basePrompt, $text, $result->errors);
        }

        return new FlowspecGenerationResult(
            document: $document,
            text: $text,
            validated: $validated,
            meta: [
                ...$context->toMeta(),
                'attempts' => $attempts,
                'tokens'   => $tokens,
                'provider' => config('services.flowspec.provider'),
                'model'    => config('services.flowspec.model'),
            ],
        );
    }

    /** Protected para os testes substituírem a chamada real à API por um dublê. */
    protected function prompt(string $prompt): AgentResponse
    {
        return agent(instructions: $this->prompts->systemPrompt())->prompt(
            $prompt,
            provider: config('services.flowspec.provider'),
            model: config('services.flowspec.model'),
            timeout: (int) config('services.flowspec.timeout'),
        );
    }

    /**
     * Bloco JSON dentro de uma cerca de código (```json ... ```) — o modelo
     * cercou o JSON deliberadamente, então uma falha de `json_decode` aqui é
     * um erro real do modelo, não uma leitura errada nossa.
     */
    private function fencedJsonBlock(string $text): ?string
    {
        return preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $text, $match) === 1
            ? $match[1]
            : null;
    }

    /**
     * Sem cerca de código, varre da primeira `{` à última `}` — mas só trata
     * como uma TENTATIVA de flowSpec se aquilo decodificar para um array com
     * chave `meta` ou `flowSpec`. O system prompt ensina uma sintaxe de
     * chaves duplas (`{{ step.alias.campo }}`), então uma resposta puramente
     * conversacional que cite essa sintaxe também contém `{`/`}` — sem este
     * filtro, ela seria extraída, falhar o `json_decode` e queimar uma
     * tentativa do loop de correção com um "corrija o JSON" sem sentido.
     *
     * @return array<string, mixed>|null
     */
    private function heuristicJsonCandidate(string $text): ?array
    {
        $start = strpos($text, '{');
        $end = strrpos($text, '}');

        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        $candidate = json_decode(substr($text, $start, $end - $start + 1), true);

        return is_array($candidate) && (array_key_exists('meta', $candidate) || array_key_exists('flowSpec', $candidate))
            ? $candidate
            : null;
    }
}

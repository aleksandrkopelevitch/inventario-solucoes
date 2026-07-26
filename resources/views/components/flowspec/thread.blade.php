@php
    // Admins see the generation-context debug panel below each answer.
    $canDebug = auth()->user()?->can('viewAny', App\Models\FlowspecExample::class);
@endphp

<div id="{{ $domId }}" class="flex flex-col gap-4">
    @forelse ($messages as $message)
        @if ($message->role === 'user')
            <div class="ml-auto max-w-[85%] whitespace-pre-line rounded-card rounded-br-sm bg-accent px-4 py-2.5 text-sm leading-relaxed text-white shadow-sm">
                {{ $message->content }}
            </div>
        @else
            @php
                $meta = $message->meta ?? [];
                $validated = $meta['validated'] ?? false;
                $failed = ($meta['status'] ?? null) === 'failed';
                $attempts = count($meta['attempts'] ?? []);
                $lastErrors = $attempts > 0 ? (end($meta['attempts'])['errors'] ?? []) : [];
            @endphp

            <div class="mr-auto w-full max-w-[95%] rounded-card rounded-bl-sm border border-line bg-surface p-4 shadow-card">
                <div class="mb-2.5 flex items-center gap-2">
                    <span class="flex size-6 items-center justify-center rounded-lg bg-lime text-lime-ink shadow-sm">
                        <x-heroicon-o-sparkles class="size-3.5" />
                    </span>
                    <span class="text-xs font-semibold uppercase tracking-wide text-muted">Especialista em Integrações</span>
                </div>
                <p @class(['text-sm leading-relaxed whitespace-pre-line', 'text-crit' => $failed, 'text-ink' => ! $failed])>{{ $message->content }}</p>

                {{-- Botões de "adicionar documentação" — só numa resposta conversacional
                     (FlowspecGenerationService::suggestedDocuments()). Cada botão reusa a
                     mesma referência `type:id` do chips picker "Documentos específicos":
                     o clique (chips.js, `data-ak-chips-add`) adiciona direto no campo
                     `documents` do composer, sem o usuário precisar buscar na mão. --}}
                @if (($meta['suggested_documents'] ?? []) !== [])
                    <div class="mt-3 rounded-field border border-line bg-canvas p-3">
                        <p class="text-xs font-medium text-ink">Notei que a documentação de outros sistemas pode ajudar — adicionar ao contexto?</p>
                        <div class="mt-2 flex flex-wrap gap-1.5">
                            @foreach ($meta['suggested_documents'] as $doc)
                                <x-forms.button type="button" variant="glass" class="!px-2.5 !py-1 !text-xs"
                                    data-ak-chips-add="{{ json_encode(['name' => 'documents', 'value' => $doc['type'] . ':' . $doc['id'], 'label' => $doc['label']]) }}">
                                    <x-heroicon-o-plus class="size-3.5" /> {{ $doc['label'] }}
                                </x-forms.button>
                            @endforeach
                        </div>
                    </div>
                @endif

                @if ($message->flow_spec !== null)
                    {{-- Badges: validation, attempts, examples used --}}
                    <div class="mt-3 flex flex-wrap items-center gap-1.5 text-[11px] font-medium">
                        @if ($validated)
                            <span class="rounded-full border border-accent-line bg-accent-soft px-2 py-0.5 text-accent">Validado</span>
                        @else
                            <span class="rounded-full border border-hot-line bg-hot-soft px-2 py-0.5 text-hot">Com pendências</span>
                        @endif
                        <span class="rounded-full border border-line bg-raised px-2 py-0.5 text-muted">{{ $attempts }} {{ $attempts === 1 ? 'tentativa' : 'tentativas' }}</span>
                        @foreach ($meta['examples'] ?? [] as $exampleSlug)
                            <span class="rounded-full border border-line bg-raised px-2 py-0.5 text-muted">ex: {{ $exampleSlug }}</span>
                        @endforeach
                    </div>

                    @if (! $validated && $lastErrors !== [])
                        <ul class="mt-2 list-inside list-disc rounded-field border border-hot-line bg-hot-soft px-3 py-2 text-xs text-hot">
                            @foreach ($lastErrors as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    @endif

                    @if ($canDebug)
                        <details class="mt-3">
                            <summary class="cursor-pointer text-xs font-medium text-muted hover:text-ink">Contexto usado (debug)</summary>
                            <dl class="mt-2 flex flex-col gap-2 rounded-field border border-line bg-canvas p-3 text-xs text-body">
                                <div>
                                    <dt class="font-medium text-muted">Solutions consideradas</dt>
                                    <dd>{{ ($meta['solutions'] ?? []) !== [] ? implode(', ', $meta['solutions']) : '—' }}</dd>
                                </div>
                                <div>
                                    <dt class="font-medium text-muted">Páginas de documentação usadas</dt>
                                    <dd>{{ ($meta['pages'] ?? []) !== [] ? implode(', ', $meta['pages']) : '—' }}</dd>
                                </div>
                                <div>
                                    <dt class="font-medium text-muted">Documentação de integrações usada</dt>
                                    <dd>{{ ($meta['integration_docs'] ?? []) !== [] ? implode(', ', $meta['integration_docs']) : '—' }}</dd>
                                </div>
                                @if (($meta['omitted_documents'] ?? []) !== [])
                                    <div>
                                        <dt class="font-medium text-muted">Documentos omitidos (orçamento de contexto)</dt>
                                        <dd>{{ implode(', ', $meta['omitted_documents']) }}</dd>
                                    </div>
                                @endif
                                <div>
                                    <dt class="font-medium text-muted">Tags candidatas</dt>
                                    <dd>{{ ($meta['tags'] ?? []) !== [] ? implode(', ', $meta['tags']) : '—' }}</dd>
                                </div>
                                <div>
                                    <dt class="font-medium text-muted">Provider / modelo</dt>
                                    <dd>{{ $meta['provider'] ?? '—' }} / {{ $meta['model'] ?? '—' }}</dd>
                                </div>
                                <div>
                                    <dt class="font-medium text-muted">Tokens</dt>
                                    <dd>{{ $meta['tokens']['prompt'] ?? 0 }} prompt / {{ $meta['tokens']['completion'] ?? 0 }} completion</dd>
                                </div>
                            </dl>
                        </details>
                    @endif

                    {{-- JSON pronto para colar --}}
                    <div class="relative mt-3">
                        <pre id="flowspec-json-{{ $message->id }}"
                             class="max-h-80 overflow-auto rounded-field border border-line bg-canvas p-3 font-mono text-[11.5px] leading-relaxed text-body">{{ json_encode($message->flow_spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                        <x-forms.button type="button" variant="glass" class="!absolute right-2 top-2 !px-2.5 !py-1 !text-xs"
                            data-ak-flowspec-copy="flowspec-json-{{ $message->id }}">
                            <x-heroicon-o-clipboard-document class="size-3.5" /> Copiar JSON
                        </x-forms.button>
                    </div>
                @endif
            </div>
        @endif
    @empty
        <p class="text-sm text-muted">Nenhuma mensagem ainda.</p>
    @endforelse

    @if ($awaiting)
        <div data-ak-flowspec-poll="{{ route('flowspec.status', $chat) }}"
             class="mr-auto flex items-center gap-2.5 rounded-card rounded-bl-sm border border-line bg-surface px-4 py-3 text-sm text-muted">
            <span class="flex gap-1">
                <span class="size-1.5 animate-bounce rounded-full bg-accent [animation-delay:0ms]"></span>
                <span class="size-1.5 animate-bounce rounded-full bg-accent [animation-delay:150ms]"></span>
                <span class="size-1.5 animate-bounce rounded-full bg-accent [animation-delay:300ms]"></span>
            </span>
            O especialista está montando o flowSpec — reúne o contexto, chama o modelo e valida (pode levar alguns minutos)…
        </div>
    @endif
</div>

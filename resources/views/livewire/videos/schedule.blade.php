<section class="mx-auto flex w-full max-w-7xl flex-col gap-6 pt-6">
    <x-studio.page-header
        title="Publicar"
        subtitle="Revise os metadados sugeridos por corte e publique agora."
    >
        <x-slot:meta>
            <x-ui.badge>{{ $video->title ?? 'Vídeo' }}</x-ui.badge>
        </x-slot:meta>
    </x-studio.page-header>

    {{-- Plataformas e contas --}}
    <x-studio.panel title="Plataformas e contas" subtitle="As contas conectadas ficam disponíveis para os destinos escolhidos em cada corte.">
        <div class="mt-4 grid gap-3 sm:grid-cols-2">
            @foreach($platformLabels as $key => $label)
                @php($account = ($accountsByPlatform[$key] ?? collect())->first())
                <div @class([
                    'flex flex-col gap-3 rounded-xl border border-slate-800 bg-slate-950/70 p-3',
                    'opacity-50' => ! $account,
                ])>
                    <div class="flex items-center justify-between gap-2">
                        <div class="font-medium">{{ $label }}</div>
                        <x-ui.badge size="sm" color="{{ $account ? 'green' : 'zinc' }}">{{ $account ? 'Conectada' : 'Sem conta' }}</x-ui.badge>
                    </div>

                    <div>
                        <x-ui.text class="text-xs text-slate-500">Conta</x-ui.text>
                        @if($account)
                            <div class="truncate text-sm text-slate-100">{{ $account->name }}</div>
                        @else
                            <div class="text-sm text-amber-400">
                                Nenhuma conta — <a class="underline" href="{{ route('social-accounts') }}" wire:navigate>conectar</a>
                            </div>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </x-studio.panel>

    {{-- Cortes + metadados --}}
    <x-studio.panel>

        <div class="mt-4 columns-1 gap-3 md:columns-2">
            @forelse($cuts as $cut)
                @php($publishedTargets = $publishedTargetsByCut[$cut->uuid] ?? [])
                @php($hasYoutubeAccount = ($accountsByPlatform['youtube'] ?? collect())->isNotEmpty())
                @php($alreadyPublishedOnYoutube = (bool) ($publishedTargets['youtube'] ?? false))
                @php($blockedByMissingAccount = ! $hasYoutubeAccount)
                @php($locked = $alreadyPublishedOnYoutube || $blockedByMissingAccount)
                @php($publishMode = $cutPublishModes[$cut->uuid] ?? 'now')
                <div
                    class="mb-3 break-inside-avoid rounded-xl border border-slate-800 bg-slate-950/70 p-3"
                    @class(['opacity-50' => $locked])
                    x-data="{ editing: @entangle('editingCuts.' . $cut->uuid), open: false }"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0 flex-1">
                            <label @class(['flex items-center gap-2 font-medium', 'cursor-pointer' => ! $locked, 'cursor-not-allowed' => $locked])>
                                <input type="checkbox"
                                       value="{{ $cut->uuid }}"
                                       wire:model.live="selectedCuts"
                                       @disabled($locked)
                                       @class(['h-4 w-4 rounded accent-cyan-500', 'cursor-pointer' => ! $locked, 'cursor-not-allowed' => $locked])>
                                <span class="truncate">{{ $cut->name }}</span>
                            </label>
                            <div class="mt-1 text-xs tabular-nums text-slate-500">
                                {{ number_format((float) $cut->start_seconds, 1) }}s – {{ number_format((float) $cut->end_seconds, 1) }}s
                            </div>
                            <div class="mt-2 flex flex-wrap items-center gap-2">
                                <x-ui.badge color="red" size="sm">YouTube</x-ui.badge>

                                <x-ui.badge size="sm" color="{{ $publishMode === 'scheduled' ? 'amber' : 'green' }}">
                                    {{ $publishMode === 'scheduled' ? 'Agendado' : 'Agora' }}
                                </x-ui.badge>

                                <x-ui.badge size="sm" color="zinc">{{ ($cutScheduleGapHours[$cut->uuid] ?? 2) }}h</x-ui.badge>

                                @if($alreadyPublishedOnYoutube)
                                    <x-ui.badge color="amber" size="sm">já publicado no YouTube</x-ui.badge>
                                @elseif($blockedByMissingAccount)
                                    <x-ui.badge color="zinc" size="sm">falta vincular conta</x-ui.badge>
                                @elseif($cut->rendered_at)
                                    <x-ui.badge color="green" size="sm">renderizado</x-ui.badge>
                                @else
                                    <x-ui.badge color="zinc" size="sm">pendente</x-ui.badge>
                                @endif
                            </div>
                        </div>

                        <button
                            type="button"
                            class="inline-flex items-center gap-2 rounded-lg border border-slate-800 bg-slate-900 px-3 py-2 text-sm text-slate-200 transition hover:border-slate-700 hover:bg-slate-800"
                            x-on:click="open = !open"
                            x-bind:aria-expanded="open"
                        >
                            <span x-text="open ? 'Fechar' : 'Abrir'"></span>
                            <x-ui.icon name="chevron-down" class="size-4 transition" x-bind:class="{ 'rotate-180': open }" />
                        </button>
                    </div>

                    <div x-show="open" x-cloak x-transition.opacity class="mt-3 flex flex-col gap-3">
                        <div class="flex items-center justify-between gap-2">
                            <div class="text-xs text-slate-500">Destino</div>
                            @if($alreadyPublishedOnYoutube)
                                <x-ui.badge color="green" size="sm">publicado</x-ui.badge>
                            @elseif($blockedByMissingAccount)
                                <x-ui.badge color="zinc" size="sm">sem conta vinculada</x-ui.badge>
                            @else
                                <x-ui.badge color="red" size="sm">YouTube</x-ui.badge>
                            @endif
                        </div>

                        <div class="rounded-lg border border-slate-800 bg-slate-900/50 p-3">
                            <div class="flex items-center justify-between gap-3">
                                <div>
                                    <div class="text-sm font-medium text-slate-100">YouTube</div>
                                    <div class="mt-1 text-xs text-slate-500">
                                        As publicações de cortes são enviadas apenas para a conta YouTube conectada.
                                    </div>
                                </div>
                                <x-ui.badge size="sm" color="{{ $hasYoutubeAccount ? 'green' : 'zinc' }}">
                                    {{ $hasYoutubeAccount ? 'Disponível' : 'Sem conta' }}
                                </x-ui.badge>
                            </div>
                        </div>

                        <div class="rounded-lg border border-slate-800 bg-slate-900/50 p-3">
                            <div class="flex items-center justify-between gap-2">
                                <div class="text-xs text-slate-500">Quando publicar</div>
                                <x-ui.badge size="sm" color="{{ ($cutPublishModes[$cut->uuid] ?? 'now') === 'scheduled' ? 'amber' : 'green' }}">
                                    {{ ($cutPublishModes[$cut->uuid] ?? 'now') === 'scheduled' ? 'Agendado' : 'Agora' }}
                                </x-ui.badge>
                            </div>

                            <select
                                wire:model.live="cutPublishModes.{{ $cut->uuid }}"
                                class="mt-2 w-full rounded-lg border border-slate-800 bg-slate-900 px-2 py-2 text-sm text-slate-100"
                                @disabled($locked)
                            >
                                <option value="now">Publicar agora</option>
                                <option value="scheduled">Agendar data/hora</option>
                            </select>

                            <div class="mt-3">
                                @if(($cutPublishModes[$cut->uuid] ?? 'now') === 'scheduled')
                                    <label class="block">
                                        <div class="mb-1 text-xs text-slate-500">Data e hora</div>
                                        <input
                                            type="datetime-local"
                                            wire:model.blur="cutPublishAt.{{ $cut->uuid }}"
                                            class="w-full rounded-lg border border-slate-800 bg-slate-900 px-2 py-2 text-sm text-slate-100"
                                            @disabled($locked)
                                        >
                                    </label>
                                    <div class="mt-2 text-xs text-slate-500">
                                        Essa data vira a referência do próximo corte.
                                    </div>
                                @else
                                    <div class="text-sm text-slate-300">
                                        Vai publicar imediatamente quando você confirmar.
                                    </div>
                                @endif
                            </div>

                            <div class="mt-3 border-t border-slate-800 pt-3">
                                <div class="flex items-center justify-between gap-2">
                                    <div class="text-xs text-slate-500">Intervalo para o próximo corte</div>
                                    <x-ui.badge size="sm" color="zinc">{{ ($cutScheduleGapHours[$cut->uuid] ?? 2) }}h</x-ui.badge>
                                </div>
                                <select
                                    wire:model.live="cutScheduleGapHours.{{ $cut->uuid }}"
                                    class="mt-2 w-full rounded-lg border border-slate-800 bg-slate-900 px-2 py-2 text-sm text-slate-100"
                                    @disabled($locked)
                                >
                                    <option value="1">1 hora</option>
                                    <option value="2">2 horas</option>
                                    <option value="3">3 horas</option>
                                    <option value="4">4 horas</option>
                                    <option value="5">5 horas</option>
                                    <option value="6">6 horas</option>
                                </select>
                                <div class="mt-2 text-xs text-slate-500">
                                    Esse valor define quanto tempo o próximo card vai herdar deste corte.
                                </div>
                            </div>
                        </div>

                        <div x-show="!editing" x-cloak class="space-y-3 rounded-lg border border-slate-800 bg-slate-900/50 p-3">
                            <div class="flex items-center justify-between gap-2">
                                <div class="text-xs text-slate-500">Metadados</div>
                                @unless($locked)
                                    <x-ui.button variant="ghost" size="xs" icon="pencil-square" class="cursor-pointer" x-on:click="editing = true">
                                        Editar
                                    </x-ui.button>
                                @endunless
                            </div>
                            <div>
                                <div class="text-[11px] uppercase tracking-wide text-slate-500">Título</div>
                                <p class="mt-1 text-sm text-slate-100">{{ $cutMeta[$cut->uuid]['title'] ?? '—' }}</p>
                            </div>
                            <div>
                                <div class="text-[11px] uppercase tracking-wide text-slate-500">Descrição</div>
                                <p class="mt-1 whitespace-pre-line text-sm text-slate-100">{{ $cutMeta[$cut->uuid]['description'] ?? '—' }}</p>
                            </div>
                            <div>
                                <div class="text-[11px] uppercase tracking-wide text-slate-500">Hashtags</div>
                                <p class="mt-1 text-sm text-cyan-200">{{ $cutMeta[$cut->uuid]['hashtags'] ?? '—' }}</p>
                            </div>
                        </div>

                        <div x-show="editing" x-cloak class="flex flex-col gap-3">
                            <div>
                                <x-ui.text class="text-xs text-slate-500">Título</x-ui.text>
                                <input type="text" wire:model="cutMeta.{{ $cut->uuid }}.title"
                                       class="w-full rounded-lg border border-slate-800 bg-slate-900 px-2 py-1.5 text-sm text-slate-100"
                                       placeholder="Título chamativo">
                            </div>
                            <div>
                                <x-ui.text class="text-xs text-slate-500">Descrição</x-ui.text>
                                <textarea rows="7" wire:model="cutMeta.{{ $cut->uuid }}.description"
                                          class="w-full rounded-lg border border-slate-800 bg-slate-900 px-2 py-1.5 text-sm text-slate-100"
                                          placeholder="Legenda do post"></textarea>
                            </div>
                            <div>
                                <x-ui.text class="text-xs text-slate-500">Hashtags</x-ui.text>
                                <textarea rows="5" wire:model="cutMeta.{{ $cut->uuid }}.hashtags"
                                          class="w-full rounded-lg border border-slate-800 bg-slate-900 px-2 py-1.5 text-sm text-slate-100"
                                          placeholder="#viral #fyp #cortes"></textarea>
                            </div>
                            <div class="flex justify-end">
                                <x-ui.button wire:click="saveCutMeta('{{ $cut->uuid }}')" variant="primary" size="sm" class="cursor-pointer" icon="check">
                                    Salvar
                                </x-ui.button>
                            </div>
                        </div>
                    </div>
                </div>
            @empty
                <div class="py-6 text-center text-slate-500">Nenhum corte disponível. Renderize os cortes no editor primeiro.</div>
            @endforelse
        </div>
    </x-studio.panel>

    <div class="flex flex-wrap items-center gap-3">
        <x-ui.button wire:click="confirmPublications" variant="primary" class="cursor-pointer" icon="check">
            <span wire:loading.remove wire:target="confirmPublications">Confirmar publicações</span>
            <span wire:loading wire:target="confirmPublications">Confirmando...</span>
        </x-ui.button>
        <x-ui.button :href="route('videos.publications', $video)" variant="subtle" class="cursor-pointer" wire:navigate>
            Ver publicações deste vídeo
        </x-ui.button>
        <x-ui.button :href="route('videos.editor', $video)" variant="subtle" class="cursor-pointer" wire:navigate>
            Voltar ao editor
        </x-ui.button>
    </div>
</section>

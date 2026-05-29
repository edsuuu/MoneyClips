<section class="mx-auto flex w-full max-w-7xl flex-col gap-6">
    <x-studio.page-header
        eyebrow="Distribuição"
        title="Publicar ou Agendar"
        subtitle="Escolha a conta, revise os metadados sugeridos por corte e publique agora ou em sequência."
    >
        <x-slot:meta>
            <flux:badge>{{ $video->title ?? 'Vídeo' }}</flux:badge>
            <flux:badge color="zinc">{{ $video->uuid }}</flux:badge>
        </x-slot:meta>
        <x-slot:actions>
            <flux:button :href="route('posts.dashboard')" size="sm" variant="subtle" icon="chart-bar" class="cursor-pointer" wire:navigate>
                Dashboard
            </flux:button>
            <flux:button :href="route('social-accounts')" size="sm" variant="subtle" icon="user-circle" class="cursor-pointer" wire:navigate>
                Contas vinculadas
            </flux:button>
        </x-slot:actions>
    </x-studio.page-header>

    <x-studio.panel
        :title="$preferredMode === 'publish' ? 'Fluxo rapido de publicacao' : 'Fluxo de agendamento'"
        :subtitle="$preferredMode === 'publish'
            ? 'Os cortes ja chegam selecionados com YouTube marcado. Revise a conta e clique em publicar agora.'
            : 'Os cortes ja chegam com titulo, descricao, hashtags e parte sugeridos. Ajuste o necessario e agende.'"
    >
        <flux:text class="text-xs text-slate-500">
            Contas ativas neste fluxo: {{ collect($supportedPlatforms)->map(fn (string $platform) => $platformLabels[$platform] ?? ucfirst($platform))->implode(' · ') }}.
        </flux:text>
    </x-studio.panel>

    {{-- Plataformas, contas e sequência --}}
    <x-studio.panel title="Plataformas, contas e horários" subtitle="Os cortes selecionados são postados em sequência: o 1º no horário de início e os seguintes a cada intervalo.">
        <flux:text class="mt-1 text-xs text-slate-500">
            Os cortes selecionados são postados em sequência: o 1º no horário de início e os seguintes a cada intervalo.
        </flux:text>

        <div class="mt-4 grid gap-3">
            @foreach($platformLabels as $key => $label)
                @php($accounts = $accountsByPlatform[$key] ?? collect())
                <div class="grid gap-3 rounded-xl border border-slate-800 bg-slate-950/70 p-3 md:grid-cols-[auto_1fr_1fr_auto] md:items-end">
                    <label class="flex items-center gap-2 font-medium">
                        <input type="checkbox" wire:model.live="platforms.{{ $key }}"
                               class="h-4 w-4 cursor-pointer rounded accent-cyan-500">
                        {{ $label }}
                    </label>

                    <div>
                        <flux:text class="text-xs text-slate-500">Conta</flux:text>
                        @if($accounts->isEmpty())
                            <div class="text-sm text-amber-400">
                                Nenhuma conta — <a class="underline" href="{{ route('social-accounts') }}" wire:navigate>conectar</a>
                            </div>
                        @else
                            <select wire:model="account.{{ $key }}"
                                    class="w-full cursor-pointer rounded-lg border border-slate-800 bg-slate-900 px-2 py-1.5 text-sm text-slate-100">
                                <option value="">Selecione...</option>
                                @foreach($accounts as $acc)
                                    <option value="{{ $acc->uuid }}">{{ $acc->name }}</option>
                                @endforeach
                            </select>
                        @endif
                    </div>

                    <div>
                        <flux:text class="text-xs text-slate-500">Início</flux:text>
                        <input type="datetime-local" wire:model="startAt.{{ $key }}"
                               class="w-full cursor-pointer rounded-lg border border-slate-800 bg-slate-900 px-2 py-1.5 text-sm text-slate-100">
                    </div>

                    <div>
                        <flux:text class="text-xs text-slate-500">Intervalo (min)</flux:text>
                        <input type="number" min="0" wire:model="intervalMinutes.{{ $key }}"
                               class="w-24 rounded-lg border border-slate-800 bg-slate-900 px-2 py-1.5 text-sm text-slate-100">
                    </div>
                </div>
            @endforeach
        </div>
    </x-studio.panel>

    {{-- Cortes + metadados --}}
    <x-studio.panel title="Cortes e conteúdo do post" subtitle="Marque os cortes e ajuste o texto que vai em cada publicação.">

        <div class="mt-4 grid gap-3">
            @forelse($cuts as $cut)
                <div class="rounded-xl border border-slate-800 bg-slate-950/70 p-3">
                    <div class="flex items-center justify-between gap-3">
                        <label class="flex items-center gap-2 font-medium cursor-pointer">
                            <input type="checkbox" value="{{ $cut->uuid }}" wire:model.live="selectedCuts"
                                   class="h-4 w-4 rounded accent-cyan-500 cursor-pointer">
                            {{ $cut->name }}
                            <span class="text-xs tabular-nums text-slate-500">
                                {{ number_format((float) $cut->start_seconds, 1) }}s – {{ number_format((float) $cut->end_seconds, 1) }}s
                            </span>
                        </label>
                        @if($cut->rendered_at)
                            <flux:badge color="green" size="sm">renderizado</flux:badge>
                        @else
                            <flux:badge color="zinc" size="sm">pendente</flux:badge>
                        @endif
                    </div>

                    <div class="mt-3 grid gap-2 md:grid-cols-2">
                        <div class="md:col-span-2">
                            <flux:text class="text-xs text-slate-500">Título</flux:text>
                            <input type="text" wire:model="cutMeta.{{ $cut->uuid }}.title"
                                   class="w-full rounded-lg border border-slate-800 bg-slate-900 px-2 py-1.5 text-sm text-slate-100"
                                   placeholder="Título chamativo">
                        </div>
                        <div>
                            <flux:text class="text-xs text-slate-500">Descrição</flux:text>
                            <textarea rows="3" wire:model="cutMeta.{{ $cut->uuid }}.description"
                                      class="w-full rounded-lg border border-slate-800 bg-slate-900 px-2 py-1.5 text-sm text-slate-100"
                                      placeholder="Legenda do post"></textarea>
                        </div>
                        <div>
                            <flux:text class="text-xs text-slate-500">Hashtags</flux:text>
                            <textarea rows="3" wire:model="cutMeta.{{ $cut->uuid }}.hashtags"
                                      class="w-full rounded-lg border border-slate-800 bg-slate-900 px-2 py-1.5 text-sm text-slate-100"
                                      placeholder="#viral #fyp #cortes"></textarea>
                        </div>
                    </div>
                </div>
            @empty
                <div class="py-6 text-center text-slate-500">Nenhum corte disponível. Renderize os cortes no editor primeiro.</div>
            @endforelse
        </div>
    </x-studio.panel>

    <div class="flex items-center gap-3">
        <flux:button wire:click="publishNow" variant="primary" class="cursor-pointer" icon="paper-airplane">
            <span wire:loading.remove wire:target="publishNow">Publicar agora</span>
            <span wire:loading wire:target="publishNow">Publicando...</span>
        </flux:button>
        <flux:button wire:click="schedule" variant="subtle" class="cursor-pointer" icon="calendar">
            <span wire:loading.remove wire:target="schedule">Agendar publicações</span>
            <span wire:loading wire:target="schedule">Agendando...</span>
        </flux:button>
        <flux:button :href="route('videos.editor', $video)" variant="subtle" class="cursor-pointer" wire:navigate>
            Voltar ao editor
        </flux:button>
    </div>

    {{-- Posts já agendados deste vídeo --}}
    @if($recentPosts->isNotEmpty())
        <x-studio.panel title="Publicações deste vídeo">
            <div class="flex items-center justify-between">
                <div></div>
                <flux:button :href="route('posts.dashboard')" size="xs" variant="ghost" class="cursor-pointer" wire:navigate>Ver dashboard</flux:button>
            </div>
            <div class="mt-3 overflow-x-auto">
                <table class="studio-table w-full text-sm">
                    <thead>
                        <tr>
                            <th class="py-2 pr-3">#</th>
                            <th class="py-2 pr-3">Plataforma</th>
                            <th class="py-2 pr-3">Conta</th>
                            <th class="py-2 pr-3">Título</th>
                            <th class="py-2 pr-3">Agendado</th>
                            <th class="py-2 pr-3">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($recentPosts as $post)
                            <tr>
                                <td class="py-2 pr-3 tabular-nums">{{ $post->sequence }}</td>
                                <td class="py-2 pr-3">{{ $platformLabels[$post->platform] ?? ucfirst($post->platform) }}</td>
                                <td class="py-2 pr-3">{{ $post->account?->name ?? '—' }}</td>
                                <td class="py-2 pr-3 max-w-xs truncate">{{ $post->title ?? '—' }}</td>
                                <td class="py-2 pr-3 tabular-nums">{{ $post->scheduled_for?->format('d/m H:i') }}</td>
                                <td class="py-2 pr-3">
                                    @include('livewire.videos._post-status', ['status' => $post->status])
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-studio.panel>
    @endif
</section>

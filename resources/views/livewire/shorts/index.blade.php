<section class="mx-auto flex w-full max-w-7xl flex-col gap-6">
    <x-studio.page-header
        eyebrow="Auto-postagem"
        title="Estoque de Shorts"
        subtitle="Shorts baixados de canais via yt-dlp, prontos para o sorteio das postagens automáticas no YouTube."
    >
        <x-slot:meta>
            @if($account)
                <x-ui.badge color="green" size="sm">Canal: {{ $account->name }}</x-ui.badge>
            @else
                <x-ui.badge color="red" size="sm">Nenhuma conta do YouTube conectada</x-ui.badge>
            @endif
        </x-slot:meta>
        <x-slot:actions>
            <x-ui.button wire:click="dispatchRandom" size="sm" variant="primary" icon="play" class="cursor-pointer">
                Postar 1 agora (sorteio)
            </x-ui.button>
            <x-ui.button :href="route('social-accounts')" size="sm" variant="subtle" icon="user-circle" class="cursor-pointer" wire:navigate>
                Contas vinculadas
            </x-ui.button>
        </x-slot:actions>
    </x-studio.page-header>

    {{-- Cartões de estoque --}}
    <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
        <x-studio.metric-card label="Total" :value="$counts['total']" tone="zinc" />
        <x-studio.metric-card label="Baixados" :value="$counts['downloaded']" tone="blue" />
        <x-studio.metric-card label="Disponíveis" :value="$counts['available']" tone="amber" />
        <x-studio.metric-card label="Postados" :value="$counts['posted']" tone="green" />
    </div>

    <x-studio.panel title="Shorts" subtitle="Baixe mais com: php artisan youtube:download-shorts &quot;url-do-canal&quot;">
        <div class="mb-4 inline-flex flex-wrap items-center gap-2 rounded-xl border border-slate-800 bg-slate-900/70 p-1.5">
            @foreach(['all' => 'Todos', 'available' => 'Disponíveis', 'posted' => 'Postados'] as $key => $label)
                <button
                    type="button"
                    wire:click="setFilter('{{ $key }}')"
                    class="cursor-pointer rounded-lg px-3 py-1.5 text-sm transition {{ $filter === $key ? 'bg-slate-100 text-slate-950' : 'text-slate-400 hover:bg-slate-800 hover:text-slate-50' }}"
                >
                    {{ $label }}
                </button>
            @endforeach
        </div>

        @if($shorts->isEmpty())
            <div class="rounded-xl border border-dashed border-slate-800 p-10 text-center text-sm text-slate-400">
                Nenhum Short por aqui ainda. Baixe os Shorts de um canal com
                <code class="rounded bg-slate-900 px-1.5 py-0.5 text-slate-200">php artisan youtube:download-shorts "https://www.youtube.com/@canal"</code>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-800 text-left text-xs uppercase tracking-wide text-slate-500">
                            <th class="px-3 py-2">Short</th>
                            <th class="px-3 py-2">Hashtags</th>
                            <th class="px-3 py-2">Baixado em</th>
                            <th class="px-3 py-2">Status</th>
                            <th class="px-3 py-2 text-right">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($shorts as $short)
                            <tr class="border-b border-slate-900 hover:bg-slate-900/50" wire:key="short-{{ $short->id }}">
                                <td class="max-w-xs px-3 py-2.5">
                                    <p class="truncate font-medium text-slate-100">{{ $short->title ?? $short->youtube_id }}</p>
                                    <a
                                        href="https://www.youtube.com/shorts/{{ $short->youtube_id }}"
                                        target="_blank"
                                        rel="noopener"
                                        class="text-xs text-slate-500 hover:text-slate-300"
                                    >{{ $short->youtube_id }}</a>
                                </td>
                                <td class="max-w-[200px] truncate px-3 py-2.5 text-xs text-slate-400">
                                    {{ implode(' ', array_slice($short->hashtags ?? [], 0, 4)) }}
                                </td>
                                <td class="px-3 py-2.5 text-xs text-slate-400">
                                    {{ $short->downloaded_at?->format('d/m/Y H:i') ?? '—' }}
                                </td>
                                <td class="px-3 py-2.5">
                                    @if($short->posted_at !== null)
                                        <x-ui.badge color="green" size="sm">Postado</x-ui.badge>
                                        @if($short->youtube_video_id)
                                            <a
                                                href="https://www.youtube.com/shorts/{{ $short->youtube_video_id }}"
                                                target="_blank"
                                                rel="noopener"
                                                class="ml-1 text-xs text-emerald-400 hover:text-emerald-300"
                                            >ver</a>
                                        @endif
                                    @elseif($short->video_path !== null)
                                        <x-ui.badge color="amber" size="sm">Disponível</x-ui.badge>
                                    @else
                                        <x-ui.badge color="zinc" size="sm">Sem vídeo</x-ui.badge>
                                    @endif
                                </td>
                                <td class="px-3 py-2.5 text-right">
                                    @if($short->posted_at === null && $short->video_path !== null)
                                        <x-ui.button
                                            wire:click="postNow({{ $short->id }})"
                                            wire:confirm="Postar este Short no YouTube agora?"
                                            size="xs"
                                            variant="primary"
                                            icon="arrow-up-tray"
                                            class="cursor-pointer"
                                        >
                                            Postar
                                        </x-ui.button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-4">
                {{ $shorts->links() }}
            </div>
        @endif
    </x-studio.panel>
</section>

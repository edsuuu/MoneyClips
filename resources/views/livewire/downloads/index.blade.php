<section class="mx-auto flex w-full max-w-7xl flex-col gap-6">
    <x-studio.page-header
        eyebrow="Downloads"
        title="Estoque e postagens"
        subtitle="Vídeos importados para youtube_shorts e status das postagens enviadas ao TikTok."
    >
        <x-slot:actions>
            <x-ui.button x-data x-on:click="$dispatch('modal-show', { name: 'instant-post' })" size="sm" variant="primary" icon="paper-airplane" class="cursor-pointer">
                Postagem instantânea
            </x-ui.button>
            <x-ui.button x-data x-on:click="$dispatch('modal-show', { name: 'new-download' })" size="sm" variant="primary" icon="arrow-down-tray" class="cursor-pointer">
                Novo download
            </x-ui.button>
            <x-ui.button wire:click="$refresh" size="sm" variant="subtle" icon="arrow-path" class="cursor-pointer">
                Atualizar
            </x-ui.button>
        </x-slot:actions>
    </x-studio.page-header>

    @php
        $tabs = [
            ['key' => 'available', 'label' => 'Disponíveis', 'count' => $counts['available'], 'tone' => 'zinc'],
            ['key' => 'queued',    'label' => 'Em fila',     'count' => $counts['queued'],    'tone' => 'amber'],
            ['key' => 'posted',    'label' => 'Postados',    'count' => $counts['posted'],    'tone' => 'green'],
            ['key' => 'failed',    'label' => 'Falhas',      'count' => $counts['failed'],    'tone' => 'red'],
        ];
    @endphp

    <div class="flex flex-wrap gap-2 border-b border-slate-800 pb-2">
        @foreach($tabs as $tabInfo)
            <button
                type="button"
                wire:click="setTab('{{ $tabInfo['key'] }}')"
                @class([
                    'inline-flex shrink-0 cursor-pointer items-center gap-2 rounded-lg px-3.5 py-1.5 text-sm font-medium transition',
                    'bg-slate-800 text-slate-100 shadow-sm' => $tab === $tabInfo['key'],
                    'text-slate-400 hover:bg-slate-900 hover:text-slate-200' => $tab !== $tabInfo['key'],
                ])
            >
                <span>{{ $tabInfo['label'] }}</span>
                <span @class([
                    'inline-flex min-w-5 items-center justify-center rounded px-1.5 py-0.5 text-[10px] font-semibold tabular-nums',
                    'bg-slate-700 text-slate-100' => $tab === $tabInfo['key'],
                    'bg-zinc-500/20 text-zinc-400' => $tab !== $tabInfo['key'] && $tabInfo['tone'] === 'zinc',
                    'bg-amber-500/20 text-amber-400' => $tab !== $tabInfo['key'] && $tabInfo['tone'] === 'amber',
                    'bg-emerald-500/20 text-emerald-400' => $tab !== $tabInfo['key'] && $tabInfo['tone'] === 'green',
                    'bg-red-500/20 text-red-400' => $tab !== $tabInfo['key'] && $tabInfo['tone'] === 'red',
                ])>{{ $tabInfo['count'] }}</span>
            </button>
        @endforeach
    </div>

    <x-studio.panel
        :title="match($tab) {
            'queued' => 'Em fila no TikTok',
            'posted' => 'Já postados no TikTok',
            'failed' => 'Falhas no TikTok',
            default => 'Disponíveis para postar',
        }"
        :subtitle="match($tab) {
            'queued' => 'Vídeos enfileirados ou em processamento no microserviço tiktok-uploader.',
            'posted' => 'Vídeos já publicados (ou dry-run). Não aparecem na aba Disponíveis.',
            'failed' => 'Vídeos cuja postagem falhou — podem ser reenviados.',
            default => 'Estoque baixado que ainda não foi enviado pra fila.',
        }"
    >
        @if($items->isEmpty())
            <div class="rounded-lg border border-dashed border-slate-800 p-10 text-center text-sm text-slate-400">
                @if($tab === 'available')
                    Nenhum vídeo disponível para postar. Dispare um download pela tela
                    <button type="button" x-data x-on:click="$dispatch('modal-show', { name: 'new-download' })" class="cursor-pointer text-slate-200 underline hover:text-slate-50">Novo download</button>.
                @else
                    Nada por aqui.
                @endif
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-800 text-left text-xs uppercase tracking-wide text-slate-500">
                            <th class="px-3 py-2">Vídeo</th>
                            <th class="px-3 py-2">Hashtags</th>
                            <th class="px-3 py-2">Storage</th>
                            <th class="px-3 py-2">Status TikTok</th>
                            <th class="px-3 py-2 text-right">Ação</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($items as $item)
                            @php
                                /** @var \App\Models\TiktokPost|null $post */
                                $post = $item['post'];
                                $status = $item['post_status'];
                            @endphp
                            <tr class="border-b border-slate-900 hover:bg-slate-900/50" wire:key="download-item-{{ $tab }}-{{ $item['youtube_id'] }}">
                                <td class="max-w-sm px-3 py-2.5">
                                    <p class="line-clamp-2 font-medium text-slate-100">{{ $item['title'] }}</p>
                                    @if($item['storage_url'])
                                        <a
                                            href="{{ $item['storage_url'] }}"
                                            target="_blank"
                                            rel="noopener"
                                            class="mt-1 inline-flex items-center gap-1 text-xs text-emerald-300 hover:text-emerald-200"
                                            title="Abre o vídeo direto do MinIO ({{ $item['storage_path'] }})"
                                        >
                                            <span>▶</span>
                                            <span>ver no MinIO</span>
                                        </a>
                                    @else
                                        <span class="mt-1 inline-block text-xs text-slate-600">sem link (storage offline)</span>
                                    @endif
                                    <a
                                        href="https://www.youtube.com/shorts/{{ $item['youtube_id'] }}"
                                        target="_blank"
                                        rel="noopener"
                                        class="mt-1 block text-xs text-slate-500 hover:text-slate-300"
                                    >{{ $item['youtube_id'] }}</a>
                                </td>
                                <td class="max-w-[220px] px-3 py-2.5 text-xs text-slate-400">
                                    <span class="line-clamp-2">{{ implode(' ', array_slice($item['hashtags'], 0, 6)) ?: '—' }}</span>
                                </td>
                                <td class="max-w-[240px] px-3 py-2.5 text-xs text-slate-400">
                                    <p class="truncate">{{ $item['storage_path'] ?: '—' }}</p>
                                    <p class="mt-1 text-slate-600">Baixado em {{ $item['downloaded_at'] }}</p>
                                </td>
                                <td class="px-3 py-2.5">
                                    @if($status === 'completed')
                                        <x-ui.badge color="green" size="sm">Postado</x-ui.badge>
                                    @elseif($status === 'dry-run')
                                        <x-ui.badge color="blue" size="sm">Dry-run</x-ui.badge>
                                    @elseif(in_array($status, ['queued', 'processing'], true))
                                        <x-ui.badge color="amber" size="sm">Em fila</x-ui.badge>
                                    @elseif($status === 'failed')
                                        <x-ui.badge color="red" size="sm">Falhou</x-ui.badge>
                                    @else
                                        <x-ui.badge color="zinc" size="sm">Disponível</x-ui.badge>
                                    @endif

                                    @if($item['post_error'])
                                        <p class="mt-1 max-w-xs truncate text-xs text-red-300" title="{{ $item['post_error'] }}">{{ $item['post_error'] }}</p>
                                    @endif
                                </td>
                                <td class="px-3 py-2.5 text-right">
                                    <x-ui.button
                                        wire:click="requestPostToTiktok('{{ $item['youtube_id'] }}')"
                                        size="xs"
                                        variant="primary"
                                        icon="arrow-up-tray"
                                        class="cursor-pointer"
                                        :disabled="! $item['can_post']"
                                    >
                                        @if($tab === 'failed')
                                            Reenviar
                                        @else
                                            Postar no TikTok
                                        @endif
                                    </x-ui.button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-4">
                {{ $items->links() }}
            </div>
        @endif
    </x-studio.panel>

    <x-ui.modal name="new-download" max-width="max-w-2xl">
        <livewire:downloads.new-download />
    </x-ui.modal>

    <x-ui.modal name="instant-post" max-width="max-w-4xl">
        <livewire:downloads.instant-post />
    </x-ui.modal>

    <x-ui.confirm-modal
        wire:model="showTiktokConfirmation"
        title="Enviar para o TikTok?"
        description="O vídeo será enviado para a fila de postagem do TikTok."
        icon="arrow-up-tray"
    >
        <div class="rounded-lg border border-slate-800 bg-slate-950/70 p-4">
            <p class="line-clamp-2 text-sm font-medium text-slate-100">{{ $pendingTitle ?: $pendingYoutubeId }}</p>
            @if($pendingYoutubeId !== '')
                <p class="mt-1 text-xs text-slate-500">{{ $pendingYoutubeId }}</p>
            @endif
        </div>

        <x-slot:actions>
            <x-ui.button wire:click="cancelPostToTiktok" variant="filled" class="cursor-pointer">
                Cancelar
            </x-ui.button>
            <x-ui.button wire:click="postPendingToTiktok" variant="primary" icon="arrow-up-tray" class="cursor-pointer">
                Confirmar postagem
            </x-ui.button>
        </x-slot:actions>
    </x-ui.confirm-modal>
</section>

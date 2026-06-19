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

    <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
        <x-studio.metric-card label="Baixados" :value="$counts['downloaded']" tone="blue" />
        <x-studio.metric-card label="Em fila" :value="$counts['queued']" tone="amber" />
        <x-studio.metric-card label="Postados" :value="$counts['posted']" tone="green" />
        <x-studio.metric-card label="Falhas" :value="$counts['failed']" tone="red" />
    </div>

    <x-studio.panel title="Vídeos baixados" subtitle="A lista vem da tabela local youtube_shorts (alimentada pelo webhook do microserviço).">
        @if($items->isEmpty())
            <div class="rounded-lg border border-dashed border-slate-800 p-10 text-center text-sm text-slate-400">
                Nenhum vídeo local encontrado. Dispare um download pela tela
                <button type="button" x-data x-on:click="$dispatch('modal-show', { name: 'new-download' })" class="cursor-pointer text-slate-200 underline hover:text-slate-50">Novo download</button>.
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
                                $status = $post?->status;
                            @endphp
                            <tr class="border-b border-slate-900 hover:bg-slate-900/50" wire:key="download-item-{{ $item['youtube_id'] }}">
                                <td class="max-w-sm px-3 py-2.5">
                                    <p class="line-clamp-2 font-medium text-slate-100">{{ $item['title'] }}</p>
                                    <a
                                        href="https://www.youtube.com/shorts/{{ $item['youtube_id'] }}"
                                        target="_blank"
                                        rel="noopener"
                                        class="text-xs text-slate-500 hover:text-slate-300"
                                    >{{ $item['youtube_id'] }}</a>
                                </td>
                                <td class="max-w-[220px] px-3 py-2.5 text-xs text-slate-400">
                                    <span class="line-clamp-2">{{ implode(' ', array_slice($item['hashtags'], 0, 6)) ?: '—' }}</span>
                                </td>
                                <td class="max-w-[240px] px-3 py-2.5 text-xs text-slate-400">
                                    <p class="truncate">{{ $item['storage_path'] ?: '—' }}</p>
                                    <p class="mt-1 text-slate-600">Baixado em {{ $item['downloaded_at'] }}</p>
                                    @if($item['storage_size_bytes'] > 0)
                                        <p class="mt-1 text-slate-600">{{ number_format($item['storage_size_bytes'] / 1048576, 1, ',', '.') }} MB</p>
                                    @endif
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

                                    @if($post?->error)
                                        <p class="mt-1 max-w-xs truncate text-xs text-red-300" title="{{ $post->error }}">{{ $post->error }}</p>
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
                                        Postar no TikTok
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

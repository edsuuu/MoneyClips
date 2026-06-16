<section class="mx-auto flex w-full max-w-7xl flex-col gap-6">
    <x-studio.page-header
        eyebrow="Downloads"
        title="Estoque e postagens"
        subtitle="Vídeos baixados pelo microserviço download-youtube e status das postagens enviadas ao TikTok."
    >
        <x-slot:actions>
            <x-ui.button :href="route('downloads.create')" size="sm" variant="primary" icon="arrow-down-tray" class="cursor-pointer" wire:navigate>
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

    @if($loadError)
        <div class="rounded-lg border border-red-900/60 bg-red-950/40 p-4 text-sm text-red-200">
            {{ $loadError }}
        </div>
    @endif

    <x-studio.panel title="Vídeos baixados" subtitle="A lista vem de GET /shorts/items no microserviço download-youtube.">
        @if($items->isEmpty())
            <div class="rounded-lg border border-dashed border-slate-800 p-10 text-center text-sm text-slate-400">
                Nenhum vídeo baixado encontrado. Crie um download pela tela
                <a href="{{ route('downloads.create') }}" wire:navigate class="text-slate-200 underline hover:text-slate-50">Novo download</a>.
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
                                        wire:click='postToTiktok(@js($item["youtube_id"]), @js($item["title"]), @js($item["hashtags"]))'
                                        wire:confirm="Enviar este vídeo para postagem no TikTok?"
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
</section>

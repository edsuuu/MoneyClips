<section class="mx-auto flex w-full max-w-3xl flex-col gap-6">
    <x-studio.page-header
        eyebrow="YouTube"
        title="Download de Shorts"
        subtitle="Liste os Shorts de um canal e baixe-os para agendar as postagens automaticamente."
    />

    {{-- 1. Buscar informações do canal --}}
    <x-studio.panel title="Canal" subtitle="Informe a URL do canal do YouTube.">
        <form wire:submit="fetchChannel" class="flex flex-col gap-3 sm:flex-row sm:items-end">
            <flux:input
                wire:model="channelUrl"
                label="URL do canal"
                placeholder="https://www.youtube.com/@canal"
                class="flex-1"
            />
            <flux:button type="submit" variant="primary" icon="magnifying-glass" class="cursor-pointer">
                <span wire:loading.remove wire:target="fetchChannel">Buscar</span>
                <span wire:loading wire:target="fetchChannel">Buscando...</span>
            </flux:button>
        </form>
    </x-studio.panel>

    {{-- 2. Resultado + opções de download --}}
    @if ($videos !== null)
        <x-studio.panel
            title="Resultado"
            :subtitle="'Vídeos encontrados: '.count($videos)"
        >
            @if (count($videos) > 0)
                <form wire:submit="startDownload" class="flex flex-col gap-4">
                    <flux:checkbox wire:model.live="downloadAll" label="Baixar todos" />

                    @if (! $downloadAll)
                        <flux:input
                            type="number"
                            min="1"
                            :max="count($videos)"
                            wire:model="limit"
                            label="Quantos vídeos baixar"
                            class="max-w-40"
                        />
                    @endif

                    <div>
                        <flux:button type="submit" variant="primary" icon="arrow-down-tray" class="cursor-pointer">
                            <span wire:loading.remove wire:target="startDownload">Iniciar download</span>
                            <span wire:loading wire:target="startDownload">Enviando...</span>
                        </flux:button>
                    </div>
                </form>
            @else
                <flux:text class="text-slate-400">Nenhum Short encontrado para este canal.</flux:text>
            @endif
        </x-studio.panel>
    @endif

    {{-- 3. Vídeos já baixados --}}
    <x-studio.panel
        title="Vídeos baixados"
        :subtitle="'Total: '.$downloaded->total()"
    >
        @if ($downloaded->isEmpty())
            <flux:text class="text-slate-400">Nenhum vídeo baixado ainda.</flux:text>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-slate-800 text-xs uppercase text-slate-500">
                        <tr>
                            <th class="py-2 pr-3 font-medium">Vídeo</th>
                            <th class="py-2 pr-3 font-medium">YouTube ID</th>
                            <th class="py-2 pr-3 font-medium">Postagem</th>
                            <th class="py-2 pr-3 font-medium">Baixado em</th>
                            <th class="py-2 font-medium">MinIO</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800/70">
                        @foreach ($downloaded as $short)
                            @php($job = $short->jobs->first())
                            <tr class="align-top">
                                <td class="py-3 pr-3">
                                    <div class="font-medium text-slate-100">{{ $short->title ?: $short->youtube_id }}</div>
                                    @if (! empty($short->hashtags))
                                        <div class="mt-1 flex flex-wrap gap-1">
                                            @foreach (array_slice($short->hashtags, 0, 6) as $tag)
                                                <span class="rounded bg-slate-800 px-1.5 py-0.5 text-xs text-slate-400">{{ $tag }}</span>
                                            @endforeach
                                        </div>
                                    @endif
                                </td>
                                <td class="py-3 pr-3">
                                    <a href="https://www.youtube.com/shorts/{{ $short->youtube_id }}"
                                       target="_blank" rel="noopener"
                                       class="text-indigo-400 hover:underline">{{ $short->youtube_id }}</a>
                                </td>
                                <td class="py-3 pr-3">
                                    @if ($job)
                                        @php($tone = match ($job->status) {
                                            \App\Models\YoutubeShortJob::STATUS_POSTED => 'green',
                                            \App\Models\YoutubeShortJob::STATUS_FAILED => 'red',
                                            default => 'amber',
                                        })
                                        <flux:badge :color="$tone" size="sm">{{ ucfirst($job->status) }}</flux:badge>
                                        <div class="mt-1 text-xs text-slate-500">
                                            {{ $job->scheduled_at->format('d/m/Y H:i') }}
                                        </div>
                                    @else
                                        <span class="text-xs text-slate-500">—</span>
                                    @endif
                                </td>
                                <td class="py-3 pr-3 text-xs text-slate-400">
                                    {{ $short->downloaded_at?->format('d/m/Y H:i') ?? '—' }}
                                </td>
                                <td class="py-3 text-xs text-slate-400">
                                    <span class="break-all">{{ $short->minio_path }}</span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-4">
                {{ $downloaded->links() }}
            </div>
        @endif
    </x-studio.panel>
</section>

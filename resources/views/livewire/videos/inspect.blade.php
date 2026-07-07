<section class="w-full">
    <div class="relative mb-6 w-full">
        <div class="flex items-center justify-between gap-4">
            <div>
                <x-ui.heading size="xl" level="1">Inspecionar Vídeo</x-ui.heading>
                <x-ui.text class="mt-1">Leia os metadados de qualquer Short do estoque via ffprobe.</x-ui.text>
            </div>
        </div>
        <x-ui.separator variant="subtle" class="mt-4" />
    </div>

    <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
        {{-- Lista de Shorts --}}
        <div>
            <x-ui.input
                type="search"
                wire:model.live.debounce.300ms="search"
                placeholder="Buscar por youtube_id, título ou caminho…"
            />

            <div class="mt-3 space-y-2">
                @forelse($shorts as $short)
                    <div @class([
                        'flex items-start justify-between gap-3 rounded-xl border p-3 transition',
                        'border-slate-100 bg-slate-900/80' => $selectedShortId === $short->id,
                        'border-slate-800 bg-slate-950/70 hover:border-slate-700' => $selectedShortId !== $short->id,
                    ])>
                        <div class="min-w-0">
                            <div class="truncate text-sm font-medium text-slate-100">{{ $short->title ?: $short->youtube_id }}</div>
                            <div class="mt-0.5 truncate font-mono text-xs text-slate-400">{{ $short->youtube_id }}</div>
                            <div class="mt-0.5 truncate font-mono text-[11px] text-slate-500">{{ $short->video_path }}</div>
                            <div class="mt-0.5 text-[11px] text-slate-500">
                                {{ $short->downloaded_at?->timezone(config('app.timezone', 'America/Sao_Paulo'))->format('d/m/Y H:i') ?? '—' }}
                            </div>
                        </div>
                        <x-ui.button
                            variant="outline"
                            size="sm"
                            wire:click="probeVideo({{ $short->id }})"
                            wire:loading.attr="disabled"
                            wire:target="probeVideo({{ $short->id }})"
                        >
                            <x-ui.icon name="eye" class="size-4" />
                            <span wire:loading.remove wire:target="probeVideo({{ $short->id }})">Inspecionar</span>
                            <span wire:loading wire:target="probeVideo({{ $short->id }})">Lendo…</span>
                        </x-ui.button>
                    </div>
                @empty
                    <div class="rounded-xl border border-slate-800 bg-slate-950/70 p-6 text-center text-sm text-slate-400">
                        Nenhum Short encontrado.
                    </div>
                @endforelse
            </div>
        </div>

        {{-- Painel de metadados --}}
        <div>
            <div wire:loading.flex wire:target="probeVideo" class="items-center gap-2 rounded-xl border border-slate-800 bg-slate-950/70 p-6 text-sm text-slate-300">
                <x-ui.icon name="loading" class="size-5" />
                Lendo metadados via ffprobe…
            </div>

            <div wire:loading.remove wire:target="probeVideo">
                @if($error)
                    <x-ui.callout variant="danger" icon="exclamation-triangle" heading="Não foi possível inspecionar">
                        {{ $error }}
                    </x-ui.callout>
                @elseif($metadata)
                    @php
                        $mbps = ($metadata['video_bitrate'] ?? 0) / 1_000_000;
                        $bitrateColor = $metadata['video_bitrate'] > 8_000_000 ? 'green'
                            : ($metadata['video_bitrate'] > 4_000_000 ? 'amber' : 'red');
                        $sec = (int) round($metadata['duration'] ?? 0);
                        $min = intdiv($sec, 60);
                        $durationLabel = $min > 0 ? "{$min}m ".($sec % 60).'s' : "{$sec}s";
                    @endphp

                    <div class="rounded-2xl border border-slate-800 bg-slate-950/70 p-5">
                        <div class="mb-4">
                            <div class="truncate text-sm font-semibold text-slate-100">{{ $metadata['title'] ?: $metadata['youtube_id'] }}</div>
                            <div class="mt-0.5 truncate font-mono text-xs text-slate-400">{{ $metadata['video_path'] }}</div>
                        </div>

                        <dl class="grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
                            <div>
                                <dt class="text-xs text-slate-500">Resolução</dt>
                                <dd class="font-medium text-slate-100">{{ $metadata['width'] ?? '?' }}×{{ $metadata['height'] ?? '?' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-slate-500">Duração</dt>
                                <dd class="font-medium text-slate-100">{{ $durationLabel }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-slate-500">FPS</dt>
                                <dd class="font-medium text-slate-100">{{ $metadata['fps'] ? number_format($metadata['fps'], 2) : 'N/A' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-slate-500">Codec de vídeo</dt>
                                <dd class="font-medium text-slate-100">{{ $metadata['codec'] }} ({{ $metadata['profile'] }})</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-slate-500">Bitrate de vídeo</dt>
                                <dd class="mt-0.5">
                                    <x-ui.badge :color="$bitrateColor" size="sm">
                                        {{ $metadata['video_bitrate'] > 0 ? number_format($mbps, 2).' Mbps' : 'N/A' }}
                                    </x-ui.badge>
                                </dd>
                            </div>
                            <div>
                                <dt class="text-xs text-slate-500">Bitrate de áudio</dt>
                                <dd class="font-medium text-slate-100">
                                    {{ $metadata['audio_bitrate'] > 0 ? round($metadata['audio_bitrate'] / 1000).' kbps' : 'N/A' }}
                                    <span class="text-xs text-slate-500">({{ $metadata['audio_codec'] }})</span>
                                </dd>
                            </div>
                            <div>
                                <dt class="text-xs text-slate-500">Tamanho</dt>
                                <dd class="font-medium text-slate-100">{{ $metadata['file_size'] > 0 ? number_format($metadata['file_size'] / 1_048_576, 2).' MB' : 'N/A' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-slate-500">Formato</dt>
                                <dd class="truncate font-medium text-slate-100">{{ $metadata['format_name'] }}</dd>
                            </div>
                        </dl>

                        @if($metadata['video_bitrate'] > 0 && $metadata['video_bitrate'] < 4_000_000)
                            <x-ui.callout variant="warning" icon="exclamation-triangle" class="mt-4">
                                Bitrate abaixo de 4 Mbps — candidato a reencode antes de postar no TikTok.
                            </x-ui.callout>
                        @endif
                    </div>
                @else
                    <div class="rounded-2xl border border-dashed border-slate-800 bg-slate-950/40 p-8 text-center text-sm text-slate-500">
                        Selecione um Short e clique em <span class="font-medium text-slate-300">Inspecionar</span>.
                    </div>
                @endif
            </div>
        </div>
    </div>
</section>

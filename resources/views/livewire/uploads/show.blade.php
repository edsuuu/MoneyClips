<div @if ($isPackaging) wire:poll.5s @endif>
    <div class="mb-6 flex items-center justify-between">
        <div>
            <x-ui.heading size="lg">{{ $title }}</x-ui.heading>
            <x-ui.subheading>{{ $statusLabel }}</x-ui.subheading>
        </div>

        <x-ui.button variant="subtle" tag="a" href="{{ route('uploads.index') }}">Voltar</x-ui.button>
    </div>

    @if ($isReady)
        <div class="overflow-hidden rounded-2xl border border-slate-800 bg-black">
            <video
                class="aspect-video w-full"
                controls
                playsinline
                preload="metadata"
                @if ($posterUrl) poster="{{ $posterUrl }}" @endif
                data-hls-src="{{ $hlsUrl }}"
                @if ($fallbackUrl) data-fallback-src="{{ $fallbackUrl }}" @endif
                x-init="window.initAdaptiveVideoPlayer($el)"
            ></video>
        </div>

        @if ($renditions)
            <div class="mt-4 flex flex-wrap items-center gap-2">
                <span class="text-xs text-slate-500">Qualidades disponíveis:</span>
                @foreach ($renditions as $rendition)
                    <span class="rounded-full bg-slate-800 px-2.5 py-1 text-xs font-semibold text-slate-300">{{ $rendition }}</span>
                @endforeach
            </div>
        @endif
    @elseif ($isPackaging)
        <div class="rounded-2xl border border-amber-500/30 bg-amber-500/5 px-8 py-16 text-center">
            <x-ui.icon name="cog-6-tooth" class="mx-auto size-9 animate-spin text-amber-400" />
            <div class="mt-4 text-sm font-semibold text-amber-200">Preparando reprodução — {{ $progress }}%</div>
            <div class="mt-1 text-xs text-amber-400/70">Vídeos longos podem levar horas. Pode fechar a página.</div>

            <div class="mx-auto mt-5 h-2 w-full max-w-sm overflow-hidden rounded-full bg-slate-800">
                <div class="h-full rounded-full bg-amber-500 transition-all" style="width: {{ $progress }}%"></div>
            </div>

            @if ($fallbackUrl)
                <div class="mt-8">
                    <div class="mb-2 text-xs text-slate-500">Enquanto isso, o original:</div>
                    <video class="mx-auto aspect-video w-full max-w-2xl rounded-xl" controls playsinline preload="none" src="{{ $fallbackUrl }}"></video>
                </div>
            @endif
        </div>
    @else
        <div class="rounded-2xl border border-red-500/30 bg-red-500/5 px-8 py-16 text-center">
            <x-ui.icon name="exclamation-triangle" class="mx-auto size-9 text-red-400" />
            <div class="mt-4 text-sm font-semibold text-red-200">{{ $statusLabel }}</div>
            @if ($error)
                <div class="mt-2 text-xs text-red-400/80">{{ $error }}</div>
            @endif
        </div>
    @endif
</div>

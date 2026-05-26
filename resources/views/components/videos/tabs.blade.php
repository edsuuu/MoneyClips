@props(['video' => null])

<div class="mb-5">
    <div class="inline-flex flex-wrap items-center gap-2 rounded-xl border border-slate-800 bg-slate-900/70 p-1.5">
        <a
            href="{{ route('videos.index') }}"
            wire:navigate
            class="px-3 py-1.5 rounded-lg text-sm transition cursor-pointer {{ request()->routeIs('videos.index') ? 'bg-slate-100 text-slate-950' : 'text-slate-400 hover:bg-slate-800 hover:text-slate-50' }}"
        >
            Vídeos
        </a>

        @if($video)
            <a
                href="{{ route('videos.editor', $video) }}"
                wire:navigate
                class="px-3 py-1.5 rounded-lg text-sm transition cursor-pointer {{ request()->routeIs('videos.editor') ? 'bg-slate-100 text-slate-950' : 'text-slate-400 hover:bg-slate-800 hover:text-slate-50' }}"
            >
                Editor
            </a>

            <a
                href="{{ route('videos.schedule', $video) }}"
                wire:navigate
                class="px-3 py-1.5 rounded-lg text-sm transition cursor-pointer {{ request()->routeIs('videos.schedule') ? 'bg-slate-100 text-slate-950' : 'text-slate-400 hover:bg-slate-800 hover:text-slate-50' }}"
            >
                Agendamentos
            </a>
        @endif
    </div>
</div>

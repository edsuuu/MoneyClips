<div class="flex h-full flex-col gap-3 rounded-[14px] border border-slate-800 bg-slate-900 p-3.5 transition hover:border-slate-700" wire:key="video-{{ $section }}-{{ $video['id'] }}">
    <div class="flex gap-3">
        <div class="flex h-[74px] w-14 shrink-0 items-center justify-center rounded-lg bg-slate-800">
            <x-ui.icon name="play" class="size-4 text-slate-500" />
        </div>
        <div class="min-w-0 flex-1">
            <div class="line-clamp-2 text-sm font-bold leading-snug">{{ $video['title'] }}</div>
            <div class="mt-1 font-mono text-[11px] text-slate-500">{{ $video['youtube_id'] }}</div>
        </div>
    </div>

    @if ($video['displayTags'] !== [])
        <div class="flex flex-wrap gap-1.5">
            @foreach ($video['displayTags'] as $tag)
                <span class="rounded-full bg-sky-400/10 px-2 py-0.5 font-mono text-[11px] text-sky-700 dark:text-sky-300">{{ $tag }}</span>
            @endforeach
        </div>
    @endif

    <div class="mt-auto flex items-center justify-between border-t border-slate-800 pt-2.5">
        <div class="flex items-center gap-1.5">
            <span @class([
                'flex items-center gap-1 rounded-full px-2.5 py-1 font-mono text-[11px] font-bold',
                $video['statusBadge']['class'] => true,
            ])>
                {{ $video['statusBadge']['label'] }}
            </span>

            @if ($video['reencoded'])
                <span title="Reencodado em alta qualidade" class="rounded-full bg-sky-400/15 px-2 py-1 font-mono text-[10px] font-bold text-sky-700 dark:text-sky-400">HQ</span>
            @endif
        </div>

        <div class="flex items-center gap-2">
            @if ($section === 'posted')
                @if ($video['posted_youtube'])<span class="font-mono text-[10px] font-bold text-red-600 dark:text-red-400">YT</span>@endif
                @if ($video['posted_tiktok'])<span class="font-mono text-[10px] font-bold text-slate-200">TT</span>@endif
                @if ($video['youtube_link'])
                    <a href="{{ $video['youtube_link'] }}" target="_blank" class="flex items-center gap-1 text-xs font-semibold text-sky-700 hover:text-sky-600 dark:text-sky-400 dark:hover:text-sky-300">
                        Abrir <x-ui.icon name="arrow-top-right-on-square" class="size-3" />
                    </a>
                @endif
            @else
                <button type="button" wire:click="openEdit({{ $video['id'] }})"
                    class="flex cursor-pointer items-center gap-1 text-xs font-semibold text-sky-700 transition hover:text-sky-600 dark:text-sky-400 dark:hover:text-sky-300">
                    Visualizar
                    <x-ui.icon name="arrow-top-right-on-square" class="size-3" />
                </button>
            @endif
        </div>
    </div>

    @if ($video['canRedo'])
        <div x-data="{ asking: false, change: '' }" class="flex flex-col gap-2">
            <button
                type="button"
                x-on:click="asking = !asking"
                @disabled($video['isRedoing'])
                class="flex cursor-pointer items-center justify-center gap-1.5 rounded-[9px] bg-gradient-to-r from-sky-600 to-violet-600 px-3 py-2 text-[12.5px] font-bold text-white transition hover:from-sky-500 hover:to-violet-500 disabled:cursor-not-allowed disabled:opacity-50"
            >
                @if ($video['isRedoing'])
                    <x-ui.icon name="loading" class="size-3" />
                    Refazendo…
                @else
                    <x-ui.icon name="sparkles" class="size-3" />
                    Refazer com IA
                @endif
            </button>

            <div x-show="asking" x-cloak class="flex gap-2">
                <input
                    type="text"
                    x-model="change"
                    maxlength="300"
                    aria-label="O que mudar no Short"
                    placeholder="O que mudar? (mais memes, zoom no grito...)"
                    class="min-w-0 flex-1 rounded-lg border border-slate-700 bg-slate-950 px-2.5 py-1.5 text-xs text-slate-200 placeholder:text-slate-500 focus:border-sky-500 focus:outline-none"
                />
                <button
                    type="button"
                    x-on:click="$wire.redo({{ $video['id'] }}, change); asking = false; change = ''"
                    class="shrink-0 cursor-pointer rounded-lg bg-gradient-to-r from-sky-600 to-violet-600 px-3 py-1.5 text-xs font-semibold text-white transition hover:from-sky-500 hover:to-violet-500"
                >
                    Refazer
                </button>
            </div>

            @if (! is_null($video['redoError']))
                <p class="text-xs break-words text-red-600 dark:text-red-400">{{ $video['redoError'] }}</p>
            @endif
        </div>
    @endif

    @if ($section === 'downloaded')
        <div class="flex gap-2">
            <button type="button" wire:click="markReady({{ $video['id'] }})"
                class="flex flex-1 cursor-pointer items-center justify-center gap-1.5 rounded-[9px] bg-sky-400 px-3 py-2 text-[12.5px] font-bold text-gray-950 transition hover:bg-sky-300">
                <x-ui.icon name="plus" class="size-3" />
                Marcar como pronto
            </button>
        </div>
    @endif
</div>

{{-- Card de vídeo do estoque (design docs/designs/VideoCard.dc.html).
     Recebe: $video (view model do Index, com statusBadge/displayTags prontos)
     e $section (downloaded|ready|templated|posted). --}}
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
                <span class="rounded-full bg-sky-400/10 px-2 py-0.5 font-mono text-[11px] text-sky-300">{{ $tag }}</span>
            @endforeach
        </div>
    @endif

    <div class="mt-auto flex items-center justify-between border-t border-slate-800 pt-2.5">
        <div class="flex items-center gap-1.5">
            <span @class([
                'flex items-center gap-1 rounded-full px-2.5 py-1 font-mono text-[11px] font-bold',
                $video['statusBadge']['class'] => true,
            ])>
                @if ($video['statusBadge']['loading'])
                    <x-ui.icon name="loading" class="size-2.5" />
                @endif
                {{ $video['statusBadge']['label'] }}
            </span>

            @if ($video['reencoded'])
                <span title="Reencodado em alta qualidade" class="rounded-full bg-sky-400/15 px-2 py-1 font-mono text-[10px] font-bold text-sky-400">HQ</span>
            @endif
        </div>

        <div class="flex items-center gap-2">
            @if ($section === 'posted')
                @if ($video['posted_youtube'])<span class="font-mono text-[10px] font-bold text-red-400">YT</span>@endif
                @if ($video['posted_tiktok'])<span class="font-mono text-[10px] font-bold text-slate-200">TT</span>@endif
                @if ($video['youtube_link'])
                    <a href="{{ $video['youtube_link'] }}" target="_blank" class="flex items-center gap-1 text-xs font-semibold text-sky-400 hover:text-sky-300">
                        Abrir <x-ui.icon name="arrow-top-right-on-square" class="size-3" />
                    </a>
                @endif
            @else
                <button type="button" wire:click="openEdit({{ $video['id'] }})"
                    class="flex cursor-pointer items-center gap-1 text-xs font-semibold text-sky-400 transition hover:text-sky-300">
                    Visualizar
                    <x-ui.icon name="arrow-top-right-on-square" class="size-3" />
                </button>
            @endif
        </div>
    </div>

    @if ($video['processing'] === null && in_array($section, ['downloaded', 'ready', 'templated'], true))
        <div class="flex gap-2">
            @if ($section === 'downloaded')
                <button type="button" wire:click="markReady({{ $video['id'] }})"
                    class="flex flex-1 cursor-pointer items-center justify-center gap-1.5 rounded-[9px] bg-sky-400 px-3 py-2 text-[12.5px] font-bold text-slate-950 transition hover:bg-sky-300">
                    <x-ui.icon name="plus" class="size-3" />
                    Adicionar à fila
                </button>
            @elseif ($section === 'ready')
                <button type="button" wire:click="openInstant({{ $video['id'] }})"
                    class="flex flex-1 cursor-pointer items-center justify-center gap-1.5 rounded-[9px] border border-slate-700 bg-slate-800 px-3 py-2 text-[12.5px] font-semibold text-slate-200 transition hover:border-sky-400 hover:text-sky-400">
                    <x-ui.icon name="bolt" class="size-3" />
                    Postar agora
                </button>
            @else
                <button type="button" wire:click="openSchedule({{ $video['id'] }})"
                    class="flex flex-1 cursor-pointer items-center justify-center gap-1.5 rounded-[9px] bg-sky-400 px-3 py-2 text-[12.5px] font-bold text-slate-950 transition hover:bg-sky-300">
                    <x-ui.icon name="calendar-days" class="size-3" />
                    Agendar
                </button>
            @endif

            @if ($section === 'templated')
                <button type="button" wire:click="editInTemplateEditor({{ $video['id'] }})" title="Editar template"
                    class="flex w-9 shrink-0 cursor-pointer items-center justify-center rounded-[9px] border border-slate-700 bg-slate-800 text-slate-300 transition hover:border-sky-400 hover:text-sky-400">
                    <x-ui.icon name="pencil-square" class="size-3.5" />
                </button>
            @else
                <button type="button" wire:click="startReencode({{ $video['id'] }})" wire:confirm="Reencodar este vídeo em alta qualidade?"
                    title="Reencodar (HQ)"
                    class="flex w-9 shrink-0 cursor-pointer items-center justify-center rounded-[9px] border border-slate-700 bg-slate-800 text-slate-300 transition hover:border-sky-400 hover:text-sky-400">
                    <x-ui.icon name="arrow-path" class="size-3.5" />
                </button>
            @endif
        </div>
    @endif
</div>

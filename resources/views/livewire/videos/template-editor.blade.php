{{-- Editor de template (tab do /meus-videos, design docs/designs/Estoque.dc.html).
     Todo o mapa de estilos vem pronto do componente (previewClass/swatchClass). --}}
<div class="grid items-start gap-6 lg:grid-cols-[340px_minmax(0,1fr)]">

    {{-- Prévia 9:16 --}}
    <div class="flex flex-col gap-3.5 lg:sticky lg:top-24">
        <div @class(['flex aspect-[9/16] w-full flex-col overflow-hidden rounded-[20px] border p-4', $previewClass => true])>
            @if ($showHeader)
                <div class="flex items-center gap-2.5 pb-3.5">
                    <div class="flex size-9 shrink-0 items-center justify-center rounded-full bg-sky-400 text-sm font-extrabold text-slate-950">
                        {{ $channelInitial }}
                    </div>
                    <div class="min-w-0">
                        <div class="truncate text-[15px] font-extrabold leading-tight">{{ $channelNameDisplay }}</div>
                        <div class="truncate font-mono text-[11.5px] opacity-60">{{ $channelHandleDisplay }}</div>
                    </div>
                </div>
            @endif

            <div class="flex min-h-0 flex-1 items-center">
                <div @class([
                    'flex w-full items-center justify-center rounded-[14px] bg-slate-700/40 shadow-xl',
                    'h-full' => $isVertical,
                    'aspect-video' => ! $isVertical,
                ])>
                    <span class="flex size-12 items-center justify-center rounded-full bg-white/90">
                        <x-ui.icon name="play" class="size-5 text-slate-900" />
                    </span>
                </div>
            </div>

            <div class="pt-3.5 text-center text-[13px] font-bold uppercase tracking-wide opacity-70">
                legenda karaokê {{ $captionPositionLabel }}
            </div>
        </div>
        <div class="text-center text-[11.5px] text-slate-500">Prévia · formato 9:16 (Shorts / TikTok) · render real no AutoCaption</div>
    </div>

    {{-- Controles --}}
    <div class="flex flex-col gap-5">
        <div>
            <div class="text-[13.5px] font-bold">Vídeo base</div>
            <div class="mb-2.5 mt-0.5 text-xs text-slate-500">Escolha qual vídeo do estoque receberá este template. Ele entra no quadro central da prévia.</div>
            <div class="flex gap-2.5 overflow-x-auto pb-1">
                @forelse ($sources as $source)
                    <button type="button" wire:click="selectSource({{ $source['id'] }})" wire:key="tpl-src-{{ $source['id'] }}"
                        class="w-24 shrink-0 cursor-pointer text-left">
                        <div @class([
                            'relative flex h-32 w-24 items-center justify-center rounded-[10px] border-2 bg-slate-800 transition',
                            'border-sky-400' => $source['selected'],
                            'border-transparent hover:border-sky-400/60' => ! $source['selected'],
                        ])>
                            <x-ui.icon name="play" class="size-5 text-slate-500" />
                            @if ($source['selected'])
                                <span class="absolute right-1 top-1 flex size-5 items-center justify-center rounded-full bg-sky-400">
                                    <x-ui.icon name="check" class="size-3 text-slate-950" />
                                </span>
                            @endif
                        </div>
                        <div class="mt-1 line-clamp-2 text-[10.5px] leading-tight text-slate-400">{{ $source['title'] }}</div>
                    </button>
                @empty
                    <div class="rounded-xl border border-dashed border-slate-700 px-6 py-6 text-[13px] text-slate-500">Sem vídeos disponíveis no estoque.</div>
                @endforelse
            </div>
        </div>

        <div>
            <div class="mb-2.5 text-[13.5px] font-bold">Template</div>
            <div class="grid grid-cols-3 gap-2.5">
                @foreach ($styles as $option)
                    <button type="button" wire:click="setStyle('{{ $option['value'] }}')" wire:key="tpl-style-{{ $option['value'] }}"
                        @class([
                            'flex cursor-pointer flex-col items-center gap-2 rounded-xl border-2 bg-slate-900 p-3 transition',
                            'border-sky-400' => $option['selected'],
                            'border-slate-700 hover:border-sky-400/50' => ! $option['selected'],
                        ])>
                        <div @class(['flex h-14 w-10 flex-col gap-1 rounded-md p-1', $option['swatchClass'] => true])>
                            @if ($option['hasHeader'])
                                <div class="h-1.5 rounded-sm bg-sky-400"></div>
                            @endif
                            <div class="flex flex-1 items-center"><div class="h-4 w-full rounded-sm bg-slate-500/60"></div></div>
                            <div class="h-1 rounded-sm bg-slate-500/40"></div>
                        </div>
                        <span @class([
                            'text-[11.5px] font-semibold',
                            'text-slate-50' => $option['selected'],
                            'text-slate-400' => ! $option['selected'],
                        ])>{{ $option['label'] }}</span>
                    </button>
                @endforeach
            </div>
        </div>

        <div class="grid gap-3.5 sm:grid-cols-2">
            <div>
                <div class="mb-1.5 text-[12.5px] font-semibold text-slate-300">Nome do canal</div>
                <input type="text" wire:model.live.debounce.400ms="channelName" placeholder="Cortes do Podcast"
                    class="w-full rounded-[9px] border border-slate-700 bg-slate-950 px-3 py-2.5 text-[13.5px] text-slate-100 outline-none focus:border-sky-500" />
            </div>
            <div>
                <div class="mb-1.5 text-[12.5px] font-semibold text-slate-300">@handle</div>
                <input type="text" wire:model.live.debounce.400ms="channelHandle" placeholder="@cortes.podcast"
                    class="w-full rounded-[9px] border border-slate-700 bg-slate-950 px-3 py-2.5 font-mono text-[13px] text-sky-300 outline-none focus:border-sky-500" />
            </div>
        </div>

        <div class="grid gap-3.5 sm:grid-cols-2">
            <div>
                <div class="mb-1.5 text-[12.5px] font-semibold text-slate-300">Título do post</div>
                <input type="text" wire:model="title" placeholder="Ex: O LADO PODRE DO CRIME!"
                    class="w-full rounded-[9px] border border-slate-700 bg-slate-950 px-3 py-2.5 text-[13.5px] text-slate-100 outline-none focus:border-sky-500" />
            </div>
            <div>
                <div class="mb-1.5 text-[12.5px] font-semibold text-slate-300">Hashtags</div>
                <input type="text" wire:model="hashtags" placeholder="#shorts #podcast"
                    class="w-full rounded-[9px] border border-slate-700 bg-slate-950 px-3 py-2.5 font-mono text-[13px] text-sky-300 outline-none focus:border-sky-500" />
            </div>
        </div>

        <div class="flex items-center gap-3 border-t border-slate-800 pt-4">
            <button type="button" wire:click="save" wire:loading.attr="disabled"
                class="flex cursor-pointer items-center gap-2 rounded-[10px] bg-sky-400 px-5 py-3 text-sm font-bold text-slate-950 transition hover:bg-sky-300 disabled:opacity-60">
                <x-ui.icon name="check" class="size-3.5" />
                Salvar como pronto para postar
            </button>
            <span wire:loading wire:target="save" class="flex items-center gap-1.5 text-[13px] font-semibold text-slate-400">
                <x-ui.icon name="loading" class="size-3.5" /> enviando…
            </span>
        </div>
    </div>
</div>

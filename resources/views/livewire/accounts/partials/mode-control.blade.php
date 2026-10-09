<div class="flex flex-col gap-1.5">
    <div class="grid grid-cols-3 gap-1 rounded-[10px] border border-slate-800 bg-slate-950 p-1" role="group" aria-label="Modo da conta">
        @foreach ($modes as $mode)
            <button type="button" wire:click="setMode({{ $accountId }}, '{{ $mode['value'] }}')" aria-pressed="@json($mode['active'])" wire:key="mode-{{ $accountId }}-{{ $mode['value'] }}"
                @class([
                    'cursor-pointer rounded-[7px] px-2 py-1.5 text-[12.5px] font-semibold transition',
                    'bg-slate-800 text-slate-50' => $mode['active'],
                    'text-slate-400 hover:text-slate-200' => ! $mode['active'],
                ])>
                {{ $mode['label'] }}
            </button>
        @endforeach
    </div>
    <p class="text-xs text-slate-500">{{ $help }}</p>
</div>

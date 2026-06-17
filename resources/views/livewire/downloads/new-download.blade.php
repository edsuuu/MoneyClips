<div class="flex w-full flex-col gap-5">
    <div>
        <h2 class="text-lg font-semibold text-slate-50">Novo download</h2>
        <p class="text-sm text-slate-400">URL de um canal do YouTube para o microserviço baixar os Shorts.</p>
    </div>

    <x-studio.panel title="Baixar vídeos" subtitle="O Laravel apenas dispara; cada Short concluído chega via webhook e aparece na lista abaixo.">
        <form wire:submit="start" class="flex flex-col gap-4 md:flex-row md:items-end">
            <div class="flex-1">
                <x-ui.input
                    wire:model="channelUrl"
                    label="URL do canal"
                    type="url"
                    placeholder="https://www.youtube.com/@canal"
                    required
                />
            </div>
            <x-ui.button type="submit" variant="primary" icon="arrow-down-tray" class="cursor-pointer">
                <span wire:loading.remove wire:target="start">Disparar download</span>
                <span wire:loading wire:target="start">Enviando...</span>
            </x-ui.button>
        </form>
    </x-studio.panel>
</div>

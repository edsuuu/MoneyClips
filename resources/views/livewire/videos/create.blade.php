<section class="mx-auto flex w-full max-w-xl justify-center px-4 py-10">
    <form wire:submit="start" class="w-full rounded-3xl border border-slate-800 bg-slate-950/80 p-6 shadow-2xl shadow-black/20">
        <div class="space-y-4">
            <flux:input
                wire:model="url"
                label="URL do vídeo"
                type="url"
                placeholder="https://www.youtube.com/watch?v=..."
                required
            />

            <flux:button type="submit" variant="primary" icon="arrow-down-tray" class="w-full cursor-pointer">
                <span wire:loading.remove wire:target="start">Iniciar processamento</span>
                <span wire:loading wire:target="start">Enviando...</span>
            </flux:button>
        </div>
    </form>
</section>

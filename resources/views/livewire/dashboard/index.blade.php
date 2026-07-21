<div>
    <x-ui.heading size="xl">Dashboard</x-ui.heading>
    <x-ui.subheading>Resumo da sua conta.</x-ui.subheading>

    <div class="mt-8 grid gap-4 sm:grid-cols-2">
        <div class="rounded-2xl border border-slate-800 bg-slate-900/60 p-5">
            <div class="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">Vídeos enviados</div>
            <div class="mt-2 text-3xl font-bold text-slate-50">{{ $videoCount }}</div>
        </div>

        <div class="flex flex-col justify-between rounded-2xl border border-sky-500/30 bg-sky-500/5 p-5">
            <div>
                <div class="text-[11px] font-semibold uppercase tracking-[0.16em] text-sky-400">Começar</div>
                <div class="mt-2 text-sm text-slate-300">Envie um vídeo para transformar em clipes.</div>
            </div>

            <x-ui.button variant="primary" class="mt-4 w-full" :href="route('upload.index')" wire:navigate>
                Enviar vídeo
            </x-ui.button>
        </div>
    </div>
</div>

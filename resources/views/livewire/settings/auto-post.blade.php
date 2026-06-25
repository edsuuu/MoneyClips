<section class="w-full">
    <div class="relative mb-6 w-full">
        <x-ui.heading size="xl">{{ __('Settings') }}</x-ui.heading>
        <x-ui.subheading size="lg" class="mb-6">{{ __('Manage your profile and account settings') }}</x-ui.subheading>
        <x-ui.separator />
    </div>

    <div class="flex items-start max-md:flex-col">
        <div class="me-10 w-full pb-4 md:w-[220px]">
            <x-settings.nav />
        </div>

        <x-ui.separator class="md:hidden" />

        <div class="flex-1 self-stretch max-md:pt-6">
            <x-ui.heading>{{ __('Auto-postagem') }}</x-ui.heading>
            <x-ui.subheading>Habilite ou pause cada plataforma. As mudanças impactam o próximo disparo do cron imediatamente — sem precisar SSH no servidor.</x-ui.subheading>

            <div class="mt-6 grid max-w-2xl gap-4">
                {{-- YouTube --}}
                <div class="flex items-start justify-between gap-4 rounded-xl border border-slate-800 bg-slate-950/70 p-4">
                    <div>
                        <p class="text-sm font-medium text-slate-100">YouTube Shorts</p>
                        <p class="mt-1 text-xs text-slate-400">Upload síncrono via YouTube Data API. Requer conta vinculada com token válido.</p>
                    </div>
                    <button
                        type="button"
                        wire:click="toggleYoutube"
                        class="relative inline-flex h-6 w-11 cursor-pointer items-center rounded-full transition-colors {{ $youtubeEnabled ? 'bg-emerald-500' : 'bg-slate-700' }}"
                        aria-pressed="{{ $youtubeEnabled ? 'true' : 'false' }}"
                    >
                        <span class="sr-only">Toggle YouTube</span>
                        <span class="inline-block size-4 transform rounded-full bg-white shadow transition {{ $youtubeEnabled ? 'translate-x-6' : 'translate-x-1' }}"></span>
                    </button>
                </div>

                {{-- TikTok --}}
                <div class="flex items-start justify-between gap-4 rounded-xl border border-slate-800 bg-slate-950/70 p-4">
                    <div>
                        <p class="text-sm font-medium text-slate-100">TikTok</p>
                        <p class="mt-1 text-xs text-slate-400">Enfileira no microserviço uploader (Playwright headless). Requer cookie de sessão ativo.</p>
                    </div>
                    <button
                        type="button"
                        wire:click="toggleTiktok"
                        class="relative inline-flex h-6 w-11 cursor-pointer items-center rounded-full transition-colors {{ $tiktokEnabled ? 'bg-emerald-500' : 'bg-slate-700' }}"
                        aria-pressed="{{ $tiktokEnabled ? 'true' : 'false' }}"
                    >
                        <span class="sr-only">Toggle TikTok</span>
                        <span class="inline-block size-4 transform rounded-full bg-white shadow transition {{ $tiktokEnabled ? 'translate-x-6' : 'translate-x-1' }}"></span>
                    </button>
                </div>

                {{-- Auditoria --}}
                @if ($settings->updated_at)
                    <div class="rounded-xl border border-slate-800 bg-slate-950/40 p-4 text-xs text-slate-500">
                        Última alteração:
                        <span class="text-slate-300">{{ $settings->updated_at->timezone(config('app.timezone'))->format('d/m/Y H:i') }}</span>
                        @if ($settings->updatedBy)
                            por <span class="text-slate-300">{{ $settings->updatedBy->name }}</span>
                        @endif
                    </div>
                @endif
            </div>
        </div>
    </div>
</section>

<x-layout :title="__('Dashboard')" layout="sidebar">
    @php
        $stockTotal = \App\Models\YoutubeShort::query()->whereNotNull('video_path')->count();
        $stockAvailable = \App\Models\YoutubeShort::query()->availableToPost()->count();
        $postedYoutube = \App\Models\YoutubeShort::query()->whereNotNull('posted_youtube_at')->count();
        $postedTiktok = \App\Models\YoutubeShort::query()->whereNotNull('posted_tiktok_at')->count();
        $accountsCount = \App\Models\SocialAccount::query()->count();
        $todaysFirings = \App\Services\AutoPost\WindowSchedule::todaysFiringTimes();

        $recentShorts = \App\Models\YoutubeShort::query()
            ->whereNotNull('dispatched_at')
            ->latest('dispatched_at')
            ->limit(6)
            ->get();
    @endphp

    <section class="mx-auto flex w-full max-w-7xl flex-col gap-6">
        <x-studio.page-header
            eyebrow="Overview"
            title="Dashboard"
            subtitle="Estoque de Shorts, auto-postagem em YouTube + TikTok e estado dos microserviços."
        >
            <x-slot:actions>
                <x-ui.button :href="route('downloads.index')" variant="primary" icon="plus" class="cursor-pointer" wire:navigate>
                    Baixar canal
                </x-ui.button>
            </x-slot:actions>
        </x-studio.page-header>

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <x-studio.metric-card label="Estoque disponível" :value="$stockAvailable.' / '.$stockTotal" tone="blue" />
            <x-studio.metric-card label="Postados no YouTube" :value="$postedYoutube" tone="amber" />
            <x-studio.metric-card label="Postados no TikTok" :value="$postedTiktok" tone="green" />
            <x-studio.metric-card label="Contas conectadas" :value="$accountsCount" tone="slate" />
        </div>

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1.4fr)_360px]">
            <x-studio.panel title="Atalhos operacionais" subtitle="Acesse direto os pontos principais do fluxo.">
                <div class="grid gap-3 md:grid-cols-2">
                    <a href="{{ route('shorts.index') }}" wire:navigate class="rounded-xl border border-slate-800 bg-slate-950/70 p-4 transition hover:border-slate-700 hover:bg-slate-900">
                        <p class="text-sm font-medium text-slate-100">Estoque de Shorts</p>
                        <p class="mt-1 text-sm text-slate-400">Métricas, filtros e postagem manual do estoque.</p>
                    </a>
                    <a href="{{ route('downloads.index') }}" wire:navigate class="rounded-xl border border-slate-800 bg-slate-950/70 p-4 transition hover:border-slate-700 hover:bg-slate-900">
                        <p class="text-sm font-medium text-slate-100">Downloads</p>
                        <p class="mt-1 text-sm text-slate-400">Baixar Shorts de canais novos via microserviço.</p>
                    </a>
                    <a href="{{ route('social-accounts') }}" wire:navigate class="rounded-xl border border-slate-800 bg-slate-950/70 p-4 transition hover:border-slate-700 hover:bg-slate-900">
                        <p class="text-sm font-medium text-slate-100">Contas vinculadas</p>
                        <p class="mt-1 text-sm text-slate-400">Gerencie o canal YouTube conectado por Google OAuth.</p>
                    </a>
                    <a href="{{ route('microservices.index') }}" wire:navigate class="rounded-xl border border-slate-800 bg-slate-950/70 p-4 transition hover:border-slate-700 hover:bg-slate-900">
                        <p class="text-sm font-medium text-slate-100">Microserviços</p>
                        <p class="mt-1 text-sm text-slate-400">Saúde do download-shorts e tiktok-uploader + logs.</p>
                    </a>
                </div>
            </x-studio.panel>

            <x-studio.panel title="Auto-postagem hoje" subtitle="Janelas sorteadas no fuso São Paulo.">
                <div class="space-y-3">
                    @forelse($todaysFirings as $index => $time)
                        <div class="rounded-xl border border-slate-800 bg-slate-950/70 p-4">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">Janela {{ $index + 1 }}</p>
                            <p class="mt-2 text-lg font-medium tabular-nums text-slate-100">{{ $time }}</p>
                        </div>
                    @empty
                        <div class="rounded-xl border border-slate-800 bg-slate-950/70 p-4 text-sm text-slate-500">
                            Sem janelas configuradas para hoje.
                        </div>
                    @endforelse
                </div>
            </x-studio.panel>
        </div>

        <x-studio.panel title="Últimas postagens" subtitle="Shorts despachados pela auto-postagem.">
            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                @forelse($recentShorts as $short)
                    <div class="rounded-xl border border-slate-800 bg-slate-950/70 p-4">
                        <p class="line-clamp-2 text-sm font-medium text-slate-100">{{ $short->title ?? $short->youtube_id }}</p>
                        <div class="mt-3 flex items-center justify-between gap-2">
                            <div class="flex gap-1.5">
                                @if ($short->posted_youtube_at)
                                    <x-ui.badge size="sm" tone="green">YT ✓</x-ui.badge>
                                @endif
                                @if ($short->posted_tiktok_at)
                                    <x-ui.badge size="sm" tone="green">TT ✓</x-ui.badge>
                                @endif
                            </div>
                            <span class="text-xs tabular-nums text-slate-500">{{ $short->dispatched_at?->format('d/m H:i') }}</span>
                        </div>
                    </div>
                @empty
                    <div class="md:col-span-2 xl:col-span-3 rounded-xl border border-slate-800 bg-slate-950/70 p-6 text-center text-slate-500">
                        Nenhuma postagem ainda.
                    </div>
                @endforelse
            </div>
        </x-studio.panel>
    </section>
</x-layout>

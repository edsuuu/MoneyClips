<x-layout :title="__('Dashboard')" layout="sidebar">
    @php
        $videoCount = \App\Models\Video::query()->count();
        $processingCount = \App\Models\Video::query()->whereHas('status', fn ($query) => $query->whereNotIn('key', ['completed']))->count();
        $publishedCount = \App\Models\ScheduledPost::query()->where('status', 'posted')->count();
        $accountsCount = \App\Models\SocialAccount::query()->count();

        $recentVideos = \App\Models\Video::query()
            ->with('status')
            ->latest()
            ->limit(4)
            ->get();
    @endphp

    <section class="mx-auto flex w-full max-w-7xl flex-col gap-6">
        <x-studio.page-header
            eyebrow="Overview"
            title="Dashboard"
            subtitle="Visão consolidada do pipeline de ingestão, edição e distribuição social."
        >
            <x-slot:actions>
                <flux:button :href="route('videos.create')" variant="primary" icon="plus" class="cursor-pointer" wire:navigate>
                    Novo vídeo
                </flux:button>
            </x-slot:actions>
        </x-studio.page-header>

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <x-studio.metric-card label="Vídeos" :value="$videoCount" tone="blue" />
            <x-studio.metric-card label="Em processamento" :value="$processingCount" tone="amber" />
            <x-studio.metric-card label="Posts publicados" :value="$publishedCount" tone="green" />
            <x-studio.metric-card label="Contas conectadas" :value="$accountsCount" tone="slate" />
        </div>

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1.4fr)_360px]">
            <x-studio.panel title="Atalhos operacionais" subtitle="Acesse direto os pontos principais do fluxo.">
                <div class="grid gap-3 md:grid-cols-2">
                    <a href="{{ route('videos.index') }}" wire:navigate class="rounded-xl border border-slate-800 bg-slate-950/70 p-4 transition hover:border-slate-700 hover:bg-slate-900">
                        <p class="text-sm font-medium text-slate-100">Biblioteca de vídeos</p>
                        <p class="mt-1 text-sm text-slate-400">Abra qualquer vídeo já processado e retome o editor.</p>
                    </a>
                    <a href="{{ route('posts.dashboard') }}" wire:navigate class="rounded-xl border border-slate-800 bg-slate-950/70 p-4 transition hover:border-slate-700 hover:bg-slate-900">
                        <p class="text-sm font-medium text-slate-100">Monitoramento de posts</p>
                        <p class="mt-1 text-sm text-slate-400">Acompanhe falhas, retries e histórico de publicações.</p>
                    </a>
                    <a href="{{ route('social-accounts') }}" wire:navigate class="rounded-xl border border-slate-800 bg-slate-950/70 p-4 transition hover:border-slate-700 hover:bg-slate-900">
                        <p class="text-sm font-medium text-slate-100">Contas sociais</p>
                        <p class="mt-1 text-sm text-slate-400">Gerencie credenciais OAuth e ativações por plataforma.</p>
                    </a>
                    <a href="{{ route('videos.create') }}" wire:navigate class="rounded-xl border border-slate-800 bg-slate-950/70 p-4 transition hover:border-slate-700 hover:bg-slate-900">
                        <p class="text-sm font-medium text-slate-100">Nova ingestão</p>
                        <p class="mt-1 text-sm text-slate-400">Inicie um vídeo novo com fluxo manual ou automático.</p>
                    </a>
                </div>
            </x-studio.panel>

            <x-studio.panel title="Estado do workspace" subtitle="Leitura rápida dos módulos conectados.">
                <div class="space-y-3">
                    <div class="rounded-xl border border-slate-800 bg-slate-950/70 p-4">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">Processamento</p>
                        <p class="mt-2 text-sm text-slate-200">{{ $processingCount }} vídeo(s) aguardando etapas finais.</p>
                    </div>
                    <div class="rounded-xl border border-slate-800 bg-slate-950/70 p-4">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">Distribuição</p>
                        <p class="mt-2 text-sm text-slate-200">{{ $publishedCount }} publicação(ões) concluídas até agora.</p>
                    </div>
                    <div class="rounded-xl border border-slate-800 bg-slate-950/70 p-4">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">Credenciais</p>
                        <p class="mt-2 text-sm text-slate-200">{{ $accountsCount }} conta(s) social(is) disponível(is) para uso.</p>
                    </div>
                </div>
            </x-studio.panel>
        </div>

        <x-studio.panel title="Últimos vídeos" subtitle="Retomada rápida para revisão, cortes ou agendamento.">
            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                @forelse($recentVideos as $video)
                    <a href="{{ route('videos.editor', $video) }}" wire:navigate class="rounded-xl border border-slate-800 bg-slate-950/70 p-4 transition hover:border-slate-700 hover:bg-slate-900">
                        <p class="line-clamp-2 text-sm font-medium text-slate-100">{{ $video->title ?? $video->url }}</p>
                        <div class="mt-3 flex items-center justify-between gap-2">
                            <flux:badge size="sm">{{ $video->status?->label ?? '—' }}</flux:badge>
                            <span class="text-xs tabular-nums text-slate-500">{{ $video->created_at?->format('d/m H:i') }}</span>
                        </div>
                    </a>
                @empty
                    <div class="md:col-span-2 xl:col-span-4 rounded-xl border border-slate-800 bg-slate-950/70 p-6 text-center text-slate-500">
                        Nenhum vídeo encontrado ainda.
                    </div>
                @endforelse
            </div>
        </x-studio.panel>
    </section>
</x-layout>

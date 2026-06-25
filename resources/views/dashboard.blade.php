<x-layout :title="__('Dashboard')" layout="sidebar">
    @php
        $stockTotal = \App\Models\YoutubeShort::query()->whereNotNull('video_path')->count();
        $stockAvailable = \App\Models\YoutubeShort::query()->availableToPost()->count();
        $postedYoutube = \App\Models\YoutubeShort::query()->whereNotNull('posted_youtube_at')->count();
        $postedTiktok = \App\Models\YoutubeShort::query()->whereNotNull('posted_tiktok_at')->count();
        $accountsCount = \App\Models\SocialAccount::query()->count();
    @endphp

    <section class="mx-auto flex w-full max-w-7xl flex-col gap-6">
        <x-studio.page-header
            eyebrow="Overview"
            title="Dashboard"
            subtitle="Estoque de Shorts e auto-postagem em YouTube + TikTok."
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
    </section>
</x-layout>

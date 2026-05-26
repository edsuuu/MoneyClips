<x-layout :title="__('Home')" layout="navbar">
    <div class="mx-auto flex w-full max-w-6xl flex-col gap-8 px-4 py-8 sm:px-6 lg:px-8">
        <section class="rounded-lg border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Generate Clips</p>
            <h1 class="mt-2 text-2xl font-semibold text-zinc-900 dark:text-zinc-100 sm:text-3xl">Transforme vídeos longos em clipes prontos para publicação</h1>
            <p class="mt-3 max-w-2xl text-sm text-zinc-600 dark:text-zinc-300">
                Faça upload, acompanhe a transcrição e ajuste cortes no editor em um fluxo único.
            </p>

            <div class="mt-6 flex flex-wrap gap-3">
                <flux:button variant="primary" :href="route('videos.create')" icon="video-camera" wire:navigate>
                    Novo Vídeo
                </flux:button>

                @auth
                    <flux:button variant="subtle" :href="route('dashboard')" icon="layout-grid" wire:navigate>
                        Dashboard
                    </flux:button>
                @else
                    <flux:button variant="subtle" :href="route('login')" icon="arrow-right-end-on-rectangle" wire:navigate>
                        Entrar
                    </flux:button>
                @endauth
            </div>
        </section>

        <section class="grid gap-4 md:grid-cols-3">
            <article class="rounded-lg border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Passo 1</p>
                <h2 class="mt-2 text-base font-semibold text-zinc-900 dark:text-zinc-100">Ingestão</h2>
                <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-300">Envie o vídeo, configure idioma e inicie o processamento.</p>
            </article>

            <article class="rounded-lg border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Passo 2</p>
                <h2 class="mt-2 text-base font-semibold text-zinc-900 dark:text-zinc-100">Transcrição</h2>
                <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-300">Revise a transcrição gerada e valide o conteúdo detectado.</p>
            </article>

            <article class="rounded-lg border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Passo 3</p>
                <h2 class="mt-2 text-base font-semibold text-zinc-900 dark:text-zinc-100">Editor</h2>
                <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-300">Aplique cortes recomendados e ajuste legendas antes do render.</p>
            </article>
        </section>
    </div>
</x-layout>

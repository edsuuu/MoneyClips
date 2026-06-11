<x-layout :title="__('Publicações do vídeo')" layout="sidebar">
    <x-videos.tabs :video="$video" />
    <section class="mx-auto flex w-full max-w-7xl flex-col gap-6 pt-6">
        <x-studio.page-header
            title="Publicações deste vídeo"
            subtitle="Acompanhe o que já foi agendado, publicado ou ainda está em processamento para este vídeo."
        >
            <x-slot:meta>
                <x-ui.badge>{{ $video->title ?? 'Vídeo' }}</x-ui.badge>
            </x-slot:meta>
        </x-studio.page-header>

        <x-studio.panel>
            <div class="flex flex-wrap items-center gap-3">
                <x-ui.button :href="route('videos.schedule', $video)" variant="subtle" class="cursor-pointer" wire:navigate>
                    Voltar ao agendamento
                </x-ui.button>
                <x-ui.button :href="route('posts.dashboard')" variant="ghost" class="cursor-pointer" wire:navigate>
                    Ver dashboard geral
                </x-ui.button>
            </div>

            @if($posts->isEmpty())
                <div class="mt-6 rounded-xl border border-dashed border-slate-800 bg-slate-950/50 px-6 py-10 text-center text-sm text-slate-400">
                    Nenhuma publicação encontrada para este vídeo ainda.
                </div>
            @else
                <div class="mt-4 overflow-x-auto">
                    <table class="studio-table w-full text-sm">
                        <thead>
                            <tr>
                                <th class="py-2 pr-3">#</th>
                                <th class="py-2 pr-3">Corte</th>
                                <th class="py-2 pr-3">Plataforma</th>
                                <th class="py-2 pr-3">Conta</th>
                                <th class="py-2 pr-3">Título</th>
                                <th class="py-2 pr-3">Horário</th>
                                <th class="py-2 pr-3">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($posts as $post)
                                <tr>
                                    <td class="py-2 pr-3 tabular-nums">{{ $post->sequence }}</td>
                                    <td class="py-2 pr-3">{{ $post->cut?->name ?? '—' }}</td>
                                    <td class="py-2 pr-3">{{ $platformLabels[$post->platform] ?? ucfirst($post->platform) }}</td>
                                    <td class="py-2 pr-3">{{ $post->account?->name ?? '—' }}</td>
                                    <td class="py-2 pr-3 max-w-xs truncate">{{ $post->title ?? '—' }}</td>
                                    <td class="py-2 pr-3 tabular-nums">{{ $post->scheduled_for?->format('d/m H:i') }}</td>
                                    <td class="py-2 pr-3">
                                        @include('livewire.videos._post-status', ['status' => $post->status])
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="mt-4">
                    {{ $posts->links() }}
                </div>
            @endif
        </x-studio.panel>
    </section>
</x-layout>

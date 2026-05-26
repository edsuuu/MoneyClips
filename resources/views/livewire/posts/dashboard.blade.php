<section class="mx-auto flex w-full max-w-7xl flex-col gap-6">
    <x-studio.page-header
        eyebrow="Monitoramento"
        title="Dashboard de Publicações"
        subtitle="Acompanhe o que foi postado, o que falhou e os logs de cada envio."
    >
        <x-slot:actions>
            <flux:button :href="route('social-accounts')" size="sm" variant="subtle" icon="user-circle" class="cursor-pointer" wire:navigate>
                Contas sociais
            </flux:button>
        </x-slot:actions>
    </x-studio.page-header>

    {{-- Cartões de status --}}
    <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-3">
        @php
            $cards = [
                'total'      => ['Total', 'zinc'],
                'scheduled'  => ['Agendados', 'blue'],
                'publishing' => ['Publicando', 'amber'],
                'posted'     => ['Postados', 'green'],
                'failed'     => ['Falhas', 'red'],
                'pending'    => ['Pendentes', 'zinc'],
                'cancelled'  => ['Cancelados', 'zinc'],
            ];
        @endphp
        @foreach($cards as $key => [$label, $color])
            <x-studio.metric-card :label="$label" :value="$counts[$key] ?? 0" :tone="$color" />
        @endforeach
    </div>

    {{-- Filtros --}}
    <x-studio.panel title="Filtros" subtitle="Refine a lista por status operacional e plataforma.">
    <div class="flex flex-wrap items-end gap-3">
        <div>
            <flux:text class="text-xs text-slate-500">Status</flux:text>
            <select wire:model.live="statusFilter"
                    class="cursor-pointer rounded-lg border border-slate-800 bg-slate-900 px-2 py-1.5 text-sm text-slate-100">
                <option value="">Todos</option>
                <option value="scheduled">Agendado</option>
                <option value="publishing">Publicando</option>
                <option value="posted">Postado</option>
                <option value="failed">Falhou</option>
                <option value="pending">Pendente</option>
                <option value="cancelled">Cancelado</option>
            </select>
        </div>
        <div>
            <flux:text class="text-xs text-slate-500">Plataforma</flux:text>
            <select wire:model.live="platformFilter"
                    class="cursor-pointer rounded-lg border border-slate-800 bg-slate-900 px-2 py-1.5 text-sm text-slate-100">
                <option value="">Todas</option>
                @foreach($platformLabels as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
    </div>
    </x-studio.panel>

    {{-- Tabela --}}
    <x-studio.panel title="Fila e histórico">
        <div class="overflow-x-auto">
            <table class="studio-table w-full text-sm">
                <thead>
                    <tr>
                        <th class="py-2 px-3">Vídeo / Corte</th>
                        <th class="py-2 px-3">Plataforma</th>
                        <th class="py-2 px-3">Conta</th>
                        <th class="py-2 px-3">Seq</th>
                        <th class="py-2 px-3">Agendado</th>
                        <th class="py-2 px-3">Status</th>
                        <th class="py-2 px-3">Resultado</th>
                        <th class="py-2 px-3"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($posts as $post)
                        <tr class="align-top">
                            <td class="py-2 px-3">
                                <div class="max-w-[16rem] truncate font-medium">{{ $post->title ?? $post->video?->title ?? $post->video?->url }}</div>
                                <div class="text-xs text-slate-500">{{ $post->cut?->name ?? '—' }}</div>
                            </td>
                            <td class="py-2 px-3">{{ $platformLabels[$post->platform] ?? ucfirst($post->platform) }}</td>
                            <td class="py-2 px-3">{{ $post->account?->name ?? '—' }}</td>
                            <td class="py-2 px-3 tabular-nums">{{ $post->sequence }}</td>
                            <td class="py-2 px-3 tabular-nums whitespace-nowrap">{{ $post->scheduled_for?->format('d/m/Y H:i') }}</td>
                            <td class="py-2 px-3">
                                @include('livewire.videos._post-status', ['status' => $post->status])
                                @if($post->attempts > 0)
                                    <div class="mt-1 text-[10px] text-slate-500">{{ $post->attempts }} tentativa(s)</div>
                                @endif
                            </td>
                            <td class="py-2 px-3 max-w-[18rem]">
                                @if($post->isPosted() && $post->external_url)
                                    <a href="{{ $post->external_url }}" target="_blank" class="text-cyan-400 underline">Ver post</a>
                                @elseif($post->error_message)
                                    <span class="text-xs break-words text-red-400">{{ \Illuminate\Support\Str::limit($post->error_message, 140) }}</span>
                                @else
                                    <span class="text-slate-500">—</span>
                                @endif
                            </td>
                            <td class="py-2 px-3 whitespace-nowrap">
                                <div class="flex items-center gap-1">
                                    <flux:button size="xs" variant="ghost" wire:click="toggleLogs({{ $post->id }})">Logs</flux:button>
                                    @if(in_array($post->status, ['failed','scheduled','pending','cancelled'], true))
                                        <flux:button size="xs" variant="ghost" wire:click="retry({{ $post->id }})"
                                                     wire:confirm="Reenviar este post para publicação agora?">Reenviar</flux:button>
                                    @endif
                                    @if(! $post->isPosted() && $post->status !== 'cancelled')
                                        <flux:button size="xs" variant="ghost" wire:click="cancel({{ $post->id }})"
                                                     wire:confirm="Cancelar este agendamento?">Cancelar</flux:button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                        @if($openLogsFor === $post->id)
                            <tr class="bg-slate-950/80">
                                <td colspan="8" class="px-3 py-3">
                                    <div class="mb-2 text-xs font-medium text-slate-500">Logs do post</div>
                                    @forelse($post->logs as $log)
                                        <div class="flex items-start gap-2 border-b border-slate-900 py-1 last:border-0">
                                            <span class="w-32 shrink-0 tabular-nums text-slate-400">{{ $log->created_at?->format('d/m H:i:s') }}</span>
                                            @php $lc = ['info'=>'text-slate-300','warning'=>'text-amber-400','error'=>'text-red-400'][$log->level] ?? '' @endphp
                                            <span class="uppercase text-[10px] font-bold w-14 shrink-0 {{ $lc }}">{{ $log->level }}</span>
                                            <span class="{{ $lc }} break-words">{{ $log->message }}</span>
                                        </div>
                                    @empty
                                        <div class="text-slate-400">Sem logs ainda.</div>
                                    @endforelse
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr><td colspan="8" class="py-8 text-center text-slate-500">Nenhuma publicação encontrada.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-studio.panel>

    <div>{{ $posts->links() }}</div>
</section>

<section class="w-full">
    <div class="relative mb-6 w-full">
        <div class="flex items-start justify-between gap-4">
            <div>
                <x-ui.heading size="xl" level="1">Contas TikTok</x-ui.heading>
                <x-ui.subheading size="lg">Credenciais de login usadas para publicar. O YouTube fica em Configurações › Contas vinculadas.</x-ui.subheading>
            </div>
            @unless($showForm)
                <x-ui.button wire:click="create" variant="primary" icon="plus" class="shrink-0">
                    Nova conta
                </x-ui.button>
            @endunless
        </div>
        <x-ui.separator variant="subtle" class="mt-4" />
    </div>

    @if($showForm)
        <form wire:submit="save" class="mb-6 grid gap-4 rounded-2xl border border-slate-800 bg-slate-950/70 p-5 shadow-sm md:grid-cols-2">
            <x-ui.input wire:model="name" label="Nome / @handle" placeholder="clipsd211" />
            <div class="flex items-end pb-2">
                <label class="inline-flex cursor-pointer items-center gap-2 text-sm text-slate-300">
                    <input type="checkbox" wire:model="is_active" class="rounded border-slate-600 bg-slate-900 text-emerald-500 focus:ring-emerald-500/40" />
                    Ativa
                </label>
            </div>
            <x-ui.input wire:model="login_email" type="email" label="Email" placeholder="conta@email.com" />
            <x-ui.input wire:model="login_password" :viewable="true" label="Senha" />

            <div class="flex flex-wrap gap-2 md:col-span-2">
                <x-ui.button type="submit" variant="primary" icon="check" wire:loading.attr="disabled" wire:target="save">
                    <span wire:loading.remove wire:target="save">Salvar</span>
                    <span wire:loading wire:target="save">Salvando…</span>
                </x-ui.button>
                <x-ui.button type="button" variant="ghost" wire:click="cancel">Cancelar</x-ui.button>
            </div>
        </form>
    @endif

    <div class="space-y-4">
        @forelse($accounts as $account)
            @php
                $statusColor = match ($account->session_status) {
                    \App\Models\SocialAccount::SESSION_VALID => 'green',
                    \App\Models\SocialAccount::SESSION_INVALID => 'red',
                    default => 'zinc',
                };
                $statusLabel = match ($account->session_status) {
                    \App\Models\SocialAccount::SESSION_VALID => 'Sessão válida',
                    \App\Models\SocialAccount::SESSION_INVALID => 'Sessão inválida',
                    default => 'Sessão desconhecida',
                };
            @endphp
            <div class="rounded-2xl border border-slate-800 bg-slate-950/70 p-4 shadow-sm sm:p-5">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div class="flex items-start gap-4">
                        <div class="flex h-12 w-12 items-center justify-center rounded-2xl border border-slate-800 bg-slate-900 text-xs font-semibold tracking-[0.18em] text-slate-300">
                            TT
                        </div>
                        <div class="space-y-2">
                            <div>
                                <div class="text-base font-semibold text-slate-100">{{ $account->name }}</div>
                                <div class="text-sm text-slate-400">{{ $account->login_email ?: 'sem email' }}</div>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <x-ui.badge :color="$statusColor" size="sm">{{ $statusLabel }}</x-ui.badge>
                                @unless($account->is_active)
                                    <x-ui.badge color="amber" size="sm">Inativa</x-ui.badge>
                                @endunless
                            </div>
                        </div>
                    </div>

                    <div class="flex shrink-0 gap-2">
                        <x-ui.button wire:click="edit({{ $account->id }})" variant="filled" size="sm" icon="pencil-square">Editar</x-ui.button>
                        <x-ui.button
                            wire:click="delete({{ $account->id }})"
                            wire:confirm="Remover esta conta?"
                            variant="danger"
                            size="sm"
                            icon="x-mark"
                        >Remover</x-ui.button>
                    </div>
                </div>
            </div>
        @empty
            <div class="rounded-2xl border border-dashed border-slate-800 bg-slate-950/40 p-8 text-center text-sm text-slate-400">
                Nenhuma conta TikTok cadastrada. Clique em <span class="text-slate-200">Nova conta</span> para adicionar.
            </div>
        @endforelse
    </div>
</section>

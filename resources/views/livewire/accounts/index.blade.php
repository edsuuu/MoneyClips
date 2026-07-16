<div class="w-full">
    {{-- Header (design docs/designs/Contas.dc.html) --}}
    <div class="mb-6 flex flex-wrap items-start justify-between gap-6 border-b border-slate-800 pb-6">
        <div>
            <h1 class="text-3xl font-extrabold tracking-tight text-slate-50">Contas</h1>
            <p class="mt-2 text-sm text-slate-500">Contas usadas para publicar. O TikTok entra por email/senha; o YouTube é vinculado via Google.</p>
        </div>
        <div class="flex flex-wrap gap-2.5">
            <button type="button" wire:click="createTiktok"
                class="flex cursor-pointer items-center gap-2 rounded-[10px] border border-slate-700 bg-slate-800 px-4 py-2.5 text-[13.5px] font-semibold text-slate-100 transition hover:bg-slate-700">
                <x-ui.icon name="plus" class="size-3.5" />
                TikTok
            </button>
            <button type="button" wire:click="openYoutube"
                class="flex cursor-pointer items-center gap-2 rounded-[10px] border border-slate-700 bg-slate-800 px-4 py-2.5 text-[13.5px] font-semibold text-slate-100 transition hover:bg-slate-700">
                <x-ui.icon name="plus" class="size-3.5" />
                YouTube
            </button>
        </div>
    </div>

    @if(session('status'))
        <x-ui.callout class="mb-4" variant="success" icon="check-circle">{{ session('status') }}</x-ui.callout>
    @endif
    @if(session('error'))
        <x-ui.callout class="mb-4" variant="danger" icon="exclamation-triangle">{{ session('error') }}</x-ui.callout>
    @endif

    {{-- Grid de contas --}}
    <div class="grid gap-3.5 md:grid-cols-2 xl:grid-cols-3">
        {{-- YouTube (OAuth) --}}
        @if($youtubeAccount)
            <div class="flex flex-col gap-4 rounded-[14px] border border-slate-800 bg-slate-900 p-5">
                <div class="flex items-center gap-3">
                    <div class="flex size-10 shrink-0 items-center justify-center rounded-[11px] bg-red-500/20 text-sm font-extrabold text-red-400">YT</div>
                    <div class="min-w-0 flex-1">
                        <div class="text-[15px] font-bold">YouTube</div>
                        <div class="truncate font-mono text-xs text-slate-500">{{ $youtubeAccount->name }}</div>
                    </div>
                    <div @class([
                        'flex shrink-0 items-center gap-1.5 text-xs font-semibold',
                        'text-amber-400' => $youtubeStatus['expired'],
                        'text-emerald-400' => ! $youtubeStatus['expired'],
                    ])>
                        <span @class([
                            'size-[7px] rounded-full',
                            'bg-amber-400' => $youtubeStatus['expired'],
                            'bg-emerald-400' => ! $youtubeStatus['expired'],
                        ])></span>
                        {{ $youtubeStatus['label'] }}
                    </div>
                </div>

                <div class="flex items-center justify-between border-t border-slate-800 pt-3.5">
                    <span class="text-[13px] text-slate-400">Ativa para publicação</span>
                    <div class="flex items-center gap-3">
                        <x-ui.toggle :active="$youtubeAccount->is_active" wire:click="toggleActive({{ $youtubeAccount->id }})" title="Ativar/desativar conta" />
                        <button type="button" wire:click="openYoutube" title="Gerenciar vinculação"
                            class="flex size-[30px] cursor-pointer items-center justify-center rounded-lg text-slate-500 transition hover:bg-sky-950/60 hover:text-sky-400">
                            <x-ui.icon name="cog" class="size-4" />
                        </button>
                        <button type="button" wire:click="delete({{ $youtubeAccount->id }})" wire:confirm="Desvincular o canal do YouTube?" title="Desvincular conta"
                            class="flex size-[30px] cursor-pointer items-center justify-center rounded-lg text-slate-500 transition hover:bg-red-950/60 hover:text-red-400">
                            <x-ui.icon name="x-mark" class="size-4" />
                        </button>
                    </div>
                </div>
            </div>
        @endif

        {{-- TikTok (email/senha) --}}
        @foreach($tiktokAccounts as $account)
            <div class="flex flex-col gap-4 rounded-[14px] border border-slate-800 bg-slate-900 p-5" wire:key="account-{{ $account['id'] }}">
                <div class="flex items-center gap-3">
                    <div class="flex size-10 shrink-0 items-center justify-center rounded-[11px] bg-slate-700/60 text-sm font-extrabold text-slate-200">TT</div>
                    <div class="min-w-0 flex-1">
                        <div class="text-[15px] font-bold">TikTok</div>
                        <div class="truncate font-mono text-xs text-slate-500">{{ $account['subtitle'] }}</div>
                    </div>
                    <div @class([
                        'flex shrink-0 items-center gap-1.5 text-xs font-semibold',
                        'text-emerald-400' => $account['statusColor'] === 'green',
                        'text-red-400' => $account['statusColor'] === 'red',
                        'text-slate-500' => ! in_array($account['statusColor'], ['green', 'red'], true),
                    ])>
                        <span @class([
                            'size-[7px] rounded-full',
                            'bg-emerald-400' => $account['statusColor'] === 'green',
                            'bg-red-400' => $account['statusColor'] === 'red',
                            'bg-slate-600' => ! in_array($account['statusColor'], ['green', 'red'], true),
                        ])></span>
                        {{ $account['statusLabel'] }}
                    </div>
                </div>

                <div class="flex items-center justify-between border-t border-slate-800 pt-3.5">
                    <span class="text-[13px] text-slate-400">Ativa para publicação</span>
                    <div class="flex items-center gap-3">
                        <x-ui.toggle :active="$account['is_active']" wire:click="toggleActive({{ $account['id'] }})" title="Ativar/desativar conta" />
                        <button type="button" wire:click="editTiktok({{ $account['id'] }})" title="Editar credenciais"
                            class="flex size-[30px] cursor-pointer items-center justify-center rounded-lg text-slate-500 transition hover:bg-sky-950/60 hover:text-sky-400">
                            <x-ui.icon name="pencil-square" class="size-4" />
                        </button>
                        <button type="button" wire:click="delete({{ $account['id'] }})" wire:confirm="Remover esta conta?" title="Remover conta"
                            class="flex size-[30px] cursor-pointer items-center justify-center rounded-lg text-slate-500 transition hover:bg-red-950/60 hover:text-red-400">
                            <x-ui.icon name="x-mark" class="size-4" />
                        </button>
                    </div>
                </div>
            </div>
        @endforeach

        @if($tiktokAccounts->isEmpty() && ! $youtubeAccount)
            <div class="rounded-2xl border border-dashed border-slate-800 p-8 text-center text-sm text-slate-500 md:col-span-2 xl:col-span-3">
                Nenhuma conta cadastrada. Use os botões <span class="text-slate-200">TikTok</span> ou <span class="text-slate-200">YouTube</span> acima para adicionar.
            </div>
        @endif
    </div>

    {{-- Modal TikTok: email + senha --}}
    <x-ui.modal wire:model="showTiktokModal" max-width="max-w-lg">
        <form wire:submit="saveTiktok" class="space-y-5">
            <div>
                <h2 class="text-lg font-extrabold text-slate-50">{{ $tiktokModalTitle }}</h2>
                <p class="mt-1 text-sm text-slate-400">Credenciais de login usadas para publicar no TikTok.</p>
            </div>

            <div class="grid gap-4">
                <x-ui.input wire:model="name" label="Nome / @@handle" placeholder="@clipsd211" />
                <x-ui.input wire:model="login_email" type="email" label="Email" placeholder="conta@email.com" />
                <x-ui.input wire:model="login_password" :viewable="true" label="Senha" />
                <x-ui.checkbox wire:model="is_active" label="Conta ativa" />
            </div>

            <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <x-ui.button type="button" variant="filled" wire:click="cancel">Cancelar</x-ui.button>
                <x-ui.button type="submit" variant="primary" icon="check" wire:loading.attr="disabled" wire:target="saveTiktok">
                    <span wire:loading.remove wire:target="saveTiktok">Salvar</span>
                    <span wire:loading wire:target="saveTiktok">Salvando…</span>
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    {{-- Modal YouTube: vinculação manual via OAuth do Google --}}
    <x-ui.modal wire:model="showYoutubeModal" max-width="max-w-lg">
        <div class="space-y-5">
            <div>
                <h2 class="text-lg font-extrabold text-slate-50">Conta YouTube</h2>
                <p class="mt-1 text-sm text-slate-400">O YouTube não usa email/senha aqui. A vinculação é manual: conecte com sua conta Google para autorizar a publicação.</p>
            </div>

            @if($youtubeAccount)
                <div class="rounded-xl border border-slate-800 bg-slate-950/70 p-4">
                    <div class="text-sm font-medium text-slate-100">{{ $youtubeAccount->name }}</div>
                    @if($youtubeAccount->external_account_id)
                        <a
                            class="text-xs text-slate-400 underline decoration-slate-700 underline-offset-4 hover:text-slate-200"
                            href="https://www.youtube.com/channel/{{ $youtubeAccount->external_account_id }}"
                            target="_blank"
                            rel="noopener noreferrer"
                        >Ver canal</a>
                    @endif
                    <div class="mt-2">
                        <x-ui.badge :color="$youtubeStatus['expired'] ? 'amber' : 'green'" size="sm">
                            {{ $youtubeStatus['expired'] ? 'Token expirado — revincule' : 'Vinculado' }}
                        </x-ui.badge>
                    </div>
                </div>
            @else
                <div class="rounded-xl border border-dashed border-slate-700 p-6 text-center text-sm text-slate-500">
                    Nenhum canal do YouTube vinculado ainda.
                </div>
            @endif

            @unless($googleOAuthReady)
                <x-ui.callout variant="warning" icon="exclamation-triangle">
                    Configure o app do Google (client_id/secret) no <span class="font-mono">.env</span> antes de vincular.
                </x-ui.callout>
            @endunless

            <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <x-ui.button type="button" variant="filled" wire:click="cancel">Fechar</x-ui.button>

                @if($youtubeAccount)
                    <x-ui.button
                        wire:click="delete({{ $youtubeAccount->id }})"
                        wire:confirm="Desvincular o canal do YouTube?"
                        variant="danger"
                        icon="x-mark"
                    >Desvincular</x-ui.button>
                @endif

                @if($googleOAuthReady)
                    <x-ui.button
                        :href="route('oauth.connect', ['platform' => 'youtube'])"
                        variant="primary"
                        icon="arrow-top-right-on-square"
                    >{{ $youtubeAccount ? 'Revincular com Google' : 'Vincular com Google' }}</x-ui.button>
                @endif
            </div>
        </div>
    </x-ui.modal>
</div>

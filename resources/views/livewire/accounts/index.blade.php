<div class="w-full">
    <div class="relative mb-6 w-full">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <x-ui.heading size="xl" level="1">Contas</x-ui.heading>
                <x-ui.subheading size="lg">Contas usadas para publicar. O TikTok entra por email/senha; o YouTube é vinculado via Google.</x-ui.subheading>
            </div>

            {{-- Um botão por plataforma (pronto pra crescer: Instagram, X…). --}}
            <div class="flex flex-wrap gap-2 sm:shrink-0">
                <x-ui.button wire:click="createTiktok" variant="filled" icon="plus">TikTok</x-ui.button>
                <x-ui.button wire:click="openYoutube" variant="filled" icon="plus">YouTube</x-ui.button>
            </div>
        </div>
        <x-ui.separator variant="subtle" class="mt-4" />
    </div>

    @if(session('status'))
        <x-ui.callout class="mb-4" variant="success" icon="check-circle">{{ session('status') }}</x-ui.callout>
    @endif
    @if(session('error'))
        <x-ui.callout class="mb-4" variant="danger" icon="exclamation-triangle">{{ session('error') }}</x-ui.callout>
    @endif

    <div class="space-y-4">
        {{-- YouTube (OAuth) --}}
        @if($youtubeAccount)
            <div class="rounded-2xl border border-slate-800 bg-slate-950/70 p-4 shadow-sm sm:p-5">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div class="flex items-start gap-4">
                        <div class="flex h-12 w-12 items-center justify-center rounded-2xl border border-slate-800 bg-slate-900 text-xs font-semibold tracking-[0.18em] text-slate-300">
                            YT
                        </div>
                        <div class="space-y-2">
                            <div>
                                <div class="text-base font-semibold text-slate-100">{{ $youtubeAccount->name }}</div>
                                <div class="text-sm text-slate-400">
                                    @if($youtubeAccount->external_account_id)
                                        <a
                                            class="underline decoration-slate-700 underline-offset-4 transition hover:text-slate-200"
                                            href="https://www.youtube.com/channel/{{ $youtubeAccount->external_account_id }}"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                        >Ver canal</a>
                                    @else
                                        Canal do YouTube
                                    @endif
                                </div>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <x-ui.badge color="blue" size="sm">YouTube</x-ui.badge>
                                <x-ui.badge :color="$youtubeAccount->tokenExpired() ? 'amber' : 'green'" size="sm">
                                    {{ $youtubeAccount->tokenExpired() ? 'Token expirado' : 'Vinculado' }}
                                </x-ui.badge>
                            </div>
                        </div>
                    </div>

                    <div class="flex shrink-0 gap-2">
                        <x-ui.button wire:click="openYoutube" variant="filled" size="sm" icon="cog">Gerenciar</x-ui.button>
                    </div>
                </div>
            </div>
        @endif

        {{-- TikTok (email/senha) --}}
        @foreach($tiktokAccounts as $account)
            <div class="rounded-2xl border border-slate-800 bg-slate-950/70 p-4 shadow-sm sm:p-5">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div class="flex items-start gap-4">
                        <div class="flex h-12 w-12 items-center justify-center rounded-2xl border border-slate-800 bg-slate-900 text-xs font-semibold tracking-[0.18em] text-slate-300">
                            TT
                        </div>
                        <div class="space-y-2">
                            <div>
                                <div class="text-base font-semibold text-slate-100">{{ $account['name'] }}</div>
                                <div class="text-sm text-slate-400">{{ $account['login_email'] ?: 'sem email' }}</div>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <x-ui.badge color="zinc" size="sm">TikTok</x-ui.badge>
                                <x-ui.badge :color="$account['statusColor']" size="sm">{{ $account['statusLabel'] }}</x-ui.badge>
                                @unless($account['is_active'])
                                    <x-ui.badge color="amber" size="sm">Inativa</x-ui.badge>
                                @endunless
                            </div>
                        </div>
                    </div>

                    <div class="flex shrink-0 gap-2">
                        <x-ui.button wire:click="editTiktok({{ $account['id'] }})" variant="filled" size="sm" icon="pencil-square">Editar</x-ui.button>
                        <x-ui.button
                            wire:click="delete({{ $account['id'] }})"
                            wire:confirm="Remover esta conta?"
                            variant="danger"
                            size="sm"
                            icon="x-mark"
                        >Remover</x-ui.button>
                    </div>
                </div>
            </div>
        @endforeach

        @if($tiktokAccounts->isEmpty() && ! $youtubeAccount)
            <div class="rounded-2xl border border-dashed border-slate-800 bg-slate-950/40 p-8 text-center text-sm text-slate-400">
                Nenhuma conta cadastrada. Use os botões <span class="text-slate-200">TikTok</span> ou <span class="text-slate-200">YouTube</span> acima para adicionar.
            </div>
        @endif
    </div>

    {{-- Modal TikTok: email + senha --}}
    <x-ui.modal wire:model="showTiktokModal" max-width="max-w-lg">
        <form wire:submit="saveTiktok" class="space-y-5">
            <div>
                <h2 class="text-lg font-semibold text-slate-50">{{ $editingAccountId ? 'Editar conta TikTok' : 'Nova conta TikTok' }}</h2>
                <p class="text-sm text-slate-400">Credenciais de login usadas para publicar no TikTok.</p>
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
                <h2 class="text-lg font-semibold text-slate-50">Conta YouTube</h2>
                <p class="text-sm text-slate-400">O YouTube não usa email/senha aqui. A vinculação é manual: conecte com sua conta Google para autorizar a publicação.</p>
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
                        <x-ui.badge :color="$youtubeAccount->tokenExpired() ? 'amber' : 'green'" size="sm">
                            {{ $youtubeAccount->tokenExpired() ? 'Token expirado — revincule' : 'Vinculado' }}
                        </x-ui.badge>
                    </div>
                </div>
            @else
                <div class="rounded-xl border border-dashed border-slate-800 bg-slate-950/40 p-4 text-sm text-slate-400">
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

<section class="w-full">
    <div class="relative mb-6 w-full">
        <flux:heading size="xl" level="1">{{ __('Settings') }}</flux:heading>
        <flux:subheading size="lg" class="mb-6">Gerencie o perfil e as plataformas usadas para publicar.</flux:subheading>
        <flux:separator variant="subtle" />
    </div>

    <div class="flex items-start max-md:flex-col">
        <div class="me-10 w-full pb-4 md:w-[220px]">
            <x-settings.nav />
        </div>

        <flux:separator class="md:hidden" />

        <div class="flex-1 self-stretch max-md:pt-6">
            <flux:heading>Contas vinculadas</flux:heading>
            <flux:subheading>Uma lista simples por plataforma, com status e um atalho para conectar ou revisar o vínculo.</flux:subheading>

            @if(session('status'))
                <flux:callout class="mt-4" variant="success" icon="check-circle">{{ session('status') }}</flux:callout>
            @endif
            @if(session('error'))
                <flux:callout class="mt-4" variant="danger" icon="exclamation-triangle">{{ session('error') }}</flux:callout>
            @endif

            <div class="mt-6 space-y-4">
                @foreach($providers as $provider)
                    <div class="rounded-2xl border border-slate-800 bg-slate-950/70 p-4 shadow-sm sm:p-5">
                        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                            <div class="flex items-start gap-4">
                                <div class="flex h-12 w-12 items-center justify-center rounded-2xl border border-slate-800 bg-slate-900 text-xs font-semibold tracking-[0.18em] text-slate-300">
                                    {{ $provider['badge'] }}
                                </div>

                                <div class="space-y-2">
                                    <div>
                                        <div class="text-base font-semibold text-slate-100">{{ $provider['label'] }}</div>
                                        <div class="text-sm text-slate-400">{{ $provider['description'] }}</div>
                                    </div>

                                    <div class="text-sm text-slate-300">
                                        @if($provider['account'])
                                            <span class="font-medium">{{ $provider['account']->name }}</span>
                                            @if($provider['account']->external_account_id)
                                                <span class="text-slate-500">·</span>
                                                @if($provider['channelUrl'])
                                                    <a
                                                        class="text-slate-400 underline decoration-slate-700 underline-offset-4 transition hover:text-slate-200"
                                                        href="{{ $provider['channelUrl'] }}"
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                    >
                                                        Canal
                                                    </a>
                                                @else
                                                    <span class="text-slate-500">{{ $provider['account']->external_account_id }}</span>
                                                @endif
                                            @endif
                                        @else
                                            <span class="text-slate-500">Nenhuma conta conectada.</span>
                                        @endif
                                    </div>
                                </div>
                            </div>

                            <div class="flex w-full flex-col items-stretch gap-2 sm:w-32">
                                <flux:badge :color="$provider['statusColor']" size="sm" class="flex min-h-8 w-full justify-center px-3 text-center">
                                    {{ $provider['status'] }}
                                </flux:badge>

                                <div class="flex flex-col gap-2">
                                    @if($provider['usesOauth'])
                                        @unless($provider['isLinked'])
                                            @php($oauthReady = $provider['key'] === 'tiktok' ? $tiktokOAuthReady : $googleOAuthReady)
                                            @if($oauthReady)
                                                <flux:button :href="route('oauth.connect', ['platform' => $provider['key']])" size="sm" variant="primary" class="w-full cursor-pointer justify-center">
                                                    Vincular
                                                </flux:button>
                                            @else
                                                <flux:button size="sm" variant="filled" class="w-full justify-center" disabled>
                                                    Configurar .env
                                                </flux:button>
                                            @endif
                                        @endif
                                    @else
                                        <flux:button wire:click="manage('{{ $provider['key'] }}')" size="sm" variant="primary" class="w-full cursor-pointer justify-center">
                                            {{ $provider['actionLabel'] }}
                                        </flux:button>
                                    @endif

                                    @if($provider['account'])
                                        <flux:button wire:click="disconnect('{{ $provider['key'] }}')" size="sm" variant="danger" class="w-full cursor-pointer justify-center">
                                            Desvincular
                                        </flux:button>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            @if($managingPlatform)
                <div class="mt-6 rounded-2xl border border-slate-800 bg-slate-950/80 p-5">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <flux:heading size="lg">Gerenciar {{ $platformLabels[$managingPlatform] ?? ucfirst($managingPlatform) }}</flux:heading>
                            <flux:subheading>
                                Para TikTok, o fluxo recomendado agora é o Login Kit, que preenche os dados automaticamente ao vincular.
                            </flux:subheading>
                        </div>

                        <flux:button wire:click="cancelManage" size="sm" variant="ghost" class="cursor-pointer">
                            Fechar
                        </flux:button>
                    </div>

                    @if($managingPlatform === 'tiktok')
                        <div class="mt-5 rounded-2xl border border-slate-800 bg-slate-900/60 p-4">
                            <div class="text-sm font-medium text-slate-100">Campos esperados pelo TikTok</div>
                            <div class="mt-2 space-y-1 text-sm text-slate-400">
                                <div><code>Nome da conta</code>: apelido só para identificar internamente.</div>
                                <div><code>ID da conta</code>: use o <code>open_id</code> retornado pelo TikTok.</div>
                                <div><code>Access token</code>: token atual do usuário.</div>
                                <div><code>Refresh token</code>: necessário para renovar o acesso depois.</div>
                            </div>
                        </div>
                    @endif

                    <form wire:submit="save" @class([
                        'mt-5 grid gap-4',
                        'md:grid-cols-2' => $managingPlatform !== 'tiktok',
                    ])>
                        <flux:input
                            wire:model="name"
                            label="Nome da conta"
                            placeholder="@canal ou nome interno"
                            @class(['md:col-span-2' => $managingPlatform === 'tiktok'])
                        />
                        <flux:input
                            wire:model="external_account_id"
                            label="ID da conta"
                            description="Para TikTok, use o open_id."
                            @class(['md:col-span-2' => $managingPlatform === 'tiktok'])
                        />
                        <flux:input
                            wire:model="token_expires_at"
                            type="datetime-local"
                            label="Token expira em (opcional)"
                            @class(['md:col-span-2' => $managingPlatform === 'tiktok'])
                        />
                        <div class="md:col-span-2">
                            <flux:textarea wire:model="access_token" label="Access token" rows="3" />
                        </div>
                        <div class="md:col-span-2">
                            <flux:textarea wire:model="refresh_token" label="Refresh token (opcional)" rows="3" />
                        </div>
                        <div class="md:col-span-2">
                            <flux:textarea wire:model="meta" label="Meta (JSON opcional)" rows="4" placeholder='{"privacy_level":"SELF_ONLY"}' />
                        </div>
                        <div class="md:col-span-2 flex flex-wrap gap-2">
                            <flux:button type="submit" variant="primary" class="cursor-pointer">
                                Salvar vínculo
                            </flux:button>
                            <flux:button wire:click="cancelManage" type="button" variant="ghost" class="cursor-pointer">
                                Cancelar
                            </flux:button>
                        </div>
                    </form>
                </div>
            @endif
        </div>
    </div>
</section>

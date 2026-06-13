<section class="w-full">
    <div class="relative mb-6 w-full">
        <x-ui.heading size="xl" level="1">{{ __('Settings') }}</x-ui.heading>
        <x-ui.subheading size="lg" class="mb-6">Gerencie o perfil e as plataformas usadas para publicar.</x-ui.subheading>
        <x-ui.separator variant="subtle" />
    </div>

    <div class="flex items-start max-md:flex-col">
        <div class="me-10 w-full pb-4 md:w-[220px]">
            <x-settings.nav />
        </div>

        <x-ui.separator class="md:hidden" />

        <div class="flex-1 self-stretch max-md:pt-6">
            <x-ui.heading>Contas vinculadas</x-ui.heading>
            <x-ui.subheading>Uma lista simples por plataforma, com status e um atalho para conectar ou revisar o vínculo.</x-ui.subheading>

            @if(session('status'))
                <x-ui.callout class="mt-4" variant="success" icon="check-circle">{{ session('status') }}</x-ui.callout>
            @endif
            @if(session('error'))
                <x-ui.callout class="mt-4" variant="danger" icon="exclamation-triangle">{{ session('error') }}</x-ui.callout>
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
                                <x-ui.badge :color="$provider['statusColor']" size="sm" class="flex min-h-8 w-full justify-center px-3 text-center">
                                    {{ $provider['status'] }}
                                </x-ui.badge>

                                <div class="flex flex-col gap-2">
                                    @if($provider['usesOauth'])
                                        @unless($provider['isLinked'])
                                            @if($googleOAuthReady)
                                                <x-ui.button :href="route('oauth.connect', ['platform' => $provider['key']])" size="sm" variant="primary" class="w-full cursor-pointer justify-center">
                                                    Vincular
                                                </x-ui.button>
                                            @else
                                                <x-ui.button size="sm" variant="filled" class="w-full justify-center" disabled>
                                                    Configurar .env
                                                </x-ui.button>
                                            @endif
                                        @endif
                                    @else
                                        <x-ui.button wire:click="manage('{{ $provider['key'] }}')" size="sm" variant="primary" class="w-full cursor-pointer justify-center">
                                            {{ $provider['actionLabel'] }}
                                        </x-ui.button>
                                    @endif

                                    @if($provider['account'])
                                        <x-ui.button wire:click="disconnect('{{ $provider['key'] }}')" size="sm" variant="danger" class="w-full cursor-pointer justify-center">
                                            Desvincular
                                        </x-ui.button>
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
                            <x-ui.heading size="lg">Gerenciar {{ $platformLabels[$managingPlatform] ?? ucfirst($managingPlatform) }}</x-ui.heading>
                            <x-ui.subheading>Revise as credenciais usadas para publicar nesta plataforma.</x-ui.subheading>
                        </div>

                        <x-ui.button wire:click="cancelManage" size="sm" variant="ghost" class="cursor-pointer">
                            Fechar
                        </x-ui.button>
                    </div>

                    <form wire:submit="save" class="mt-5 grid gap-4 md:grid-cols-2">
                        <x-ui.input
                            wire:model="name"
                            label="Nome da conta"
                            placeholder="@canal ou nome interno"
                        />
                        <x-ui.input
                            wire:model="external_account_id"
                            label="ID da conta"
                        />
                        <x-ui.input
                            wire:model="token_expires_at"
                            type="datetime-local"
                            label="Token expira em (opcional)"
                        />
                        <div class="md:col-span-2">
                            <x-ui.textarea wire:model="access_token" label="Access token" rows="3" />
                        </div>
                        <div class="md:col-span-2">
                            <x-ui.textarea wire:model="refresh_token" label="Refresh token (opcional)" rows="3" />
                        </div>
                        <div class="md:col-span-2">
                            <x-ui.textarea wire:model="meta" label="Meta (JSON opcional)" rows="4" placeholder='{"privacy_level":"SELF_ONLY"}' />
                        </div>
                        <div class="md:col-span-2 flex flex-wrap gap-2">
                            <x-ui.button type="submit" variant="primary" class="cursor-pointer">
                                Salvar vínculo
                            </x-ui.button>
                            <x-ui.button wire:click="cancelManage" type="button" variant="ghost" class="cursor-pointer">
                                Cancelar
                            </x-ui.button>
                        </div>
                    </form>
                </div>
            @endif
        </div>
    </div>
</section>

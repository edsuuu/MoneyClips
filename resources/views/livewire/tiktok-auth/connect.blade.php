<section
    class="mx-auto flex w-full max-w-4xl flex-col gap-6"
    @if($listening) wire:poll.3s="refreshState" @endif
    x-data="{
        openTikTok() {
            window.open('https://www.tiktok.com/login', 'tiktok_login', 'width=520,height=720');
        },
        copy(text) {
            navigator.clipboard.writeText(text);
        }
    }"
>
    <x-studio.page-header
        eyebrow="TikTok"
        title="Conectar conta"
        subtitle="Login via cookies do seu navegador, sem captcha. Usa a extensão Chrome tiktok-cookie-bridge."
    >
        <x-slot:actions>
            <x-ui.button wire:click="refreshState" size="sm" variant="subtle" icon="arrow-path" class="cursor-pointer">
                Verificar agora
            </x-ui.button>
        </x-slot:actions>
    </x-studio.page-header>

    {{-- Estado da sessão atual --}}
    <x-studio.panel title="Estado atual da sessão" subtitle="Lido do microserviço tiktok-uploader (GET /session).">
        <div class="grid gap-3 sm:grid-cols-2">
            <div class="rounded-lg border border-slate-800 bg-slate-950/70 p-4">
                <p class="text-xs uppercase tracking-wide text-slate-500">Uploader</p>
                <p class="mt-1 text-sm">
                    @if($serviceUp)
                        <x-ui.badge color="green" size="sm">Online</x-ui.badge>
                    @else
                        <x-ui.badge color="red" size="sm">Offline</x-ui.badge>
                    @endif
                </p>
            </div>
            <div class="rounded-lg border border-slate-800 bg-slate-950/70 p-4">
                <p class="text-xs uppercase tracking-wide text-slate-500">Sessão TikTok</p>
                @php $valid = (bool) ($session['session_valid'] ?? false); @endphp
                <p class="mt-1 text-sm">
                    @if($valid)
                        <x-ui.badge color="green" size="sm">Válida</x-ui.badge>
                    @else
                        <x-ui.badge color="zinc" size="sm">Não conectada</x-ui.badge>
                    @endif
                </p>
                @if(! empty($session['account']))
                    <p class="mt-2 text-xs text-slate-400">Conta: <span class="font-mono">{{ $session['account'] }}</span></p>
                @endif
                @if(! empty($session['expires_at']))
                    <p class="mt-1 text-xs text-slate-500">Expira em {{ $session['expires_at'] }}</p>
                @endif
            </div>
        </div>

        @if($lastIngestAt !== '')
            <p class="mt-3 text-xs text-slate-500">Último envio de cookies recebido em {{ $lastIngestAt }}.</p>
        @endif
    </x-studio.panel>

    {{-- Fluxo de conexão --}}
    <x-studio.panel title="Conectar" subtitle="Abre o tiktok.com numa popup; depois clique no ícone da extensão pra mandar os cookies.">
        @if(! $bridgeConfigured)
            <div class="rounded-lg border border-red-900 bg-red-950/40 p-4 text-sm text-red-200">
                <p class="font-medium">TIKTOK_BRIDGE_TOKEN não está configurado no .env.</p>
                <p class="mt-1 text-xs text-red-300">Gere um token longo e único, salve em .env como TIKTOK_BRIDGE_TOKEN=... e reinicie o servidor.</p>
            </div>
        @else
            <ol class="space-y-4 text-sm text-slate-300">
                <li>
                    <p class="font-medium text-slate-100">1. Instale a extensão Chrome</p>
                    <p class="text-xs text-slate-500">
                        Carregue a pasta <span class="font-mono text-slate-400">extensions/tiktok-cookie-bridge/</span>
                        em <span class="font-mono">chrome://extensions</span> (Modo dev → Carregar sem compactação).
                    </p>
                </li>

                <li>
                    <p class="font-medium text-slate-100">2. Faça login no TikTok</p>
                    <div class="mt-2 flex gap-2">
                        <x-ui.button x-on:click="openTikTok" variant="primary" size="sm" icon="arrow-top-right-on-square" class="cursor-pointer">
                            Abrir popup do TikTok
                        </x-ui.button>
                        @if(! $listening)
                            <x-ui.button wire:click="startListening" variant="filled" size="sm" icon="signal" class="cursor-pointer">
                                Começar a aguardar cookies
                            </x-ui.button>
                        @else
                            <x-ui.button wire:click="stopListening" variant="filled" size="sm" icon="stop" class="cursor-pointer">
                                Parar de aguardar
                            </x-ui.button>
                        @endif
                    </div>
                    @if($listening)
                        <p class="mt-2 text-xs text-emerald-300">Aguardando os cookies (atualiza a cada 3s).</p>
                    @endif
                </li>

                <li>
                    <p class="font-medium text-slate-100">3. Configure a extensão (uma vez por navegador)</p>
                    <p class="text-xs text-slate-500">Cole estes dois valores no popup da extensão:</p>
                    <div class="mt-2 grid gap-3 sm:grid-cols-2">
                        <div class="rounded-md border border-slate-800 bg-slate-950 p-3">
                            <p class="text-xs uppercase tracking-wide text-slate-500">Endpoint</p>
                            <div class="mt-1 flex items-center gap-2">
                                <code class="line-clamp-1 break-all text-xs text-slate-200">{{ $ingestEndpoint }}</code>
                                <button type="button" x-on:click="copy('{{ $ingestEndpoint }}')" class="cursor-pointer text-xs text-slate-400 hover:text-slate-200">copiar</button>
                            </div>
                        </div>
                        <div class="rounded-md border border-slate-800 bg-slate-950 p-3">
                            <p class="text-xs uppercase tracking-wide text-slate-500">Token</p>
                            <div class="mt-1 flex items-center gap-2">
                                <code class="line-clamp-1 break-all text-xs text-slate-200">{{ $bridgeToken }}</code>
                                <button type="button" x-on:click="copy('{{ $bridgeToken }}')" class="cursor-pointer text-xs text-slate-400 hover:text-slate-200">copiar</button>
                            </div>
                        </div>
                    </div>
                </li>

                <li>
                    <p class="font-medium text-slate-100">4. Clique no ícone da extensão e "Ler cookies e enviar"</p>
                    <p class="text-xs text-slate-500">Quando o envio entrar, o card acima vira <strong class="text-emerald-300">Válida</strong>.</p>
                </li>
            </ol>
        @endif
    </x-studio.panel>
</section>

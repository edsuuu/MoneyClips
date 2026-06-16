<div class="mt-8">
    <x-ui.heading>Sessão do TikTok</x-ui.heading>
    <x-ui.subheading>
        O login roda no microserviço (navegador headless). Daqui você dispara o login,
        injeta uma sessão exportada de um login local e acompanha o status.
    </x-ui.subheading>

    <div class="mt-4 rounded-2xl border border-slate-800 bg-slate-950/70 p-4 shadow-sm sm:p-5">
        {{-- Status --}}
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="space-y-1">
                <div class="text-base font-semibold text-slate-100">
                    {{ $session['account'] ?? 'Conta TikTok' }}
                </div>
                <div class="text-sm text-slate-400">
                    @if(! $serviceUp)
                        Microserviço indisponível — confira <code class="text-slate-300">make micro-up</code>.
                    @elseif($session && ($session['valid'] ?? false))
                        Cookies presentes e válidos. Pronto para postar.
                    @elseif($session && ($session['has_cookies'] ?? false))
                        Há cookies, mas a sessão expirou. Faça login ou injete uma nova sessão.
                    @else
                        Sem cookies salvos. Faça login ou injete uma sessão.
                    @endif
                </div>
            </div>

            <div class="flex w-full flex-col items-stretch gap-2 sm:w-40">
                @php
                    [$statusLabel, $statusColor] = match (true) {
                        ! $serviceUp => ['Offline', 'zinc'],
                        (bool) ($session['valid'] ?? false) => ['Sessão válida', 'green'],
                        (bool) ($session['has_cookies'] ?? false) => ['Expirada', 'amber'],
                        default => ['Sem sessão', 'red'],
                    };
                @endphp
                <x-ui.badge :color="$statusColor" size="sm" class="flex min-h-8 w-full justify-center px-3 text-center">
                    {{ $statusLabel }}
                </x-ui.badge>
                <x-ui.button variant="subtle" size="sm" wire:click="refresh" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="refresh">Atualizar</span>
                    <span wire:loading wire:target="refresh">Atualizando…</span>
                </x-ui.button>
            </div>
        </div>

        <x-ui.separator variant="subtle" class="my-5" />

        {{-- Login --}}
        <div class="space-y-3">
            <div class="text-sm font-medium text-slate-200">Login automático</div>
            <p class="text-sm text-slate-400">
                Usa email/senha do <code class="text-slate-300">.env</code> do uploader. O navegador é headless;
                se houver captcha que não resolva, prefira injetar a sessão abaixo.
            </p>
            <div class="flex flex-wrap items-center gap-4">
                <x-ui.checkbox wire:model="forceLogin" label="Re-login limpo (apaga cookies atuais)" />
                <x-ui.button variant="primary" size="sm" wire:click="login" wire:loading.attr="disabled" wire:target="login">
                    <span wire:loading.remove wire:target="login">Logar agora</span>
                    <span wire:loading wire:target="login">Logando… (pode demorar)</span>
                </x-ui.button>
            </div>
        </div>

        <x-ui.separator variant="subtle" class="my-5" />

        {{-- Injeção de sessão --}}
        <div class="space-y-3">
            <div class="text-sm font-medium text-slate-200">Injetar sessão (cookies)</div>
            <x-ui.textarea
                wire:model="cookiesJson"
                rows="6"
                placeholder='[{"name":"sessionid","value":"...","domain":".tiktok.com","path":"/"}]'
                description="Cole o JSON de cookies exportado de um login local do TikTok."
            />
            <div class="flex justify-end">
                <x-ui.button variant="filled" size="sm" wire:click="inject" wire:loading.attr="disabled" wire:target="inject">
                    <span wire:loading.remove wire:target="inject">Injetar sessão</span>
                    <span wire:loading wire:target="inject">Injetando…</span>
                </x-ui.button>
            </div>
        </div>
    </div>
</div>

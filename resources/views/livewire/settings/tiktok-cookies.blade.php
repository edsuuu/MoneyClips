<div class="rounded-xl border border-slate-800 bg-slate-950/70 p-5">
    <div class="flex items-start justify-between gap-4">
        <div>
            <p class="text-sm font-medium text-slate-100">Sessão TikTok</p>
            <p class="mt-1 text-xs text-slate-400">
                Não há OAuth oficial — autenticamos por cookies do navegador. Exporte com a extensão do Playwright/EditThisCookie e cole o JSON aqui.
            </p>
        </div>
        @if ($account)
            @php
                $statusColor = match ($account->session_status) {
                    'valid' => 'text-emerald-400',
                    'invalid' => 'text-red-400',
                    default => 'text-slate-400',
                };
                $statusLabel = match ($account->session_status) {
                    'valid' => 'Válida',
                    'invalid' => 'Inválida',
                    default => 'Desconhecida',
                };
            @endphp
            <div class="text-right">
                <p class="text-[11px] uppercase tracking-[0.16em] text-slate-500">Status</p>
                <p class="mt-1 text-sm font-medium {{ $statusColor }}">{{ $statusLabel }}</p>
                @if ($account->cookies_last_validated_at)
                    <p class="mt-1 text-xs text-slate-500">
                        Última validação: {{ $account->cookies_last_validated_at->timezone(config('app.timezone'))->format('d/m H:i') }}
                    </p>
                @endif
            </div>
        @endif
    </div>

    <form wire:submit="saveCookies" class="mt-4 grid gap-3">
        <label class="block">
            <span class="text-xs text-slate-400">Handle (@)</span>
            <input
                type="text"
                wire:model="accountName"
                placeholder="clipsd211"
                class="mt-1 w-full rounded-lg border border-slate-700 bg-slate-900 px-3 py-2 text-sm text-slate-100 focus:outline-none"
            />
            @error('accountName') <span class="text-xs text-red-400">{{ $message }}</span> @enderror
        </label>

        <label class="block">
            <span class="text-xs text-slate-400">Cookies (array JSON)</span>
            <textarea
                wire:model="cookiesJson"
                rows="8"
                placeholder='[{"name": "sessionid", "value": "...", "domain": ".tiktok.com", "path": "/"}]'
                class="mt-1 w-full rounded-lg border border-slate-700 bg-slate-900 px-3 py-2 font-mono text-xs text-slate-100 focus:outline-none"
            ></textarea>
            @error('cookiesJson') <span class="text-xs text-red-400">{{ $message }}</span> @enderror
        </label>

        <div class="flex flex-col gap-2">
            <div class="flex justify-end gap-2">
                <x-ui.button
                    type="submit"
                    variant="primary"
                    size="sm"
                    icon="check"
                    class="cursor-pointer"
                    wire:loading.attr="disabled"
                    wire:target="saveCookies"
                >
                    <span wire:loading.remove wire:target="saveCookies">Salvar cookies</span>
                    <span wire:loading wire:target="saveCookies">Salvando…</span>
                </x-ui.button>
            </div>
        </div>
    </form>

    <div class="mt-4 flex flex-col gap-2 border-t border-slate-700 pt-4">
        <p class="text-xs text-slate-400">Ações:</p>
        <div class="flex flex-wrap gap-2">
            <x-ui.button
                type="button"
                variant="filled"
                size="sm"
                wire:click="testSession"
                wire:loading.attr="disabled"
                wire:target="testSession"
                class="cursor-pointer"
            >
                <span wire:loading.remove wire:target="testSession">Testar sessão</span>
                <span wire:loading wire:target="testSession">Testando…</span>
            </x-ui.button>

            <x-ui.button
                type="button"
                variant="filled"
                size="sm"
                wire:click="attemptLogin"
                wire:loading.attr="disabled"
                wire:target="attemptLogin"
                class="cursor-pointer"
            >
                <span wire:loading.remove wire:target="attemptLogin">Tentar re-login</span>
                <span wire:loading wire:target="attemptLogin">Autenticando…</span>
            </x-ui.button>
        </div>
        <p class="text-xs text-slate-500">
            "Testar" valida cookies atuais. "Re-login" usa email/senha do .env pra gerar novos cookies.
        </p>
    </div>
</div>

<section class="mx-auto flex w-full max-w-6xl flex-col gap-6">
    <x-studio.page-header
        eyebrow="Credenciais"
        title="Contas sociais"
        subtitle="Conecte as contas para publicar de verdade. Os tokens são guardados criptografados."
    />

    @if(session('status'))
        <flux:callout variant="success" icon="check-circle">{{ session('status') }}</flux:callout>
    @endif
    @if(session('error'))
        <flux:callout variant="danger" icon="exclamation-triangle">{{ session('error') }}</flux:callout>
    @endif

    {{-- Conexão via OAuth (1 clique) --}}
    <x-studio.panel title="Conectar com 1 clique (OAuth)" subtitle="Requer o app de desenvolvedor configurado no .env de cada plataforma.">
        <flux:text class="mb-3 text-xs text-slate-500">
            Requer o app de desenvolvedor configurado no <code>.env</code> (client id/secret) de cada plataforma.
        </flux:text>
        <div class="flex flex-wrap gap-2">
            <flux:button :href="route('oauth.connect', ['platform' => 'youtube'])" icon="play" size="sm" class="cursor-pointer">
                YouTube
            </flux:button>
            <flux:button :href="route('oauth.connect', ['platform' => 'facebook'])" icon="camera" size="sm" class="cursor-pointer">
                Instagram / Facebook (Meta)
            </flux:button>
            <flux:button :href="route('oauth.connect', ['platform' => 'tiktok'])" icon="musical-note" size="sm" class="cursor-pointer">
                TikTok
            </flux:button>
        </div>
    </x-studio.panel>

    <x-studio.panel title="Conectar manualmente (colar token)">

        <form wire:submit="save" class="grid gap-3 md:grid-cols-2">
            <div>
                <flux:text class="text-xs text-slate-500">Plataforma</flux:text>
                <select wire:model="platform"
                        class="w-full cursor-pointer rounded-lg border border-slate-800 bg-slate-900 px-2 py-1.5 text-sm text-slate-100">
                    @foreach($platformLabels as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <flux:input wire:model="name" label="Nome (rótulo)" placeholder="Meu canal / @handle" />
            <flux:input wire:model="external_account_id" label="ID da conta"
                        description="channel_id (YouTube) · ig_user_id (Instagram) · page_id (Facebook) · open_id (TikTok)" />
            <flux:input wire:model="token_expires_at" type="datetime-local" label="Token expira em (opcional)" />
            <div class="md:col-span-2">
                <flux:textarea wire:model="access_token" label="Access token" rows="2" placeholder="Bearer token OAuth da plataforma" />
            </div>
            <div class="md:col-span-2">
                <flux:textarea wire:model="refresh_token" label="Refresh token (opcional)" rows="2" />
            </div>
            <div class="md:col-span-2">
                <flux:textarea wire:model="meta" label="Meta (JSON opcional)" rows="3"
                               placeholder='{"ig_user_id":"178...","page_id":"100...","privacy_level":"PUBLIC_TO_EVERYONE"}' />
            </div>
            <div class="md:col-span-2">
                <flux:button type="submit" variant="primary" icon="plus" class="cursor-pointer">Conectar conta</flux:button>
            </div>
        </form>
    </x-studio.panel>

    <x-studio.panel title="Contas conectadas">
        <div class="overflow-x-auto">
            <table class="studio-table w-full text-sm">
                <thead>
                    <tr>
                        <th class="py-2 pr-3">Plataforma</th>
                        <th class="py-2 pr-3">Nome</th>
                        <th class="py-2 pr-3">ID</th>
                        <th class="py-2 pr-3">Token</th>
                        <th class="py-2 pr-3">Ativa</th>
                        <th class="py-2 pr-3"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($accounts as $account)
                        <tr>
                            <td class="py-2 pr-3">{{ $platformLabels[$account->platform] ?? ucfirst($account->platform) }}</td>
                            <td class="py-2 pr-3 font-medium">{{ $account->name }}</td>
                            <td class="py-2 pr-3 text-slate-500">{{ $account->external_account_id ?? '—' }}</td>
                            <td class="py-2 pr-3">
                                @if($account->tokenExpired())
                                    <flux:badge color="red" size="sm">expirado</flux:badge>
                                @else
                                    <flux:badge color="green" size="sm">ok</flux:badge>
                                @endif
                            </td>
                            <td class="py-2 pr-3">
                                <button wire:click="toggleActive({{ $account->id }})" class="cursor-pointer">
                                    @if($account->is_active)
                                        <flux:badge color="green" size="sm">ativa</flux:badge>
                                    @else
                                        <flux:badge color="zinc" size="sm">inativa</flux:badge>
                                    @endif
                                </button>
                            </td>
                            <td class="py-2 pr-3">
                                <flux:button size="xs" variant="ghost" wire:click="delete({{ $account->id }})"
                                             class="cursor-pointer">Remover</flux:button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-6 text-center text-slate-500">Nenhuma conta conectada ainda.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-studio.panel>
</section>

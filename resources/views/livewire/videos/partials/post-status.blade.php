<div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs" wire:key="post-{{ $post['id'] }}">
    <span class="w-14 shrink-0 font-semibold text-slate-300" title="{{ $post['account_name'] }}">{{ $post['platform_label'] }}</span>
    <x-ui.badge size="sm" :color="$post['badge_color']">
        @if ($post['is_posting'])
            <x-ui.icon name="loading" class="size-3" />
        @endif
        {{ $post['badge_label'] }}
    </x-ui.badge>
    @if ($post['is_private'])
        <x-ui.badge size="sm" color="amber" title="A conta ainda não passou na auditoria da plataforma: só você vê o vídeo.">Saiu privado</x-ui.badge>
    @endif
    <span class="min-w-0 truncate text-slate-500 max-sm:order-last max-sm:w-full max-sm:pl-16 sm:flex-1" title="{{ $post['detail'] }}">{{ $post['detail'] }}</span>

    @if ($post['can_cancel'])
        <button type="button" wire:click="cancelPost({{ $post['id'] }})" wire:confirm="{{ $post['cancel_confirm'] }}" wire:loading.attr="disabled"
            aria-label="Cancelar" title="Cancelar"
            class="ml-auto flex size-6 shrink-0 cursor-pointer items-center justify-center rounded-md text-slate-500 transition hover:bg-slate-800 hover:text-slate-200">
            <x-ui.icon name="x-mark" class="size-3.5" />
        </button>
    @endif

    @if ($post['url'])
        <a href="{{ $post['url'] }}" target="_blank" rel="noopener noreferrer"
            class="ml-auto flex shrink-0 items-center gap-1 font-semibold text-sky-700 transition hover:text-sky-600 dark:text-sky-400 dark:hover:text-sky-300">
            {{ $post['url_label'] }}
            <x-ui.icon name="arrow-top-right-on-square" class="size-3" />
        </a>
    @endif

    @if ($post['action'] === 'retry')
        <x-ui.button size="xs" variant="outline" icon="arrow-path" class="ml-auto shrink-0" wire:click="retryPost({{ $post['id'] }})" wire:loading.attr="disabled" wire:target="retryPost({{ $post['id'] }})">
            {{ $post['action_label'] }}
        </x-ui.button>
    @elseif ($post['action'] === 'reconnect')
        <x-ui.button size="xs" variant="outline" class="ml-auto shrink-0" :href="route('accounts.index')" wire:navigate>
            {{ $post['action_label'] }}
        </x-ui.button>
    @endif
</div>

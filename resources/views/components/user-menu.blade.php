@props(['placement' => 'bottom', 'align' => 'start', 'collapsible' => false])

<x-ui.dropdown :align="$align" :placement="$placement" {{ $attributes }}>
    <x-slot:trigger>
        <div class="flex w-full items-center gap-2 rounded-lg p-2 text-start transition hover:bg-slate-900" data-test="sidebar-menu-button">
            <x-ui.avatar :initials="auth()->user()->initials()" :name="auth()->user()->name" />
            <span
                class="flex-1 truncate text-sm font-medium text-slate-200 transition-opacity duration-200"
                @if ($collapsible) :class="expanded ? 'lg:opacity-100 lg:delay-150' : 'lg:pointer-events-none lg:opacity-0 lg:delay-0'" @endif
            >{{ auth()->user()->name }}</span>
            <x-ui.icon
                name="chevrons-up-down"
                class="shrink-0 text-slate-500 transition-opacity duration-200"
                @if ($collapsible) ::class="expanded ? 'lg:opacity-100 lg:delay-150' : 'lg:pointer-events-none lg:opacity-0 lg:delay-0'" @endif
            />
        </div>
    </x-slot:trigger>

    <div class="flex items-center gap-2 px-2 py-2 text-start text-sm">
        <x-ui.avatar :initials="auth()->user()->initials()" :name="auth()->user()->name" />
        <div class="grid flex-1 leading-tight">
            <span class="truncate font-semibold text-slate-100">{{ auth()->user()->name }}</span>
            <span class="truncate text-xs text-slate-400">{{ auth()->user()->email }}</span>
        </div>
    </div>

    <x-ui.separator class="my-1" />

    <x-ui.menu-item :href="route('profile.edit')" icon="cog" wire:navigate>
        {{ __('Settings') }}
    </x-ui.menu-item>

    <x-ui.menu-item
        x-data
        x-on:click="$store.theme.toggle()"
        role="switch"
        x-bind:aria-checked="$store.theme.dark"
        data-test="theme-toggle"
    >
        <x-ui.icon name="sun" class="dark:hidden" />
        <x-ui.icon name="moon" class="hidden dark:block" />
        <span class="flex-1 text-start">
            <span class="dark:hidden">{{ __('Light theme') }}</span>
            <span class="hidden dark:inline">{{ __('Dark theme') }}</span>
        </span>
        <span class="relative h-4 w-7 shrink-0 rounded-full bg-slate-700 transition dark:bg-emerald-500">
            <span class="absolute top-0.5 left-0.5 size-3 rounded-full bg-white transition-all dark:left-[15px]"></span>
        </span>
    </x-ui.menu-item>

    <form method="POST" action="{{ route('logout') }}" class="w-full">
        @csrf
        <x-ui.menu-item type="submit" icon="arrow-right-start-on-rectangle" data-test="logout-button">
            {{ __('Log out') }}
        </x-ui.menu-item>
    </form>
</x-ui.dropdown>

@props(['placement' => 'bottom'])

<x-ui.dropdown align="start" :placement="$placement" {{ $attributes }}>
    <x-slot:trigger>
        <div class="flex w-full items-center gap-2 rounded-lg p-2 text-start transition hover:bg-slate-900" data-test="sidebar-menu-button">
            <x-ui.avatar :initials="auth()->user()->initials()" :name="auth()->user()->name" />
            <span class="flex-1 truncate text-sm font-medium text-slate-200">{{ auth()->user()->name }}</span>
            <x-ui.icon name="chevrons-up-down" class="text-slate-500" />
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

    <form method="POST" action="{{ route('logout') }}" class="w-full">
        @csrf
        <x-ui.menu-item type="submit" icon="arrow-right-start-on-rectangle" data-test="logout-button">
            {{ __('Log out') }}
        </x-ui.menu-item>
    </form>
</x-ui.dropdown>

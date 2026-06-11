<nav aria-label="{{ __('Settings') }}" class="grid gap-1">
    <x-nav-item :href="route('profile.edit')" :current="request()->routeIs('profile.edit')">
        {{ __('Profile') }}
    </x-nav-item>
    <x-nav-item :href="route('settings.accounts')" :current="request()->routeIs('settings.accounts') || request()->routeIs('social-accounts')">
        {{ __('Contas vinculadas') }}
    </x-nav-item>
    <x-nav-item :href="route('security.edit')" :current="request()->routeIs('security.edit')">
        {{ __('Segurança') }}
    </x-nav-item>
    <x-nav-item :href="route('appearance.edit')" :current="request()->routeIs('appearance.edit')">
        {{ __('Appearance') }}
    </x-nav-item>
</nav>

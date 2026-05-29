<flux:navlist aria-label="{{ __('Settings') }}">
    <flux:navlist.item :href="route('profile.edit')" :current="request()->routeIs('profile.edit')" wire:navigate>
        {{ __('Profile') }}
    </flux:navlist.item>
    <flux:navlist.item :href="route('settings.accounts')" :current="request()->routeIs('settings.accounts') || request()->routeIs('social-accounts')" wire:navigate>
        {{ __('Contas vinculadas') }}
    </flux:navlist.item>
    <flux:navlist.item :href="route('security.edit')" :current="request()->routeIs('security.edit')" wire:navigate>
        {{ __('Security') }}
    </flux:navlist.item>
    <flux:navlist.item :href="route('appearance.edit')" :current="request()->routeIs('appearance.edit')" wire:navigate>
        {{ __('Appearance') }}
    </flux:navlist.item>
</flux:navlist>

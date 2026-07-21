<div class="flex flex-col gap-6">
    <div class="flex w-full flex-col text-center">
        <x-ui.heading size="xl">{{ __('Reset password') }}</x-ui.heading>
        <x-ui.subheading>{{ __('Enter your new password below') }}</x-ui.subheading>
    </div>

    <form wire:submit="resetPassword" class="flex flex-col gap-6">
        <x-ui.input
            wire:model="email"
            :label="__('Email address')"
            type="email"
            required
            readonly
            autocomplete="email"
        />

        <x-ui.input
            wire:model="password"
            :label="__('Password')"
            type="password"
            required
            autofocus
            autocomplete="new-password"
            :placeholder="__('Password')"
            viewable
        />

        <x-ui.input
            wire:model="password_confirmation"
            :label="__('Confirm password')"
            type="password"
            required
            autocomplete="new-password"
            :placeholder="__('Confirm password')"
            viewable
        />

        <div class="flex items-center justify-end">
            <x-ui.button variant="primary" type="submit" class="w-full">
                {{ __('Reset password') }}
            </x-ui.button>
        </div>
    </form>
</div>

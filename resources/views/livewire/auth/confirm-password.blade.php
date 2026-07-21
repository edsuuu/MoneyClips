<div class="flex flex-col gap-6">
    <div class="flex w-full flex-col text-center">
        <x-ui.heading size="xl">{{ __('Confirm password') }}</x-ui.heading>
        <x-ui.subheading>{{ __('This is a secure area of the application. Please confirm your password before continuing.') }}</x-ui.subheading>
    </div>

    @if (session('status'))
        <div class="text-center font-medium text-sm text-green-600">
            {{ session('status') }}
        </div>
    @endif

    <form wire:submit="confirmPassword" class="flex flex-col gap-6">
        <x-ui.input
            wire:model="password"
            :label="__('Password')"
            type="password"
            required
            autocomplete="current-password"
            autofocus
            viewable
        />

        <div class="flex justify-end">
            <x-ui.button variant="primary" type="submit" class="w-full">
                {{ __('Confirm') }}
            </x-ui.button>
        </div>
    </form>
</div>

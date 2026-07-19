<x-guest-layout :title="__('Register')">
    <div class="mx-auto flex w-full max-w-sm flex-col gap-6">
        <div class="flex w-full flex-col text-center">
            <x-ui.heading size="xl">{{ __('Create an account') }}</x-ui.heading>
            <x-ui.subheading>{{ __('Enter your details below to create your account') }}</x-ui.subheading>
        </div>

        <livewire:auth.register />
    </div>
</x-guest-layout>

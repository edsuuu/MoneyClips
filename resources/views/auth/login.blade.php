<x-guest-layout :title="__('Log in')">
    <div class="flex min-h-[70vh] items-center justify-center px-4 py-10 sm:px-6">
        <div class="flex w-full max-w-sm flex-col gap-6">
            <div class="flex w-full flex-col text-center">
                <x-ui.heading size="xl">{{ __('Log in to your account') }}</x-ui.heading>
                <x-ui.subheading>{{ __('Enter your email and password below to log in') }}</x-ui.subheading>
            </div>

            <livewire:auth.login />
        </div>
    </div>
</x-guest-layout>

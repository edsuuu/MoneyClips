<section class="w-full">
    <div class="relative mb-6 w-full">
    <x-ui.heading size="xl" level="1">{{ __('Settings') }}</x-ui.heading>
    <x-ui.subheading size="lg" class="mb-6">{{ __('Manage your profile and account settings') }}</x-ui.subheading>
    <x-ui.separator variant="subtle" />
</div>

    <x-ui.heading class="sr-only">{{ __('Profile settings') }}</x-ui.heading>

    <div class="flex items-start max-md:flex-col">
        <div class="me-10 w-full pb-4 md:w-[220px]">
            <x-settings.nav />
        </div>

        <x-ui.separator class="md:hidden" />

        <div class="flex-1 self-stretch max-md:pt-6">
            <x-ui.heading>{{ __('Profile') }}</x-ui.heading>
            <x-ui.subheading>{{ __('Update your name and email address') }}</x-ui.subheading>

            <div class="mt-5 w-full max-w-lg">
                <form wire:submit="updateProfileInformation" class="my-6 w-full space-y-6">
                    <x-ui.input wire:model="name" :label="__('Name')" type="text" required autofocus autocomplete="name" />

                    <div>
                        <x-ui.input wire:model="email" :label="__('Email')" type="email" required autocomplete="email" />

                        @if ($this->hasUnverifiedEmail)
                            <div>
                                <x-ui.text class="mt-4">
                                    {{ __('Your email address is unverified.') }}

                                    <x-ui.link class="text-sm cursor-pointer" wire:click.prevent="resendVerificationNotification">
                                        {{ __('Click here to re-send the verification email.') }}
                                    </x-ui.link>
                                </x-ui.text>

                            </div>
                        @endif
                    </div>

                    <div class="flex items-center gap-4">
                        <x-ui.button variant="primary" type="submit">{{ __('Save') }}</x-ui.button>
                    </div>
                </form>

                @if ($this->showDeleteUser)
                    <livewire:settings.delete-user-form />
                @endif
            </div>
        </div>
    </div>
</section>

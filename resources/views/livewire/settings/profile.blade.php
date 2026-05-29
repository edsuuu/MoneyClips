<section class="w-full">
    <div class="relative mb-6 w-full">
    <flux:heading size="xl" level="1">{{ __('Settings') }}</flux:heading>
    <flux:subheading size="lg" class="mb-6">{{ __('Manage your profile and account settings') }}</flux:subheading>
    <flux:separator variant="subtle" />
</div>

    <flux:heading class="sr-only">{{ __('Profile settings') }}</flux:heading>

    <div class="flex items-start max-md:flex-col">
        <div class="me-10 w-full pb-4 md:w-[220px]">
            <x-settings.nav />
        </div>

        <flux:separator class="md:hidden" />

        <div class="flex-1 self-stretch max-md:pt-6">
            <flux:heading>{{ __('Profile') }}</flux:heading>
            <flux:subheading>{{ __('Update your name and email address') }}</flux:subheading>

            <div class="mt-5 w-full max-w-lg">
                <form wire:submit="updateProfileInformation" class="my-6 w-full space-y-6">
                    <flux:input wire:model="name" :label="__('Name')" type="text" required autofocus autocomplete="name" />

                    <div>
                        <flux:input wire:model="email" :label="__('Email')" type="email" required autocomplete="email" />

                        @if ($this->hasUnverifiedEmail)
                            <div>
                                <flux:text class="mt-4">
                                    {{ __('Your email address is unverified.') }}

                                    <flux:link class="text-sm cursor-pointer" wire:click.prevent="resendVerificationNotification">
                                        {{ __('Click here to re-send the verification email.') }}
                                    </flux:link>
                                </flux:text>

                            </div>
                        @endif
                    </div>

                    <div class="flex items-center gap-4">
                        <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
                    </div>
                </form>

                @if ($this->showDeleteUser)
                    <livewire:settings.delete-user-form />
                @endif
            </div>
        </div>
    </div>
</section>

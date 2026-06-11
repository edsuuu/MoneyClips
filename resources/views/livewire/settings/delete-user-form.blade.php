<section class="mt-10 space-y-6">
    <div class="relative mb-5">
        <x-ui.heading>{{ __('Delete account') }}</x-ui.heading>
        <x-ui.subheading>{{ __('Delete your account and all of its resources') }}</x-ui.subheading>
    </div>

    <x-ui.button variant="danger" x-data x-on:click="$dispatch('modal-show', { name: 'confirm-user-deletion' })">
        {{ __('Delete account') }}
    </x-ui.button>

    <x-ui.modal name="confirm-user-deletion" class="max-w-lg">
        <form method="POST" wire:submit="deleteUser" class="space-y-6">
            <div class="space-y-1">
                <x-ui.heading size="lg">{{ __('Are you sure you want to delete your account?') }}</x-ui.heading>

                <x-ui.subheading>
                    {{ __('Once your account is deleted, all of its resources and data will be permanently deleted. Please enter your password to confirm you would like to permanently delete your account.') }}
                </x-ui.subheading>
            </div>

            <x-ui.input wire:model="password" :label="__('Password')" type="password" viewable />

            <div class="flex justify-end gap-2">
                <x-ui.button variant="filled" x-on:click="$dispatch('modal-close', { name: 'confirm-user-deletion' })">
                    {{ __('Cancel') }}
                </x-ui.button>

                <x-ui.button variant="danger" type="submit">{{ __('Delete account') }}</x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</section>

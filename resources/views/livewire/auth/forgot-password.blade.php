<div class="flex flex-col gap-6">
    <div class="flex w-full flex-col text-center">
        <x-ui.heading size="xl">{{ __('Forgot password') }}</x-ui.heading>
        <x-ui.subheading>{{ __('Enter your email to receive a password reset link') }}</x-ui.subheading>
    </div>

    <!-- Session Status -->
    @if (session('status'))
        <div class="text-center font-medium text-sm text-green-600">
            {{ session('status') }}
        </div>
    @endif

    <form wire:submit="sendPasswordResetLink" class="flex flex-col gap-6">
        <!-- Email Address -->
        <x-ui.input
            wire:model="email"
            :label="__('Email address')"
            type="email"
            required
            autofocus
            autocomplete="email"
            placeholder="email@example.com"
        />

        <div class="flex items-center justify-end">
            <x-ui.button variant="primary" type="submit" class="w-full">
                {{ __('Email password reset link') }}
            </x-ui.button>
        </div>
    </form>

    <div class="space-x-1 text-sm text-center rtl:space-x-reverse text-zinc-600 dark:text-zinc-400">
        <span>{{ __('Remember your password?') }}</span>
        <button
            type="button"
            x-data
            x-on:click="$dispatch('modal-close', { name: 'forgot-password' }); $dispatch('modal-show', { name: 'login' })"
            class="cursor-pointer font-medium text-slate-200 underline decoration-slate-600 underline-offset-4 transition hover:text-white hover:decoration-slate-300"
        >{{ __('Log in') }}</button>
    </div>
</div>

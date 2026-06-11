<div class="flex flex-col gap-6">
    <div class="flex w-full flex-col text-center">
        <x-ui.heading size="xl">{{ __('Create an account') }}</x-ui.heading>
        <x-ui.subheading>{{ __('Enter your details below to create your account') }}</x-ui.subheading>
    </div>

    <!-- Session Status -->
    @if (session('status'))
        <div class="text-center font-medium text-sm text-green-600">
            {{ session('status') }}
        </div>
    @endif

    <div class="flex flex-col gap-3">
        <x-ui.button
            :href="route('auth.google.redirect')"
            variant="subtle"
            class="w-full"
            icon="arrow-top-right-on-square"
        >
            {{ __('Continue with Google') }}
        </x-ui.button>
        <div class="text-center text-xs uppercase tracking-[0.2em] text-zinc-500">{{ __('or') }}</div>
    </div>

    <form wire:submit="register" class="flex flex-col gap-6">
        <!-- Name -->
        <x-ui.input
            wire:model="name"
            :label="__('Name')"
            type="text"
            required
            autofocus
            autocomplete="name"
            :placeholder="__('Full name')"
        />

        <!-- Email Address -->
        <x-ui.input
            wire:model="email"
            :label="__('Email address')"
            type="email"
            required
            autocomplete="email"
            placeholder="email@example.com"
        />

        <!-- Password -->
        <x-ui.input
            wire:model="password"
            :label="__('Password')"
            type="password"
            required
            autocomplete="new-password"
            :placeholder="__('Password')"
            viewable
        />

        <!-- Confirm Password -->
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
                {{ __('Create account') }}
            </x-ui.button>
        </div>
    </form>

    <div class="space-x-1 text-sm text-center rtl:space-x-reverse text-zinc-600 dark:text-zinc-400">
        <span>{{ __('Already have an account?') }}</span>
        <x-ui.link :href="route('login')" wire:navigate>{{ __('Log in') }}</x-ui.link>
    </div>
</div>

<div class="flex flex-col gap-6">
    <div class="flex w-full flex-col text-center">
        <x-ui.heading size="xl">{{ __('Log in to your account') }}</x-ui.heading>
        <x-ui.subheading>{{ __('Enter your email and password below to log in') }}</x-ui.subheading>
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

    <form wire:submit="login" class="flex flex-col gap-6">
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

        <!-- Password -->
        <div class="relative">
            <x-ui.input
                wire:model="password"
                :label="__('Password')"
                type="password"
                required
                autocomplete="current-password"
                :placeholder="__('Password')"
                viewable
            />

            @if (Route::has('password.request'))
                <x-ui.link class="absolute top-0 text-sm end-0" :href="route('password.request')" wire:navigate>
                    {{ __('Forgot your password?') }}
                </x-ui.link>
            @endif
        </div>

        <!-- Remember Me -->
        <x-ui.checkbox wire:model="remember" :label="__('Remember me')" />

        <div class="flex items-center justify-end">
            <x-ui.button variant="primary" type="submit" class="w-full" data-test="login-button">
                {{ __('Log in') }}
            </x-ui.button>
        </div>
    </form>
</div>

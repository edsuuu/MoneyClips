<div class="flex flex-col">
    @if (session('status'))
        <div class="text-center font-medium text-sm text-green-600">
            {{ session('status') }}
        </div>
    @endif

    <div class="flex flex-col gap-3">
        <x-google-button />

        <div class="text-center text-[10px] uppercase tracking-[0.2em] text-zinc-500">{{ __('or') }}</div>
    </div>

    <form wire:submit="login" class="flex flex-col gap-4">
        <x-ui.input
            wire:model="email"
            :label="__('Email address')"
            type="email"
            required
            autofocus
            autocomplete="email"
            placeholder="email@example.com"
        />

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
                <button
                    type="button"
                    x-data
                    x-on:click="$dispatch('modal-close', { name: 'login' }); $dispatch('modal-show', { name: 'forgot-password' })"
                    class="absolute end-0 top-0 cursor-pointer text-sm font-medium text-slate-200 underline decoration-slate-600 underline-offset-4 transition hover:text-white hover:decoration-slate-300"
                >
                    {{ __('Forgot your password?') }}
                </button>
            @endif
        </div>

        <x-ui.checkbox wire:model="remember" :label="__('Remember me')" />

        <div class="flex items-center justify-end">
            <x-ui.button variant="primary" type="submit" class="w-full" data-test="login-button">
                {{ __('Log in') }}
            </x-ui.button>
        </div>
    </form>
</div>

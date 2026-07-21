<section class="w-full">
    <div class="relative mb-6 w-full">
        <x-ui.heading size="xl" level="1">{{ __('Settings') }}</x-ui.heading>
        <x-ui.subheading size="lg" class="mb-6">{{ __('Manage your profile and account settings') }}</x-ui.subheading>
        <x-ui.separator variant="subtle" />
    </div>

    <x-ui.heading class="sr-only">{{ __('Security settings') }}</x-ui.heading>

    <div class="flex items-start max-md:flex-col">
        <div class="me-10 w-full pb-4 md:w-[220px]">
            <x-settings.nav />
        </div>

        <x-ui.separator class="md:hidden" />

        <div class="flex-1 self-stretch max-md:pt-6">
            <x-ui.heading>{{ $hasPassword ? __('Update password') : __('Criar senha') }}</x-ui.heading>
            <x-ui.subheading>
                {{ $hasPassword
                    ? __('Ensure your account is using a long, random password to stay secure')
                    : __('Você entrou com o Google e ainda não tem senha. Crie uma para acessar também por e-mail e senha.') }}
            </x-ui.subheading>

            <div class="mt-5 w-full max-w-lg">
                <form method="POST" wire:submit="updatePassword" class="mt-6 space-y-6">
                    @if ($hasPassword)
                        <x-ui.input
                            wire:model="current_password"
                            :label="__('Current password')"
                            type="password"
                            required
                            autocomplete="current-password"
                            viewable
                        />
                    @endif
                    <x-ui.input
                        wire:model="password"
                        :label="__('New password')"
                        type="password"
                        required
                        autocomplete="new-password"
                        viewable
                    />
                    <x-ui.input
                        wire:model="password_confirmation"
                        :label="__('Confirm password')"
                        type="password"
                        required
                        autocomplete="new-password"
                        viewable
                    />

                    <div class="flex items-center gap-4">
                        <x-ui.button variant="primary" type="submit" data-test="update-password-button">{{ __('Save') }}</x-ui.button>
                    </div>
                </form>

                @if ($canManageTwoFactor)
                    <section class="mt-12">
                        <x-ui.heading>{{ __('Two-factor authentication') }}</x-ui.heading>
                        <x-ui.subheading>{{ __('Manage your two-factor authentication settings') }}</x-ui.subheading>

                        <div class="flex flex-col w-full mx-auto space-y-6 text-sm" wire:cloak>
                            @if ($twoFactorEnabled)
                                <div class="space-y-4">
                                    <x-ui.text>
                                        {{ __('You will be prompted for a secure, random pin during login, which you can retrieve from the TOTP-supported application on your phone.') }}
                                    </x-ui.text>

                                    <div class="flex justify-start">
                                        <x-ui.button
                                            variant="danger"
                                            wire:click="disable"
                                        >
                                            {{ __('Disable 2FA') }}
                                        </x-ui.button>
                                    </div>

                                    <livewire:settings.two-factor.recovery-codes :$requiresConfirmation/>
                                </div>
                            @else
                                <div class="space-y-4">
                                    <x-ui.text variant="subtle">
                                        {{ __('When you enable two-factor authentication, you will be prompted for a secure pin during login. This pin can be retrieved from a TOTP-supported application on your phone.') }}
                                    </x-ui.text>

                                    <x-ui.button
                                        variant="primary"
                                        wire:click="enable"
                                    >
                                        {{ __('Enable 2FA') }}
                                    </x-ui.button>
                                </div>
                            @endif
                        </div>
                    </section>

                    <x-ui.modal
                        name="two-factor-setup-modal"
                        wire:model="showModal"
                    >
                        <div class="space-y-6">
                            <div class="flex flex-col items-center space-y-4">
                                <div class="p-0.5 w-auto rounded-full border border-stone-600 bg-stone-800 shadow-sm">
                                    <div class="p-2.5 rounded-full border border-stone-600 overflow-hidden bg-stone-200 relative">
                                        <div class="flex items-stretch absolute inset-0 w-full h-full divide-x [&>div]:flex-1 divide-stone-300 justify-around opacity-50">
                                            @for ($i = 1; $i <= 5; $i++)
                                                <div></div>
                                            @endfor
                                        </div>

                                        <div class="flex flex-col items-stretch absolute w-full h-full divide-y [&>div]:flex-1 inset-0 divide-stone-300 justify-around opacity-50">
                                            @for ($i = 1; $i <= 5; $i++)
                                                <div></div>
                                            @endfor
                                        </div>

                                        <x-ui.icon name="qr-code" class="relative z-20 text-accent-foreground"/>
                                    </div>
                                </div>

                                <div class="space-y-2 text-center">
                                    <x-ui.heading size="lg">{{ $this->modalConfig['title'] }}</x-ui.heading>
                                    <x-ui.text>{{ $this->modalConfig['description'] }}</x-ui.text>
                                </div>
                            </div>

                            @if ($showVerificationStep)
                                <div class="space-y-6">
                                    <div
                                        class="flex flex-col items-center space-y-3 justify-center"
                                        x-data
                                        x-init="$nextTick(() => $el.querySelector('input')?.focus())"
                                    >
                                        <x-ui.otp
                                            name="code"
                                            wire:model="code"
                                            length="6"
                                            label="OTP Code"
                                            class="mx-auto"
                                        />
                                    </div>

                                    <div class="flex items-center space-x-3">
                                        <x-ui.button
                                            variant="outline"
                                            class="flex-1"
                                            wire:click="resetVerification"
                                        >
                                            {{ __('Back') }}
                                        </x-ui.button>

                                        <x-ui.button
                                            variant="primary"
                                            class="flex-1"
                                            wire:click="confirmTwoFactor"
                                            x-bind:disabled="$wire.code.length < 6"
                                        >
                                            {{ __('Confirm') }}
                                        </x-ui.button>
                                    </div>
                                </div>
                            @else
                                @error('setupData')
                                    <x-ui.callout variant="danger" icon="x-circle" heading="{{ $message }}"/>
                                @enderror

                                <div class="flex justify-center">
                                    <div class="relative w-64 overflow-hidden border rounded-lg border-stone-700 aspect-square">
                                        @empty($qrCodeSvg)
                                            <div class="absolute inset-0 flex items-center justify-center bg-stone-700 animate-pulse">
                                                <x-ui.icon name="loading"/>
                                            </div>
                                        @else
                                        <div class="flex items-center justify-center h-full p-4">
                                            <div class="bg-white p-3 rounded">
                                                {!! $qrCodeSvg !!}
                                            </div>
                                        </div>
                                        @endempty
                                    </div>
                                </div>

                                <div>
                                    <x-ui.button
                                        :disabled="$errors->has('setupData')"
                                        variant="primary"
                                        class="w-full"
                                        wire:click="showVerificationIfNecessary"
                                    >
                                        {{ $this->modalConfig['buttonText'] }}
                                    </x-ui.button>
                                </div>

                                <div class="space-y-4">
                                    <div class="relative flex items-center justify-center w-full">
                                        <div class="absolute inset-0 w-full h-px top-1/2 bg-stone-600"></div>
                                        <span class="relative px-2 text-sm bg-stone-800 text-stone-400">
                                            {{ __('or, enter the code manually') }}
                                        </span>
                                    </div>

                                    <div
                                        class="flex items-center space-x-2"
                                        x-data="{
                                            copied: false,
                                            async copy() {
                                                try {
                                                    await navigator.clipboard.writeText('{{ $manualSetupKey }}');
                                                    this.copied = true;
                                                    setTimeout(() => this.copied = false, 1500);
                                                } catch (e) {
                                                    console.warn('Could not copy to clipboard');
                                                }
                                            }
                                        }"
                                    >
                                        <div class="flex items-stretch w-full border rounded-xl border-stone-700">
                                            @empty($manualSetupKey)
                                                <div class="flex items-center justify-center w-full p-3 bg-stone-700">
                                                    <x-ui.icon name="loading" variant="mini"/>
                                                </div>
                                            @else
                                                <input
                                                    type="text"
                                                    readonly
                                                    value="{{ $manualSetupKey }}"
                                                    class="w-full p-3 bg-transparent outline-none text-stone-100"
                                                />

                                                <button
                                                    @click="copy()"
                                                    class="px-3 transition-colors border-l cursor-pointer border-stone-600"
                                                >
                                                    <x-ui.icon name="document-duplicate" x-show="!copied" />
                                                    <x-ui.icon name="check" x-show="copied" x-cloak class="text-green-500" />
                                                </button>
                                            @endempty
                                        </div>
                                    </div>
                                </div>
                            @endif
                        </div>
                    </x-ui.modal>
                @endif

                <section class="mt-12">
                    <x-ui.heading>{{ __('Sessões ativas') }}</x-ui.heading>
                    <x-ui.subheading>{{ __('Dispositivos conectados na sua conta. Encerre os que você não reconhece.') }}</x-ui.subheading>

                    <div class="mt-6 space-y-3">
                        @forelse ($this->sessions as $session)
                            <div class="flex items-center justify-between gap-4 rounded-lg border border-slate-700 px-4 py-3">
                                <div class="min-w-0">
                                    <x-ui.text>{{ $session['device'] }}</x-ui.text>
                                    <x-ui.text variant="subtle" class="text-xs">
                                        {{ $session['ip'] }} · {{ $session['last_active'] }}
                                    </x-ui.text>
                                </div>

                                @if ($session['is_current'])
                                    <x-ui.text variant="subtle" class="shrink-0 text-xs">{{ __('Este dispositivo') }}</x-ui.text>
                                @else
                                    <x-ui.button
                                        variant="danger"
                                        class="shrink-0"
                                        wire:click="logoutSession('{{ $session['id'] }}')"
                                    >
                                        {{ __('Encerrar') }}
                                    </x-ui.button>
                                @endif
                            </div>
                        @empty
                            <x-ui.text variant="subtle">{{ __('Nenhuma sessão ativa encontrada.') }}</x-ui.text>
                        @endforelse
                    </div>

                    @if (count($this->sessions) > 1)
                        <div class="mt-4">
                            <x-ui.button variant="outline" wire:click="logoutOtherSessions">
                                {{ __('Encerrar todas as outras sessões') }}
                            </x-ui.button>
                        </div>
                    @endif
                </section>
            </div>
        </div>
    </div>
</section>

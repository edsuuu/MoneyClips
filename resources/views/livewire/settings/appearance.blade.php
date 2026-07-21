<section class="w-full">
    <div class="relative mb-6 w-full">
        <x-ui.heading size="xl">{{ __('Settings') }}</x-ui.heading>
        <x-ui.subheading size="lg" class="mb-6">{{ __('Manage your profile and account settings') }}</x-ui.subheading>
        <x-ui.separator />
    </div>

    <x-ui.heading class="sr-only">{{ __('Appearance settings') }}</x-ui.heading>

    <div class="flex items-start max-md:flex-col">
        <div class="me-10 w-full pb-4 md:w-[220px]">
            <x-settings.nav />
        </div>

        <x-ui.separator class="md:hidden" />

        <div class="flex-1 self-stretch max-md:pt-6">
            <x-ui.heading>{{ __('Appearance') }}</x-ui.heading>
            <x-ui.subheading>{{ __('Update the appearance settings for your account') }}</x-ui.subheading>

            <div class="mt-5 w-full max-w-lg" x-data>
                <div class="grid grid-cols-2 gap-1 rounded-xl border border-slate-800 bg-slate-900/60 p-1">
                    <button
                        type="button"
                        x-on:click="$store.theme.dark && $store.theme.toggle()"
                        x-bind:class="$store.theme.dark ? 'text-slate-400 hover:text-slate-100' : 'bg-slate-800 text-slate-100'"
                        class="flex cursor-pointer items-center justify-center gap-2 rounded-lg py-2 text-sm font-semibold transition"
                    >
                        <x-ui.icon name="sun" class="size-4" />
                        {{ __('Light theme') }}
                    </button>

                    <button
                        type="button"
                        x-on:click="$store.theme.dark || $store.theme.toggle()"
                        x-bind:class="$store.theme.dark ? 'bg-slate-800 text-slate-100' : 'text-slate-400 hover:text-slate-100'"
                        class="flex cursor-pointer items-center justify-center gap-2 rounded-lg py-2 text-sm font-semibold transition"
                    >
                        <x-ui.icon name="moon" class="size-4" />
                        {{ __('Dark theme') }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</section>

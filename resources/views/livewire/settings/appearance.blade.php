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

            <div class="mt-5 w-full max-w-lg">
                <x-ui.callout icon="moon" :heading="__('Tema escuro')">
                    A interface do estúdio usa tema escuro fixo, otimizado para edição de vídeo.
                </x-ui.callout>
            </div>
        </div>
    </div>
</section>

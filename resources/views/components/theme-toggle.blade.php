<button
    type="button"
    x-data
    x-on:click="$store.theme.toggle()"
    x-bind:aria-pressed="$store.theme.dark"
    x-bind:title="$store.theme.dark ? '{{ __('Light theme') }}' : '{{ __('Dark theme') }}'"
    {{ $attributes->class('flex size-9 shrink-0 cursor-pointer items-center justify-center rounded-lg text-slate-400 transition hover:bg-slate-900 hover:text-slate-100') }}
    data-test="theme-toggle"
>
    <x-ui.icon name="sun" class="size-5 dark:hidden" />
    <x-ui.icon name="moon" class="hidden size-5 dark:block" />
    <span class="sr-only">{{ __('Toggle theme') }}</span>
</button>

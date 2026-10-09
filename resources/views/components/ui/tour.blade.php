@props(['name', 'steps'])

<div
    x-data="guidedTour(@js($steps), @js($name))"
    x-bind:hidden="!open"
    hidden
    x-on:tour-start.window="start()"
    x-on:keydown.escape.window="close()"
    x-on:keydown.arrow-right.window="next()"
    x-on:keydown.arrow-left.window="back()"
    x-on:click.window.capture="advanceOnClick($event)"
    x-on:resize.window="place()"
    x-on:scroll.window.capture.passive="place()"
    class="pointer-events-none fixed inset-0 z-70"
>
    <div class="pointer-events-auto absolute inset-0" x-bind:style="backdrop"></div>

    <div
        class="absolute rounded-xl shadow-[0_0_0_9999px_rgb(0_0_0/0.6)] ring-2 ring-sky-500"
        x-bind:style="spotlight"
    ></div>

    <div
        x-ref="balloon"
        x-trap.noreturn.noautofocus="open"
        role="dialog"
        aria-modal="true"
        aria-labelledby="tour-title"
        aria-describedby="tour-body"
        tabindex="-1"
        class="pointer-events-auto absolute rounded-2xl border border-slate-800 bg-slate-900 p-4 shadow-2xl shadow-black/50 outline-none md:w-80"
        x-bind:style="balloon"
    >
        <div aria-live="polite">
            <div class="font-mono text-[11px] tracking-[0.08em] text-slate-500" x-text="counter"></div>
            <h2 id="tour-title" class="mt-1 text-sm font-bold text-slate-50" x-text="title"></h2>
            <p id="tour-body" class="mt-1 text-[13px] leading-relaxed text-slate-300" x-text="body"></p>
        </div>

        <div class="mt-4 flex items-center gap-2">
            <x-ui.button variant="ghost" size="sm" x-show="!isLast" x-on:click="close()">Pular tutorial</x-ui.button>
            <div class="ml-auto flex gap-2">
                <x-ui.button variant="outline" size="sm" x-show="index > 0" x-on:click="back()">Voltar</x-ui.button>
                <x-ui.button x-ref="next" variant="primary" size="sm" x-on:click="next()" x-text="nextLabel"></x-ui.button>
            </div>
        </div>
    </div>
</div>

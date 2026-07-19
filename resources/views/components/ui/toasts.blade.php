<div
    x-data="{
        toasts: [],
        add(detail) {
            const id = Date.now() + Math.random();
            this.toasts.push({ id, message: detail.message ?? '', variant: detail.variant ?? 'success' });
            setTimeout(() => this.remove(id), 5000);
        },
        remove(id) {
            this.toasts = this.toasts.filter((t) => t.id !== id);
        },
    }"
    x-on:toast.window="add($event.detail)"
    class="pointer-events-none fixed bottom-4 right-4 z-[60] flex w-80 flex-col gap-2"
>
    <template x-for="toast in toasts" :key="toast.id">
        <div
            x-transition.origin.bottom.right
            class="pointer-events-auto flex items-start gap-3 rounded-xl border p-3 text-sm shadow-xl shadow-black/40 backdrop-blur"
            :class="{
                'border-emerald-500/40 bg-emerald-950/90 text-emerald-100': toast.variant === 'success',
                'border-red-500/40 bg-red-950/90 text-red-100': toast.variant === 'danger',
                'border-amber-500/40 bg-amber-950/90 text-amber-100': toast.variant === 'warning',
            }"
        >
            <span x-text="toast.message" class="flex-1 leading-5"></span>
            <button type="button" class="cursor-pointer opacity-60 transition hover:opacity-100" x-on:click="remove(toast.id)">
                <x-ui.icon name="x-mark" class="size-3.5" />
            </button>
        </div>
    </template>
</div>

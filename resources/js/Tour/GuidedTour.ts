export interface TourStep {
    target: string;
    title: string;
    body: string;
    advance?: 'click';
}

export class GuidedTour {
    private static readonly GAP = 12;

    private static readonly GUTTER = 16;

    private static readonly PADDING = 6;

    private static readonly STRIP_BELOW = 768;

    public open = false;

    public index = 0;

    public steps: TourStep[] = [];

    public spotlight = '';

    public backdrop = '';

    public balloon = '';

    public $refs!: Record<string, HTMLElement | undefined>;

    public $nextTick!: (callback: () => void) => Promise<void>;

    private readonly allSteps: TourStep[];

    private readonly storageKey: string;

    private returnFocus: HTMLElement | null = null;

    public constructor(steps: TourStep[], name: string) {
        this.allSteps = steps;
        this.storageKey = `tour.${name}`;
    }

    public init(): void {
        if (window.localStorage.getItem(this.storageKey) === null) {
            void this.$nextTick(() => this.start());
        }
    }

    public get title(): string {
        return this.steps[this.index]?.title ?? '';
    }

    public get body(): string {
        return this.steps[this.index]?.body ?? '';
    }

    public get counter(): string {
        return `${this.index + 1} de ${this.steps.length}`;
    }

    public get isLast(): boolean {
        return this.index === this.steps.length - 1;
    }

    public get nextLabel(): string {
        return this.isLast ? 'Concluir' : 'Próximo';
    }

    public start(): void {
        this.steps = this.allSteps.filter((step) => GuidedTour.onScreen(GuidedTour.target(step)));

        if (this.steps.length === 0) {
            return;
        }

        this.returnFocus =
            document.activeElement instanceof HTMLElement ? document.activeElement : null;
        this.index = 0;
        this.open = true;
        this.show('balloon');
    }

    public next(): void {
        if (!this.open) {
            return;
        }

        if (this.isLast) {
            this.close();

            return;
        }

        this.index++;
        this.show('next');
    }

    public back(): void {
        if (!this.open || this.index === 0) {
            return;
        }

        this.index--;
        this.show('next');
    }

    public close(): void {
        if (!this.open) {
            return;
        }

        this.open = false;
        window.localStorage.setItem(this.storageKey, 'seen');
        this.returnFocus?.focus({ preventScroll: true });
    }

    public advanceOnClick(event: MouseEvent): void {
        const step = this.steps[this.index];

        if (!this.open || step?.advance !== 'click') {
            return;
        }

        if (GuidedTour.target(step)?.contains(event.target as Node)) {
            this.next();
        }
    }

    public place(): void {
        const step = this.steps[this.index];
        const target = step ? GuidedTour.target(step) : null;
        const balloon = this.$refs.balloon;

        if (!this.open || !step || !target || !balloon) {
            return;
        }

        const rect = target.getBoundingClientRect();
        const pad = GuidedTour.PADDING;
        const hole = {
            top: rect.top - pad,
            left: rect.left - pad,
            right: rect.right + pad,
            bottom: rect.bottom + pad,
        };

        this.spotlight = `top:${hole.top}px;left:${hole.left}px;width:${hole.right - hole.left}px;height:${hole.bottom - hole.top}px`;
        this.backdrop =
            step.advance === 'click'
                ? `clip-path:polygon(evenodd,0 0,100% 0,100% 100%,0 100%,0 0,${hole.left}px ${hole.top}px,${hole.right}px ${hole.top}px,${hole.right}px ${hole.bottom}px,${hole.left}px ${hole.bottom}px,${hole.left}px ${hole.top}px)`
                : '';

        if (window.innerWidth < GuidedTour.STRIP_BELOW) {
            this.balloon = `left:${GuidedTour.GUTTER}px;right:${GuidedTour.GUTTER}px;bottom:${GuidedTour.GUTTER}px`;

            return;
        }

        const below = hole.bottom + GuidedTour.GAP;
        const above = hole.top - GuidedTour.GAP - balloon.offsetHeight;
        const fitsBelow = below + balloon.offsetHeight <= window.innerHeight - GuidedTour.GUTTER;
        const top = fitsBelow ? below : Math.max(GuidedTour.GUTTER, above);
        const maxLeft = window.innerWidth - balloon.offsetWidth - GuidedTour.GUTTER;
        const left = Math.max(GuidedTour.GUTTER, Math.min(rect.left, maxLeft));
        this.balloon = `top:${top}px;left:${left}px`;
    }

    private show(focus: 'balloon' | 'next'): void {
        const step = this.steps[this.index];

        if (step) {
            GuidedTour.target(step)?.scrollIntoView({ block: 'center', inline: 'nearest' });
        }

        window.setTimeout(() => {
            this.place();
            this.$refs[focus]?.focus({ preventScroll: true });
        });
    }

    private static target(step: TourStep): HTMLElement | null {
        return document.querySelector<HTMLElement>(`[data-tour="${step.target}"]`);
    }

    private static onScreen(element: HTMLElement | null): boolean {
        if (!element?.checkVisibility()) {
            return false;
        }

        const rect = element.getBoundingClientRect();

        return rect.right > 0 && rect.left < window.innerWidth;
    }
}

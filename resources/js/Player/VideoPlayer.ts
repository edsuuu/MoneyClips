import { HlsPlayer, type HlsInstance } from './HlsPlayer';
import { ClientLogger } from '../Support/ClientLogger';

export interface VideoPlayerConfig {
    hlsSrc: string;
    fallbackSrc: string;
    poster: string;
}

interface QualityOption {
    index: number;
    label: string;
}

export class VideoPlayer {
    public ready = false;

    public playing = false;

    public muted = false;

    public volume = 1;

    public current = 0;

    public duration = 0;

    public buffered = 0;

    public scrubbing = false;

    public controlsVisible = true;

    public menuOpen = false;

    public fullscreen = false;

    public levels: QualityOption[] = [];

    public selectedLevel = -1;

    public activeLabel = 'Auto';

    public $refs!: Record<string, HTMLElement | undefined>;

    private readonly config: VideoPlayerConfig;

    private hls: HlsInstance | null = null;

    private hideTimer = 0;

    private resumeAt = 0;

    private savedAt = 0;

    public constructor(config: VideoPlayerConfig) {
        this.config = config;
    }

    public async init(): Promise<void> {
        const video = this.video();
        video.poster = this.config.poster;
        video.dataset.hlsSrc = this.config.hlsSrc;
        this.resumeAt = VideoPlayer.readStartParam();

        if (this.config.fallbackSrc) {
            video.dataset.fallbackSrc = this.config.fallbackSrc;
        }

        video.addEventListener(
            'loadedmetadata',
            () => {
                if (this.resumeAt > 0 && this.resumeAt < video.duration) {
                    video.currentTime = this.resumeAt;
                }
                this.resumeAt = 0;
            },
            { once: true },
        );

        video.addEventListener('timeupdate', () => {
            if (!this.scrubbing) {
                this.current = video.currentTime;
            }
            this.syncBuffered();
            this.persistPosition(false);
        });
        video.addEventListener('seeked', () => this.persistPosition(true));
        video.addEventListener('durationchange', () => {
            this.duration = Number.isFinite(video.duration) ? video.duration : 0;
        });
        video.addEventListener('play', () => {
            this.playing = true;
            this.scheduleHide();
        });
        video.addEventListener('pause', () => {
            this.playing = false;
            this.showControls();
            this.persistPosition(true);
        });
        video.addEventListener('volumechange', () => {
            this.muted = video.muted;
            this.volume = video.volume;
        });
        video.addEventListener('progress', () => this.syncBuffered());

        document.addEventListener('fullscreenchange', () => {
            this.fullscreen = document.fullscreenElement === this.wrapper();
        });

        try {
            this.hls = await HlsPlayer.attach(video);
            if (this.hls) {
                this.bindQuality(this.hls);
            }
        } catch (error) {
            ClientLogger.send('error', `Player HLS falhou ao montar: ${String(error)}`);
        }

        this.ready = true;
    }

    public togglePlay(): void {
        const video = this.video();
        void (video.paused ? video.play().catch(() => undefined) : video.pause());
    }

    public toggleMute(): void {
        const video = this.video();
        video.muted = !video.muted;
    }

    public setVolumeFromEvent(event: PointerEvent): void {
        const video = this.video();
        video.volume = this.fraction(event, this.$refs.volume);
        video.muted = video.volume === 0;
    }

    public startScrub(event: PointerEvent): void {
        this.scrubbing = true;
        this.moveScrub(event);
        (event.target as HTMLElement).setPointerCapture?.(event.pointerId);
    }

    public moveScrub(event: PointerEvent): void {
        if (!this.scrubbing && event.type === 'pointermove') {
            return;
        }
        this.current = this.fraction(event, this.$refs.track) * this.duration;
    }

    public endScrub(): void {
        if (!this.scrubbing) {
            return;
        }
        this.scrubbing = false;
        this.video().currentTime = this.current;
    }

    public seekTo(seconds: number): void {
        const video = this.video();
        video.currentTime = seconds;
        void video.play().catch(() => undefined);
        this.showControls();
    }

    public skip(seconds: number): void {
        this.video().currentTime = Math.max(0, Math.min(this.duration, this.current + seconds));
    }

    public selectLevel(index: number): void {
        this.selectedLevel = index;
        this.menuOpen = false;
        if (this.hls) {
            this.hls.currentLevel = index;
        }
    }

    public toggleFullscreen(): void {
        const wrapper = this.wrapper();
        void (document.fullscreenElement
            ? document.exitFullscreen()
            : wrapper.requestFullscreen().catch(() => undefined));
    }

    public showControls(): void {
        this.controlsVisible = true;
        this.scheduleHide();
    }

    public get progressPercent(): number {
        return this.duration > 0 ? (this.current / this.duration) * 100 : 0;
    }

    public get bufferedPercent(): number {
        return this.duration > 0 ? (this.buffered / this.duration) * 100 : 0;
    }

    public get volumePercent(): number {
        return this.muted ? 0 : this.volume * 100;
    }

    public get elapsedLabel(): string {
        return VideoPlayer.timecode(this.current);
    }

    public get durationLabel(): string {
        return VideoPlayer.timecode(this.duration);
    }

    public get qualityLabel(): string {
        return this.selectedLevel === -1 ? 'Auto' : this.labelFor(this.selectedLevel);
    }

    private static timecode(seconds: number): string {
        if (!Number.isFinite(seconds) || seconds <= 0) {
            return '0:00';
        }
        const total = Math.floor(seconds);
        const h = Math.floor(total / 3600);
        const m = Math.floor((total % 3600) / 60);
        const s = total % 60;
        const mm = h > 0 ? String(m).padStart(2, '0') : String(m);

        return (h > 0 ? `${String(h)}:` : '') + `${mm}:${String(s).padStart(2, '0')}`;
    }

    private bindQuality(hls: HlsInstance): void {
        const Events = (hls.constructor as typeof import('hls.js').default).Events;

        hls.on(Events.MANIFEST_PARSED, () => {
            this.levels = hls.levels
                .map((level, index) => ({ index, label: `${String(level.height)}p` }))
                .reverse();
        });
        hls.on(Events.LEVEL_SWITCHED, (_event, data) => {
            this.activeLabel = this.labelFor(data.level);
        });
    }

    private labelFor(index: number): string {
        return this.levels.find((level) => level.index === index)?.label ?? 'Auto';
    }

    private static readStartParam(): number {
        const raw = new URLSearchParams(window.location.search).get('t');
        const value = raw === null ? 0 : Number.parseInt(raw, 10);

        return Number.isFinite(value) && value > 0 ? value : 0;
    }

    private persistPosition(force: boolean): void {
        const seconds = Math.floor(this.current);
        if (seconds === this.savedAt || (!force && Math.abs(seconds - this.savedAt) < 5)) {
            return;
        }

        this.savedAt = seconds;
        const url = new URL(window.location.href);

        if (seconds > 0) {
            url.searchParams.set('t', String(seconds));
        } else {
            url.searchParams.delete('t');
        }

        window.history.replaceState(window.history.state, '', url.toString());
    }

    private syncBuffered(): void {
        const video = this.video();
        for (let i = video.buffered.length - 1; i >= 0; i -= 1) {
            if (video.buffered.start(i) <= video.currentTime) {
                this.buffered = video.buffered.end(i);

                return;
            }
        }
    }

    private fraction(event: PointerEvent, element: HTMLElement | undefined): number {
        if (!element) {
            return 0;
        }
        const rect = element.getBoundingClientRect();

        return Math.max(0, Math.min(1, (event.clientX - rect.left) / rect.width));
    }

    private scheduleHide(): void {
        window.clearTimeout(this.hideTimer);
        if (!this.playing) {
            return;
        }
        this.hideTimer = window.setTimeout(() => {
            if (this.playing && !this.menuOpen) {
                this.controlsVisible = false;
            }
        }, 2600);
    }

    private video(): HTMLVideoElement {
        return this.$refs.video as HTMLVideoElement;
    }

    private wrapper(): HTMLElement {
        return this.$refs.wrapper as HTMLElement;
    }
}

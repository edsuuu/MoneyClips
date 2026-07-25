import { HlsPlayer, type HlsInstance } from './HlsPlayer';
import { ClientLogger } from '../Support/ClientLogger';
import { Timecode } from '../Support/Timecode';

export interface StoryboardConfig {
    url: string;
    cols: number;
    rows: number;
    interval: number;
    tileWidth: number;
    tileHeight: number;
}

export interface VideoPlayerConfig {
    hlsSrc: string;
    fallbackSrc: string;
    poster: string;
    storyboard: StoryboardConfig | null;
    captionsKey: string;
}

interface QualityOption {
    index: number;
    label: string;
}

interface Preview {
    visible: boolean;
    x: number;
    time: string;
    style: string;
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

    public switching = false;

    public waiting = false;

    public captions = false;

    public editing = false;

    public levels: QualityOption[] = [];

    public selectedLevel = -1;

    public activeLabel = 'Auto';

    public preview: Preview = { visible: false, x: 0, time: '0:00', style: '' };

    public $refs!: Record<string, HTMLElement | undefined>;

    private readonly config: VideoPlayerConfig;

    private readonly storyboard: StoryboardConfig | null;

    private hls: HlsInstance | null = null;

    private hideTimer = 0;

    private switchTimer = 0;

    private resumeAfterSwitch = false;

    private resumeAt = 0;

    private savedAt = 0;

    private rangeEnd: number | null = null;

    public constructor(config: VideoPlayerConfig) {
        this.config = config;
        this.storyboard = config.storyboard;
    }

    public async init(): Promise<void> {
        const video = this.video();
        video.poster = this.config.poster;
        video.dataset.hlsSrc = this.config.hlsSrc;
        this.resumeAt = VideoPlayer.readStartParam();

        const savedVolume = Number.parseFloat(window.localStorage.getItem('player.volume') ?? '');
        if (Number.isFinite(savedVolume)) {
            video.volume = Math.min(1, Math.max(0, savedVolume));
        }
        video.muted = window.localStorage.getItem('player.muted') === '1';

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
            if (this.rangeEnd !== null && video.currentTime >= this.rangeEnd) {
                this.rangeEnd = null;
                video.pause();
            }
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
            window.localStorage.setItem('player.volume', String(video.volume));
            window.localStorage.setItem('player.muted', video.muted ? '1' : '0');
        });
        video.addEventListener('progress', () => this.syncBuffered());
        video.addEventListener('waiting', () => {
            this.waiting = true;
        });
        video.addEventListener('playing', () => {
            this.waiting = false;
        });
        video.addEventListener('canplay', () => {
            this.waiting = false;
        });

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

        this.restoreCaptions();
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

    public trackMove(event: PointerEvent): void {
        this.moveScrub(event);
        this.updatePreview(event);
    }

    public hidePreview(): void {
        this.preview = { ...this.preview, visible: false };
    }

    private updatePreview(event: PointerEvent): void {
        const track = this.$refs.track;

        if (this.storyboard === null || track === undefined || this.duration <= 0) {
            return;
        }

        const fraction = this.fraction(event, track);
        const time = fraction * this.duration;
        const { cols, rows, interval, tileWidth: w, tileHeight: h, url } = this.storyboard;
        const index = Math.max(0, Math.min(cols * rows - 1, Math.floor(time / interval)));
        const col = index % cols;
        const row = Math.floor(index / cols);

        this.preview = {
            visible: true,
            x: fraction * track.getBoundingClientRect().width,
            time: VideoPlayer.timecode(time),
            style:
                `width:${String(w)}px;height:${String(h)}px;background-image:url('${url}');` +
                `background-position:-${String(col * w)}px -${String(row * h)}px;` +
                `background-size:${String(cols * w)}px ${String(rows * h)}px;`,
        };
    }

    public endScrub(): void {
        if (!this.scrubbing) {
            return;
        }
        this.scrubbing = false;
        this.rangeEnd = null;
        this.video().currentTime = this.current;
    }

    public seekTo(seconds: number): void {
        this.rangeEnd = null;
        const video = this.video();
        video.currentTime = seconds;
        void video.play().catch(() => undefined);
        this.showControls();
    }

    public playRange(start: number, end: number): void {
        this.seekTo(start);
        this.rangeEnd = end;
    }

    public scrubTo(seconds: number): void {
        this.rangeEnd = null;
        this.video().currentTime = seconds;
        this.showControls();
    }

    public selectLevel(index: number): void {
        this.selectedLevel = index;
        this.menuOpen = false;
        if (!this.hls) {
            return;
        }

        const video = this.video();
        this.switching = true;
        // Pausa áudio+vídeo durante a troca: o hls.js troca "seamless" e o áudio
        // continuaria tocando enquanto a imagem recarrega, dessincronizando.
        this.resumeAfterSwitch = !video.paused;
        video.pause();

        window.clearTimeout(this.switchTimer);
        // teto: se LEVEL_SWITCHED não vier (troca já bufferada), destrava e retoma.
        this.switchTimer = window.setTimeout(() => this.finishSwitch(), 4000);
        this.hls.currentLevel = index;
    }

    private finishSwitch(): void {
        window.clearTimeout(this.switchTimer);
        this.switching = false;
        if (this.resumeAfterSwitch) {
            this.resumeAfterSwitch = false;
            void this.video()
                .play()
                .catch(() => undefined);
        }
    }

    public toggleCaptions(): void {
        this.applyCaptions(!this.captions);
    }

    private restoreCaptions(): void {
        if (this.config.captionsKey === '') {
            return;
        }

        this.applyCaptions(window.localStorage.getItem(this.captionsStorageKey()) === '1');
    }

    private applyCaptions(on: boolean): void {
        const track = this.captionTrack();

        if (track === null) {
            return;
        }

        this.captions = on;
        track.mode = on ? 'showing' : 'hidden';
        window.localStorage.setItem(this.captionsStorageKey(), on ? '1' : '0');
    }

    private captionTrack(): TextTrack | null {
        return (this.$refs.captions as HTMLTrackElement | undefined)?.track ?? null;
    }

    // Após editar a legenda o transcript.json muda, mas o <track> já parseou as
    // cues antigas. Trocar o src (cache-bust) força o re-fetch das novas sem F5.
    public reloadCaptions(): void {
        const element = this.$refs.captions as HTMLTrackElement | undefined;

        if (element === undefined) {
            return;
        }

        const wasShowing = this.captions;
        element.src = `${element.src.split('?')[0]}?v=${String(Date.now())}`;

        const track = element.track;
        if (track !== null) {
            track.mode = wasShowing ? 'showing' : 'hidden';
        }
    }

    private captionsStorageKey(): string {
        return `player.captions.${this.config.captionsKey}`;
    }

    public toggleFullscreen(): void {
        const wrapper = this.wrapper();
        void (document.fullscreenElement
            ? document.exitFullscreen()
            : wrapper.requestFullscreen().catch(() => undefined));
    }

    // Atalhos: só disparam com o player focado (o wrapper tem tabindex e recebe
    // foco no clique). Enquanto o foco está num input — ex.: editor de legenda —
    // eles não chegam aqui.
    public onKey(event: KeyboardEvent): void {
        const video = this.video();
        const STEP = 0.05;

        switch (event.key) {
            case ' ':
                event.preventDefault();
                this.togglePlay();
                break;
            case 'm':
            case 'M':
                this.toggleMute();
                break;
            case 'ArrowRight':
                event.preventDefault();
                video.currentTime = Math.min(
                    Number.isFinite(video.duration) ? video.duration : video.currentTime + 1,
                    video.currentTime + 1,
                );
                this.showControls();
                break;
            case 'ArrowLeft':
                event.preventDefault();
                video.currentTime = Math.max(0, video.currentTime - 1);
                this.showControls();
                break;
            case 'ArrowUp':
                event.preventDefault();
                video.muted = false;
                video.volume = Math.min(1, video.volume + STEP);
                break;
            case 'ArrowDown':
                event.preventDefault();
                video.volume = Math.max(0, video.volume - STEP);
                break;
            case 'f':
            case 'F':
                this.toggleFullscreen();
                break;
            default:
                break;
        }
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
        return Timecode.format(seconds, false);
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
            this.finishSwitch();
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
            url.searchParams.set('t', `${String(seconds)}s`);
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

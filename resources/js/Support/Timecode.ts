export class Timecode {
    public static format(seconds: number, padMinutes = true): string {
        const total = Number.isFinite(seconds) ? Math.max(0, Math.floor(seconds)) : 0;
        const h = Math.floor(total / 3600);
        const m = Math.floor((total % 3600) / 60);
        const s = total % 60;
        const mm = h > 0 || padMinutes ? String(m).padStart(2, '0') : String(m);
        const ss = String(s).padStart(2, '0');

        return h > 0 ? `${String(h)}:${mm}:${ss}` : `${mm}:${ss}`;
    }
}

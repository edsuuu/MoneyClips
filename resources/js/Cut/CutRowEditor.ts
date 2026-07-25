import { Timecode } from '../Support/Timecode';

export class CutRowEditor {
    private static readonly MAX_CUT_SECONDS = 180;

    public editingRange = false;

    public confirmingDelete = false;

    public start = '';

    public end = '';

    public sanitize(field: 'start' | 'end'): void {
        this[field] = this[field].replace(/[^0-9:]/g, '');
    }

    public step(field: 'start' | 'end', dir: number): void {
        const start = CutRowEditor.toSeconds(this.start);
        const end = CutRowEditor.toSeconds(this.end);

        if (field === 'start') {
            const floor = Math.max(0, end - CutRowEditor.MAX_CUT_SECONDS);
            this.start = Timecode.format(Math.min(Math.max(start + dir, floor), end - 1));
        } else {
            const ceiling = start + CutRowEditor.MAX_CUT_SECONDS;
            this.end = Timecode.format(Math.max(start + 1, Math.min(end + dir, ceiling)));
        }
    }

    private static toSeconds(value: string): number {
        return value.split(':').reduce((total, part) => total * 60 + (Number(part) || 0), 0);
    }
}

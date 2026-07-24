export interface RawSegment {
    i: number;
    start: number;
    text: string;
}

interface EditableSegment extends RawSegment {
    editing: boolean;
    original: string;
}

interface SaveWire {
    saveTranscript: (edits: Array<{ i: number; text: string }>) => Promise<boolean>;
}

export class SubtitleEditor {
    public segments: EditableSegment[];

    public search = '';

    public saving = false;

    public $wire!: SaveWire;

    public $dispatch!: (event: string, detail?: unknown) => void;

    public constructor(segments: RawSegment[]) {
        this.segments = segments.map((segment) => ({
            ...segment,
            editing: false,
            original: segment.text,
        }));
    }

    public init(): void {
        window.addEventListener('beforeunload', (event: BeforeUnloadEvent) => {
            if (this.dirty) {
                event.preventDefault();
                event.returnValue = '';
            }
        });
    }

    public get editedCount(): number {
        return this.segments.filter((segment) => segment.editing).length;
    }

    public get hasEmptyEdited(): boolean {
        return this.segments.some((segment) => segment.editing && segment.text.trim() === '');
    }

    public get dirty(): boolean {
        return this.segments.some((segment) => segment.text !== segment.original);
    }

    public get canSave(): boolean {
        return !this.saving && this.editedCount > 0 && !this.hasEmptyEdited;
    }

    public visible(segment: EditableSegment): boolean {
        const query = this.search.trim().toLowerCase();

        return query === '' || segment.text.toLowerCase().includes(query);
    }

    public async save(): Promise<void> {
        if (!this.canSave) {
            return;
        }

        this.saving = true;

        const edits = this.segments
            .filter((segment) => segment.editing)
            .map((segment) => ({ i: segment.i, text: segment.text.trim() }));

        try {
            const ok = await this.$wire.saveTranscript(edits);

            if (ok) {
                this.segments.forEach((segment) => {
                    if (segment.editing) {
                        segment.text = segment.text.trim();
                        segment.original = segment.text;
                        segment.editing = false;
                    }
                });
                this.$dispatch('modal-close', { name: 'editar-legenda' });
                this.$dispatch('captions-refresh');
            }
        } finally {
            this.saving = false;
        }
    }

    public timecode(seconds: number): string {
        const total = Math.max(0, Math.floor(seconds));
        const h = Math.floor(total / 3600);
        const m = Math.floor((total % 3600) / 60);
        const s = total % 60;
        const mm = String(m).padStart(2, '0');
        const ss = String(s).padStart(2, '0');

        return h > 0 ? `${String(h)}:${mm}:${ss}` : `${mm}:${ss}`;
    }
}

import type { ModeDefinition } from './ReframeTypes';

export class ReframeModes {
    public static readonly OUT_W = 1080;

    public static readonly OUT_H = 1920;

    public static readonly ALL: Record<string, ModeDefinition> = {
        vertical: {
            label: 'Vertical',
            fit: 'cover',
            lock: 'slot',
            slots: [{ x: 0, y: 0, w: ReframeModes.OUT_W, h: ReframeModes.OUT_H }],
        },
        split: {
            label: 'Dividido',
            fit: 'cover',
            lock: 'slot',
            slots: [
                { x: 0, y: 0, w: ReframeModes.OUT_W, h: ReframeModes.OUT_H / 2 },
                {
                    x: 0,
                    y: ReframeModes.OUT_H / 2,
                    w: ReframeModes.OUT_W,
                    h: ReframeModes.OUT_H / 2,
                },
            ],
        },
        trio: {
            label: 'Trio',
            fit: 'cover',
            lock: 'slot',
            slots: [0, 1, 2].map((i) => ({
                x: 0,
                y: (ReframeModes.OUT_H / 3) * i,
                w: ReframeModes.OUT_W,
                h: ReframeModes.OUT_H / 3,
            })),
        },
        centered: {
            label: 'Centrado',
            fit: 'contain',
            lock: 'source',
            slots: [{ x: 0, y: 0, w: ReframeModes.OUT_W, h: ReframeModes.OUT_H }],
        },
    };

    public static readonly REGION_LABELS: Record<number, string[]> = {
        1: ['Região'],
        2: ['Topo', 'Base'],
        3: ['Topo', 'Meio', 'Base'],
    };

    public static get(mode: string): ModeDefinition {
        const definition = ReframeModes.ALL[mode];

        if (definition === undefined) {
            throw new Error(`Modo de reframe desconhecido: ${mode}.`);
        }

        return definition;
    }

    public static exists(mode: string): boolean {
        return ReframeModes.ALL[mode] !== undefined;
    }

    public static options(): { value: string; label: string }[] {
        return Object.entries(ReframeModes.ALL).map(([value, def]) => ({
            value,
            label: def.label,
        }));
    }
}

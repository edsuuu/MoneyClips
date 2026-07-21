export interface Region {
    x: number;
    y: number;
    w: number;
    h: number;
}

export interface Keyframe {
    t: number;
    regions: Region[];
}

export interface Slot {
    x: number;
    y: number;
    w: number;
    h: number;
}

export type ModeFit = 'cover' | 'contain';

export type ModeLock = 'slot' | 'source' | 'free';

export interface ModeDefinition {
    label: string;
    fit: ModeFit;
    lock: ModeLock;
    slots: Slot[];
}

export interface ReframeSettings {
    background: string;
}

export interface ReframePayload {
    editId: number | null;
    videoUrl: string | null;
    mode: string;
    keyframes: Keyframe[];
    settings: ReframeSettings;
}

export type DragHandle = 'move' | 'nw' | 'ne' | 'sw' | 'se';

export interface DragState {
    type: DragHandle;
    pointerId: number;
    startX: number;
    startY: number;
    start: Region;
    kfIndex: number;
}

import type { BoundingBox } from './DomainType';

export interface InferenceResult {
    boxes: BoundingBox[];
    realWidth: number;
    realHeight: number;

    found: boolean;
}

export interface ImagePlacement {
    webX: number;
    webY: number;
    webWidth: number;
    webHeight: number;
    realWidth: number;
    realHeight: number;
}

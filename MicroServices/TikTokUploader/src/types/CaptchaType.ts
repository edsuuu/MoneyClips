import type { BoundingBox } from './DomainType';

/** Resultado de uma inferência do Roboflow sobre a imagem do captcha. */
export interface InferenceResult {
    boxes: BoundingBox[];
    realWidth: number;
    realHeight: number;
    /** Só relevante no modelo de "objetos iguais": true se achou exatamente um par. */
    found: boolean;
}

/** Posição da imagem do captcha na viewport + dimensões reais da imagem. */
export interface ImagePlacement {
    webX: number;
    webY: number;
    webWidth: number;
    webHeight: number;
    realWidth: number;
    realHeight: number;
}

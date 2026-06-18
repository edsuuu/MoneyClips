/**
 * Conversão das bounding boxes do Roboflow (coordenadas na imagem real) para
 * coordenadas de clique na viewport do navegador. Porta de
 * convert_to_webpage_coordinates. Função pura.
 */

import type { ImagePlacement } from '@/types/CaptchaType';
import type { BoundingBox, Point } from '@/types/DomainType';

export function toWebpageCoordinates(boxes: BoundingBox[], placement: ImagePlacement): Point[] {
    const { webX, webY, webWidth, webHeight, realWidth, realHeight } = placement;

    return boxes.map((box) => ({
        x: webX + (box.x * webWidth) / realWidth,
        y: webY + (box.y * webHeight) / realHeight,
    }));
}

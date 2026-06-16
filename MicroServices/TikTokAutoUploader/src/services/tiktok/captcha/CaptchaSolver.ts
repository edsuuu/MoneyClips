/**
 * Resolução de captcha via Roboflow Hosted Inference. Porta de
 * run_inference_on_image / run_inference_on_image_tougher do function.py.
 *
 * Dois modelos:
 *   - SAME_OBJECTS_MODEL: acha o par de objetos idênticos ("select 2 objects
 *     that are the same").
 *   - OBJECT_CLASS_MODEL: acha todas as ocorrências de uma classe específica
 *     (captcha de pergunta, ex: "select the football").
 */

import { settings } from '@/config/env/Env';
import type { InferenceResult } from '@/types/CaptchaType';
import type { BoundingBox } from '@/types/DomainType';

const ROBOFLOW_URL = 'https://detect.roboflow.com';
const SAME_OBJECTS_MODEL = 'tk-3nwi9/2';
const OBJECT_CLASS_MODEL = 'captcha-2-6ehbe/2';

interface RoboflowPrediction extends BoundingBox {
    class: string;
    confidence: number;
}

interface RoboflowResponse {
    predictions: RoboflowPrediction[];
    image: { width: number; height: number };
}

function toBox(p: RoboflowPrediction): BoundingBox {
    return { x: p.x, y: p.y, width: p.width, height: p.height };
}

export class CaptchaSolver {
    private readonly apiKey: string;

    public constructor(apiKey: string = settings.roboflowApiKey) {
        this.apiKey = apiKey;
    }

    /** Baixa a imagem do captcha e devolve em base64 (sem prefixo data:). */
    public async fetchImageBase64(imageUrl: string): Promise<string> {
        const resp = await fetch(imageUrl);
        const buffer = Buffer.from(await resp.arrayBuffer());
        return buffer.toString('base64');
    }

    /** Modelo de objetos iguais: retorna o par de boxes a clicar. */
    public async inferSameObjects(imageBase64: string): Promise<InferenceResult> {
        const resp = await this.infer(SAME_OBJECTS_MODEL, imageBase64);

        const seen: RoboflowPrediction[] = [];
        const boxes: BoundingBox[] = [];
        let pairs = 0;

        for (const pred of resp.predictions) {
            const firstMatch = seen.find((s) => s.class === pred.class);
            if (firstMatch) {
                boxes.push(toBox(pred), toBox(firstMatch));
                pairs += 1;
            }
            seen.push(pred);
        }

        return {
            boxes,
            realWidth: resp.image.width,
            realHeight: resp.image.height,
            found: pairs === 1,
        };
    }

    /** Modelo de classe específica: retorna todas as boxes da classe pedida. */
    public async inferObjectClass(imageBase64: string, object: string): Promise<InferenceResult> {
        const resp = await this.infer(OBJECT_CLASS_MODEL, imageBase64);
        const boxes = resp.predictions.filter((p) => p.class === object).map(toBox);

        return {
            boxes,
            realWidth: resp.image.width,
            realHeight: resp.image.height,
            found: boxes.length > 0,
        };
    }

    private async infer(modelId: string, imageBase64: string): Promise<RoboflowResponse> {
        const url = `${ROBOFLOW_URL}/${modelId}?api_key=${this.apiKey}`;
        const resp = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: imageBase64,
        });

        if (!resp.ok) {
            throw new Error(`Roboflow respondeu ${resp.status} para o modelo ${modelId}.`);
        }

        return (await resp.json()) as RoboflowResponse;
    }
}

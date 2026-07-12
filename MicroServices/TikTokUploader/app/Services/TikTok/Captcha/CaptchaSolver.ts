import { settings } from '@/Config/Env';
import type { InferenceResult } from '@/Types/CaptchaType';
import type { BoundingBox } from '@/Types/DomainType';

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

    public async fetchImageBase64(imageUrl: string): Promise<string> {
        const resp = await fetch(imageUrl);
        const buffer = Buffer.from(await resp.arrayBuffer());
        return buffer.toString('base64');
    }

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

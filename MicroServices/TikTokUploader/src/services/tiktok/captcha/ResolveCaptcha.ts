/**
 * Orquestração da resolução de captcha na página — porta de
 * _solve_captcha_if_needed. Suporta os dois tipos de captcha do TikTok:
 *   1. "Select 2 objects that are the same"
 *   2. Pergunta sobre um objeto (ex: "select the object used in hoops")
 */

import type { Page } from 'playwright';

import { logger } from '@/config/logger/Logger';
import {
    CAPTCHA_CONTAINER,
    CAPTCHA_FAIL,
    CAPTCHA_IMAGE,
    CAPTCHA_QUESTION,
    CAPTCHA_REFRESH,
    CAPTCHA_SUBMIT,
    CAPTCHA_SUCCESS,
    ROTATION_CAPTCHA_IMAGES,
    ROTATION_REFRESH_BUTTON,
    ROTATION_SLIDE_BUTTON,
} from '@/services/tiktok/Selectors';
import type { InferenceResult } from '@/types/CaptchaType';
import type { Point } from '@/types/DomainType';
import { sleep } from '@/utils/Sleep';

import { CaptchaSolver } from './CaptchaSolver';
import { toWebpageCoordinates } from './Geometry';
import { objectForQuestion } from './Questions';

const SAME_OBJECTS_PROMPTS = [
    'Select 2 objects that are the same',
    'Select two objects that are the same',
];

const ROTATION_PROMPTS = [
    'drag the slider',
    'slide the slider',
    'rotate',
    'rotation',
    'arraste o controle deslizante',
    'encaixar',
    'quebra-cabeças',
];

interface RotationEstimate {
    angle: number;
    confidence: number;
}

async function getQuestion(page: Page): Promise<string> {
    const oldQuestion = page.locator(CAPTCHA_QUESTION);
    if (await oldQuestion.isVisible()) {
        return (await oldQuestion.textContent()) ?? '';
    }
    const container = page.locator(CAPTCHA_CONTAINER);
    if (await container.isVisible()) {
        return (await container.textContent()) ?? '';
    }
    return '';
}

async function getImageUrl(page: Page): Promise<string | null> {
    return page
        .locator(CAPTCHA_IMAGE)
        .getAttribute('src', { timeout: 500 })
        .catch(() => null);
}

async function waitForCaptchaControls(page: Page): Promise<void> {
    const deadline = Date.now() + 5000;
    while (Date.now() < deadline) {
        if (
            (await page.locator(ROTATION_SLIDE_BUTTON).isVisible()) ||
            (await page.locator(CAPTCHA_IMAGE).isVisible())
        ) {
            return;
        }
        await sleep(100);
    }
}

/** Converte as boxes do solver em coordenadas de clique na viewport. */
async function boxesToPoints(page: Page, result: InferenceResult): Promise<Point[]> {
    const web = await page.locator(CAPTCHA_IMAGE).boundingBox();
    if (!web) {
        return [];
    }

    const points = toWebpageCoordinates(result.boxes, {
        webX: web.x,
        webY: web.y,
        webWidth: web.width,
        webHeight: web.height,
        realWidth: result.realWidth,
        realHeight: result.realHeight,
    });

    // Fallback: se a inferência não achou nada, clica no centro aproximado.
    return points.length > 0 ? points : [{ x: web.x + 50, y: web.y + 50 }];
}

async function clickPoints(page: Page, points: Point[]): Promise<void> {
    for (const point of points) {
        await page.mouse.click(point.x, point.y);
        await sleep(500);
    }
}

/** Aguarda o veredito (sucesso/falha) após submeter o captcha. */
async function waitForVerdict(page: Page): Promise<'pass' | 'fail'> {
    const deadline = Date.now() + 10_000;
    while (Date.now() < deadline) {
        if (await page.locator(CAPTCHA_SUCCESS).isVisible()) {
            return 'pass';
        }
        if (await page.locator(CAPTCHA_FAIL).isVisible()) {
            return 'fail';
        }
        if (!(await page.locator(CAPTCHA_CONTAINER).isVisible())) {
            return 'pass';
        }
        await sleep(100);
    }
    return 'fail';
}

/** Resolve o captcha de "dois objetos iguais". */
async function solveSameObjects(page: Page, solver: CaptchaSolver): Promise<boolean> {
    let result: InferenceResult;
    do {
        await page.click(CAPTCHA_REFRESH);
        const imageUrl = await getImageUrl(page);
        if (!imageUrl) {
            return false;
        }
        const base64 = await solver.fetchImageBase64(imageUrl);
        result = await solver.inferSameObjects(base64);
    } while (!result.found);

    await clickPoints(page, await boxesToPoints(page, result));
    await page.click(CAPTCHA_SUBMIT);
    await sleep(500);
    return (await waitForVerdict(page)) === 'pass';
}

/** Resolve o captcha de pergunta sobre um objeto. */
async function solveObjectQuestion(
    page: Page,
    solver: CaptchaSolver,
    object: string,
): Promise<boolean> {
    const imageUrl = await getImageUrl(page);
    if (!imageUrl) {
        return false;
    }
    const base64 = await solver.fetchImageBase64(imageUrl);
    const result = await solver.inferObjectClass(base64, object);

    await clickPoints(page, await boxesToPoints(page, result));
    await page.click(CAPTCHA_SUBMIT);
    await sleep(1000);
    return (await waitForVerdict(page)) === 'pass';
}

/** Atualiza o captcha até cair numa pergunta reconhecida pelo mapa. */
async function refreshUntilKnown(page: Page): Promise<string> {
    let question = await getQuestion(page);
    while (objectForQuestion(question) === null) {
        await page.click(CAPTCHA_REFRESH);
        await sleep(1000);
        question = await getQuestion(page);
    }
    return objectForQuestion(question) as string;
}

function isRotationQuestion(question: string): boolean {
    const normalized = question.toLowerCase();
    return ROTATION_PROMPTS.some((prompt) => normalized.includes(prompt));
}

async function estimateRotationAngle(page: Page): Promise<RotationEstimate> {
    const selector = JSON.stringify(ROTATION_CAPTCHA_IMAGES);

    return page.evaluate<RotationEstimate>(`(() => {
        const images = [...document.querySelectorAll(${selector})].filter(
            (img) => img.complete && img.naturalWidth > 0 && img.naturalHeight > 0
        );
        if (images.length < 2) {
            return { angle: 0, confidence: 0 };
        }

        const sampleSize = 160;
        const outer = images[0];
        const inner = images[1];

        const canvas = document.createElement('canvas');
        canvas.width = sampleSize;
        canvas.height = sampleSize;
        const ctx = canvas.getContext('2d');
        if (!ctx) {
            return { angle: 0, confidence: 0 };
        }

        function pixelsFor(image, angle) {
            ctx.clearRect(0, 0, sampleSize, sampleSize);
            ctx.save();
            ctx.translate(sampleSize / 2, sampleSize / 2);
            ctx.rotate((angle * Math.PI) / 180);
            ctx.drawImage(image, -sampleSize / 2, -sampleSize / 2, sampleSize, sampleSize);
            ctx.restore();
            return ctx.getImageData(0, 0, sampleSize, sampleSize).data;
        }

        function luminanceAt(data, offset) {
            return data[offset] * 0.299 + data[offset + 1] * 0.587 + data[offset + 2] * 0.114;
        }

        function score(a, b) {
            let total = 0;
            let count = 0;
            const center = sampleSize / 2;
            const minRadius = sampleSize * 0.32;
            const maxRadius = sampleSize * 0.49;

            for (let y = 1; y < sampleSize - 1; y += 1) {
                for (let x = 1; x < sampleSize - 1; x += 1) {
                    const dx = x - center;
                    const dy = y - center;
                    const radius = Math.hypot(dx, dy);
                    if (radius < minRadius || radius > maxRadius) {
                        continue;
                    }

                    const offset = (y * sampleSize + x) * 4;
                    total += Math.abs(luminanceAt(a, offset) - luminanceAt(b, offset));
                    count += 1;
                }
            }

            return count === 0 ? Number.POSITIVE_INFINITY : total / count;
        }

        const innerPixels = pixelsFor(inner, 0);
        let bestAngle = 0;
        let bestScore = Number.POSITIVE_INFINITY;
        let secondBestScore = Number.POSITIVE_INFINITY;

        for (let angle = 0; angle < 360; angle += 1) {
            const currentScore = score(pixelsFor(outer, angle), innerPixels);
            if (currentScore < bestScore) {
                secondBestScore = bestScore;
                bestScore = currentScore;
                bestAngle = angle;
            } else if (currentScore < secondBestScore) {
                secondBestScore = currentScore;
            }
        }

        const confidence =
            secondBestScore === Number.POSITIVE_INFINITY
                ? 0
                : Math.max(0, (secondBestScore - bestScore) / secondBestScore);

        return { angle: bestAngle, confidence };
    })()`);
}

async function dragRotationSlider(page: Page, angle: number): Promise<void> {
    const button = page.locator(ROTATION_SLIDE_BUTTON);
    const buttonBox = await button.boundingBox();
    const trackBox = await button
        .locator('xpath=ancestor::div[contains(@class, "cap-h-40")][1]')
        .boundingBox();

    if (!buttonBox || !trackBox) {
        throw new Error('Slider do captcha de rotação não foi encontrado.');
    }

    const maxDrag = Math.max(1, trackBox.width - buttonBox.width);
    const targetOffsetPx = (angle / 360) * maxDrag;
    const startX = buttonBox.x + buttonBox.width / 2;
    const startY = buttonBox.y + buttonBox.height / 2;
    const targetX = startX + targetOffsetPx;
    const steps = 24;

    logger.info(`Captcha de rotação: arrastando slider ${targetOffsetPx.toFixed(1)}px.`);

    await page.mouse.move(startX, startY);
    await sleep(150);
    await page.mouse.down();
    await sleep(150);
    for (let step = 1; step <= steps; step += 1) {
        const progress = step / steps;
        const eased = 1 - (1 - progress) * (1 - progress);
        await page.mouse.move(startX + (targetX - startX) * eased, startY, { steps: 1 });
        await sleep(30);
    }
    await sleep(250);
    await page.mouse.up();
}

async function refreshRotationCaptcha(page: Page): Promise<void> {
    await page
        .locator(ROTATION_REFRESH_BUTTON)
        .click({ timeout: 1000 })
        .catch(() => undefined);
    await sleep(1000);
}

async function solveRotationCaptcha(page: Page): Promise<void> {
    const MAX_ATTEMPTS = 5;

    for (let attempt = 1; attempt <= MAX_ATTEMPTS; attempt += 1) {
        await page.waitForSelector(ROTATION_SLIDE_BUTTON, { timeout: 10_000 });
        await page.waitForSelector(ROTATION_CAPTCHA_IMAGES, { timeout: 10_000 });

        const estimate = await estimateRotationAngle(page);
        logger.info(
            `Captcha de rotação: ângulo estimado ${estimate.angle}° ` +
                `(confiança ${estimate.confidence.toFixed(3)}).`,
        );

        await dragRotationSlider(page, estimate.angle);

        if ((await waitForVerdict(page)) === 'pass') {
            logger.info('Captcha de rotação resolvido.');
            return;
        }

        await refreshRotationCaptcha(page);
    }

    throw new Error('Falha ao resolver o captcha de rotação após várias tentativas.');
}

/**
 * Resolve o captcha se ele estiver presente. Faz várias tentativas até passar
 * ou estourar o limite.
 */
export async function resolveCaptcha(page: Page, solver: CaptchaSolver): Promise<void> {
    await waitForCaptchaControls(page);

    const hasRotationCaptcha = await page.locator(ROTATION_SLIDE_BUTTON).isVisible();
    const hasClickCaptcha = !hasRotationCaptcha && (await getImageUrl(page)) !== null;
    if (!hasClickCaptcha && !hasRotationCaptcha) {
        return;
    }

    logger.info('Captcha detectado. Tentando resolver...');

    const initialQuestion = await getQuestion(page);
    if (hasRotationCaptcha || isRotationQuestion(initialQuestion)) {
        await solveRotationCaptcha(page);
        return;
    }

    const MAX_ATTEMPTS = 20;
    for (let attempt = 1; attempt <= MAX_ATTEMPTS; attempt += 1) {
        const question = await getQuestion(page);
        const isSameObjects = SAME_OBJECTS_PROMPTS.some((p) => question.includes(p));

        const passed = isSameObjects
            ? await solveSameObjects(page, solver)
            : await solveObjectQuestion(page, solver, await refreshUntilKnown(page));

        if (passed) {
            logger.info('Captcha resolvido.');
            return;
        }
        await page.click(CAPTCHA_REFRESH);
    }

    throw new Error('Falha ao resolver o captcha após várias tentativas.');
}

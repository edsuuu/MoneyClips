/**
 * Interações com aparência humana — substitui o SyncUserSimulator do
 * phantomwright (que não existe em TS). Digitação caractere a caractere com
 * atrasos aleatórios e hover antes do clique.
 */

import type { Locator, Page } from 'playwright';

import { sleep } from '@/utils/Sleep';

function randomDelay(min: number, max: number): number {
    return Math.floor(Math.random() * (max - min + 1)) + min;
}

/** Hover + clique com uma pequena pausa, imitando um usuário. */
export async function humanClick(locator: Locator): Promise<void> {
    await locator.hover();
    await sleep(randomDelay(120, 320));
    await locator.click();
}

/** Digita o texto caractere a caractere com atrasos curtos e variáveis. */
export async function humanType(page: Page, text: string): Promise<void> {
    for (const char of text) {
        await page.keyboard.type(char);
        await sleep(randomDelay(25, 90));
    }
}

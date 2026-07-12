import { mkdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { chromium } from 'playwright-extra';
import stealth from 'puppeteer-extra-plugin-stealth';

chromium.use(stealth());

const COOKIES_DIR = 'cookies';
const COOKIES_FILE = join(COOKIES_DIR, 'TK_cookies.json');
const LOGIN_TIMEOUT_MS = 10 * 60_000;

function sleep(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

function isLoggedIn(page) {
    const url = page.url();

    return url.includes('tiktok.com') && !url.includes('/login');
}

const browser = await chromium.launch({
    headless: false,
    args: ['--no-sandbox', '--disable-gpu'],
});

const page = await browser.newPage();
await page.goto('https://www.tiktok.com/login');

console.log('Faça o login no navegador aberto — os cookies serão salvos ao entrar.');

const deadline = Date.now() + LOGIN_TIMEOUT_MS;
while (!isLoggedIn(page) && Date.now() < deadline) {
    await sleep(1000);
}

if (!isLoggedIn(page)) {
    console.error('Login não concluído dentro do tempo limite.');
    await browser.close();
    process.exit(1);
}

await sleep(2000);
const cookies = await page.context().cookies();

mkdirSync(COOKIES_DIR, { recursive: true });
writeFileSync(COOKIES_FILE, JSON.stringify(cookies, null, 2));
console.log(`Cookies salvos em ${COOKIES_FILE} (${cookies.length} cookies).`);

await browser.close();

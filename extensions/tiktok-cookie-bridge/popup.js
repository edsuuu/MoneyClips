const $ = (id) => document.getElementById(id);

const SAMESITE_MAP = {
    no_restriction: 'None',
    lax: 'Lax',
    strict: 'Strict',
    unspecified: undefined,
};

chrome.storage.local.get(['endpoint', 'token'], (s) => {
    if (s.endpoint) $('endpoint').value = s.endpoint;
    if (s.token) $('token').value = s.token;
});

function setStatus(text, cls) {
    const el = $('status');
    el.textContent = text;
    el.className = cls || '';
}

async function readTiktokCookies() {
    const buckets = await Promise.all([
        chrome.cookies.getAll({ domain: '.tiktok.com' }),
        chrome.cookies.getAll({ domain: 'tiktok.com' }),
        chrome.cookies.getAll({ domain: 'www.tiktok.com' }),
    ]);
    const seen = new Map();
    for (const list of buckets) {
        for (const c of list) {
            const key = `${c.name}|${c.domain}|${c.path}`;
            if (!seen.has(key)) seen.set(key, c);
        }
    }
    return [...seen.values()].map((c) => ({
        name: c.name,
        value: c.value,
        domain: c.domain,
        path: c.path,
        expires: c.expirationDate ? Math.floor(c.expirationDate) : undefined,
        httpOnly: c.httpOnly,
        secure: c.secure,
        sameSite: SAMESITE_MAP[c.sameSite],
    }));
}

$('send').addEventListener('click', async () => {
    const endpoint = $('endpoint').value.trim();
    const token = $('token').value.trim();
    if (!endpoint || !token) {
        setStatus('Preencha endpoint e token.', 'err');
        return;
    }
    chrome.storage.local.set({ endpoint, token });
    setStatus('Lendo cookies…');

    let cookies;
    try {
        cookies = await readTiktokCookies();
    } catch (e) {
        setStatus('Erro lendo cookies: ' + e.message, 'err');
        return;
    }

    if (cookies.length === 0) {
        setStatus('Nenhum cookie do tiktok.com encontrado neste navegador.', 'err');
        return;
    }

    setStatus(`Enviando ${cookies.length} cookies…`);

    try {
        const r = await fetch(endpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Bridge-Token': token,
            },
            body: JSON.stringify({ cookies }),
        });
        if (r.ok) {
            setStatus(`OK — ${cookies.length} cookies enviados.`, 'ok');
        } else {
            const body = await r.text();
            setStatus(`Falhou: HTTP ${r.status} ${body.slice(0, 120)}`, 'err');
        }
    } catch (e) {
        setStatus('Erro de rede: ' + e.message, 'err');
    }
});

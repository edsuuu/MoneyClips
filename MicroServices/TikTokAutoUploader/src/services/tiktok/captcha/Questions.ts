/**
 * Mapa de termos da pergunta do captcha -> objeto a clicar. Porta do
 * understood_Qs do function.py. Função pura.
 */

const UNDERSTOOD_TERMS: ReadonlyArray<readonly [string, string]> = [
    ['touchdowns', 'football'],
    ['orange and round', 'basketball'],
    ['used in hoops', 'basketball'],
    ['has strings', 'guitar'],
    ['oval and inflatable', 'football'],
    ['strumming', 'guitar'],
    ['bounces', 'basketball'],
    ['musical instrument', 'guitar'],
    ['laces', 'football'],
    ['bands', 'guitar'],
    ['leather', 'football'],
    ['leaves', 'tree'],
    ['pages', 'book'],
    ['throwing', 'football'],
    ['tossed in a spiral', 'football'],
    ['spiky crown', 'pineapple'],
    ['pigskin', 'football'],
    ['photography', 'camera'],
    ['lens', 'camera'],
    ['grow', 'tree'],
    ['captures images', 'camera'],
    ['keeps doctors', 'apple'],
    ['crown', 'pineapple'],
    ['driven', 'car'],
];

/** Retorna o objeto a clicar para a pergunta, ou null se não reconhecida. */
export function objectForQuestion(question: string): string | null {
    for (const [term, object] of UNDERSTOOD_TERMS) {
        if (question.includes(term)) {
            return object;
        }
    }
    return null;
}

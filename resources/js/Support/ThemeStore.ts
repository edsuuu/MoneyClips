export class ThemeStore {
    public static readonly STORAGE_KEY = 'theme';

    public dark = document.documentElement.classList.contains('dark');

    /**
     * O <head> ja aplicou a classe antes do primeiro paint; aqui so espelhamos
     * o estado pro Alpine.
     */
    public toggle(): void {
        const root = document.documentElement;

        root.classList.add('theme-switching');
        this.dark = !this.dark;
        window.localStorage.setItem(ThemeStore.STORAGE_KEY, this.dark ? 'dark' : 'light');
        root.classList.toggle('dark', this.dark);

        // Reflow sincrono: aplica as cores novas ainda com transition:none.
        // (rAF nao serve — nao dispara em aba de fundo e a classe ficaria presa.)
        void root.offsetHeight;
        root.classList.remove('theme-switching');
    }
}

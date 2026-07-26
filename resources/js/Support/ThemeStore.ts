export class ThemeStore {
    public static readonly STORAGE_KEY = 'theme';

    public dark = document.documentElement.classList.contains('dark');

    public toggle(): void {
        const root = document.documentElement;

        root.classList.add('theme-switching');
        this.dark = !this.dark;
        window.localStorage.setItem(ThemeStore.STORAGE_KEY, this.dark ? 'dark' : 'light');
        root.classList.toggle('dark', this.dark);

        void root.offsetHeight;
        root.classList.remove('theme-switching');
    }
}

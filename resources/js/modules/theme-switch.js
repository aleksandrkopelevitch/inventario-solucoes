export function init() {
    const toggle = document.getElementById('theme-switch');
    if (!toggle) return;

    // initialize from saved preference
    const stored = localStorage.getItem('theme');
    if (stored === 'dark') {
        document.documentElement.classList.add('dark');
    }

    toggle.addEventListener('click', () => {
        const html = document.documentElement;
        html.classList.toggle('dark');
        const theme = html.classList.contains('dark') ? 'dark' : 'light';
        localStorage.setItem('theme', theme);
    });
}

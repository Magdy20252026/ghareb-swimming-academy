const swimmerAdminBody = document.body;
const swimmerAdminThemeToggle = document.getElementById('themeToggle');
const swimmerAdminThemeKey = swimmerAdminBody.dataset.themeKey || 'swimmer-admin-theme';

function swimmerAdminSetTheme(theme) {
    if (theme === 'dark') {
        swimmerAdminBody.classList.add('dark-mode');
        swimmerAdminBody.classList.remove('light-mode');
        if (swimmerAdminThemeToggle) {
            swimmerAdminThemeToggle.checked = true;
        }
        return;
    }

    swimmerAdminBody.classList.add('light-mode');
    swimmerAdminBody.classList.remove('dark-mode');
    if (swimmerAdminThemeToggle) {
        swimmerAdminThemeToggle.checked = false;
    }
}

window.addEventListener('load', () => {
    swimmerAdminSetTheme(localStorage.getItem(swimmerAdminThemeKey) === 'dark' ? 'dark' : 'light');
});

if (swimmerAdminThemeToggle) {
    swimmerAdminThemeToggle.addEventListener('change', () => {
        const nextTheme = swimmerAdminThemeToggle.checked ? 'dark' : 'light';
        swimmerAdminSetTheme(nextTheme);
        localStorage.setItem(swimmerAdminThemeKey, nextTheme);
    });
}

const settleRemainingBody = document.body;
const settleRemainingThemeToggle = document.getElementById('themeToggle');

function setSettleRemainingTheme(theme) {
    if (theme === 'dark') {
        settleRemainingBody.classList.add('dark-mode');
        settleRemainingBody.classList.remove('light-mode');
        if (settleRemainingThemeToggle) {
            settleRemainingThemeToggle.checked = true;
        }
        return;
    }

    settleRemainingBody.classList.add('light-mode');
    settleRemainingBody.classList.remove('dark-mode');
    if (settleRemainingThemeToggle) {
        settleRemainingThemeToggle.checked = false;
    }
}

window.addEventListener('load', () => {
    const savedTheme = localStorage.getItem('settle-remaining-theme');
    setSettleRemainingTheme(savedTheme === 'dark' ? 'dark' : 'light');
});

if (settleRemainingThemeToggle) {
    settleRemainingThemeToggle.addEventListener('change', () => {
        const nextTheme = settleRemainingThemeToggle.checked ? 'dark' : 'light';
        setSettleRemainingTheme(nextTheme);
        localStorage.setItem('settle-remaining-theme', nextTheme);
    });
}

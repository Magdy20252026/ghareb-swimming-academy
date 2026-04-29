const swimmerPortalBody = document.body;
const swimmerPortalThemeToggle = document.getElementById('themeToggle');
const swimmerPortalThemeKey = swimmerPortalBody.dataset.themeKey || 'swimmer-portal-theme';
const swimmerPortalSidebarToggle = document.getElementById('sidebarToggle');
const swimmerPortalSidebarStateKey = 'swimmer-portal-sidebar-collapsed';
const swimmerPortalSidebarBreakpoint = 1080;
const swimmerPortalMobileBreakpoint = 700;
let swimmerPortalResizeDebounceTimer = null;

function swimmerPortalIsMobileLayout() {
    return window.innerWidth <= swimmerPortalMobileBreakpoint;
}

function swimmerPortalSetSidebarCollapsed(isCollapsed) {
    swimmerPortalBody.classList.toggle('portal-sidebar-collapsed', isCollapsed);
    if (!swimmerPortalSidebarToggle) {
        return;
    }

    swimmerPortalSidebarToggle.setAttribute('aria-expanded', isCollapsed ? 'false' : 'true');
    const toggleText = swimmerPortalSidebarToggle.querySelector('.sidebar-toggle-text');
    if (toggleText) {
        toggleText.textContent = isCollapsed ? 'فرد القائمة' : 'طي القائمة';
    }
}

function swimmerPortalApplySidebarPreference() {
    if (swimmerPortalIsMobileLayout()) {
        swimmerPortalSetSidebarCollapsed(false);
        return;
    }

    const savedState = localStorage.getItem(swimmerPortalSidebarStateKey);
    if (savedState === null) {
        swimmerPortalSetSidebarCollapsed(window.innerWidth <= swimmerPortalSidebarBreakpoint);
        return;
    }

    swimmerPortalSetSidebarCollapsed(savedState === 'true');
}

function swimmerPortalSetTheme(theme) {
    if (theme === 'dark') {
        swimmerPortalBody.classList.add('dark-mode');
        swimmerPortalBody.classList.remove('light-mode');
        if (swimmerPortalThemeToggle) {
            swimmerPortalThemeToggle.checked = true;
        }
        return;
    }

    swimmerPortalBody.classList.add('light-mode');
    swimmerPortalBody.classList.remove('dark-mode');
    if (swimmerPortalThemeToggle) {
        swimmerPortalThemeToggle.checked = false;
    }
}

window.addEventListener('load', () => {
    swimmerPortalSetTheme(localStorage.getItem(swimmerPortalThemeKey) === 'dark' ? 'dark' : 'light');
    swimmerPortalApplySidebarPreference();
});

if (swimmerPortalThemeToggle) {
    swimmerPortalThemeToggle.addEventListener('change', () => {
        const nextTheme = swimmerPortalThemeToggle.checked ? 'dark' : 'light';
        swimmerPortalSetTheme(nextTheme);
        localStorage.setItem(swimmerPortalThemeKey, nextTheme);
    });
}

if (swimmerPortalSidebarToggle) {
    swimmerPortalSidebarToggle.addEventListener('click', () => {
        if (swimmerPortalIsMobileLayout()) {
            swimmerPortalSetSidebarCollapsed(false);
            return;
        }

        const isCollapsed = !swimmerPortalBody.classList.contains('portal-sidebar-collapsed');
        swimmerPortalSetSidebarCollapsed(isCollapsed);
        localStorage.setItem(swimmerPortalSidebarStateKey, isCollapsed ? 'true' : 'false');
    });
}

window.addEventListener('resize', () => {
    if (swimmerPortalResizeDebounceTimer !== null) {
        clearTimeout(swimmerPortalResizeDebounceTimer);
    }

    swimmerPortalResizeDebounceTimer = window.setTimeout(() => {
        swimmerPortalApplySidebarPreference();
    }, 120);
});

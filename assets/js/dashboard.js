const body = document.body;
const themeToggle = document.getElementById("themeToggle");
const sidebar = document.getElementById("sidebar");
const sidebarToggle = document.getElementById("sidebarToggle");
const mobileMenuBtn = document.getElementById("mobileMenuBtn");
const mobileOverlay = document.getElementById("mobileOverlay");

window.addEventListener("load", () => {
    const savedTheme = localStorage.getItem("dashboard-theme");
    const savedSidebar = localStorage.getItem("dashboard-sidebar");

    if (savedTheme === "dark") {
        body.classList.add("dark-mode");
        body.classList.remove("light-mode");
        if (themeToggle) themeToggle.checked = true;
    } else {
        body.classList.add("light-mode");
        body.classList.remove("dark-mode");
        if (themeToggle) themeToggle.checked = false;
    }

    if (window.innerWidth > 991 && savedSidebar === "collapsed") {
        sidebar.classList.add("collapsed");
    }
});

if (themeToggle) {
    themeToggle.addEventListener("change", () => {
        if (themeToggle.checked) {
            body.classList.add("dark-mode");
            body.classList.remove("light-mode");
            localStorage.setItem("dashboard-theme", "dark");
        } else {
            body.classList.add("light-mode");
            body.classList.remove("dark-mode");
            localStorage.setItem("dashboard-theme", "light");
        }
    });
}

function toggleMobileSidebar() {
    sidebar.classList.toggle("mobile-open");
    mobileOverlay.classList.toggle("show");
}

if (sidebarToggle) {
    sidebarToggle.addEventListener("click", () => {
        if (window.innerWidth <= 991) {
            toggleMobileSidebar();
        } else {
            sidebar.classList.toggle("collapsed");

            if (sidebar.classList.contains("collapsed")) {
                localStorage.setItem("dashboard-sidebar", "collapsed");
            } else {
                localStorage.setItem("dashboard-sidebar", "expanded");
            }
        }
    });
}

if (mobileMenuBtn) {
    mobileMenuBtn.addEventListener("click", () => {
        toggleMobileSidebar();
    });
}

if (mobileOverlay) {
    mobileOverlay.addEventListener("click", () => {
        sidebar.classList.remove("mobile-open");
        mobileOverlay.classList.remove("show");
    });
}

window.addEventListener("resize", () => {
    if (window.innerWidth > 991) {
        sidebar.classList.remove("mobile-open");
        mobileOverlay.classList.remove("show");
    }
});
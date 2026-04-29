const reportsBody = document.body;
const reportsThemeToggle = document.getElementById("themeToggle");
const reportModals = document.querySelectorAll(".details-modal");
const reportOpenButtons = document.querySelectorAll("[data-modal-target]");
const reportCloseButtons = document.querySelectorAll("[data-close-modal]");

function setReportsTheme(theme) {
    if (theme === "dark") {
        reportsBody.classList.add("dark-mode");
        reportsBody.classList.remove("light-mode");
        if (reportsThemeToggle) {
            reportsThemeToggle.checked = true;
        }
        return;
    }

    reportsBody.classList.add("light-mode");
    reportsBody.classList.remove("dark-mode");
    if (reportsThemeToggle) {
        reportsThemeToggle.checked = false;
    }
}

function closeAllReportModals() {
    reportModals.forEach((modal) => {
        modal.classList.remove("show");
        modal.setAttribute("aria-hidden", "true");
    });
    reportsBody.classList.remove("modal-open");
}

window.addEventListener("load", () => {
    const savedTheme = localStorage.getItem("reports-theme");
    setReportsTheme(savedTheme === "dark" ? "dark" : "light");
});

if (reportsThemeToggle) {
    reportsThemeToggle.addEventListener("change", () => {
        const nextTheme = reportsThemeToggle.checked ? "dark" : "light";
        setReportsTheme(nextTheme);
        localStorage.setItem("reports-theme", nextTheme);
    });
}

reportOpenButtons.forEach((button) => {
    button.addEventListener("click", () => {
        const modalId = button.getAttribute("data-modal-target");
        if (!modalId) {
            return;
        }

        const modal = document.getElementById(modalId);
        if (!modal) {
            return;
        }

        closeAllReportModals();
        modal.classList.add("show");
        modal.setAttribute("aria-hidden", "false");
        reportsBody.classList.add("modal-open");
    });
});

reportCloseButtons.forEach((button) => {
    button.addEventListener("click", () => {
        closeAllReportModals();
    });
});

reportModals.forEach((modal) => {
    modal.addEventListener("click", (event) => {
        if (event.target === modal) {
            closeAllReportModals();
        }
    });
});

window.addEventListener("keydown", (event) => {
    if (event.key === "Escape") {
        closeAllReportModals();
    }
});

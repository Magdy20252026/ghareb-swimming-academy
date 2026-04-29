const academiesBody = document.body;
const academiesThemeToggle = document.getElementById("themeToggle");
const academiesClearBtn = document.getElementById("clearBtn");
const academiesIdField = document.getElementById("id");
const academiesNameField = document.getElementById("academy_name");
const academiesPriceField = document.getElementById("subscription_price");
const academiesDeleteForms = document.querySelectorAll(".delete-academy-form");
const academiesPlayerButtons = document.querySelectorAll(".players-toggle");
const academiesShowPlayersLabel = "عرض السباحين";
const academiesHidePlayersLabel = "إخفاء السباحين";

function setAcademiesTheme(theme) {
    if (theme === "dark") {
        academiesBody.classList.add("dark-mode");
        academiesBody.classList.remove("light-mode");
        if (academiesThemeToggle) {
            academiesThemeToggle.checked = true;
        }
    } else {
        academiesBody.classList.add("light-mode");
        academiesBody.classList.remove("dark-mode");
        if (academiesThemeToggle) {
            academiesThemeToggle.checked = false;
        }
    }
}

function clearAcademiesForm(shouldResetUrl = true) {
    if (academiesIdField) {
        academiesIdField.value = "";
    }

    if (academiesNameField) {
        academiesNameField.value = "";
    }

    if (academiesPriceField) {
        academiesPriceField.value = "";
    }

    if (shouldResetUrl) {
        window.history.replaceState({}, document.title, "academies.php");
    }
}

function closeAllAcademyPlayersPanels() {
    academiesPlayerButtons.forEach((button) => {
        const targetId = button.getAttribute("data-target");
        if (!targetId) {
            return;
        }

        const targetRow = document.getElementById(targetId);
        if (!targetRow) {
            return;
        }

        targetRow.hidden = true;
        button.classList.remove("is-open");
        button.setAttribute("aria-expanded", "false");
        button.textContent = academiesShowPlayersLabel;
    });
}

window.addEventListener("load", () => {
    const savedTheme = localStorage.getItem("academies-theme");
    const successMessage = document.querySelector(".message-box.success");

    setAcademiesTheme(savedTheme === "dark" ? "dark" : "light");

    if (successMessage && window.location.search.includes("edit=")) {
        window.history.replaceState({}, document.title, "academies.php");
    }
});

if (academiesThemeToggle) {
    academiesThemeToggle.addEventListener("change", () => {
        const nextTheme = academiesThemeToggle.checked ? "dark" : "light";
        setAcademiesTheme(nextTheme);
        localStorage.setItem("academies-theme", nextTheme);
    });
}

if (academiesClearBtn) {
    academiesClearBtn.addEventListener("click", () => {
        clearAcademiesForm();
        closeAllAcademyPlayersPanels();
    });
}

academiesDeleteForms.forEach((form) => {
    form.addEventListener("submit", (event) => {
        if (!window.confirm("هل أنت متأكد من حذف هذه الأكاديمية؟")) {
            event.preventDefault();
        }
    });
});

academiesPlayerButtons.forEach((button) => {
    button.addEventListener("click", () => {
        const targetId = button.getAttribute("data-target");
        if (!targetId) {
            return;
        }

        const targetRow = document.getElementById(targetId);
        if (!targetRow) {
            return;
        }

        const isOpen = button.getAttribute("aria-expanded") === "true";
        closeAllAcademyPlayersPanels();

        if (!isOpen) {
            targetRow.hidden = false;
            button.classList.add("is-open");
            button.setAttribute("aria-expanded", "true");
            button.textContent = academiesHidePlayersLabel;
        }
    });
});

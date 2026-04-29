const bodyAdminAdvances = document.body;
const themeToggleAdminAdvances = document.getElementById("themeToggle");
const clearBtnAdminAdvances = document.getElementById("clearBtn");
const amountField = document.getElementById("amount");
const administratorIdField = document.getElementById("administrator_id");
const selectedAdministratorMeta = document.getElementById("selectedAdministratorMeta");
const confirmSubmitForms = document.querySelectorAll(".js-confirm-submit");

const administratorSelect = document.getElementById("administratorSelect");
const administratorSelectTrigger = document.getElementById("administratorSelectTrigger");
const administratorSelectDropdown = document.getElementById("administratorSelectDropdown");
const administratorSelectText = document.getElementById("administratorSelectText");
const administratorSearchInput = document.getElementById("administratorSearchInput");
const administratorSelectEmpty = document.getElementById("administratorSelectEmpty");
const administratorOptions = Array.from(document.querySelectorAll(".select-option"));

const arabicNumberMapAdminAdvances = {
    "٠": "0",
    "١": "1",
    "٢": "2",
    "٣": "3",
    "٤": "4",
    "٥": "5",
    "٦": "6",
    "٧": "7",
    "٨": "8",
    "٩": "9",
    "۰": "0",
    "۱": "1",
    "۲": "2",
    "۳": "3",
    "۴": "4",
    "۵": "5",
    "۶": "6",
    "۷": "7",
    "۸": "8",
    "۹": "9",
};

function normalizeArabicNumbersAdminAdvances(value) {
    return value.replace(/[٠-٩۰-۹]/g, (digit) => arabicNumberMapAdminAdvances[digit]);
}

function setAdminAdvancesTheme(isDarkMode) {
    if (isDarkMode) {
        bodyAdminAdvances.classList.add("dark-mode");
        bodyAdminAdvances.classList.remove("light-mode");
        localStorage.setItem("admin-advances-theme", "dark");
    } else {
        bodyAdminAdvances.classList.add("light-mode");
        bodyAdminAdvances.classList.remove("dark-mode");
        localStorage.setItem("admin-advances-theme", "light");
    }
}

function closeAdministratorDropdown() {
    if (!administratorSelect || !administratorSelectDropdown || !administratorSelectTrigger) {
        return;
    }

    administratorSelect.classList.remove("open");
    administratorSelectDropdown.hidden = true;
    administratorSelectTrigger.setAttribute("aria-expanded", "false");
}

function openAdministratorDropdown() {
    if (!administratorSelect || !administratorSelectDropdown || !administratorSelectTrigger) {
        return;
    }

    administratorSelect.classList.add("open");
    administratorSelectDropdown.hidden = false;
    administratorSelectTrigger.setAttribute("aria-expanded", "true");
    if (administratorSearchInput) {
        administratorSearchInput.focus();
        administratorSearchInput.select();
    }
}

function renderSelectedAdministratorMeta(option) {
    if (!selectedAdministratorMeta) {
        return;
    }

    if (!option) {
        selectedAdministratorMeta.innerHTML = "";
        return;
    }

    const phone = option.dataset.phone || "-";
    selectedAdministratorMeta.innerHTML = `<span>📞 ${phone}</span>`;
}

function selectAdministratorOption(option) {
    if (!option || !administratorIdField || !administratorSelectText) {
        return;
    }

    administratorIdField.value = option.dataset.value || "";
    administratorSelectText.textContent = option.dataset.name || "اختر الإداري من القائمة";

    administratorOptions.forEach((currentOption) => {
        currentOption.classList.toggle("is-selected", currentOption === option);
    });

    renderSelectedAdministratorMeta(option);
    closeAdministratorDropdown();
}

function filterAdministratorOptions() {
    if (!administratorSearchInput || !administratorSelectEmpty) {
        return;
    }

    const searchValue = administratorSearchInput.value.trim().toLowerCase();
    let visibleCount = 0;

    administratorOptions.forEach((option) => {
        const haystack = `${option.dataset.name || ""} ${option.dataset.phone || ""}`.toLowerCase();
        const shouldShow = searchValue === "" || haystack.includes(searchValue);
        option.hidden = !shouldShow;
        if (shouldShow) {
            visibleCount += 1;
        }
    });

    administratorSelectEmpty.hidden = visibleCount !== 0;
}

window.addEventListener("load", () => {
    const savedTheme = localStorage.getItem("admin-advances-theme");
    setAdminAdvancesTheme(savedTheme === "dark");
    if (themeToggleAdminAdvances) {
        themeToggleAdminAdvances.checked = savedTheme === "dark";
    }

    const selectedOption = administratorOptions.find((option) => option.dataset.value === (administratorIdField ? administratorIdField.value : ""));
    renderSelectedAdministratorMeta(selectedOption || null);
    filterAdministratorOptions();
});

if (themeToggleAdminAdvances) {
    themeToggleAdminAdvances.addEventListener("change", () => {
        setAdminAdvancesTheme(themeToggleAdminAdvances.checked);
    });
}

if (clearBtnAdminAdvances) {
    clearBtnAdminAdvances.addEventListener("click", (event) => {
        event.preventDefault();
        const resetUrl = bodyAdminAdvances.dataset.resetUrl || "admin_advances.php";
        window.location.href = resetUrl;
    });
}

if (amountField) {
    amountField.addEventListener("input", () => {
        let normalizedValue = normalizeArabicNumbersAdminAdvances(amountField.value).replace(/,/g, ".");
        normalizedValue = normalizedValue.replace(/[^0-9.]/g, "");

        const parts = normalizedValue.split(".");
        if (parts.length > 2) {
            normalizedValue = `${parts[0]}.${parts.slice(1).join("")}`;
        }

        const [integerPart, decimalPart] = normalizedValue.split(".");
        amountField.value = decimalPart !== undefined
            ? `${integerPart}.${decimalPart.slice(0, 2)}`
            : integerPart;
    });
}

if (administratorSelectTrigger && administratorOptions.length > 0) {
    administratorSelectTrigger.addEventListener("click", () => {
        if (administratorSelectDropdown.hidden) {
            openAdministratorDropdown();
            filterAdministratorOptions();
        } else {
            closeAdministratorDropdown();
        }
    });
}

administratorOptions.forEach((option) => {
    option.addEventListener("click", () => {
        selectAdministratorOption(option);
    });
});

if (administratorSearchInput) {
    administratorSearchInput.addEventListener("input", filterAdministratorOptions);
}

document.addEventListener("click", (event) => {
    if (!administratorSelect || administratorSelect.contains(event.target)) {
        return;
    }

    closeAdministratorDropdown();
});

window.addEventListener("keydown", (event) => {
    if (event.key === "Escape") {
        closeAdministratorDropdown();
    }
});

confirmSubmitForms.forEach((form) => {
    form.addEventListener("submit", (event) => {
        const confirmMessage = form.getAttribute("data-confirm-message") || bodyAdminAdvances.dataset.defaultConfirmMessage || "هل أنت متأكد؟";
        if (!window.confirm(confirmMessage)) {
            event.preventDefault();
        }
    });
});

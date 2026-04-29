const bodyAdminSalaries = document.body;
const themeToggleAdminSalaries = document.getElementById("themeToggle");
const clearBtnAdminSalaries = document.getElementById("clearBtn");
const confirmSubmitSalaryForms = document.querySelectorAll(".js-confirm-submit");
const paymentCycleOptionsAdminSalaries = document.querySelectorAll(".payment-cycle-option");
const salaryAmountField = document.getElementById("salary_amount");
const salaryAmountPreview = document.getElementById("salaryAmountPreview");
const salaryNetPreview = document.getElementById("salaryNetPreview");
const salaryNetBox = document.getElementById("salaryNetBox");

const administratorIdFilterField = document.getElementById("administrator_id_filter");
const selectedAdministratorMetaAdminSalaries = document.getElementById("selectedAdministratorMeta");
const administratorSelectAdminSalaries = document.getElementById("administratorSelect");
const administratorSelectTriggerAdminSalaries = document.getElementById("administratorSelectTrigger");
const administratorSelectDropdownAdminSalaries = document.getElementById("administratorSelectDropdown");
const administratorSelectTextAdminSalaries = document.getElementById("administratorSelectText");
const administratorSearchInputAdminSalaries = document.getElementById("administratorSearchInput");
const administratorSelectEmptyAdminSalaries = document.getElementById("administratorSelectEmpty");
const administratorOptionsAdminSalaries = Array.from(document.querySelectorAll(".select-option"));
const administratorOptionsByIdAdminSalaries = new Map(administratorOptionsAdminSalaries.map((option) => [option.dataset.value || "", option]));

const arabicNumberMapAdminSalaries = {
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

function normalizeArabicNumbersAdminSalaries(value) {
    return value.replace(/[٠-٩۰-۹]/g, (digit) => arabicNumberMapAdminSalaries[digit]);
}

function parseSalaryAmountAdminSalaries(value) {
    const parsedValue = Number.parseFloat(value);
    return Number.isFinite(parsedValue) ? parsedValue : 0;
}

function formatSalaryAmountAdminSalaries(value) {
    return value.toLocaleString("en-US", {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
}

function updateSalarySummariesAdminSalaries() {
    if (!salaryAmountField) {
        return;
    }

    const salaryAmount = parseSalaryAmountAdminSalaries(salaryAmountField.value);
    const totalAdvances = parseSalaryAmountAdminSalaries(salaryAmountField.dataset.totalAdvances || "0");
    const netAmount = salaryAmount - totalAdvances;

    if (salaryAmountPreview) {
        salaryAmountPreview.textContent = formatSalaryAmountAdminSalaries(salaryAmount);
    }

    if (salaryNetPreview) {
        salaryNetPreview.textContent = formatSalaryAmountAdminSalaries(netAmount);
    }

    if (salaryNetBox) {
        salaryNetBox.textContent = formatSalaryAmountAdminSalaries(netAmount);
        salaryNetBox.classList.toggle("positive-text", netAmount >= 0);
        salaryNetBox.classList.toggle("negative-text", netAmount < 0);
    }
}

function setAdminSalariesTheme(isDarkMode) {
    if (isDarkMode) {
        bodyAdminSalaries.classList.add("dark-mode");
        bodyAdminSalaries.classList.remove("light-mode");
        localStorage.setItem("admin-salaries-theme", "dark");
    } else {
        bodyAdminSalaries.classList.add("light-mode");
        bodyAdminSalaries.classList.remove("dark-mode");
        localStorage.setItem("admin-salaries-theme", "light");
    }
}

function closeAdministratorDropdownAdminSalaries() {
    if (!administratorSelectAdminSalaries || !administratorSelectDropdownAdminSalaries || !administratorSelectTriggerAdminSalaries) {
        return;
    }

    administratorSelectAdminSalaries.classList.remove("open");
    administratorSelectDropdownAdminSalaries.hidden = true;
    administratorSelectTriggerAdminSalaries.setAttribute("aria-expanded", "false");
}

function openAdministratorDropdownAdminSalaries() {
    if (!administratorSelectAdminSalaries || !administratorSelectDropdownAdminSalaries || !administratorSelectTriggerAdminSalaries) {
        return;
    }

    administratorSelectAdminSalaries.classList.add("open");
    administratorSelectDropdownAdminSalaries.hidden = false;
    administratorSelectTriggerAdminSalaries.setAttribute("aria-expanded", "true");
    if (administratorSearchInputAdminSalaries) {
        administratorSearchInputAdminSalaries.focus();
        administratorSearchInputAdminSalaries.select();
    }
}

function renderSelectedAdministratorMetaAdminSalaries(option) {
    if (!selectedAdministratorMetaAdminSalaries) {
        return;
    }

    if (!option) {
        selectedAdministratorMetaAdminSalaries.innerHTML = "";
        return;
    }

    selectedAdministratorMetaAdminSalaries.innerHTML = `
        <span>📞 ${option.dataset.phone || "-"}</span>
        <span>⏱️ ${option.dataset.hours || "0.00"}</span>
        <span>💵 ${option.dataset.advances || "0.00"}</span>
    `;
}

function selectAdministratorOptionAdminSalaries(option) {
    if (!option || !administratorIdFilterField || !administratorSelectTextAdminSalaries) {
        return;
    }

    administratorIdFilterField.value = option.dataset.value || "";
    administratorSelectTextAdminSalaries.textContent = option.dataset.name || "اختر الإداري من القائمة";

    administratorOptionsAdminSalaries.forEach((currentOption) => {
        currentOption.classList.toggle("is-selected", currentOption === option);
    });

    renderSelectedAdministratorMetaAdminSalaries(option);
    closeAdministratorDropdownAdminSalaries();
}

function filterAdministratorOptionsAdminSalaries() {
    if (!administratorSearchInputAdminSalaries || !administratorSelectEmptyAdminSalaries) {
        return;
    }

    const searchValue = administratorSearchInputAdminSalaries.value.trim().toLowerCase();
    let visibleCount = 0;

    administratorOptionsAdminSalaries.forEach((option) => {
        const haystack = `${option.dataset.name || ""} ${option.dataset.phone || ""}`.toLowerCase();
        const shouldShow = searchValue === "" || haystack.includes(searchValue);
        option.hidden = !shouldShow;
        if (shouldShow) {
            visibleCount += 1;
        }
    });

    administratorSelectEmptyAdminSalaries.hidden = visibleCount !== 0;
}

window.addEventListener("load", () => {
    const savedTheme = localStorage.getItem("admin-salaries-theme");
    setAdminSalariesTheme(savedTheme === "dark");
    if (themeToggleAdminSalaries) {
        themeToggleAdminSalaries.checked = savedTheme === "dark";
    }

    const administratorId = administratorIdFilterField ? administratorIdFilterField.value : "";
    const selectedOption = administratorOptionsByIdAdminSalaries.get(administratorId) || null;
    renderSelectedAdministratorMetaAdminSalaries(selectedOption);
    filterAdministratorOptionsAdminSalaries();
    updateSalarySummariesAdminSalaries();
});

if (themeToggleAdminSalaries) {
    themeToggleAdminSalaries.addEventListener("change", () => {
        setAdminSalariesTheme(themeToggleAdminSalaries.checked);
    });
}

if (clearBtnAdminSalaries) {
    clearBtnAdminSalaries.addEventListener("click", (event) => {
        event.preventDefault();
        const resetUrl = bodyAdminSalaries.dataset.resetUrl || "admin_salaries.php";
        window.location.href = resetUrl;
    });
}

if (salaryAmountField) {
    salaryAmountField.addEventListener("input", () => {
        let normalizedValue = normalizeArabicNumbersAdminSalaries(salaryAmountField.value).replace(/,/g, ".");
        normalizedValue = normalizedValue.replace(/[^0-9.]/g, "");

        const parts = normalizedValue.split(".");
        if (parts.length > 2) {
            normalizedValue = `${parts[0]}.${parts.slice(1).join("")}`;
        }

        const [integerPart, decimalPart] = normalizedValue.split(".");
        salaryAmountField.value = decimalPart !== undefined
            ? `${integerPart}.${decimalPart.slice(0, 2)}`
            : integerPart;
        updateSalarySummariesAdminSalaries();
    });
}

if (administratorSelectTriggerAdminSalaries && administratorOptionsAdminSalaries.length > 0) {
    administratorSelectTriggerAdminSalaries.addEventListener("click", () => {
        if (administratorSelectDropdownAdminSalaries.hidden) {
            openAdministratorDropdownAdminSalaries();
            filterAdministratorOptionsAdminSalaries();
        } else {
            closeAdministratorDropdownAdminSalaries();
        }
    });
}

administratorOptionsAdminSalaries.forEach((option) => {
    option.addEventListener("click", () => {
        selectAdministratorOptionAdminSalaries(option);
    });
});

if (administratorSearchInputAdminSalaries) {
    administratorSearchInputAdminSalaries.addEventListener("input", filterAdministratorOptionsAdminSalaries);
}

document.addEventListener("click", (event) => {
    if (!administratorSelectAdminSalaries || administratorSelectAdminSalaries.contains(event.target)) {
        return;
    }

    closeAdministratorDropdownAdminSalaries();
});

window.addEventListener("keydown", (event) => {
    if (event.key === "Escape") {
        closeAdministratorDropdownAdminSalaries();
    }
});

confirmSubmitSalaryForms.forEach((form) => {
    form.addEventListener("submit", (event) => {
        const confirmMessage = form.getAttribute("data-confirm-message") || bodyAdminSalaries.dataset.defaultConfirmMessage || "هل أنت متأكد؟";
        if (!window.confirm(confirmMessage)) {
            event.preventDefault();
        }
    });
});

paymentCycleOptionsAdminSalaries.forEach((option) => {
    const radio = option.querySelector('input[type="radio"]');
    if (!radio) {
        return;
    }

    radio.addEventListener("change", () => {
        paymentCycleOptionsAdminSalaries.forEach((currentOption) => {
            currentOption.classList.toggle("is-active", currentOption === option);
        });
    });
});

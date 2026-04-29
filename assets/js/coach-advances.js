const bodyCoachAdvances = document.body;
const themeToggleCoachAdvances = document.getElementById("themeToggle");
const clearBtnCoachAdvances = document.getElementById("clearBtn");
const amountField = document.getElementById("amount");
const coachIdField = document.getElementById("coach_id");
const selectedCoachMeta = document.getElementById("selectedCoachMeta");
const confirmSubmitForms = document.querySelectorAll(".js-confirm-submit");

const coachSelect = document.getElementById("coachSelect");
const coachSelectTrigger = document.getElementById("coachSelectTrigger");
const coachSelectDropdown = document.getElementById("coachSelectDropdown");
const coachSelectText = document.getElementById("coachSelectText");
const coachSearchInput = document.getElementById("coachSearchInput");
const coachSelectEmpty = document.getElementById("coachSelectEmpty");
const coachOptions = Array.from(document.querySelectorAll(".select-option"));

const arabicNumberMapCoachAdvances = {
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

function normalizeArabicNumbersCoachAdvances(value) {
    return value.replace(/[٠-٩۰-۹]/g, (digit) => arabicNumberMapCoachAdvances[digit] || digit);
}

function setCoachAdvancesTheme(isDarkMode) {
    if (isDarkMode) {
        bodyCoachAdvances.classList.add("dark-mode");
        bodyCoachAdvances.classList.remove("light-mode");
        localStorage.setItem("coach-advances-theme", "dark");
    } else {
        bodyCoachAdvances.classList.add("light-mode");
        bodyCoachAdvances.classList.remove("dark-mode");
        localStorage.setItem("coach-advances-theme", "light");
    }
}

function closeCoachDropdown() {
    if (!coachSelect || !coachSelectDropdown || !coachSelectTrigger) {
        return;
    }

    coachSelect.classList.remove("open");
    coachSelectDropdown.hidden = true;
    coachSelectTrigger.setAttribute("aria-expanded", "false");
}

function openCoachDropdown() {
    if (!coachSelect || !coachSelectDropdown || !coachSelectTrigger) {
        return;
    }

    coachSelect.classList.add("open");
    coachSelectDropdown.hidden = false;
    coachSelectTrigger.setAttribute("aria-expanded", "true");
    if (coachSearchInput) {
        coachSearchInput.focus();
        coachSearchInput.select();
    }
}

function renderSelectedCoachMeta(option) {
    if (!selectedCoachMeta) {
        return;
    }

    if (!option) {
        selectedCoachMeta.innerHTML = "";
        return;
    }

    const phone = option.dataset.phone || "-";
    selectedCoachMeta.innerHTML = `<span>📞 ${phone}</span>`;
}

function selectCoachOption(option) {
    if (!option || !coachIdField || !coachSelectText) {
        return;
    }

    coachIdField.value = option.dataset.value || "";
    coachSelectText.textContent = option.dataset.name || "اختر المدرب من القائمة";

    coachOptions.forEach((currentOption) => {
        currentOption.classList.toggle("is-selected", currentOption === option);
    });

    renderSelectedCoachMeta(option);
    closeCoachDropdown();
}

function filterCoachOptions() {
    if (!coachSearchInput || !coachSelectEmpty) {
        return;
    }

    const searchValue = coachSearchInput.value.trim().toLowerCase();
    let visibleCount = 0;

    coachOptions.forEach((option) => {
        const haystack = `${option.dataset.name || ""} ${option.dataset.phone || ""}`.toLowerCase();
        const shouldShow = searchValue === "" || haystack.includes(searchValue);
        option.hidden = !shouldShow;
        if (shouldShow) {
            visibleCount += 1;
        }
    });

    coachSelectEmpty.hidden = visibleCount !== 0;
}

window.addEventListener("load", () => {
    const savedTheme = localStorage.getItem("coach-advances-theme");
    setCoachAdvancesTheme(savedTheme === "dark");
    if (themeToggleCoachAdvances) {
        themeToggleCoachAdvances.checked = savedTheme === "dark";
    }

    const selectedOption = coachOptions.find((option) => option.dataset.value === (coachIdField ? coachIdField.value : ""));
    renderSelectedCoachMeta(selectedOption || null);
    filterCoachOptions();
});

if (themeToggleCoachAdvances) {
    themeToggleCoachAdvances.addEventListener("change", () => {
        setCoachAdvancesTheme(themeToggleCoachAdvances.checked);
    });
}

if (clearBtnCoachAdvances) {
    clearBtnCoachAdvances.addEventListener("click", (event) => {
        event.preventDefault();
        const resetUrl = bodyCoachAdvances.dataset.resetUrl || "coach_advances.php";
        window.location.href = resetUrl;
    });
}

if (amountField) {
    amountField.addEventListener("input", () => {
        let normalizedValue = normalizeArabicNumbersCoachAdvances(amountField.value).replace(/,/g, ".");
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

if (coachSelectTrigger && coachOptions.length > 0) {
    coachSelectTrigger.addEventListener("click", () => {
        if (coachSelectDropdown.hidden) {
            openCoachDropdown();
            filterCoachOptions();
        } else {
            closeCoachDropdown();
        }
    });
}

coachOptions.forEach((option) => {
    option.addEventListener("click", () => {
        selectCoachOption(option);
    });
});

if (coachSearchInput) {
    coachSearchInput.addEventListener("input", filterCoachOptions);
}

document.addEventListener("click", (event) => {
    if (!coachSelect || coachSelect.contains(event.target)) {
        return;
    }

    closeCoachDropdown();
});

window.addEventListener("keydown", (event) => {
    if (event.key === "Escape") {
        closeCoachDropdown();
    }
});

confirmSubmitForms.forEach((form) => {
    form.addEventListener("submit", (event) => {
        const confirmMessage = form.getAttribute("data-confirm-message") || bodyCoachAdvances.dataset.defaultConfirmMessage || "هل أنت متأكد؟";
        if (!window.confirm(confirmMessage)) {
            event.preventDefault();
        }
    });
});

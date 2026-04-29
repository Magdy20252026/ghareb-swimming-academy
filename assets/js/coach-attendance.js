const bodyAttendance = document.body;
const canViewCompensation = bodyAttendance?.dataset.canViewCompensation === "1";
const themeToggleAttendance = document.getElementById("themeToggle");
const clearBtnAttendance = document.getElementById("clearBtn");
const workHoursField = document.getElementById("work_hours");
const coachIdField = document.getElementById("coach_id");
const estimatedAmountBox = document.getElementById("estimatedAmountBox");
const selectedCoachMeta = document.getElementById("selectedCoachMeta");
const confirmSubmitForms = document.querySelectorAll(".js-confirm-submit");

const coachSelect = document.getElementById("coachSelect");
const coachSelectTrigger = document.getElementById("coachSelectTrigger");
const coachSelectDropdown = document.getElementById("coachSelectDropdown");
const coachSelectText = document.getElementById("coachSelectText");
const coachSearchInput = document.getElementById("coachSearchInput");
const coachOptionsList = document.getElementById("coachOptionsList");
const coachSelectEmpty = document.getElementById("coachSelectEmpty");
const coachOptions = Array.from(document.querySelectorAll(".select-option"));

const arabicNumberMapAttendance = {
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

function normalizeArabicNumbersAttendance(value) {
    return value.replace(/[٠-٩۰-۹]/g, (digit) => arabicNumberMapAttendance[digit] || digit);
}

function setAttendanceTheme(isDarkMode) {
    if (isDarkMode) {
        bodyAttendance.classList.add("dark-mode");
        bodyAttendance.classList.remove("light-mode");
        localStorage.setItem("coach-attendance-theme", "dark");
    } else {
        bodyAttendance.classList.add("light-mode");
        bodyAttendance.classList.remove("dark-mode");
        localStorage.setItem("coach-attendance-theme", "light");
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
    selectedCoachMeta.innerHTML = canViewCompensation
        ? `<span>📞 ${phone}</span><span>💵 سعر الساعة: ${option.dataset.rate || "0.00"}</span>`
        : `<span>📞 ${phone}</span>`;
}

function updateEstimatedAmount() {
    if (!canViewCompensation || !estimatedAmountBox) {
        return;
    }

    const selectedOption = coachOptions.find((option) => option.dataset.value === (coachIdField ? coachIdField.value : ""));
    const hourlyRate = Number.parseFloat(selectedOption?.dataset.rate || "0");
    const rawWorkHours = workHoursField ? workHoursField.value || "" : "";
    const normalizedWorkHours = normalizeArabicNumbersAttendance(rawWorkHours).replace(/,/g, ".");
    const workHours = Number.parseFloat(normalizedWorkHours) || 0;
    const total = hourlyRate * workHours;
    estimatedAmountBox.textContent = total.toFixed(2);
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
    updateEstimatedAmount();
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
    const savedTheme = localStorage.getItem("coach-attendance-theme");
    setAttendanceTheme(savedTheme === "dark");
    if (themeToggleAttendance) {
        themeToggleAttendance.checked = savedTheme === "dark";
    }

    const selectedOption = coachOptions.find((option) => option.dataset.value === (coachIdField ? coachIdField.value : ""));
    renderSelectedCoachMeta(selectedOption || null);
    updateEstimatedAmount();
});

if (themeToggleAttendance) {
    themeToggleAttendance.addEventListener("change", () => {
        setAttendanceTheme(themeToggleAttendance.checked);
    });
}

if (clearBtnAttendance) {
    clearBtnAttendance.addEventListener("click", (event) => {
        event.preventDefault();
        const resetUrl = bodyAttendance.dataset.resetUrl || "coach_attendance.php";
        window.location.href = resetUrl;
    });
}

if (workHoursField) {
    workHoursField.addEventListener("input", () => {
        let normalizedValue = normalizeArabicNumbersAttendance(workHoursField.value).replace(/,/g, ".");
        normalizedValue = normalizedValue.replace(/[^0-9.]/g, "");

        const parts = normalizedValue.split(".");
        if (parts.length > 2) {
            normalizedValue = `${parts[0]}.${parts.slice(1).join("")}`;
        }

        const [integerPart, decimalPart] = normalizedValue.split(".");
        workHoursField.value = decimalPart !== undefined
            ? `${integerPart}.${decimalPart.slice(0, 2)}`
            : integerPart;

        updateEstimatedAmount();
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
        const confirmMessage = form.getAttribute("data-confirm-message") || "هل أنت متأكد؟";
        if (!window.confirm(confirmMessage)) {
            event.preventDefault();
        }
    });
});

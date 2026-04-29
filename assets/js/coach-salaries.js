const bodyCoachSalaries = document.body;
const themeToggleCoachSalaries = document.getElementById("themeToggle");
const clearBtnCoachSalaries = document.getElementById("clearBtn");
const confirmSubmitSalaryTargets = document.querySelectorAll(".js-confirm-submit");
const paymentCycleOptions = document.querySelectorAll(".payment-cycle-option");

const coachIdFilterField = document.getElementById("coach_id_filter");
const selectedCoachMeta = document.getElementById("selectedCoachMeta");
const coachSelect = document.getElementById("coachSelect");
const coachSelectTrigger = document.getElementById("coachSelectTrigger");
const coachSelectDropdown = document.getElementById("coachSelectDropdown");
const coachSelectText = document.getElementById("coachSelectText");
const coachSearchInput = document.getElementById("coachSearchInput");
const coachSelectEmpty = document.getElementById("coachSelectEmpty");
const coachOptions = Array.from(document.querySelectorAll(".select-option"));
const coachOptionsById = new Map(coachOptions.map((option) => [option.dataset.value || "", option]));
const salaryPaymentForm = document.querySelector(".salary-payment-form");
const salaryPaymentCoachField = salaryPaymentForm?.querySelector('input[name="coach_id"]') || null;
const salaryPaymentPeriodStartField = salaryPaymentForm?.querySelector('input[name="period_start"]') || null;
const salaryPaymentPeriodEndField = salaryPaymentForm?.querySelector('input[name="period_end"]') || null;
const salaryPaymentActionButtons = Array.from(salaryPaymentForm?.querySelectorAll('button[name="action"]') || []);
const coachPeriodStartField = document.getElementById("period_start");
const coachPeriodEndField = document.getElementById("period_end");

function setCoachSalariesTheme(isDarkMode) {
    if (isDarkMode) {
        bodyCoachSalaries.classList.add("dark-mode");
        bodyCoachSalaries.classList.remove("light-mode");
        localStorage.setItem("coach-salaries-theme", "dark");
    } else {
        bodyCoachSalaries.classList.add("light-mode");
        bodyCoachSalaries.classList.remove("dark-mode");
        localStorage.setItem("coach-salaries-theme", "light");
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

    selectedCoachMeta.innerHTML = `
        <span>📞 ${option.dataset.phone || "-"}</span>
        <span>⏱️ ${option.dataset.hours || "0.00"}</span>
        <span>💵 ${option.dataset.advances || "0.00"}</span>
    `;
}

function selectCoachOption(option) {
    if (!option || !coachIdFilterField || !coachSelectText) {
        return;
    }

    coachIdFilterField.value = option.dataset.value || "";
    coachSelectText.textContent = option.dataset.name || "اختر المدرب من القائمة";

    coachOptions.forEach((currentOption) => {
        currentOption.classList.toggle("is-selected", currentOption === option);
    });

    renderSelectedCoachMeta(option);
    syncSalaryPaymentFields();
    closeCoachDropdown();
}

function syncSalaryPaymentFields() {
    if (salaryPaymentCoachField && coachIdFilterField) {
        salaryPaymentCoachField.value = coachIdFilterField.value || "";
    }

    if (salaryPaymentPeriodStartField && coachPeriodStartField) {
        salaryPaymentPeriodStartField.value = coachPeriodStartField.value || "";
    }

    if (salaryPaymentPeriodEndField && coachPeriodEndField) {
        salaryPaymentPeriodEndField.value = coachPeriodEndField.value || "";
    }

    const hasCoach = Boolean(coachIdFilterField?.value);
    const hasPeriod = Boolean(coachPeriodStartField?.value) && Boolean(coachPeriodEndField?.value);
    const shouldEnableActions = hasCoach && hasPeriod;

    salaryPaymentActionButtons.forEach((button) => {
        button.disabled = !shouldEnableActions;
    });
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
    const savedTheme = localStorage.getItem("coach-salaries-theme");
    setCoachSalariesTheme(savedTheme === "dark");
    if (themeToggleCoachSalaries) {
        themeToggleCoachSalaries.checked = savedTheme === "dark";
    }

    const coachId = coachIdFilterField ? coachIdFilterField.value : "";
    const selectedOption = coachOptionsById.get(coachId) || null;
    renderSelectedCoachMeta(selectedOption || null);
    filterCoachOptions();
    syncSalaryPaymentFields();
});

if (themeToggleCoachSalaries) {
    themeToggleCoachSalaries.addEventListener("change", () => {
        setCoachSalariesTheme(themeToggleCoachSalaries.checked);
    });
}

if (clearBtnCoachSalaries) {
    clearBtnCoachSalaries.addEventListener("click", (event) => {
        event.preventDefault();
        const resetUrl = bodyCoachSalaries.dataset.resetUrl || "coach_salaries.php";
        window.location.href = resetUrl;
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

coachPeriodStartField?.addEventListener("input", syncSalaryPaymentFields);
coachPeriodEndField?.addEventListener("input", syncSalaryPaymentFields);

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

confirmSubmitSalaryTargets.forEach((element) => {
    const confirmMessage = element.getAttribute("data-confirm-message") || bodyCoachSalaries.dataset.defaultConfirmMessage || "هل أنت متأكد؟";

    if (element.tagName === "FORM") {
        element.addEventListener("submit", (event) => {
            if (!window.confirm(confirmMessage)) {
                event.preventDefault();
            }
        });
        return;
    }

    element.addEventListener("click", (event) => {
        if (!window.confirm(confirmMessage)) {
            event.preventDefault();
        }
    });
});

salaryPaymentActionButtons.forEach((button) => {
    button.addEventListener("click", () => {
        syncSalaryPaymentFields();
    });
});

paymentCycleOptions.forEach((option) => {
    const radio = option.querySelector('input[type="radio"]');
    if (!radio) {
        return;
    }

    radio.addEventListener("change", () => {
        paymentCycleOptions.forEach((currentOption) => {
            currentOption.classList.toggle("is-active", currentOption === option);
        });
    });
});

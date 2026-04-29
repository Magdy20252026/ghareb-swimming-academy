const renewBody = document.body;
const renewThemeToggle = document.getElementById('themeToggle');
const renewSubscriptionSelect = document.getElementById('subscription_id');
const renewStartDateField = document.getElementById('renew_start_date');
const renewBasePriceField = document.getElementById('renew_base_price');
const renewOldRemainingField = document.getElementById('renew_old_remaining');
const renewTotalAmountField = document.getElementById('renew_total_amount');
const renewPaidAmountField = document.getElementById('renew_paid_amount');
const renewRemainingAmountField = document.getElementById('renew_remaining_amount');
const renewCategoryDisplay = document.getElementById('subscription_category_display');
const renewCoachDisplay = document.getElementById('subscription_coach_display');
const renewScheduleDisplay = document.getElementById('subscription_schedule_display');
const renewExercisesDisplay = document.getElementById('subscription_exercises_display');
const renewCapacityDisplay = document.getElementById('subscription_capacity_display');
const renewEndDisplay = document.getElementById('renew_end_display');

function setRenewTheme(theme) {
    if (theme === 'dark') {
        renewBody.classList.add('dark-mode');
        renewBody.classList.remove('light-mode');
        if (renewThemeToggle) {
            renewThemeToggle.checked = true;
        }
        return;
    }

    renewBody.classList.add('light-mode');
    renewBody.classList.remove('dark-mode');
    if (renewThemeToggle) {
        renewThemeToggle.checked = false;
    }
}

function parseRenewMoneyValue(value) {
    const parsedValue = Number.parseFloat(value || '0');
    return Number.isFinite(parsedValue) ? parsedValue : 0;
}

function getSelectedRenewSubscriptionOption() {
    if (!renewSubscriptionSelect) {
        return null;
    }

    return renewSubscriptionSelect.options[renewSubscriptionSelect.selectedIndex] || null;
}

function createRenewLocalDate(dateValue) {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(dateValue)) {
        return null;
    }

    const [yearValue, monthValue, dayValue] = dateValue
        .split('-')
        .map((value) => Number.parseInt(value, 10));
    const date = new Date(yearValue, monthValue - 1, dayValue);

    return Number.isNaN(date.getTime()) ? null : date;
}

function calculateRenewEndDate(startDateValue, selectedOption = getSelectedRenewSubscriptionOption()) {
    if (!selectedOption || !startDateValue || !/^\d{4}-\d{2}-\d{2}$/.test(startDateValue)) {
        return '';
    }

    const exercisesCount = Number.parseInt(selectedOption.dataset.exercises || '0', 10) || 0;
    const scheduleDays = (selectedOption.dataset.scheduleDays || '')
        .split(',')
        .map((value) => value.trim())
        .filter(Boolean);

    if (exercisesCount <= 0 || scheduleDays.length === 0) {
        return '';
    }

    const allowedDays = new Set(scheduleDays);
    const currentDate = createRenewLocalDate(startDateValue);
    if (!currentDate) {
        return '';
    }

    const jsWeekDays = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
    let sessionsCount = 0;

    while (sessionsCount < exercisesCount) {
        const currentDayKey = jsWeekDays[currentDate.getDay()] || '';
        if (allowedDays.has(currentDayKey)) {
            sessionsCount += 1;
        }

        if (sessionsCount >= exercisesCount) {
            const year = currentDate.getFullYear();
            const month = String(currentDate.getMonth() + 1).padStart(2, '0');
            const day = String(currentDate.getDate()).padStart(2, '0');
            return `${year}-${month}-${day}`;
        }

        currentDate.setDate(currentDate.getDate() + 1);
    }

    return '';
}

function updateRenewAmounts() {
    if (!renewTotalAmountField || !renewRemainingAmountField) {
        return;
    }

    const basePrice = parseRenewMoneyValue(renewBasePriceField ? renewBasePriceField.value : '0');
    const oldRemaining = parseRenewMoneyValue(renewOldRemainingField ? renewOldRemainingField.value : '0');
    const paidAmount = parseRenewMoneyValue(renewPaidAmountField ? renewPaidAmountField.value : '0');
    const totalAmount = basePrice + oldRemaining;
    const remainingAmount = Math.max(totalAmount - paidAmount, 0);

    renewTotalAmountField.value = totalAmount.toFixed(2);
    renewRemainingAmountField.value = remainingAmount.toFixed(2);

    if (renewPaidAmountField) {
        renewPaidAmountField.max = totalAmount.toFixed(2);
    }
}

function updateRenewSubscriptionDisplay() {
    const selectedOption = getSelectedRenewSubscriptionOption();
    if (!selectedOption) {
        return;
    }

    if (renewCategoryDisplay) {
        renewCategoryDisplay.textContent = selectedOption.dataset.category || '—';
    }
    if (renewCoachDisplay) {
        renewCoachDisplay.textContent = selectedOption.dataset.coach || '—';
    }
    if (renewScheduleDisplay) {
        renewScheduleDisplay.textContent = selectedOption.dataset.schedule || '—';
    }
    if (renewExercisesDisplay) {
        renewExercisesDisplay.textContent = selectedOption.dataset.exercises || '0';
    }
    if (renewCapacityDisplay) {
        renewCapacityDisplay.textContent = `${selectedOption.dataset.currentCount || '0'} / ${selectedOption.dataset.maxTrainees || '0'}`;
    }
    if (renewEndDisplay) {
        renewEndDisplay.textContent = calculateRenewEndDate(renewStartDateField ? renewStartDateField.value : '', selectedOption) || '—';
    }

    updateRenewAmounts();
}

window.addEventListener('load', () => {
    const savedTheme = localStorage.getItem('renew-subscription-theme');
    setRenewTheme(savedTheme === 'dark' ? 'dark' : 'light');
    updateRenewSubscriptionDisplay();
});

if (renewThemeToggle) {
    renewThemeToggle.addEventListener('change', () => {
        const nextTheme = renewThemeToggle.checked ? 'dark' : 'light';
        setRenewTheme(nextTheme);
        localStorage.setItem('renew-subscription-theme', nextTheme);
    });
}

if (renewSubscriptionSelect) {
    renewSubscriptionSelect.addEventListener('change', updateRenewSubscriptionDisplay);
}

if (renewStartDateField) {
    renewStartDateField.addEventListener('change', updateRenewSubscriptionDisplay);
}

if (renewBasePriceField) {
    renewBasePriceField.addEventListener('input', updateRenewAmounts);
}

if (renewPaidAmountField) {
    renewPaidAmountField.addEventListener('input', updateRenewAmounts);
}

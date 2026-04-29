const academyPlayersBody = document.body;
const academyPlayersThemeToggle = document.getElementById('themeToggle');
const academyPlayersClearBtn = document.getElementById('clearBtn');
const academyPlayersPageUrl = academyPlayersBody.dataset.pageUrl || 'academies_players.php';
const academySelect = document.getElementById('academy_id');
const academyPriceDisplay = document.getElementById('academy_price_display');
const totalAmountField = document.getElementById('subscription_amount');
const paidAmountField = document.getElementById('paid_amount');
const remainingAmountField = document.getElementById('remaining_amount');
const renewCard = document.getElementById('renew-card');
const renewBasePriceField = document.getElementById('renew_base_price_display');
const renewOldRemainingField = document.getElementById('renew_old_remaining_display');
const renewTotalAmountField = document.getElementById('renew_total_amount');
const renewPaidAmountField = document.getElementById('renew_paid_amount');
const renewRemainingAmountField = document.getElementById('renew_remaining_amount');
const renewTotalDisplay = document.getElementById('renew_total_display');
const renewRemainingDisplay = document.getElementById('renew_remaining_display');

function setAcademyPlayersTheme(theme) {
    if (theme === 'dark') {
        academyPlayersBody.classList.add('dark-mode');
        academyPlayersBody.classList.remove('light-mode');
        if (academyPlayersThemeToggle) {
            academyPlayersThemeToggle.checked = true;
        }
        return;
    }

    academyPlayersBody.classList.add('light-mode');
    academyPlayersBody.classList.remove('dark-mode');
    if (academyPlayersThemeToggle) {
        academyPlayersThemeToggle.checked = false;
    }
}

function parseMoneyValue(value) {
    const parsedValue = Number.parseFloat(value || '0');
    return Number.isFinite(parsedValue) ? parsedValue : 0;
}

function updateRegistrationAmounts() {
    if (!academySelect || !academyPriceDisplay || !totalAmountField || !remainingAmountField) {
        return;
    }

    const selectedOption = academySelect.options[academySelect.selectedIndex];
    const academyPrice = parseMoneyValue(selectedOption ? selectedOption.dataset.price : '0');
    const paidAmount = parseMoneyValue(paidAmountField ? paidAmountField.value : '0');
    const remainingAmount = Math.max(academyPrice - paidAmount, 0);

    academyPriceDisplay.value = academyPrice.toFixed(2);
    totalAmountField.value = academyPrice.toFixed(2);
    remainingAmountField.value = remainingAmount.toFixed(2);

    if (paidAmountField) {
        paidAmountField.max = academyPrice.toFixed(2);
    }
}

function updateRenewAmounts() {
    if (!renewCard || !renewTotalDisplay || !renewRemainingDisplay || !renewPaidAmountField) {
        return;
    }

    const oldRemaining = parseMoneyValue(renewCard.dataset.oldRemaining || '0');
    const newPrice = parseMoneyValue(renewCard.dataset.newPrice || '0');
    const paidAmount = parseMoneyValue(renewPaidAmountField.value);
    const totalAmount = oldRemaining + newPrice;
    const remainingAmount = Math.max(totalAmount - paidAmount, 0);

    if (renewBasePriceField) {
        renewBasePriceField.value = newPrice.toFixed(2);
    }
    if (renewOldRemainingField) {
        renewOldRemainingField.value = oldRemaining.toFixed(2);
    }
    if (renewTotalAmountField) {
        renewTotalAmountField.value = totalAmount.toFixed(2);
    }
    if (renewRemainingAmountField) {
        renewRemainingAmountField.value = remainingAmount.toFixed(2);
    }
    renewTotalDisplay.textContent = `${totalAmount.toFixed(2)} ج.م`;
    renewRemainingDisplay.textContent = `${remainingAmount.toFixed(2)} ج.م`;
    renewPaidAmountField.max = totalAmount.toFixed(2);
}

window.addEventListener('load', () => {
    const savedTheme = localStorage.getItem('academies-players-theme');
    setAcademyPlayersTheme(savedTheme === 'dark' ? 'dark' : 'light');
    updateRegistrationAmounts();
    updateRenewAmounts();
});

if (academyPlayersThemeToggle) {
    academyPlayersThemeToggle.addEventListener('change', () => {
        const nextTheme = academyPlayersThemeToggle.checked ? 'dark' : 'light';
        setAcademyPlayersTheme(nextTheme);
        localStorage.setItem('academies-players-theme', nextTheme);
    });
}

if (academySelect) {
    academySelect.addEventListener('change', updateRegistrationAmounts);
}

if (paidAmountField) {
    paidAmountField.addEventListener('input', updateRegistrationAmounts);
}

if (renewPaidAmountField) {
    renewPaidAmountField.addEventListener('input', updateRenewAmounts);
}

if (academyPlayersClearBtn) {
    academyPlayersClearBtn.addEventListener('click', () => {
        window.location.href = academyPlayersPageUrl;
    });
}

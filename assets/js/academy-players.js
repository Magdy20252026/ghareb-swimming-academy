const academyPlayersBody = document.body;
const academyPlayersThemeToggle = document.getElementById('themeToggle');
const academyPlayersClearBtn = document.getElementById('clearBtn');
const academyPlayersOpenPlayerModalButtons = Array.from(document.querySelectorAll('[data-open-player-modal]'));
const academyPlayersPlayerModal = document.getElementById('playerFormModal');
const academyPlayersPageUrl = academyPlayersBody.dataset.pageUrl || 'academy_players.php';
const academyPlayersFormCloseUrl = academyPlayersBody.dataset.formCloseUrl || academyPlayersPageUrl;
const academyPlayersShouldOpenFormModal = academyPlayersBody.dataset.formModalOpen === '1';
const academyPlayersCanManageDiscount = academyPlayersBody.dataset.canManageDiscount === '1';
const academyPlayersCustomStarsOptions = (academyPlayersBody.dataset.customStarsOptions || '3,4')
    .split(',')
    .map((value) => Number.parseInt(value.trim(), 10))
    .filter((value) => Number.isInteger(value) && value > 0);
const academyPlayersResolvedCustomStarsOptions = academyPlayersCustomStarsOptions.length > 0
    ? academyPlayersCustomStarsOptions
    : [3, 4];
const academyPlayersFormAvailableExercisesCount = Number.parseInt(
    academyPlayersBody.dataset.formAvailableExercisesCount || '0',
    10,
);
const subscriptionSelectMinFontSize = 11;
const subscriptionSelectMaxFontSize = 15;
const subscriptionSelectReservedControlWidth = 34;
const subscriptionSelectResizeDebounceDelay = 120;
let subscriptionSelectTextSizer = null;
let subscriptionSelectResizeTimeoutId = null;
const subscriptionSelectListenersController = new AbortController();
const subscriptionSelectListenerOptions = { signal: subscriptionSelectListenersController.signal };

const subscriptionSelect = document.getElementById('subscription_id');
const subscriptionBranchField = document.getElementById('subscription_branch');
const barcodeField = document.getElementById('barcode');
const birthDateField = document.getElementById('birth_date');
const ageField = document.getElementById('player_age');
const paidAmountField = document.getElementById('paid_amount');
const discountField = document.getElementById('additional_discount');
const basePriceField = document.getElementById('subscription_base_price');
const startDateField = document.getElementById('subscription_start_date');
const totalAmountField = document.getElementById('subscription_amount');
const remainingAmountField = document.getElementById('remaining_amount');
const categoryLevelDisplay = document.getElementById('subscription_category_display');
const coachDisplay = document.getElementById('subscription_coach_display');
const scheduleDisplay = document.getElementById('subscription_schedule_display');
const exercisesDisplay = document.getElementById('subscription_exercises_display');
const capacityDisplay = document.getElementById('subscription_capacity_display');
const endDisplay = document.getElementById('subscription_end_display');
const allDocumentsCheckbox = document.getElementById('all_required_documents');
const birthCertificateCheckbox = document.getElementById('birth_certificate_required');
const birthCertificateUploadWrap = document.getElementById('birthCertificateUploadWrap');
const medicalReportToggleCard = document.getElementById('medicalReportToggleCard');
const medicalReportCheckbox = document.getElementById('medical_report_required');
const medicalReportUploadWrap = document.getElementById('medicalReportUploadWrap');
const federationCardToggleCard = document.getElementById('federationCardToggleCard');
const federationCardCheckbox = document.getElementById('federation_card_required');
const federationCardUploadWrap = document.getElementById('federationCardUploadWrap');
const starsCard = document.getElementById('starsCard');
const starsCountGroup = document.getElementById('starsCountGroup');
const starsCountField = document.getElementById('stars_count');
const starsPreview = document.getElementById('starsPreview');
const lastStarDateField = document.getElementById('last_star_date');
const paymentField = document.getElementById('payment_amount');
const paymentCard = document.getElementById('collect-payment-card');
const paymentScrollFallbackDelay = 400;
let academyPlayersLastSubscriptionId = subscriptionSelect?.value || '';
let academyPlayersLastModalTrigger = null;

function openPlayerFormModal(triggerElement = null) {
    if (!academyPlayersPlayerModal) {
        return;
    }

    academyPlayersPlayerModal.classList.remove('hidden');
    academyPlayersBody.classList.add('modal-open');
    academyPlayersLastModalTrigger = triggerElement instanceof HTMLElement ? triggerElement : null;
    academyPlayersOpenPlayerModalButtons.forEach((button) => {
        button.setAttribute('aria-expanded', 'true');
    });
    window.requestAnimationFrame(() => {
        barcodeField?.focus();
    });
}

function isPlayerModalOpen() {
    return Boolean(academyPlayersPlayerModal) && !academyPlayersPlayerModal.classList.contains('hidden');
}

function closePlayerFormModal(shouldResetPage = false) {
    if (shouldResetPage) {
        window.location.href = academyPlayersFormCloseUrl;
        return;
    }

    if (!academyPlayersPlayerModal) {
        return;
    }

    academyPlayersPlayerModal.classList.add('hidden');
    academyPlayersBody.classList.remove('modal-open');
    academyPlayersOpenPlayerModalButtons.forEach((button) => {
        button.setAttribute('aria-expanded', 'false');
    });
    academyPlayersLastModalTrigger?.focus();
}

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

function calculateAgeValue(yearValue) {
    const parsed = Number.parseInt(yearValue, 10);
    if (!Number.isInteger(parsed) || parsed < 1950 || parsed > new Date().getFullYear()) {
        return '—';
    }
    const age = new Date().getFullYear() - parsed;
    return age >= 0 ? String(age) : '—';
}

function updateAgeField() {
    if (!ageField || !birthDateField) {
        return;
    }

    ageField.value = calculateAgeValue(birthDateField.value);
}

function getSelectedSubscriptionOption() {
    if (!subscriptionSelect) {
        return null;
    }

    return subscriptionSelect.options[subscriptionSelect.selectedIndex] || null;
}

function getSubscriptionSelectTextSizer() {
    if (subscriptionSelectTextSizer) {
        return subscriptionSelectTextSizer;
    }

    subscriptionSelectTextSizer = document.createElement('span');
    subscriptionSelectTextSizer.style.position = 'absolute';
    subscriptionSelectTextSizer.style.visibility = 'hidden';
    subscriptionSelectTextSizer.style.pointerEvents = 'none';
    subscriptionSelectTextSizer.style.whiteSpace = 'nowrap';
    subscriptionSelectTextSizer.style.inset = '-9999px auto auto -9999px';
    document.body.appendChild(subscriptionSelectTextSizer);
    return subscriptionSelectTextSizer;
}

function getSubscriptionSelectFields() {
    return Array.from(document.querySelectorAll('.subscription-select-group select'));
}

function fitSubscriptionSelectText(selectField) {
    if (!(selectField instanceof HTMLSelectElement)) {
        return;
    }

    const selectedOption = selectField.options[selectField.selectedIndex] || null;
    const selectedText = selectedOption?.textContent?.trim() || '';
    selectField.title = selectedText;
    selectField.style.fontSize = `${subscriptionSelectMaxFontSize}px`;

    if (selectedText === '' || selectField.clientWidth <= 0) {
        return;
    }

    const computedStyle = window.getComputedStyle(selectField);
    const textSizer = getSubscriptionSelectTextSizer();
    textSizer.style.fontFamily = computedStyle.fontFamily;
    textSizer.style.fontWeight = computedStyle.fontWeight;
    textSizer.style.letterSpacing = computedStyle.letterSpacing;
    textSizer.textContent = selectedText;

    const paddingLeft = Number.parseFloat(computedStyle.paddingLeft) || 0;
    const paddingRight = Number.parseFloat(computedStyle.paddingRight) || 0;
    const availableWidth = selectField.clientWidth
        - paddingLeft
        - paddingRight
        - subscriptionSelectReservedControlWidth;

    if (availableWidth <= 0) {
        return;
    }

    let lowFontSize = subscriptionSelectMinFontSize;
    let highFontSize = subscriptionSelectMaxFontSize;
    let fittedFontSize = subscriptionSelectMinFontSize;

    while (lowFontSize <= highFontSize) {
        const fontSize = Math.floor((lowFontSize + highFontSize) / 2);
        textSizer.style.fontSize = `${fontSize}px`;

        if (textSizer.offsetWidth <= availableWidth) {
            fittedFontSize = fontSize;
            lowFontSize = fontSize + 1;
        } else {
            highFontSize = fontSize - 1;
        }
    }

    selectField.style.fontSize = `${fittedFontSize}px`;
}

function fitAllSubscriptionSelectTexts() {
    getSubscriptionSelectFields().forEach((selectField) => {
        fitSubscriptionSelectText(selectField);
    });
}

function debounceFitAllSubscriptionSelectTexts() {
    window.clearTimeout(subscriptionSelectResizeTimeoutId);
    subscriptionSelectResizeTimeoutId = window.setTimeout(() => {
        fitAllSubscriptionSelectTexts();
    }, subscriptionSelectResizeDebounceDelay);
}

function cleanupSubscriptionSelectFitting() {
    if (subscriptionSelectResizeTimeoutId !== null) {
        window.clearTimeout(subscriptionSelectResizeTimeoutId);
        subscriptionSelectResizeTimeoutId = null;
    }

    if (subscriptionSelectTextSizer instanceof HTMLElement) {
        subscriptionSelectTextSizer.remove();
        subscriptionSelectTextSizer = null;
    }
}

function applySubscriptionBranchFilter(preferredBranch = subscriptionBranchField?.value || '') {
    if (!subscriptionSelect || subscriptionSelect.disabled) {
        return;
    }

    const currentValue = subscriptionSelect.value;
    let currentValueVisible = false;
    let firstVisibleValue = '';

    Array.from(subscriptionSelect.options).forEach((option) => {
        if (!option.value) {
            option.hidden = false;
            option.disabled = false;
            return;
        }

        const matchesBranch = preferredBranch === '' || option.dataset.branch === preferredBranch;
        option.hidden = !matchesBranch;
        option.disabled = !matchesBranch;

        if (matchesBranch && firstVisibleValue === '') {
            firstVisibleValue = option.value;
        }

        if (matchesBranch && option.value === currentValue) {
            currentValueVisible = true;
        }
    });

    if (!currentValueVisible && firstVisibleValue !== '') {
        subscriptionSelect.value = firstVisibleValue;
    }
}

function calculateSubscriptionEndDate(
    startDateValue,
    selectedOption = getSelectedSubscriptionOption(),
    exercisesCount = null,
) {
    if (!selectedOption || !startDateValue || !/^\d{4}-\d{2}-\d{2}$/.test(startDateValue)) {
        return '';
    }

    const resolvedExercisesCount = Number.isInteger(exercisesCount)
        ? Math.max(exercisesCount, 0)
        : (Number.parseInt(selectedOption.dataset.exercises || '0', 10) || 0);
    const scheduleDays = (selectedOption.dataset.scheduleDays || '')
        .split(',')
        .map((value) => value.trim())
        .filter(Boolean);

    if (scheduleDays.length === 0) {
        return '';
    }

    const allowedDays = new Set(scheduleDays);
    const startDate = new Date(`${startDateValue}T00:00:00`);
    if (Number.isNaN(startDate.getTime())) {
        return '';
    }
    if (resolvedExercisesCount === 0) {
        return startDateValue;
    }

    const jsWeekDays = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
    const currentDate = new Date(startDate);
    let sessionsCount = 0;

    while (sessionsCount < resolvedExercisesCount) {
        const currentDayKey = jsWeekDays[currentDate.getDay()] || '';
        if (allowedDays.has(currentDayKey)) {
            sessionsCount += 1;
        }

        if (sessionsCount >= resolvedExercisesCount) {
            const year = currentDate.getFullYear();
            const month = String(currentDate.getMonth() + 1).padStart(2, '0');
            const day = String(currentDate.getDate()).padStart(2, '0');
            return `${year}-${month}-${day}`;
        }

        currentDate.setDate(currentDate.getDate() + 1);
    }

    return '';
}

function resolveDisplayedExercisesCount(selectedOption = getSelectedSubscriptionOption()) {
    const playerIdValue = document.getElementById('id')?.value || '';
    if (playerIdValue !== '' && Number.isInteger(academyPlayersFormAvailableExercisesCount)) {
        return Math.max(academyPlayersFormAvailableExercisesCount, 0);
    }

    return Number.parseInt(selectedOption?.dataset.exercises || '0', 10) || 0;
}

function updateSubscriptionEndDisplay(selectedOption = getSelectedSubscriptionOption()) {
    if (!endDisplay) {
        return;
    }

    const computedEndDate = calculateSubscriptionEndDate(
        startDateField?.value || '',
        selectedOption,
        resolveDisplayedExercisesCount(selectedOption),
    );
    endDisplay.textContent = computedEndDate || '—';
}

function updateSubscriptionDisplays() {
    const selectedOption = getSelectedSubscriptionOption();
    if (!selectedOption) {
        return;
    }

    const category = selectedOption.dataset.category || '';
    const branch = selectedOption.dataset.branch || '';
    const coach = selectedOption.dataset.coach || '';
    const schedule = selectedOption.dataset.schedule || '';
    const exercises = String(resolveDisplayedExercisesCount(selectedOption));
    const currentCount = selectedOption.dataset.currentCount || '0';
    const maxTrainees = selectedOption.dataset.maxTrainees || '0';
    const subscriptionPrice = selectedOption.dataset.price || '0.00';

    if (categoryLevelDisplay) categoryLevelDisplay.textContent = category || '—';
    if (subscriptionBranchField) {
        subscriptionBranchField.value = branch;
        applySubscriptionBranchFilter(branch);
    }
    if (coachDisplay) coachDisplay.textContent = coach;
    if (scheduleDisplay) scheduleDisplay.textContent = schedule;
    if (exercisesDisplay) exercisesDisplay.textContent = exercises;
    if (capacityDisplay) capacityDisplay.textContent = `${currentCount} / ${maxTrainees}`;
    if (basePriceField && academyPlayersLastSubscriptionId !== selectedOption.value) {
        basePriceField.value = subscriptionPrice;
    }
    academyPlayersLastSubscriptionId = selectedOption.value;

    updateConditionalFields(selectedOption);
    updateSubscriptionEndDisplay(selectedOption);
    updateSubscriptionAmounts();
    fitAllSubscriptionSelectTexts();
}

function updateConditionalFields(selectedOption = getSelectedSubscriptionOption()) {
    if (!selectedOption) {
        return;
    }

    const fixedStarsCount = Number.parseInt(selectedOption.dataset.starsCount || '0', 10) || 0;
    const allowsCustomStars = selectedOption.dataset.allowsCustomStars === '1';
    let starsCount = fixedStarsCount;

    if (allowsCustomStars) {
        const currentStarsCount = Number.parseInt(starsCountField?.value || '0', 10) || 0;
        if (starsCountField && !academyPlayersResolvedCustomStarsOptions.includes(currentStarsCount)) {
            starsCountField.value = String(academyPlayersResolvedCustomStarsOptions[0]);
        }
        starsCount = Number.parseInt(starsCountField?.value || '0', 10) || 0;
    } else if (starsCountField) {
        starsCountField.value = fixedStarsCount > 0 ? String(fixedStarsCount) : '';
    }

    if (starsCard) {
        starsCard.classList.toggle('hidden', starsCount <= 0);
    }
    if (starsCountGroup) {
        starsCountGroup.classList.toggle('hidden', !allowsCustomStars);
    }
    if (starsPreview) {
        starsPreview.textContent = starsCount > 0 ? '★'.repeat(starsCount) : '—';
    }
    if (lastStarDateField && starsCount <= 0) {
        lastStarDateField.value = '';
    }

    updateRequiredDocumentsState();
}

function updateRequiredDocumentsState(source = '') {
    const documentCheckboxes = [
        birthCertificateCheckbox,
        medicalReportCheckbox,
        federationCardCheckbox,
    ].filter(Boolean);

    if (source === 'all' && allDocumentsCheckbox) {
        documentCheckboxes.forEach((checkbox) => {
            checkbox.checked = allDocumentsCheckbox.checked;
        });
    }

    const allChecked = documentCheckboxes.length > 0 && documentCheckboxes.every((checkbox) => checkbox.checked);
    if (allDocumentsCheckbox) {
        allDocumentsCheckbox.checked = allChecked;
    }
    if (birthCertificateUploadWrap) {
        birthCertificateUploadWrap.classList.toggle('hidden', !birthCertificateCheckbox?.checked);
    }
    if (medicalReportUploadWrap) {
        medicalReportUploadWrap.classList.toggle('hidden', !medicalReportCheckbox?.checked);
    }
    if (federationCardUploadWrap) {
        federationCardUploadWrap.classList.toggle('hidden', !federationCardCheckbox?.checked);
    }
}

function updateSubscriptionAmounts() {
    if (!basePriceField || !totalAmountField || !remainingAmountField) {
        return;
    }

    const basePrice = parseMoneyValue(basePriceField.value);
    const discountValue = academyPlayersCanManageDiscount && discountField ? parseMoneyValue(discountField.value) : 0;
    const paidValue = paidAmountField ? parseMoneyValue(paidAmountField.value) : 0;
    const totalValue = Math.max(basePrice - discountValue, 0);
    const remainingValue = Math.max(totalValue - paidValue, 0);

    totalAmountField.value = totalValue.toFixed(2);
    remainingAmountField.value = remainingValue.toFixed(2);

    if (discountField) {
        discountField.max = basePrice.toFixed(2);
    }
    if (paidAmountField) {
        paidAmountField.max = totalValue.toFixed(2);
    }
}

function clearAcademyPlayersForm() {
    window.location.href = academyPlayersPageUrl;
}

function focusPaymentInput() {
    if (paymentField) {
        paymentField.focus();
    }
}

function focusPaymentField() {
    if (!paymentField || !paymentCard) {
        return;
    }

    const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const scrollBehavior = prefersReducedMotion ? 'auto' : 'smooth';

    if ('onscrollend' in window && scrollBehavior === 'smooth') {
        let focused = false;
        let fallbackTimeout = 0;
        const finishFocus = () => {
            if (focused) {
                return;
            }

            focused = true;
            window.removeEventListener('scrollend', finishFocus);
            window.clearTimeout(fallbackTimeout);
            focusPaymentInput();
        };

        window.addEventListener('scrollend', finishFocus, { once: true });
        fallbackTimeout = window.setTimeout(finishFocus, paymentScrollFallbackDelay);
    }

    paymentCard.scrollIntoView({ behavior: scrollBehavior, block: 'start' });
    if (!('onscrollend' in window) || scrollBehavior !== 'smooth') {
        window.requestAnimationFrame(focusPaymentInput);
    }
}

window.addEventListener('load', () => {
    const savedTheme = localStorage.getItem('academy-players-theme');
    setAcademyPlayersTheme(savedTheme === 'dark' ? 'dark' : 'light');
    updateAgeField();
    applySubscriptionBranchFilter(subscriptionBranchField?.value || '');
    updateSubscriptionDisplays();
    updateRequiredDocumentsState();

    if (academyPlayersShouldOpenFormModal) {
        openPlayerFormModal();
    }

    if (window.location.hash === '#collect-payment-card') {
        focusPaymentField();
    }
});

window.addEventListener('resize', debounceFitAllSubscriptionSelectTexts, subscriptionSelectListenerOptions);

window.addEventListener('pagehide', () => {
    cleanupSubscriptionSelectFitting();
    subscriptionSelectListenersController.abort();
});

if (academyPlayersThemeToggle) {
    academyPlayersThemeToggle.addEventListener('change', () => {
        const nextTheme = academyPlayersThemeToggle.checked ? 'dark' : 'light';
        setAcademyPlayersTheme(nextTheme);
        localStorage.setItem('academy-players-theme', nextTheme);
    });
}

if (allDocumentsCheckbox) {
    allDocumentsCheckbox.addEventListener('change', () => updateRequiredDocumentsState('all'));
}

[birthCertificateCheckbox, medicalReportCheckbox, federationCardCheckbox]
    .filter(Boolean)
    .forEach((checkbox) => {
        checkbox.addEventListener('change', () => updateRequiredDocumentsState('single'));
    });

if (subscriptionSelect) {
    subscriptionSelect.addEventListener('change', () => {
        updateSubscriptionDisplays();
    });
}

if (subscriptionBranchField) {
    subscriptionBranchField.addEventListener('change', () => {
        applySubscriptionBranchFilter(subscriptionBranchField.value);
        updateSubscriptionDisplays();
    });
}

if (birthDateField) {
    birthDateField.addEventListener('change', updateAgeField);
    birthDateField.addEventListener('input', updateAgeField);
}

if (paidAmountField) {
    paidAmountField.addEventListener('input', updateSubscriptionAmounts);
}

if (basePriceField) {
    basePriceField.addEventListener('input', updateSubscriptionAmounts);
}

if (discountField) {
    discountField.addEventListener('input', updateSubscriptionAmounts);
}

if (startDateField) {
    startDateField.addEventListener('change', () => updateSubscriptionEndDisplay());
    startDateField.addEventListener('input', () => updateSubscriptionEndDisplay());
}

if (starsCountField) {
    starsCountField.addEventListener('change', () => updateConditionalFields());
}

if (academyPlayersClearBtn) {
    academyPlayersClearBtn.addEventListener('click', clearAcademyPlayersForm);
}

academyPlayersOpenPlayerModalButtons.forEach((button) => {
    button.addEventListener('click', () => openPlayerFormModal(button));
});

if (academyPlayersPlayerModal) {
    academyPlayersPlayerModal.addEventListener('click', (event) => {
        if (event.target === academyPlayersPlayerModal) {
            closePlayerFormModal(academyPlayersShouldOpenFormModal);
            return;
        }

        const closeButton = event.target.closest('[data-close-player-modal]');
        if (closeButton) {
            closePlayerFormModal(academyPlayersShouldOpenFormModal);
        }
    });
}

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && isPlayerModalOpen()) {
        closePlayerFormModal(academyPlayersShouldOpenFormModal);
    }
});

document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.classList.contains('delete-player-form')) {
        return;
    }

    if (!window.confirm('هل تريد حذف السباح؟')) {
        event.preventDefault();
    }
});

const subscriptionsBody = document.body;
const subscriptionsThemeToggle = document.getElementById('themeToggle');
const subscriptionsClearBtn = document.getElementById('clearBtn');
const subscriptionsPageUrl = subscriptionsBody?.dataset.pageUrl || window.location.pathname.split('/').pop() || '';
const subscriptionsFormCloseUrl = subscriptionsBody?.dataset.formCloseUrl || subscriptionsPageUrl;
const subscriptionsShouldOpenFormModal = subscriptionsBody?.dataset.formModalOpen === '1';
const subscriptionsShouldResetModalOnClose = subscriptionsBody?.dataset.formModalReset === '1';
const subscriptionsForm = document.getElementById('subscriptionsForm');
const subscriptionsOpenModalButtons = Array.from(document.querySelectorAll('[data-open-subscription-modal]'));
const subscriptionsFormModal = document.getElementById('subscriptionFormModal');
const subscriptionsIdField = document.getElementById('id');
const subscriptionsNameField = document.getElementById('subscription_name');
const subscriptionsCategoryField = document.getElementById('subscription_category');
const subscriptionsBranchField = document.getElementById('subscription_branch');
const subscriptionsTrainingDaysCountField = document.getElementById('training_days_count');
const subscriptionsAvailableExercisesCountField = document.getElementById('available_exercises_count');
const subscriptionsCoachField = document.getElementById('coach_id');
const subscriptionsMaxTraineesField = document.getElementById('max_trainees');
const subscriptionsPriceField = document.getElementById('subscription_price');
const subscriptionsDayCheckboxes = Array.from(document.querySelectorAll('input[name="training_days[]"]'));
const subscriptionsScheduleItems = Array.from(document.querySelectorAll('.schedule-item'));
const subscriptionsSelectedDaysCount = document.getElementById('selectedDaysCount');
const subscriptionsAllowedDaysCount = document.getElementById('allowedDaysCount');
const subscriptionsClientMessage = document.getElementById('clientMessage');
const subscriptionsDeleteForms = document.querySelectorAll('.delete-subscription-form');
const subscriptionsDeleteConfirmDialog = document.getElementById('deleteConfirmDialog');
const subscriptionsCancelDeleteBtn = document.getElementById('cancelDeleteBtn');
const subscriptionsConfirmDeleteBtn = document.getElementById('confirmDeleteBtn');
const SUBSCRIPTIONS_MESSAGES = {
    maxDays: (count) => `يمكن اختيار ${count} يوم فقط.`,
    countMismatch: 'يرجى مطابقة عدد الأيام المختارة مع عدد أيام التمرين.',
    missingTime: 'يرجى إدخال ساعة التمرين لكل يوم مختار.',
};
const SUBSCRIPTIONS_TIME_AM_AR = 'ص';
const SUBSCRIPTIONS_TIME_PM_AR = 'م';
let pendingDeleteForm = null;

function openSubscriptionsFormModal(triggerElement = null) {
    if (!subscriptionsFormModal) {
        return;
    }

    subscriptionsFormModal.classList.remove('hidden');
    subscriptionsBody.classList.add('modal-open');
    subscriptionsOpenModalButtons.forEach((button) => {
        button.setAttribute('aria-expanded', 'true');
    });

    window.requestAnimationFrame(() => {
        subscriptionsCategoryField?.focus();
    });
}

function isSubscriptionsFormModalOpen() {
    return Boolean(subscriptionsFormModal) && !subscriptionsFormModal.classList.contains('hidden');
}

function closeSubscriptionsFormModal(shouldResetPage = false) {
    if (shouldResetPage) {
        window.location.href = subscriptionsFormCloseUrl;
        return;
    }

    if (!subscriptionsFormModal) {
        return;
    }

    subscriptionsFormModal.classList.add('hidden');
    subscriptionsBody.classList.remove('modal-open');
    subscriptionsOpenModalButtons.forEach((button) => {
        button.setAttribute('aria-expanded', 'false');
    });
}

function setSubscriptionsTheme(theme) {
    if (theme === 'dark') {
        subscriptionsBody.classList.add('dark-mode');
        subscriptionsBody.classList.remove('light-mode');
        if (subscriptionsThemeToggle) {
            subscriptionsThemeToggle.checked = true;
        }
    } else {
        subscriptionsBody.classList.add('light-mode');
        subscriptionsBody.classList.remove('dark-mode');
        if (subscriptionsThemeToggle) {
            subscriptionsThemeToggle.checked = false;
        }
    }
}

function getSelectedSubscriptionsDays() {
    return subscriptionsDayCheckboxes.filter((checkbox) => checkbox.checked);
}

function setSubscriptionsClientMessage(message = '') {
    if (!subscriptionsClientMessage) {
        return;
    }

    const safeMessage = String(message || '').trim();
    subscriptionsClientMessage.textContent = safeMessage;
    subscriptionsClientMessage.hidden = safeMessage === '';
}

function updateSubscriptionsSelectionBadge() {
    const selectedCount = getSelectedSubscriptionsDays().length;
    const allowedCount = Number(subscriptionsTrainingDaysCountField?.value || 0);

    if (subscriptionsSelectedDaysCount) {
        subscriptionsSelectedDaysCount.textContent = String(selectedCount);
    }

    if (subscriptionsAllowedDaysCount) {
        subscriptionsAllowedDaysCount.textContent = String(allowedCount);
    }
}

function updateSubscriptionsScheduleVisibility() {
    const selectedDayKeys = new Set(getSelectedSubscriptionsDays().map((checkbox) => checkbox.value));

    subscriptionsScheduleItems.forEach((item) => {
        const dayKey = item.dataset.dayKey || '';
        const input = item.querySelector('input[type="time"]');
        const isActive = selectedDayKeys.has(dayKey);

        item.classList.toggle('is-active', isActive);
        if (input) {
            input.required = isActive;
            if (!isActive) {
                input.value = '';
            }
        }
    });

    subscriptionsDayCheckboxes.forEach((checkbox) => {
        const wrapper = checkbox.closest('.day-option');
        if (wrapper) {
            wrapper.classList.toggle('checked', checkbox.checked);
        }
    });

    updateSubscriptionsSelectionBadge();
    updateGeneratedSubscriptionName();
}

function clearSubscriptionsForm() {
    if (subscriptionsIdField) subscriptionsIdField.value = '';
    if (subscriptionsNameField) subscriptionsNameField.value = '';
    if (subscriptionsCategoryField) subscriptionsCategoryField.value = '';
    if (subscriptionsBranchField) subscriptionsBranchField.value = '';
    if (subscriptionsTrainingDaysCountField) subscriptionsTrainingDaysCountField.value = '1';
    if (subscriptionsAvailableExercisesCountField) subscriptionsAvailableExercisesCountField.value = '1';
    if (subscriptionsCoachField) subscriptionsCoachField.value = '';
    if (subscriptionsMaxTraineesField) subscriptionsMaxTraineesField.value = '';
    if (subscriptionsPriceField) subscriptionsPriceField.value = '0.00';

    subscriptionsDayCheckboxes.forEach((checkbox) => {
        checkbox.checked = false;
    });

    subscriptionsScheduleItems.forEach((item) => {
        const input = item.querySelector('input[type="time"]');
        if (input) {
            input.value = '';
        }
    });

    updateSubscriptionsScheduleVisibility();
    setSubscriptionsClientMessage('');
    window.history.replaceState({}, document.title, subscriptionsPageUrl);
}

function formatSubscriptionsTimeTo12Hour(value) {
    const safeValue = String(value || '').trim();
    const match = safeValue.match(/^(\d{2}):(\d{2})$/);
    if (!match) {
        return safeValue;
    }

    const hours = parseInt(match[1], 10);
    const minutes = match[2];
    if (!Number.isInteger(hours) || hours < 0 || hours > 23) {
        return safeValue;
    }

    const meridiem = hours >= 12 ? SUBSCRIPTIONS_TIME_PM_AR : SUBSCRIPTIONS_TIME_AM_AR;
    const formattedHours = hours % 12 || 12;
    return `${String(formattedHours).padStart(2, '0')}:${minutes} ${meridiem}`;
}

function getGeneratedSubscriptionSchedule() {
    return subscriptionsDayCheckboxes
        .filter((checkbox) => checkbox.checked)
        .map((checkbox) => {
            const scheduleItem = document.querySelector(`.schedule-item[data-day-key="${checkbox.value}"]`);
            const timeInput = scheduleItem?.querySelector('input[type="time"]');
            const label = scheduleItem?.querySelector('label')?.textContent?.trim() || '';
            const timeValue = formatSubscriptionsTimeTo12Hour(timeInput?.value || '');

            if (!label || !timeValue) {
                return '';
            }

            return `${label} - ${timeValue}`;
        })
        .filter(Boolean)
        .join(' • ');
}

function getSelectedSubscriptionsScheduleInputs() {
    return getSelectedSubscriptionsDays()
        .map((checkbox) => {
            const scheduleItem = document.querySelector(`.schedule-item[data-day-key="${checkbox.value}"]`);
            return scheduleItem?.querySelector('input[type="time"]') || null;
        })
        .filter(Boolean);
}

function applyFirstSelectedTimeToSelectedInputs(changedInput = null) {
    const selectedInputs = getSelectedSubscriptionsScheduleInputs();
    if (selectedInputs.length <= 1) {
        return;
    }

    const firstInput = selectedInputs[0];
    // ننسخ تلقائيًا فقط عند تعديل وقت أول يوم محدد حتى لا نلغي أي تعديل يدوي لبقية الأيام.
    if (changedInput && changedInput !== firstInput) {
        return;
    }

    if (!firstInput.value) {
        return;
    }

    if (!changedInput || changedInput === firstInput) {
        selectedInputs.slice(1).forEach((input) => {
            input.value = firstInput.value;
        });
        return;
    }

    selectedInputs.slice(1).forEach((input) => {
        if (input !== changedInput && !input.value) {
            input.value = firstInput.value;
        }
    });
}

function updateGeneratedSubscriptionName() {
    if (!subscriptionsNameField) {
        return;
    }

    const coachName = subscriptionsCoachField?.value
        ? subscriptionsCoachField.selectedOptions?.[0]?.textContent?.trim() || ''
        : '';
    const category = subscriptionsCategoryField?.value?.trim() || '';
    const branch = subscriptionsBranchField?.value?.trim() || '';
    const schedule = getGeneratedSubscriptionSchedule();
    const nameParts = [category, coachName, schedule, branch].filter(Boolean);

    subscriptionsNameField.value = nameParts.join(' ');
}

function openSubscriptionsDeleteDialog(form) {
    pendingDeleteForm = form;

    if (subscriptionsDeleteConfirmDialog && typeof subscriptionsDeleteConfirmDialog.showModal === 'function') {
        subscriptionsDeleteConfirmDialog.showModal();
        subscriptionsConfirmDeleteBtn?.focus();
        return;
    }

    subscriptionsDeleteConfirmDialog?.setAttribute('open', 'open');
    subscriptionsConfirmDeleteBtn?.focus();
}

function closeSubscriptionsDeleteDialog() {
    pendingDeleteForm = null;

    if (subscriptionsDeleteConfirmDialog?.open) {
        if (typeof subscriptionsDeleteConfirmDialog.close === 'function') {
            subscriptionsDeleteConfirmDialog.close();
        } else {
            subscriptionsDeleteConfirmDialog.removeAttribute('open');
        }
    }
}

window.addEventListener('load', () => {
    const savedTheme = localStorage.getItem('subscriptions-theme');
    const successMessage = document.querySelector('.message-box.success');

    setSubscriptionsTheme(savedTheme === 'dark' ? 'dark' : 'light');
    updateSubscriptionsScheduleVisibility();
    updateGeneratedSubscriptionName();

    if (subscriptionsShouldOpenFormModal) {
        openSubscriptionsFormModal();
    }

    if (successMessage && window.location.search.includes('edit=')) {
        window.history.replaceState({}, document.title, subscriptionsPageUrl);
    }
});

if (subscriptionsThemeToggle) {
    subscriptionsThemeToggle.addEventListener('change', () => {
        const nextTheme = subscriptionsThemeToggle.checked ? 'dark' : 'light';
        setSubscriptionsTheme(nextTheme);
        localStorage.setItem('subscriptions-theme', nextTheme);
    });
}

if (subscriptionsClearBtn) {
    subscriptionsClearBtn.addEventListener('click', clearSubscriptionsForm);
}

subscriptionsOpenModalButtons.forEach((button) => {
    button.addEventListener('click', () => openSubscriptionsFormModal(button));
});

subscriptionsCategoryField?.addEventListener('change', updateGeneratedSubscriptionName);
subscriptionsBranchField?.addEventListener('change', updateGeneratedSubscriptionName);
subscriptionsCoachField?.addEventListener('change', updateGeneratedSubscriptionName);

if (subscriptionsTrainingDaysCountField) {
    subscriptionsTrainingDaysCountField.addEventListener('change', () => {
        const allowedCount = Number(subscriptionsTrainingDaysCountField.value || 0);
        const selectedDays = getSelectedSubscriptionsDays();

        if (selectedDays.length > allowedCount) {
            selectedDays.slice(allowedCount).forEach((checkbox) => {
                checkbox.checked = false;
            });
        }

        updateSubscriptionsScheduleVisibility();
    });
}

subscriptionsDayCheckboxes.forEach((checkbox) => {
    checkbox.addEventListener('change', () => {
        const allowedCount = Number(subscriptionsTrainingDaysCountField?.value || 0);
        const selectedDays = getSelectedSubscriptionsDays();

        if (selectedDays.length > allowedCount) {
            checkbox.checked = false;
            setSubscriptionsClientMessage(SUBSCRIPTIONS_MESSAGES.maxDays(allowedCount));
        } else {
            setSubscriptionsClientMessage('');
        }

        updateSubscriptionsScheduleVisibility();
        applyFirstSelectedTimeToSelectedInputs();
        updateGeneratedSubscriptionName();
    });
});

subscriptionsScheduleItems.forEach((item) => {
    const timeInput = item.querySelector('input[type="time"]');
    timeInput?.addEventListener('input', () => {
        applyFirstSelectedTimeToSelectedInputs(timeInput);
        updateGeneratedSubscriptionName();
    });
});

subscriptionsDeleteForms.forEach((form) => {
    form.addEventListener('submit', (event) => {
        event.preventDefault();
        setSubscriptionsClientMessage('');
        openSubscriptionsDeleteDialog(form);
    });
});

subscriptionsCancelDeleteBtn?.addEventListener('click', closeSubscriptionsDeleteDialog);

subscriptionsDeleteConfirmDialog?.addEventListener('cancel', (event) => {
    event.preventDefault();
    closeSubscriptionsDeleteDialog();
});

if (subscriptionsFormModal) {
    subscriptionsFormModal.addEventListener('click', (event) => {
        if (event.target === subscriptionsFormModal) {
            closeSubscriptionsFormModal(subscriptionsShouldResetModalOnClose);
            return;
        }

        const closeButton = event.target.closest('[data-close-subscription-modal]');
        if (closeButton) {
            closeSubscriptionsFormModal(subscriptionsShouldResetModalOnClose);
        }
    });
}

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && isSubscriptionsFormModalOpen()) {
        closeSubscriptionsFormModal(subscriptionsShouldResetModalOnClose);
    }
});

subscriptionsConfirmDeleteBtn?.addEventListener('click', () => {
    if (!pendingDeleteForm) {
        closeSubscriptionsDeleteDialog();
        return;
    }

    const formToSubmit = pendingDeleteForm;
    closeSubscriptionsDeleteDialog();
    formToSubmit.submit();
});

if (subscriptionsForm) {
    subscriptionsForm.addEventListener('submit', (event) => {
        const allowedCount = Number(subscriptionsTrainingDaysCountField?.value || 0);
        const selectedDays = getSelectedSubscriptionsDays();

        if (selectedDays.length !== allowedCount) {
            event.preventDefault();
            setSubscriptionsClientMessage(SUBSCRIPTIONS_MESSAGES.countMismatch);
            return;
        }

        for (const checkbox of selectedDays) {
            const scheduleItem = document.querySelector(`.schedule-item[data-day-key="${checkbox.value}"]`);
            const timeInput = scheduleItem?.querySelector('input[type="time"]');
            if (!timeInput || !timeInput.value) {
                event.preventDefault();
                setSubscriptionsClientMessage(SUBSCRIPTIONS_MESSAGES.missingTime);
                return;
            }
        }

        setSubscriptionsClientMessage('');
        updateGeneratedSubscriptionName();
    });
}

const clearBtn = document.getElementById("clearBtn");
const themeToggleAdministrators = document.getElementById("themeToggle");
const bodyAdministrators = document.body;
const fullNameField = document.getElementById("full_name");
const phoneField = document.getElementById("phone");
const barcodeField = document.getElementById("barcode");
const passwordField = document.getElementById("password");
const leaveDaysFields = document.querySelectorAll('input[name="leave_days[]"]');
const leaveDaysSummary = document.getElementById("leaveDaysSummary");
const imageViewerModal = document.getElementById("imageViewerModal");
const imageViewerImage = document.getElementById("imageViewerImage");
const imageViewerTitle = document.getElementById("imageViewerTitle");
const galleryImageButtons = document.querySelectorAll(".gallery-image-button");
const imageViewerCloseButtons = document.querySelectorAll("[data-close-image-viewer]");
const confirmSubmitForms = document.querySelectorAll(".js-confirm-submit");
const administratorImagesBasePath = (bodyAdministrators.dataset.administratorImagesBasePath || "uploads/administrators")
    .replace(/^\/+|\/+$/g, "");
const defaultConfirmMessage = bodyAdministrators.dataset.defaultConfirmMessage || "هل أنت متأكد؟";

const arabicNumberMap = {
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

function normalizeArabicNumbers(value) {
    return value.replace(/[٠-٩۰-۹]/g, (digit) => arabicNumberMap[digit] || digit);
}

function clearFields() {
    const idField = document.getElementById("id");

    if (idField) idField.value = "";
    if (fullNameField) fullNameField.value = "";
    if (phoneField) phoneField.value = "";
    if (barcodeField) barcodeField.value = "";
    if (passwordField) passwordField.value = "";
    leaveDaysFields.forEach((checkbox) => {
        checkbox.checked = false;
    });
    updateLeaveDaysSelectionState();
}

function updateLeaveDaysSelectionState() {
    const selectedDays = [];

    leaveDaysFields.forEach((checkbox) => {
        const leaveDayCard = checkbox.closest(".leave-day-card");
        if (leaveDayCard) {
            leaveDayCard.classList.toggle("is-selected", checkbox.checked);
        }

        if (checkbox.checked) {
            const leaveDayLabel = leaveDayCard?.querySelector(".leave-day-label")?.textContent?.trim() || "";
            if (leaveDayLabel !== "") {
                selectedDays.push(leaveDayLabel);
            }
        }
    });

    if (leaveDaysSummary) {
        leaveDaysSummary.textContent = selectedDays.length > 0 ? selectedDays.join(" • ") : "بدون";
    }
}

if (fullNameField) {
    fullNameField.addEventListener("input", () => {
        fullNameField.value = fullNameField.value.replace(/\s+/g, " ").trim();
    });
}

if (phoneField) {
    phoneField.addEventListener("input", () => {
        let normalizedValue = normalizeArabicNumbers(phoneField.value);
        normalizedValue = normalizedValue.replace(/[^0-9+]/g, "");
        normalizedValue = normalizedValue.replace(/(?!^)\+/g, "");
        phoneField.value = normalizedValue;
    });
}

if (barcodeField) {
    barcodeField.addEventListener("input", () => {
        let normalizedValue = normalizeArabicNumbers(barcodeField.value);
        normalizedValue = normalizedValue.replace(/\s+/g, "");
        barcodeField.value = normalizedValue;
    });
}

leaveDaysFields.forEach((checkbox) => {
    checkbox.addEventListener("change", updateLeaveDaysSelectionState);
});

if (clearBtn) {
    clearBtn.addEventListener("click", () => {
        clearFields();
        window.history.replaceState({}, document.title, "administrators.php");
    });
}

window.addEventListener("load", () => {
    const messageBox = document.querySelector(".message-box");
    const savedTheme = localStorage.getItem("administrators-theme");

    if (savedTheme === "dark") {
        bodyAdministrators.classList.add("dark-mode");
        bodyAdministrators.classList.remove("light-mode");
        if (themeToggleAdministrators) themeToggleAdministrators.checked = true;
    } else {
        bodyAdministrators.classList.add("light-mode");
        bodyAdministrators.classList.remove("dark-mode");
        if (themeToggleAdministrators) themeToggleAdministrators.checked = false;
    }

    if (messageBox && messageBox.classList.contains("success") && messageBox.dataset.shouldResetForm === "true") {
        clearFields();
        window.history.replaceState({}, document.title, "administrators.php");
    }

    updateLeaveDaysSelectionState();
});

if (themeToggleAdministrators) {
    themeToggleAdministrators.addEventListener("change", () => {
        if (themeToggleAdministrators.checked) {
            bodyAdministrators.classList.add("dark-mode");
            bodyAdministrators.classList.remove("light-mode");
            localStorage.setItem("administrators-theme", "dark");
        } else {
            bodyAdministrators.classList.add("light-mode");
            bodyAdministrators.classList.remove("dark-mode");
            localStorage.setItem("administrators-theme", "light");
        }
    });
}

function closeImageViewer() {
    if (!imageViewerModal || !imageViewerImage) {
        return;
    }

    imageViewerModal.hidden = true;
    imageViewerImage.src = "";
    imageViewerImage.alt = "";
    bodyAdministrators.classList.remove("modal-open");
}

function escapeRegExp(value) {
    return value.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
}

function normalizeAdministratorImagePathIfValid(imagePath) {
    const normalizedPath = (imagePath || "").trim();
    if (normalizedPath === "") {
        return "";
    }

    try {
        const imageUrl = new URL(normalizedPath, window.location.href);
        const normalizedPathname = imageUrl.pathname.replace(/\/+/g, "/");

        if (imageUrl.origin !== window.location.origin) {
            return "";
        }

        const administratorImagesPathPattern = new RegExp(`(^|/)${escapeRegExp(administratorImagesBasePath)}/[^/]+\\.(?:jpe?g|png|gif|webp|bmp|svg|ico|avif)$`, "i");
        if (!administratorImagesPathPattern.test(normalizedPathname)) {
            return "";
        }

        return imageUrl.href;
    } catch (error) {
        return "";
    }
}

function openImageViewer(imagePath, imageTitle) {
    if (!imageViewerModal || !imageViewerImage) {
        return;
    }

    const safeImagePath = normalizeAdministratorImagePathIfValid(imagePath);
    if (safeImagePath === "") {
        return;
    }

    imageViewerImage.src = safeImagePath;
    imageViewerImage.alt = imageTitle;
    if (imageViewerTitle) {
        imageViewerTitle.textContent = imageTitle || "معاينة الصورة";
    }

    imageViewerModal.hidden = false;
    bodyAdministrators.classList.add("modal-open");
}

galleryImageButtons.forEach((button) => {
    button.addEventListener("click", () => {
        const imagePath = button.getAttribute("data-full-image") || "";
        const imageTitle = button.getAttribute("data-image-title") || "معاينة الصورة";
        openImageViewer(imagePath, imageTitle);
    });
});

confirmSubmitForms.forEach((form) => {
    form.addEventListener("submit", (event) => {
        const confirmMessage = form.getAttribute("data-confirm-message") || defaultConfirmMessage;
        if (!window.confirm(confirmMessage)) {
            event.preventDefault();
        }
    });
});

imageViewerCloseButtons.forEach((button) => {
    button.addEventListener("click", closeImageViewer);
});

window.addEventListener("keydown", (event) => {
    if (event.key === "Escape") {
        closeImageViewer();
    }
});

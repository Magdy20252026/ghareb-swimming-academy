const clearBtn = document.getElementById("clearBtn");
const themeToggleCoaches = document.getElementById("themeToggle");
const bodyCoaches = document.body;
const fullNameField = document.getElementById("full_name");
const phoneField = document.getElementById("phone");
const passwordField = document.getElementById("password");
const hourlyRateField = document.getElementById("hourly_rate");
const imageViewerModal = document.getElementById("imageViewerModal");
const imageViewerImage = document.getElementById("imageViewerImage");
const imageViewerTitle = document.getElementById("imageViewerTitle");
const galleryImageButtons = document.querySelectorAll(".gallery-image-button");
const imageViewerCloseButtons = document.querySelectorAll("[data-close-image-viewer]");
const confirmSubmitForms = document.querySelectorAll(".js-confirm-submit");
const coachImagesBasePath = (bodyCoaches.dataset.coachImagesBasePath || "uploads/coaches")
    .replace(/^\/+|\/+$/g, "");
const defaultConfirmMessage = bodyCoaches.dataset.defaultConfirmMessage || "هل أنت متأكد؟";

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
    if (passwordField) passwordField.value = "";
    if (hourlyRateField) hourlyRateField.value = "";
}

if (fullNameField) {
    fullNameField.addEventListener("input", () => {
        fullNameField.value = fullNameField.value.replace(/\s{2,}/g, " ");
    });

    fullNameField.addEventListener("blur", () => {
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

if (hourlyRateField) {
    hourlyRateField.addEventListener("input", () => {
        let normalizedValue = normalizeArabicNumbers(hourlyRateField.value).replace(/,/g, ".");
        normalizedValue = normalizedValue.replace(/[^0-9.]/g, "");

        const parts = normalizedValue.split(".");
        if (parts.length > 2) {
            normalizedValue = `${parts[0]}.${parts.slice(1).join("")}`;
        }

        const [integerPart, decimalPart] = normalizedValue.split(".");
        hourlyRateField.value = decimalPart !== undefined
            ? `${integerPart}.${decimalPart.slice(0, 2)}`
            : integerPart;
    });
}

if (clearBtn) {
    clearBtn.addEventListener("click", () => {
        clearFields();
        window.history.replaceState({}, document.title, "coaches.php");
    });
}

window.addEventListener("load", () => {
    const messageBox = document.querySelector(".message-box");
    const savedTheme = localStorage.getItem("coaches-theme");

    if (savedTheme === "dark") {
        bodyCoaches.classList.add("dark-mode");
        bodyCoaches.classList.remove("light-mode");
        if (themeToggleCoaches) themeToggleCoaches.checked = true;
    } else {
        bodyCoaches.classList.add("light-mode");
        bodyCoaches.classList.remove("dark-mode");
        if (themeToggleCoaches) themeToggleCoaches.checked = false;
    }

    if (messageBox && messageBox.classList.contains("success") && messageBox.dataset.shouldResetForm === "true") {
        clearFields();
        window.history.replaceState({}, document.title, "coaches.php");
    }
});

if (themeToggleCoaches) {
    themeToggleCoaches.addEventListener("change", () => {
        if (themeToggleCoaches.checked) {
            bodyCoaches.classList.add("dark-mode");
            bodyCoaches.classList.remove("light-mode");
            localStorage.setItem("coaches-theme", "dark");
        } else {
            bodyCoaches.classList.add("light-mode");
            bodyCoaches.classList.remove("dark-mode");
            localStorage.setItem("coaches-theme", "light");
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
    bodyCoaches.classList.remove("modal-open");
}

function escapeRegExp(value) {
    return value.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
}

function normalizeCoachImagePathIfValid(imagePath) {
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

        const coachImagesPathPattern = new RegExp(`(^|/)${escapeRegExp(coachImagesBasePath)}/[^/]+\\.(?:jpe?g|png|gif|webp|bmp|svg|ico|avif)$`, "i");
        if (!coachImagesPathPattern.test(normalizedPathname)) {
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

    const safeImagePath = normalizeCoachImagePathIfValid(imagePath);
    if (safeImagePath === "") {
        return;
    }

    imageViewerImage.src = safeImagePath;
    imageViewerImage.alt = imageTitle;
    if (imageViewerTitle) {
        imageViewerTitle.textContent = imageTitle || "معاينة الصورة";
    }

    imageViewerModal.hidden = false;
    bodyCoaches.classList.add("modal-open");
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

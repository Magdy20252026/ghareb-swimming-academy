const playerAttendanceBody = document.body;
const playerAttendanceThemeToggle = document.getElementById('themeToggle');
const playerAttendanceBarcodeInput = document.getElementById('barcode');
const playerAttendanceBarcodeForm = document.getElementById('barcodeForm');
const playerAttendanceCameraTrigger = document.getElementById('cameraTrigger');
const playerAttendanceCameraModal = document.getElementById('cameraModal');
const playerAttendanceCameraClose = document.getElementById('cameraClose');
const playerAttendanceCameraVideo = document.getElementById('cameraVideo');
const playerAttendanceConfirmForms = document.querySelectorAll('.js-confirm-submit');
const playerAttendanceArabicNumberMap = {
    '٠': '0',
    '١': '1',
    '٢': '2',
    '٣': '3',
    '٤': '4',
    '٥': '5',
    '٦': '6',
    '٧': '7',
    '٨': '8',
    '٩': '9',
    '۰': '0',
    '۱': '1',
    '۲': '2',
    '۳': '3',
    '۴': '4',
    '۵': '5',
    '۶': '6',
    '۷': '7',
    '۸': '8',
    '۹': '9',
};
const playerAttendanceSupportedBarcodeFormats = ['code_128', 'ean_13', 'ean_8', 'upc_a', 'upc_e', 'itf', 'codabar', 'qr_code'];
const playerAttendanceMobileCameraBreakpoint = 820;
const playerAttendanceCameraUnavailableMessage = 'الكاميرا غير متاحة على هذا الجهاز';
let playerAttendanceCameraStream = null;
let playerAttendanceCameraFrame = null;
let playerAttendanceBarcodeDetector = null;
let playerAttendanceCameraAvailable = false;

function normalizePlayerAttendanceBarcode(value) {
    return String(value)
        .replace(/[٠-٩۰-۹]/g, (digit) => playerAttendanceArabicNumberMap[digit] || digit)
        .replace(/\s+/g, '');
}

function isPlayerAttendanceMobile() {
    return window.matchMedia(`(max-width: ${playerAttendanceMobileCameraBreakpoint}px)`).matches || window.matchMedia('(pointer: coarse)').matches;
}

function canUsePlayerAttendanceCamera() {
    return isPlayerAttendanceMobile() && playerAttendanceCameraAvailable;
}

function syncPlayerAttendanceCameraTrigger() {
    const canUseCamera = canUsePlayerAttendanceCamera();

    playerAttendanceBody.classList.toggle('has-mobile-camera-scan', canUseCamera);

    if (playerAttendanceCameraTrigger) {
        playerAttendanceCameraTrigger.hidden = !canUseCamera;
    }
}

async function initializePlayerAttendanceCameraSupport() {
    playerAttendanceCameraAvailable = false;
    playerAttendanceBarcodeDetector = null;

    if (
        !isPlayerAttendanceMobile()
        || !navigator.mediaDevices?.getUserMedia
        || typeof window.BarcodeDetector === 'undefined'
    ) {
        syncPlayerAttendanceCameraTrigger();
        return;
    }

    try {
        const supportedFormats = typeof window.BarcodeDetector.getSupportedFormats === 'function'
            ? await window.BarcodeDetector.getSupportedFormats()
            : [];
        const detectorFormats = supportedFormats.length > 0
            ? playerAttendanceSupportedBarcodeFormats.filter((format) => supportedFormats.includes(format))
            : playerAttendanceSupportedBarcodeFormats;

        if (detectorFormats.length === 0) {
            syncPlayerAttendanceCameraTrigger();
            return;
        }

        playerAttendanceBarcodeDetector = new window.BarcodeDetector({ formats: detectorFormats });
        playerAttendanceCameraAvailable = true;
    } catch (error) {
        playerAttendanceBarcodeDetector = null;
        playerAttendanceCameraAvailable = false;
    }

    syncPlayerAttendanceCameraTrigger();
}

function setPlayerAttendanceTheme(theme) {
    if (theme === 'dark') {
        playerAttendanceBody.classList.add('dark-mode');
        playerAttendanceBody.classList.remove('light-mode');
        localStorage.setItem('player-attendance-theme', 'dark');
        if (playerAttendanceThemeToggle) {
            playerAttendanceThemeToggle.checked = true;
        }
        return;
    }

    playerAttendanceBody.classList.add('light-mode');
    playerAttendanceBody.classList.remove('dark-mode');
    localStorage.setItem('player-attendance-theme', 'light');
    if (playerAttendanceThemeToggle) {
        playerAttendanceThemeToggle.checked = false;
    }
}

function stopPlayerAttendanceCamera() {
    if (playerAttendanceCameraFrame) {
        window.cancelAnimationFrame(playerAttendanceCameraFrame);
        playerAttendanceCameraFrame = null;
    }

    if (playerAttendanceCameraStream) {
        playerAttendanceCameraStream.getTracks().forEach((track) => track.stop());
        playerAttendanceCameraStream = null;
    }

    if (playerAttendanceCameraVideo) {
        playerAttendanceCameraVideo.srcObject = null;
    }

    if (playerAttendanceCameraModal) {
        playerAttendanceCameraModal.hidden = true;
    }
}

async function scanPlayerAttendanceFrame() {
    if (!playerAttendanceBarcodeDetector || !playerAttendanceCameraVideo || playerAttendanceCameraVideo.readyState < 2) {
        playerAttendanceCameraFrame = window.requestAnimationFrame(scanPlayerAttendanceFrame);
        return;
    }

    try {
        const barcodes = await playerAttendanceBarcodeDetector.detect(playerAttendanceCameraVideo);
        if (Array.isArray(barcodes) && barcodes.length > 0) {
            const rawValue = normalizePlayerAttendanceBarcode(barcodes[0]?.rawValue || '');
            if (rawValue !== '' && playerAttendanceBarcodeInput && playerAttendanceBarcodeForm) {
                playerAttendanceBarcodeInput.value = rawValue;
                stopPlayerAttendanceCamera();
                playerAttendanceBarcodeForm.submit();
                return;
            }
        }
    } catch (error) {
        stopPlayerAttendanceCamera();
        return;
    }

    playerAttendanceCameraFrame = window.requestAnimationFrame(scanPlayerAttendanceFrame);
}

async function startPlayerAttendanceCamera() {
    if (!playerAttendanceCameraTrigger || !playerAttendanceCameraModal || !playerAttendanceCameraVideo) {
        return;
    }

    try {
        if (!canUsePlayerAttendanceCamera() || !playerAttendanceBarcodeDetector) {
            await initializePlayerAttendanceCameraSupport();
        }

        if (!canUsePlayerAttendanceCamera() || !playerAttendanceBarcodeDetector) {
            window.alert(playerAttendanceCameraUnavailableMessage);
            return;
        }

        playerAttendanceCameraStream = await navigator.mediaDevices.getUserMedia({
            video: {
                facingMode: { ideal: 'environment' },
            },
            audio: false,
        });
        playerAttendanceCameraVideo.srcObject = playerAttendanceCameraStream;
        playerAttendanceCameraModal.hidden = false;
        await playerAttendanceCameraVideo.play();
        playerAttendanceCameraFrame = window.requestAnimationFrame(scanPlayerAttendanceFrame);
    } catch (error) {
        window.alert('تعذر تشغيل الكاميرا');
        stopPlayerAttendanceCamera();
    }
}

window.addEventListener('load', async () => {
    const savedTheme = localStorage.getItem('player-attendance-theme');
    setPlayerAttendanceTheme(savedTheme === 'dark' ? 'dark' : 'light');

    if (playerAttendanceBarcodeInput) {
        playerAttendanceBarcodeInput.value = normalizePlayerAttendanceBarcode(playerAttendanceBarcodeInput.value);
        playerAttendanceBarcodeInput.focus();
    }

    await initializePlayerAttendanceCameraSupport();
});

if (playerAttendanceThemeToggle) {
    playerAttendanceThemeToggle.addEventListener('change', () => {
        setPlayerAttendanceTheme(playerAttendanceThemeToggle.checked ? 'dark' : 'light');
    });
}

if (playerAttendanceBarcodeInput) {
    playerAttendanceBarcodeInput.addEventListener('input', () => {
        playerAttendanceBarcodeInput.value = normalizePlayerAttendanceBarcode(playerAttendanceBarcodeInput.value);
    });
}

playerAttendanceConfirmForms.forEach((form) => {
    form.addEventListener('submit', (event) => {
        const confirmMessage = form.getAttribute('data-confirm-message') || 'تأكيد؟';
        if (!window.confirm(confirmMessage)) {
            event.preventDefault();
        }
    });
});

if (playerAttendanceCameraTrigger) {
    playerAttendanceCameraTrigger.addEventListener('click', () => {
        startPlayerAttendanceCamera();
    });
}

if (playerAttendanceCameraClose) {
    playerAttendanceCameraClose.addEventListener('click', () => {
        stopPlayerAttendanceCamera();
    });
}

if (playerAttendanceCameraModal) {
    playerAttendanceCameraModal.addEventListener('click', (event) => {
        if (event.target === playerAttendanceCameraModal) {
            stopPlayerAttendanceCamera();
        }
    });
}

window.addEventListener('beforeunload', () => {
    stopPlayerAttendanceCamera();
});

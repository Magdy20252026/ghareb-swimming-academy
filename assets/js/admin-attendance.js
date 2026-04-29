const bodyAttendance = document.body;
const themeToggleAttendance = document.getElementById("themeToggle");
const liveClock = document.getElementById("liveClock");
const liveDate = document.getElementById("liveDate");
const attendanceForm = document.getElementById("attendanceForm");
const barcodeField = document.getElementById("barcode");
const submitBtn = document.getElementById("submitBtn");
const clearBtn = document.getElementById("clearBtn");
const mobileScannerSection = document.getElementById("mobileScannerSection");
const startCameraBtn = document.getElementById("startCameraBtn");
const stopCameraBtn = document.getElementById("stopCameraBtn");
const cameraFrame = document.getElementById("cameraFrame");
const cameraPreview = document.getElementById("cameraPreview");
const scanState = document.getElementById("scanState");

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

const MOBILE_VIEWPORT_QUERY = "(max-width: 820px), (pointer: coarse)";
const BARCODE_SCANNER_MAX_DURATION_MS = 350;
const BARCODE_INPUT_DEBOUNCE_MS = 180;
const BARCODE_KEYSTROKE_HISTORY_LIMIT = 12;
const BARCODE_MIN_LENGTH = 4;
const BARCODE_DETECTOR_FORMATS = ["code_128", "ean_13", "ean_8", "upc_a", "upc_e", "itf", "codabar", "qr_code"];

let submitLocked = false;
let scannerTimer = null;
let scannerKeyTimes = [];
let cameraStream = null;
let cameraDetector = null;
let cameraLoopId = null;
let clockIntervalId = null;

function normalizeArabicNumbers(value) {
    return value.replace(/[٠-٩۰-۹]/g, (digit) => arabicNumberMap[digit] || digit);
}

function normalizeBarcodeValue(value) {
    return normalizeArabicNumbers(value).replace(/\s+/g, "");
}

function setAttendanceTheme(isDarkMode) {
    if (isDarkMode) {
        bodyAttendance.classList.add("dark-mode");
        bodyAttendance.classList.remove("light-mode");
        localStorage.setItem("admin-attendance-theme", "dark");
    } else {
        bodyAttendance.classList.add("light-mode");
        bodyAttendance.classList.remove("dark-mode");
        localStorage.setItem("admin-attendance-theme", "light");
    }
}

function updateCairoClock() {
    const now = new Date();

    if (liveClock) {
        liveClock.textContent = new Intl.DateTimeFormat("en-GB", {
            timeZone: "Africa/Cairo",
            hour: "2-digit",
            minute: "2-digit",
            second: "2-digit",
            hour12: false,
        }).format(now);
    }

    if (liveDate) {
        const parts = new Intl.DateTimeFormat("en-CA", {
            timeZone: "Africa/Cairo",
            year: "numeric",
            month: "2-digit",
            day: "2-digit",
        }).formatToParts(now);
        const year = parts.find((part) => part.type === "year")?.value || "0000";
        const month = parts.find((part) => part.type === "month")?.value || "00";
        const day = parts.find((part) => part.type === "day")?.value || "00";
        liveDate.textContent = `${year}-${month}-${day}`;
    }
}

function unlockSubmit() {
    submitLocked = false;
    if (submitBtn) {
        submitBtn.disabled = false;
    }
}

function submitAttendanceForm() {
    if (!attendanceForm || !barcodeField || submitLocked) {
        return;
    }

    barcodeField.value = normalizeBarcodeValue(barcodeField.value);
    if (barcodeField.value === "") {
        return;
    }

    submitLocked = true;
    if (submitBtn) {
        submitBtn.disabled = true;
    }
    attendanceForm.submit();
}

function setScanState(text) {
    if (scanState) {
        scanState.textContent = text;
    }
}

function isMobileDevice() {
    return window.matchMedia(MOBILE_VIEWPORT_QUERY).matches;
}

function stopCamera() {
    if (cameraLoopId) {
        window.cancelAnimationFrame(cameraLoopId);
        cameraLoopId = null;
    }

    if (cameraStream) {
        cameraStream.getTracks().forEach((track) => track.stop());
        cameraStream = null;
    }

    if (cameraPreview) {
        cameraPreview.srcObject = null;
    }

    if (cameraFrame) {
        cameraFrame.hidden = true;
    }

    if (startCameraBtn) {
        startCameraBtn.hidden = false;
    }

    if (stopCameraBtn) {
        stopCameraBtn.hidden = true;
    }
}

async function scanCameraFrame() {
    if (!cameraDetector || !cameraPreview || cameraPreview.readyState < 2) {
        cameraLoopId = window.requestAnimationFrame(scanCameraFrame);
        return;
    }

    try {
        const barcodes = await cameraDetector.detect(cameraPreview);
        if (Array.isArray(barcodes) && barcodes.length > 0) {
            const detectedValue = normalizeBarcodeValue(barcodes[0].rawValue || "");
            if (detectedValue !== "" && barcodeField) {
                barcodeField.value = detectedValue;
                setScanState("تمت القراءة");
                stopCamera();
                submitAttendanceForm();
                return;
            }
        }
    } catch (error) {
        setScanState("غير متاح");
        stopCamera();
        return;
    }

    cameraLoopId = window.requestAnimationFrame(scanCameraFrame);
}

async function startCamera() {
    if (!isMobileDevice()) {
        return;
    }

    if (!("BarcodeDetector" in window) || !navigator.mediaDevices?.getUserMedia) {
        setScanState("غير متاح");
        return;
    }

    try {
        setScanState("جارٍ الفتح");
        const supportedFormats = typeof window.BarcodeDetector.getSupportedFormats === "function"
            ? await window.BarcodeDetector.getSupportedFormats()
            : BARCODE_DETECTOR_FORMATS;
        const detectorFormats = BARCODE_DETECTOR_FORMATS.filter((format) => supportedFormats.includes(format));

        cameraDetector = detectorFormats.length > 0
            ? new window.BarcodeDetector({ formats: detectorFormats })
            : new window.BarcodeDetector();
        cameraStream = await navigator.mediaDevices.getUserMedia({
            video: {
                facingMode: { ideal: "environment" },
            },
            audio: false,
        });

        if (!cameraPreview || !cameraFrame) {
            return;
        }

        cameraPreview.srcObject = cameraStream;
        cameraFrame.hidden = false;
        await cameraPreview.play();
        setScanState("جاهز");
        if (startCameraBtn) {
            startCameraBtn.hidden = true;
        }
        if (stopCameraBtn) {
            stopCameraBtn.hidden = false;
        }
        cameraLoopId = window.requestAnimationFrame(scanCameraFrame);
    } catch (error) {
        setScanState("مرفوض");
        stopCamera();
    }
}

window.addEventListener("load", () => {
    const savedTheme = localStorage.getItem("admin-attendance-theme");
    setAttendanceTheme(savedTheme === "dark");

    if (themeToggleAttendance) {
        themeToggleAttendance.checked = savedTheme === "dark";
    }

    updateCairoClock();
    clockIntervalId = window.setInterval(updateCairoClock, 1000);

    if (barcodeField) {
        barcodeField.value = normalizeBarcodeValue(barcodeField.value);
        if (!isMobileDevice()) {
            barcodeField.focus();
        }
    }

    if (!isMobileDevice() && mobileScannerSection) {
        mobileScannerSection.hidden = true;
    }
});

if (themeToggleAttendance) {
    themeToggleAttendance.addEventListener("change", () => {
        setAttendanceTheme(themeToggleAttendance.checked);
    });
}

if (barcodeField) {
    barcodeField.addEventListener("keydown", (event) => {
        scannerKeyTimes.push(Date.now());
        scannerKeyTimes = scannerKeyTimes.slice(-BARCODE_KEYSTROKE_HISTORY_LIMIT);

        if (event.key === "Enter") {
            event.preventDefault();
            submitAttendanceForm();
        }
    });

    barcodeField.addEventListener("input", () => {
        barcodeField.value = normalizeBarcodeValue(barcodeField.value);
        window.clearTimeout(scannerTimer);
        scannerTimer = window.setTimeout(() => {
            if (!barcodeField.value || barcodeField.value.length < BARCODE_MIN_LENGTH) {
                scannerKeyTimes = [];
                return;
            }

            if (scannerKeyTimes.length >= BARCODE_MIN_LENGTH) {
                const duration = scannerKeyTimes[scannerKeyTimes.length - 1] - scannerKeyTimes[0];
                if (duration <= BARCODE_SCANNER_MAX_DURATION_MS) {
                    submitAttendanceForm();
                }
            }
            scannerKeyTimes = [];
        }, BARCODE_INPUT_DEBOUNCE_MS);
    });
}

if (attendanceForm) {
    attendanceForm.addEventListener("submit", (event) => {
        event.preventDefault();
        submitAttendanceForm();
    });
}

if (clearBtn) {
    clearBtn.addEventListener("click", () => {
        unlockSubmit();
        if (barcodeField) {
            barcodeField.value = "";
            barcodeField.focus();
        }
    });
}

if (startCameraBtn) {
    startCameraBtn.addEventListener("click", () => {
        startCamera();
    });
}

if (stopCameraBtn) {
    stopCameraBtn.addEventListener("click", () => {
        setScanState("جاهز");
        stopCamera();
    });
}

document.addEventListener("visibilitychange", () => {
    if (document.hidden) {
        stopCamera();
    }
});

window.addEventListener("beforeunload", () => {
    if (clockIntervalId) {
        window.clearInterval(clockIntervalId);
        clockIntervalId = null;
    }
    stopCamera();
});

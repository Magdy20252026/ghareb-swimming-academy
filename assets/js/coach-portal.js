const portalBody = document.body;
const portalThemeToggle = document.getElementById("themeToggle");
const portalClearBtn = document.getElementById("clearBtn");
const portalPhoneField = document.getElementById("phone");
const portalPasswordField = document.getElementById("password");
const portalNotificationsButton = document.getElementById("enableNotificationsBtn");
const portalNotificationsStatus = document.getElementById("notificationsStatus");
const portalNotificationsFeedUrl = portalBody?.dataset.notificationsFeedUrl || "";
const portalNotificationsScope = portalBody?.dataset.notificationsScope || "";
const portalNotificationsAppName = portalBody?.dataset.notificationsAppName || "بوابة المدربين";
const portalNotificationsIconUrl = portalBody?.dataset.notificationsIconUrl || "coach_portal_icon.php";
const portalNotificationsServiceWorkerUrl = portalBody?.dataset.notificationsServiceWorkerUrl || "coach_portal_sw.js";
const portalUrl = portalBody?.dataset.portalUrl || "coach_portal.php";
const coachPortalNotificationsHistoryLimit = 200;
const coachPortalNotificationsPollInterval = 60000;
const coachPortalInitialNotificationBurstLimit = 3;
const coachPortalFallbackStateTokenItemLimit = 20;
const coachPortalSeenNotificationsKey = portalNotificationsScope
    ? `coach-portal-seen-notifications:${portalNotificationsScope}`
    : "";
let coachPortalServiceWorkerRegistration = null;
let coachPortalNotificationsInitialized = false;
let coachPortalNotificationsPollTimer = null;
let coachPortalLastStateToken = "";

const coachPortalArabicNumberMap = {
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

function normalizeCoachPortalPhone(value) {
    return value
        .replace(/[٠-٩۰-۹]/g, (digit) => coachPortalArabicNumberMap[digit] || digit)
        .replace(/[^0-9+]/g, "")
        .replace(/(?!^)\+/g, "");
}

function setCoachPortalTheme(isDarkMode) {
    if (isDarkMode) {
        portalBody.classList.add("dark-mode");
        portalBody.classList.remove("light-mode");
        localStorage.setItem("coach-portal-theme", "dark");
    } else {
        portalBody.classList.add("light-mode");
        portalBody.classList.remove("dark-mode");
        localStorage.setItem("coach-portal-theme", "light");
    }
}

function setCoachPortalNotificationsStatus(message, type = "info") {
    if (!portalNotificationsStatus) {
        return;
    }

    portalNotificationsStatus.textContent = message;
    portalNotificationsStatus.dataset.state = type;
}

function coachPortalNotificationsSupported() {
    return Boolean(window.Notification) && Boolean(window.fetch);
}

function stopCoachPortalNotificationsPolling() {
    if (coachPortalNotificationsPollTimer) {
        window.clearInterval(coachPortalNotificationsPollTimer);
        coachPortalNotificationsPollTimer = null;
    }
}

function coachPortalNotificationCountLabel(count) {
    if (count === 1) {
        return "إشعار واحد";
    }

    if (count === 2) {
        return "إشعاران";
    }

    return `${count} إشعارات`;
}

function coachPortalGetSeenNotificationIds() {
    if (!coachPortalSeenNotificationsKey) {
        return [];
    }

    try {
        const rawValue = localStorage.getItem(coachPortalSeenNotificationsKey);
        const parsedValue = rawValue ? JSON.parse(rawValue) : [];
        return Array.isArray(parsedValue) ? parsedValue.filter((value) => typeof value === "string") : [];
    } catch (error) {
        return [];
    }
}

function coachPortalSaveSeenNotificationIds(ids) {
    if (!coachPortalSeenNotificationsKey) {
        return;
    }

    const uniqueIds = Array.from(new Set(ids)).slice(-coachPortalNotificationsHistoryLimit);
    localStorage.setItem(coachPortalSeenNotificationsKey, JSON.stringify(uniqueIds));
}

async function registerCoachPortalServiceWorker() {
    if (!("serviceWorker" in navigator)) {
        return null;
    }

    if (coachPortalServiceWorkerRegistration) {
        return coachPortalServiceWorkerRegistration;
    }

    try {
        coachPortalServiceWorkerRegistration = await navigator.serviceWorker.register(portalNotificationsServiceWorkerUrl);
        return coachPortalServiceWorkerRegistration;
    } catch (error) {
        return null;
    }
}

async function requestCoachPortalNotificationsPermission() {
    if (!coachPortalNotificationsSupported()) {
        setCoachPortalNotificationsStatus("هذا المتصفح لا يدعم إشعارات التطبيق.", "error");
        return "unsupported";
    }

    const permission = await Notification.requestPermission();

    if (permission === "granted") {
        await registerCoachPortalServiceWorker();
        setCoachPortalNotificationsStatus("تم تفعيل إشعارات التطبيق بنجاح.", "success");
        await pollCoachPortalNotifications(true);
    } else if (permission === "denied") {
        setCoachPortalNotificationsStatus("تم رفض الإشعارات من المتصفح، فعّلها من إعدادات الجهاز.", "error");
    } else {
        setCoachPortalNotificationsStatus("يمكنك تفعيل الإشعارات لاحقًا من نفس الزر.", "info");
    }

    return permission;
}

async function showCoachPortalNotification(item) {
    const notificationTitle = item.title || portalNotificationsAppName;
    const notificationOptions = {
        body: item.body || "",
        icon: portalNotificationsIconUrl,
        badge: portalNotificationsIconUrl,
        tag: item.id || item.type || "coach-portal-event",
        data: {
            url: item.url || portalUrl,
        },
    };

    const serviceWorkerRegistration = await registerCoachPortalServiceWorker();
    if (serviceWorkerRegistration && typeof serviceWorkerRegistration.showNotification === "function") {
        await serviceWorkerRegistration.showNotification(notificationTitle, notificationOptions);
        return;
    }

    const notification = new Notification(notificationTitle, notificationOptions);
    notification.onclick = () => {
        notification.close();
        window.focus();
        const targetUrl = new URL(item.url || portalUrl, window.location.origin);
        const currentUrl = new URL(window.location.href);
        if (targetUrl.href !== currentUrl.href) {
            window.location.href = targetUrl.href;
        }
    };
}

async function fetchCoachPortalNotificationItems() {
    if (!portalNotificationsFeedUrl) {
        return null;
    }

    const response = await fetch(portalNotificationsFeedUrl, {
        method: "GET",
        credentials: "same-origin",
        headers: {
            "Accept": "application/json",
            "X-Requested-With": "XMLHttpRequest",
        },
        cache: "no-store",
    });

    if (response.status === 401) {
        stopCoachPortalNotificationsPolling();
        setCoachPortalNotificationsStatus("انتهت جلسة التطبيق، سجّل الدخول مرة أخرى.", "error");
        return {
            items: [],
            stateToken: "",
        };
    }

    if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
    }

    const payload = await response.json();
    return {
        items: Array.isArray(payload.items) ? payload.items.filter((item) => item && typeof item.id === "string") : [],
        stateToken: typeof payload.state_token === "string" ? payload.state_token : "",
    };
}

async function pollCoachPortalNotifications(forceNotify = false) {
    if (!portalNotificationsFeedUrl) {
        return;
    }

    try {
        const payload = await fetchCoachPortalNotificationItems();
        if (!payload) {
            return;
        }

        const { items, stateToken } = payload;
        const previousStateToken = coachPortalLastStateToken;
        const fallbackStateIds = items.slice(0, coachPortalFallbackStateTokenItemLimit).map((item) => item.id).sort();
        const resolvedStateToken = stateToken || `${items.length}:${fallbackStateIds.join("|")}`;
        const stateChanged = previousStateToken !== "" && resolvedStateToken !== "" && previousStateToken !== resolvedStateToken;
        const shouldAutoReload = stateChanged && !forceNotify && document.visibilityState === "visible";
        coachPortalLastStateToken = resolvedStateToken;

        if (shouldAutoReload) {
            stopCoachPortalNotificationsPolling();
            window.location.reload();
            return;
        }

        if (!coachPortalNotificationsSupported() || Notification.permission !== "granted") {
            coachPortalNotificationsInitialized = true;

            if (Notification.permission === "denied") {
                setCoachPortalNotificationsStatus("التحديث التلقائي يعمل لكن إشعارات الجهاز محظورة على هذا الجهاز.", "error");
            } else {
                setCoachPortalNotificationsStatus("التحديث التلقائي يعمل الآن، وفعّل الإشعارات ليصلك التنبيه خارج التطبيق.", "info");
            }
            return;
        }

        const seenIds = coachPortalGetSeenNotificationIds();
        const seenIdsSet = new Set(seenIds);
        if (!coachPortalNotificationsInitialized) {
            coachPortalSaveSeenNotificationIds(items.map((item) => item.id));
            coachPortalNotificationsInitialized = true;
            if (forceNotify) {
                const initialItems = items.slice(0, coachPortalInitialNotificationBurstLimit).reverse();
                await Promise.all(initialItems.map((item) => showCoachPortalNotification(item)));
                setCoachPortalNotificationsStatus(`تم إرسال ${coachPortalNotificationCountLabel(initialItems.length)} حالياً داخل التطبيق.`, "success");
                return;
            }

            setCoachPortalNotificationsStatus("الإشعارات مفعلة وسيصلك أي إشعار جديد من الإدارة أو الرواتب.", "success");
            return;
        }

        const unseenItems = items.filter((item) => forceNotify || !seenIdsSet.has(item.id));
        if (unseenItems.length === 0) {
            setCoachPortalNotificationsStatus("الإشعارات مفعلة ولا توجد تحديثات جديدة الآن.", "success");
            return;
        }

        const orderedUnseenItems = unseenItems.slice().reverse();
        await Promise.all(orderedUnseenItems.map((item) => showCoachPortalNotification(item)));

        coachPortalSaveSeenNotificationIds([...seenIds, ...unseenItems.map((item) => item.id)]);
        setCoachPortalNotificationsStatus(`تم إرسال ${coachPortalNotificationCountLabel(unseenItems.length)} جديد داخل التطبيق.`, "success");
    } catch (error) {
        setCoachPortalNotificationsStatus("تعذر تحديث إشعارات التطبيق حاليًا، سيتم إعادة المحاولة.", "error");
    }
}

async function initializeCoachPortalNotifications() {
    if (!portalNotificationsButton || !portalNotificationsFeedUrl) {
        return;
    }

    await registerCoachPortalServiceWorker();

    if (!coachPortalNotificationsSupported()) {
        portalNotificationsButton.disabled = true;
        setCoachPortalNotificationsStatus("التحديث التلقائي يعمل داخل التطبيق، لكن هذا المتصفح لا يدعم إشعارات الجهاز.", "error");
    } else if (Notification.permission === "granted") {
        setCoachPortalNotificationsStatus("الإشعارات والتحديث التلقائي مفعّلان داخل التطبيق وخارجه.", "success");
    } else if (Notification.permission === "denied") {
        setCoachPortalNotificationsStatus("التحديث التلقائي يعمل لكن إشعارات التطبيق محظورة على هذا الجهاز.", "error");
    } else {
        setCoachPortalNotificationsStatus("التحديث التلقائي يعمل، واضغط تفعيل الإشعارات ليصلك التنبيه خارج التطبيق.", "info");
    }

    coachPortalNotificationsInitialized = false;
    await pollCoachPortalNotifications();
    coachPortalNotificationsPollTimer = window.setInterval(() => {
        pollCoachPortalNotifications();
    }, coachPortalNotificationsPollInterval);

    portalNotificationsButton.addEventListener("click", async () => {
        portalNotificationsButton.disabled = true;
        await requestCoachPortalNotificationsPermission();
        portalNotificationsButton.disabled = false;
    });

    document.addEventListener("visibilitychange", () => {
        if (document.visibilityState === "visible") {
            pollCoachPortalNotifications();
        }
    });
}

window.addEventListener("load", () => {
    const savedTheme = localStorage.getItem("coach-portal-theme");
    setCoachPortalTheme(savedTheme === "dark");

    if (portalThemeToggle) {
        portalThemeToggle.checked = savedTheme === "dark";
    }

    if (portalPhoneField) {
        portalPhoneField.value = normalizeCoachPortalPhone(portalPhoneField.value);
        portalPhoneField.focus();
        portalPhoneField.setSelectionRange(portalPhoneField.value.length, portalPhoneField.value.length);
    } else if (portalPasswordField) {
        portalPasswordField.focus();
    }

    initializeCoachPortalNotifications();
});

if (portalThemeToggle) {
    portalThemeToggle.addEventListener("change", () => {
        setCoachPortalTheme(portalThemeToggle.checked);
    });
}

if (portalPhoneField) {
    portalPhoneField.addEventListener("input", () => {
        portalPhoneField.value = normalizeCoachPortalPhone(portalPhoneField.value);
    });
}

if (portalClearBtn) {
    portalClearBtn.addEventListener("click", (event) => {
        event.preventDefault();
        window.location.href = portalBody.dataset.resetUrl || "coach_portal.php";
    });
}

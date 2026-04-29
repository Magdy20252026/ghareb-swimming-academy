const permissionsBody = document.body;
const permissionsThemeToggle = document.getElementById("themeToggle");
const permissionOptions = document.querySelectorAll(".permission-option");
const permissionCheckboxes = document.querySelectorAll('.permission-option input[type="checkbox"]');
const enabledCount = document.getElementById("enabledCount");
const selectAllBtn = document.getElementById("selectAllBtn");
const clearAllBtn = document.getElementById("clearAllBtn");

function applyTheme(theme) {
    if (theme === "dark") {
        permissionsBody.classList.add("dark-mode");
        permissionsBody.classList.remove("light-mode");
        if (permissionsThemeToggle) permissionsThemeToggle.checked = true;
    } else {
        permissionsBody.classList.add("light-mode");
        permissionsBody.classList.remove("dark-mode");
        if (permissionsThemeToggle) permissionsThemeToggle.checked = false;
    }
}

function refreshPermissionCards() {
    let checkedCount = 0;

    permissionOptions.forEach((option) => {
        const checkbox = option.querySelector('input[type="checkbox"]');
        const stateLabel = option.querySelector(".permission-state");
        const isChecked = checkbox ? checkbox.checked : false;

        option.classList.toggle("checked", isChecked);
        if (stateLabel) {
            stateLabel.textContent = isChecked ? "مفعّل" : "غير مفعّل";
        }

        if (isChecked) {
            checkedCount += 1;
        }
    });

    if (enabledCount) {
        enabledCount.textContent = checkedCount;
    }
}

window.addEventListener("load", () => {
    const savedTheme = localStorage.getItem("permissions-theme");
    applyTheme(savedTheme === "dark" ? "dark" : "light");
    refreshPermissionCards();
});

if (permissionsThemeToggle) {
    permissionsThemeToggle.addEventListener("change", () => {
        const nextTheme = permissionsThemeToggle.checked ? "dark" : "light";
        applyTheme(nextTheme);
        localStorage.setItem("permissions-theme", nextTheme);
    });
}

permissionCheckboxes.forEach((checkbox) => {
    checkbox.addEventListener("change", refreshPermissionCards);
});

if (selectAllBtn) {
    selectAllBtn.addEventListener("click", () => {
        permissionCheckboxes.forEach((checkbox) => {
            checkbox.checked = true;
        });
        refreshPermissionCards();
    });
}

if (clearAllBtn) {
    clearAllBtn.addEventListener("click", () => {
        permissionCheckboxes.forEach((checkbox) => {
            checkbox.checked = false;
        });
        refreshPermissionCards();
    });
}

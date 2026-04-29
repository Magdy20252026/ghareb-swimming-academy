const clearBtn = document.getElementById("clearBtn");
const themeToggleUsers = document.getElementById("themeToggle");
const bodyUsers = document.body;

function clearFields() {
    const idField = document.getElementById("id");
    const usernameField = document.getElementById("username");
    const passwordField = document.getElementById("password");
    const roleField = document.getElementById("role");

    if (idField) idField.value = "";
    if (usernameField) usernameField.value = "";
    if (passwordField) passwordField.value = "";
    if (roleField) roleField.value = "";
}

if (clearBtn) {
    clearBtn.addEventListener("click", function () {
        clearFields();
        window.history.replaceState({}, document.title, "users.php");
    });
}

window.addEventListener("load", function () {
    const messageBox = document.querySelector(".message-box");
    const savedTheme = localStorage.getItem("users-theme");

    if (savedTheme === "dark") {
        bodyUsers.classList.add("dark-mode");
        bodyUsers.classList.remove("light-mode");
        if (themeToggleUsers) themeToggleUsers.checked = true;
    } else {
        bodyUsers.classList.add("light-mode");
        bodyUsers.classList.remove("dark-mode");
        if (themeToggleUsers) themeToggleUsers.checked = false;
    }

    if (messageBox && messageBox.classList.contains("success")) {
        clearFields();
        window.history.replaceState({}, document.title, "users.php");
    }
});

if (themeToggleUsers) {
    themeToggleUsers.addEventListener("change", () => {
        if (themeToggleUsers.checked) {
            bodyUsers.classList.add("dark-mode");
            bodyUsers.classList.remove("light-mode");
            localStorage.setItem("users-theme", "dark");
        } else {
            bodyUsers.classList.add("light-mode");
            bodyUsers.classList.remove("dark-mode");
            localStorage.setItem("users-theme", "light");
        }
    });
}
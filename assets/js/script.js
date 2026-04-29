const themeToggle = document.getElementById("themeToggle");
const body = document.body;

window.addEventListener("load", () => {
    const savedTheme = localStorage.getItem("login-theme");

    if (savedTheme === "dark") {
        body.classList.add("dark-mode");
        body.classList.remove("light-mode");
        if (themeToggle) themeToggle.checked = true;
    } else {
        body.classList.add("light-mode");
        body.classList.remove("dark-mode");
        if (themeToggle) themeToggle.checked = false;
    }
});

if (themeToggle) {
    themeToggle.addEventListener("change", () => {
        if (themeToggle.checked) {
            body.classList.add("dark-mode");
            body.classList.remove("light-mode");
            localStorage.setItem("login-theme", "dark");
        } else {
            body.classList.add("light-mode");
            body.classList.remove("dark-mode");
            localStorage.setItem("login-theme", "light");
        }
    });
}
const expensesBody = document.body;
const expensesThemeToggle = document.getElementById("themeToggle");

function setExpensesTheme(theme) {
    if (theme === "dark") {
        expensesBody.classList.add("dark-mode");
        expensesBody.classList.remove("light-mode");
        if (expensesThemeToggle) {
            expensesThemeToggle.checked = true;
        }
        return;
    }

    expensesBody.classList.add("light-mode");
    expensesBody.classList.remove("dark-mode");
    if (expensesThemeToggle) {
        expensesThemeToggle.checked = false;
    }
}

window.addEventListener("load", () => {
    const savedTheme = localStorage.getItem("expenses-theme");
    setExpensesTheme(savedTheme === "dark" ? "dark" : "light");
});

if (expensesThemeToggle) {
    expensesThemeToggle.addEventListener("change", () => {
        const nextTheme = expensesThemeToggle.checked ? "dark" : "light";
        setExpensesTheme(nextTheme);
        localStorage.setItem("expenses-theme", nextTheme);
    });
}

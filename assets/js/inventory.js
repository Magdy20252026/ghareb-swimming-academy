const inventoryBody = document.body;
const inventoryThemeToggle = document.getElementById("themeToggle");
const inventoryClearBtn = document.getElementById("clearBtn");
const inventoryIdField = document.getElementById("id");
const inventoryNameField = document.getElementById("item_name");
const inventoryQuantityField = document.getElementById("quantity");
const inventoryPurchasePriceField = document.getElementById("purchase_price");
const inventorySalePriceField = document.getElementById("sale_price");
const inventoryQuantityGroup = document.getElementById("quantityGroup");
const inventoryTrackQuantityOptions = document.querySelectorAll('input[name="track_quantity"]');
const inventoryOptionCards = document.querySelectorAll(".option-card");
const inventoryDeleteForms = document.querySelectorAll(".delete-item-form");

function setInventoryTheme(theme) {
    if (theme === "dark") {
        inventoryBody.classList.add("dark-mode");
        inventoryBody.classList.remove("light-mode");
        if (inventoryThemeToggle) {
            inventoryThemeToggle.checked = true;
        }
    } else {
        inventoryBody.classList.add("light-mode");
        inventoryBody.classList.remove("dark-mode");
        if (inventoryThemeToggle) {
            inventoryThemeToggle.checked = false;
        }
    }
}

function updateInventoryOptionCards() {
    inventoryOptionCards.forEach((card) => {
        const radio = card.querySelector('input[name="track_quantity"]');
        card.classList.toggle("active", !!radio && radio.checked);
    });
}

function updateQuantityVisibility() {
    const selectedOption = document.querySelector('input[name="track_quantity"]:checked');
    const shouldTrackQuantity = !selectedOption || selectedOption.value === "1";

    if (!inventoryQuantityGroup || !inventoryQuantityField) {
        updateInventoryOptionCards();
        return;
    }

    inventoryQuantityGroup.classList.toggle("is-hidden", !shouldTrackQuantity);
    inventoryQuantityField.required = shouldTrackQuantity;

    if (!shouldTrackQuantity) {
        inventoryQuantityField.value = "";
    }

    updateInventoryOptionCards();
}

function clearInventoryForm(shouldResetUrl = true) {
    if (inventoryIdField) {
        inventoryIdField.value = "";
    }

    if (inventoryNameField) {
        inventoryNameField.value = "";
    }

    if (inventoryQuantityField) {
        inventoryQuantityField.value = "";
    }

    if (inventoryPurchasePriceField) {
        inventoryPurchasePriceField.value = "";
    }

    if (inventorySalePriceField) {
        inventorySalePriceField.value = "";
    }

    const defaultTrackQuantity = document.querySelector('input[name="track_quantity"][value="1"]');
    if (defaultTrackQuantity) {
        defaultTrackQuantity.checked = true;
    }

    updateQuantityVisibility();

    if (shouldResetUrl) {
        window.history.replaceState({}, document.title, "inventory.php");
    }
}

window.addEventListener("load", () => {
    const savedTheme = localStorage.getItem("inventory-theme");
    const messageBox = document.querySelector(".message-box.success");

    setInventoryTheme(savedTheme === "dark" ? "dark" : "light");
    updateQuantityVisibility();

    if (messageBox && window.location.search.includes("edit=")) {
        window.history.replaceState({}, document.title, "inventory.php");
    }
});

if (inventoryThemeToggle) {
    inventoryThemeToggle.addEventListener("change", () => {
        const nextTheme = inventoryThemeToggle.checked ? "dark" : "light";
        setInventoryTheme(nextTheme);
        localStorage.setItem("inventory-theme", nextTheme);
    });
}

inventoryTrackQuantityOptions.forEach((option) => {
    option.addEventListener("change", updateQuantityVisibility);
});

if (inventoryClearBtn) {
    inventoryClearBtn.addEventListener("click", clearInventoryForm);
}

inventoryDeleteForms.forEach((form) => {
    form.addEventListener("submit", (event) => {
        if (!window.confirm("هل أنت متأكد من حذف هذا الصنف؟")) {
            event.preventDefault();
        }
    });
});

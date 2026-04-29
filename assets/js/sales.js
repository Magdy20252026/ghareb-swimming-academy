const salesBody = document.body;
const salesThemeToggle = document.getElementById("themeToggle");
const invoiceTypeCards = document.querySelectorAll(".invoice-type-card");
const invoiceTypeInputs = document.querySelectorAll('input[name="invoice_type"]');
const addRowBtn = document.getElementById("addRowBtn");
const invoiceRowsContainer = document.getElementById("invoiceRows");
const invoiceRowTemplate = document.getElementById("invoiceRowTemplate");
const invoiceItemsCount = document.getElementById("invoiceItemsCount");
const invoiceGrandTotal = document.getElementById("invoiceGrandTotal");
const invoicePaidPreview = document.getElementById("invoicePaidPreview");
const invoiceRemainingPreview = document.getElementById("invoiceRemainingPreview");
const paidAmountInput = document.getElementById("paidAmountInput");
const guardianFields = document.getElementById("guardianFields");
const guardianNameInput = document.getElementById("guardianNameInput");
const guardianPhoneInput = document.getElementById("guardianPhoneInput");
const paymentHint = document.getElementById("paymentHint");
const paymentSection = document.getElementById("paymentSection");
const saveInvoiceBtn = document.getElementById("saveInvoiceBtn");
const salesItems = window.salesInventoryData || {};
const DECIMAL_TOLERANCE = 0.01;

function formatSalesMoney(value) {
    return `${Number(value || 0).toFixed(2)} ج.م`;
}

function parseDecimalInput(value) {
    const normalizedValue = String(value || "").trim().replace(/,/g, ".");
    if (normalizedValue === "") {
        return null;
    }

    const parsedValue = Number(normalizedValue);
    return Number.isFinite(parsedValue) ? parsedValue : Number.NaN;
}

function setSalesTheme(theme) {
    if (theme === "dark") {
        salesBody.classList.add("dark-mode");
        salesBody.classList.remove("light-mode");
        if (salesThemeToggle) {
            salesThemeToggle.checked = true;
        }
    } else {
        salesBody.classList.add("light-mode");
        salesBody.classList.remove("dark-mode");
        if (salesThemeToggle) {
            salesThemeToggle.checked = false;
        }
    }
}

function getSelectedInvoiceType() {
    const selected = document.querySelector('input[name="invoice_type"]:checked');
    return selected ? selected.value : "sale";
}

function updateInvoiceTypeCards() {
    const selectedType = getSelectedInvoiceType();
    invoiceTypeCards.forEach((card) => {
        card.classList.toggle("active", card.dataset.type === selectedType);
    });

    if (saveInvoiceBtn) {
        saveInvoiceBtn.textContent = selectedType === "return" ? "حفظ فاتورة المرتجع" : "حفظ فاتورة البيع";
    }
}

function createStockState(item, quantity, invoiceType) {
    if (!item) {
        return {
            text: "اختر الصنف",
            className: "",
            hasError: false,
        };
    }

    if (Number(item.track_quantity) !== 1) {
        return {
            text: "يباع بدون حد مخزون",
            className: "static-item",
            hasError: false,
        };
    }

    const availableQuantity = Number(item.quantity || 0);

    if (invoiceType === "return") {
        return {
            text: `سيعود للمخزون ${quantity || 0} قطعة`,
            className: "available",
            hasError: false,
        };
    }

    if (!quantity) {
        return {
            text: `المتاح ${availableQuantity} قطعة`,
            className: availableQuantity > 0 ? "available" : "unavailable",
            hasError: availableQuantity <= 0,
        };
    }

    if (quantity > availableQuantity) {
        return {
            text: `المتاح ${availableQuantity} قطعة فقط`,
            className: "unavailable",
            hasError: true,
        };
    }

    if (availableQuantity - quantity <= 2) {
        return {
            text: `المتبقي بعد البيع ${availableQuantity - quantity} قطعة`,
            className: "low",
            hasError: false,
        };
    }

    return {
        text: `المتبقي بعد البيع ${availableQuantity - quantity} قطعة`,
        className: "available",
        hasError: false,
    };
}

function ensureRowSalePrice(row) {
    const itemSelect = row.querySelector(".item-select");
    const salePriceInput = row.querySelector(".sale-price-input");
    const item = salesItems[itemSelect.value] || null;

    if (!item || !salePriceInput) {
        return;
    }

    if (salePriceInput.dataset.manual !== "1") {
        salePriceInput.value = Number(item.sale_price || 0).toFixed(2);
    }
}

function updateInvoiceRow(row) {
    const itemSelect = row.querySelector(".item-select");
    const quantityInput = row.querySelector(".quantity-input");
    const salePriceInput = row.querySelector(".sale-price-input");
    const lineTotalValue = row.querySelector(".line-total-value");
    const stockState = row.querySelector(".stock-state");
    const item = salesItems[itemSelect.value] || null;
    const quantityRaw = quantityInput.value.trim();
    const parsedQuantity = parseInt(quantityRaw, 10);
    const quantity = Number.isInteger(parsedQuantity) && parsedQuantity > 0 ? parsedQuantity : 0;
    const invoiceType = getSelectedInvoiceType();

    ensureRowSalePrice(row);

    const salePriceRaw = salePriceInput.value.trim();
    const parsedSalePrice = parseDecimalInput(salePriceRaw);
    const salePrice = item && Number.isFinite(parsedSalePrice) && parsedSalePrice >= 0 ? parsedSalePrice : 0;
    const lineTotal = salePrice * quantity;
    const hasInvalidQuantity = quantityRaw !== "" && (!Number.isInteger(parsedQuantity) || parsedQuantity <= 0);
    const hasInvalidPrice = item && (salePriceRaw === "" || !Number.isFinite(parsedSalePrice) || parsedSalePrice < 0);
    const stockInfo = hasInvalidQuantity
        ? {
            text: "أدخل عددًا صحيحًا أكبر من صفر",
            className: "unavailable",
            hasError: true,
        }
        : hasInvalidPrice
            ? {
                text: "أدخل سعر بيع صحيحًا",
                className: "unavailable",
                hasError: true,
            }
            : createStockState(item, quantity, invoiceType);

    row.dataset.lineTotal = String(lineTotal);
    row.dataset.hasSelectedItem = item ? "1" : "0";
    row.classList.toggle("row-error", stockInfo.hasError);
    salePriceInput.classList.toggle("input-error", hasInvalidPrice);
    salePriceInput.classList.toggle(
        "manual-price",
        item && Number.isFinite(parsedSalePrice) && Math.abs(Number(item.sale_price || 0) - parsedSalePrice) >= DECIMAL_TOLERANCE
    );

    lineTotalValue.textContent = formatSalesMoney(lineTotal);
    stockState.textContent = stockInfo.text;
    stockState.className = `stock-state ${stockInfo.className}`.trim();
}

function syncPaidAmount(grandTotal) {
    if (!paidAmountInput) {
        return;
    }

    if (getSelectedInvoiceType() !== "sale") {
        paidAmountInput.value = grandTotal > 0 ? grandTotal.toFixed(2) : "";
        paidAmountInput.dataset.manual = "0";
        paidAmountInput.disabled = true;
        return;
    }

    paidAmountInput.disabled = false;

    if (paidAmountInput.dataset.manual !== "1") {
        paidAmountInput.value = grandTotal > 0 ? grandTotal.toFixed(2) : "";
    } else {
        const paidValue = parseDecimalInput(paidAmountInput.value);
        if (Number.isFinite(paidValue) && paidValue > grandTotal) {
            paidAmountInput.value = grandTotal > 0 ? grandTotal.toFixed(2) : "";
        }
    }
}

function updatePaymentSection(grandTotal, selectedItems, hasRowsError) {
    if (!paymentSection || !paidAmountInput || !invoicePaidPreview || !invoiceRemainingPreview) {
        return hasRowsError;
    }

    const invoiceType = getSelectedInvoiceType();
    syncPaidAmount(grandTotal);

    if (invoiceType !== "sale") {
        invoicePaidPreview.textContent = formatSalesMoney(grandTotal);
        invoiceRemainingPreview.textContent = formatSalesMoney(0);
        paymentSection.classList.add("return-mode");
        guardianFields.classList.add("is-hidden");
        guardianNameInput.required = false;
        guardianPhoneInput.required = false;
        guardianNameInput.value = "";
        guardianPhoneInput.value = "";
        paidAmountInput.classList.remove("input-error");
        paymentHint.textContent = "في فاتورة المرتجع يتم اعتبار الفاتورة منتهية مباشرة بدون رصيد متبقٍ.";
        return hasRowsError;
    }

    paymentSection.classList.remove("return-mode");

    const paidValue = parseDecimalInput(paidAmountInput.value);
    const paidAmount = paidValue === null ? grandTotal : paidValue;
    const paymentError = (
        selectedItems > 0
        && (
            !Number.isFinite(paidAmount)
            || paidAmount < 0
            || paidAmount > grandTotal + DECIMAL_TOLERANCE
        )
    );
    const remainingAmount = paymentError ? 0 : Math.max(grandTotal - Math.max(paidAmount, 0), 0);
    const requiresGuardian = selectedItems > 0 && grandTotal > 0 && remainingAmount > DECIMAL_TOLERANCE;
    const guardianMissing = requiresGuardian
        && ((guardianNameInput.value || "").trim() === "" || (guardianPhoneInput.value || "").trim() === "");

    paidAmountInput.classList.toggle("input-error", paymentError);
    invoicePaidPreview.textContent = formatSalesMoney(paymentError ? 0 : Math.max(paidAmount, 0));
    invoiceRemainingPreview.textContent = formatSalesMoney(remainingAmount);
    guardianFields.classList.toggle("is-hidden", !requiresGuardian);
    guardianNameInput.required = requiresGuardian;
    guardianPhoneInput.required = requiresGuardian;

    if (paymentError) {
        paymentHint.textContent = "المبلغ المدفوع يجب أن يكون صفرًا أو أكثر وألا يتجاوز إجمالي الفاتورة.";
    } else if (requiresGuardian) {
        paymentHint.textContent = "يوجد مبلغ متبقٍ، لذا يجب تسجيل اسم ولي الأمر ورقم الهاتف قبل الحفظ.";
    } else {
        paymentHint.textContent = "سيتم اعتبار الفاتورة مسددة بالكامل عند تطابق المبلغ المدفوع مع الإجمالي.";
    }

    return hasRowsError || paymentError || guardianMissing;
}

function updateInvoiceTotals() {
    const rows = Array.from(document.querySelectorAll(".invoice-row"));
    let grandTotal = 0;
    let selectedItems = 0;
    let hasRowsError = false;

    rows.forEach((row) => {
        grandTotal += Number(row.dataset.lineTotal || 0);
        if (row.dataset.hasSelectedItem === "1") {
            selectedItems += 1;
        }
        if (row.classList.contains("row-error")) {
            hasRowsError = true;
        }
    });

    if (invoiceItemsCount) {
        invoiceItemsCount.textContent = String(selectedItems);
    }

    if (invoiceGrandTotal) {
        invoiceGrandTotal.textContent = formatSalesMoney(grandTotal);
    }

    const hasFormError = updatePaymentSection(grandTotal, selectedItems, hasRowsError);

    if (saveInvoiceBtn) {
        saveInvoiceBtn.disabled = selectedItems === 0 || hasFormError;
    }
}

function refreshInvoiceUI() {
    document.querySelectorAll(".invoice-row").forEach(updateInvoiceRow);
    updateInvoiceTypeCards();
    updateInvoiceTotals();
}

function bindInvoiceRow(row) {
    const itemSelect = row.querySelector(".item-select");
    const quantityInput = row.querySelector(".quantity-input");
    const salePriceInput = row.querySelector(".sale-price-input");
    const removeButton = row.querySelector(".remove-row-btn");

    if (itemSelect) {
        itemSelect.addEventListener("change", () => {
            if (salePriceInput) {
                salePriceInput.dataset.manual = "0";
                if (itemSelect.value === "") {
                    salePriceInput.value = "";
                }
            }
            refreshInvoiceUI();
        });
    }

    if (quantityInput) {
        quantityInput.addEventListener("input", refreshInvoiceUI);
    }

    if (salePriceInput) {
        salePriceInput.dataset.manual = salePriceInput.value.trim() === "" ? "0" : "1";
        salePriceInput.addEventListener("input", () => {
            salePriceInput.dataset.manual = salePriceInput.value.trim() === "" ? "0" : "1";
            refreshInvoiceUI();
        });
    }

    if (removeButton) {
        removeButton.addEventListener("click", () => {
            const rows = document.querySelectorAll(".invoice-row");
            if (rows.length === 1) {
                itemSelect.value = "";
                quantityInput.value = "";
                salePriceInput.value = "";
                salePriceInput.dataset.manual = "0";
                refreshInvoiceUI();
                return;
            }

            row.remove();
            refreshInvoiceUI();
        });
    }

    updateInvoiceRow(row);
}

function appendInvoiceRow() {
    if (!invoiceRowsContainer || !invoiceRowTemplate) {
        return;
    }

    const rowFragment = invoiceRowTemplate.content.cloneNode(true);
    invoiceRowsContainer.appendChild(rowFragment);
    const newRow = invoiceRowsContainer.querySelector(".invoice-row:last-child");
    if (newRow) {
        bindInvoiceRow(newRow);
    }
    refreshInvoiceUI();
}

window.addEventListener("load", () => {
    const savedTheme = localStorage.getItem("sales-theme");
    setSalesTheme(savedTheme === "dark" ? "dark" : "light");
    document.querySelectorAll(".invoice-row").forEach(bindInvoiceRow);
    if (paidAmountInput) {
        paidAmountInput.dataset.manual = paidAmountInput.value.trim() === "" ? "0" : "1";
    }
    refreshInvoiceUI();
});

if (salesThemeToggle) {
    salesThemeToggle.addEventListener("change", () => {
        const nextTheme = salesThemeToggle.checked ? "dark" : "light";
        setSalesTheme(nextTheme);
        localStorage.setItem("sales-theme", nextTheme);
    });
}

invoiceTypeInputs.forEach((input) => {
    input.addEventListener("change", () => {
        if (paidAmountInput) {
            paidAmountInput.dataset.manual = "0";
        }
        refreshInvoiceUI();
    });
});

if (paidAmountInput) {
    paidAmountInput.addEventListener("input", () => {
        paidAmountInput.dataset.manual = paidAmountInput.value.trim() === "" ? "0" : "1";
        refreshInvoiceUI();
    });
}

[guardianNameInput, guardianPhoneInput].forEach((input) => {
    if (input) {
        input.addEventListener("input", updateInvoiceTotals);
    }
});

if (addRowBtn) {
    addRowBtn.addEventListener("click", appendInvoiceRow);
}

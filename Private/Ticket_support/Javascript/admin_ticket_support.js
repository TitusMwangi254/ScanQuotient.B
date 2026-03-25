// Theme Toggle (Matches admin_customer_feedback.php)
const sqThemeToggle = document.getElementById("sqThemeToggle");
const sqBody = document.body;

function sqSetTheme(theme) {
  sqBody.classList.toggle("sq-dark", theme === "dark");
  sqThemeToggle.innerHTML =
    theme === "dark"
      ? '<i class="fas fa-moon"></i>'
      : '<i class="fas fa-sun"></i>';
}

sqThemeToggle.addEventListener("click", () => {
  const current = sqBody.classList.contains("sq-dark") ? "light" : "dark";
  sqSetTheme(current);
  localStorage.setItem("sq-admin-theme", current);
});

sqSetTheme(localStorage.getItem("sq-admin-theme") || "light");

// Help Modal
const helpBtn = document.getElementById("helpBtn");
const helpModal = document.getElementById("helpModal");
const closeBtn = helpModal.querySelector(".close");

helpBtn.addEventListener("click", (e) => {
  e.preventDefault();
  helpModal.style.display = "flex";
});

closeBtn.addEventListener("click", () => {
  helpModal.style.display = "none";
});

window.addEventListener("click", (e) => {
  if (e.target === helpModal) {
    helpModal.style.display = "none";
  }
});

// ============================================
// FULL ROW CLICK FUNCTIONALITY - NEW
// ============================================

/**
 * Initialize clickable rows using event delegation
 * Best practice: single listener on table, not individual rows [^38^][^41^][^49^]
 */
function initClickableRows() {
  const table = document.getElementById("tickets-table");

  // Use event delegation on the table body [^38^][^44^]
  table.addEventListener("click", function (event) {
    if (event.target.closest(".ticket-action-btn")) return;
    // Find the closest row that was clicked
    const row = event.target.closest("tbody tr.clickable-row");

    // Exit if no row found or if clicking on empty state
    if (!row || row.querySelector(".sq-empty-state")) return;

    // Get the URL from data-href attribute [^41^]
    const href = row.dataset.href;

    if (href) {
      // Visual feedback before navigation
      row.style.background = "rgba(59, 130, 246, 0.15)";

      // Navigate to ticket details
      window.location.href = href;
    }
  });

  // Add middle-click support (open in new tab)
  table.addEventListener("mousedown", function (event) {
    // Middle mouse button (button 2) clicked
    if (event.button !== 1) return;

    const row = event.target.closest("tbody tr.clickable-row");
    if (!row) return;

    const href = row.dataset.href;
    if (href) {
      event.preventDefault();
      window.open(href, "_blank");
    }
  });

  // Prevent text selection on double-click for better UX
  table.addEventListener("selectstart", function (event) {
    if (event.target.closest("tbody tr.clickable-row")) {
      // Allow selection only if user is trying to select text deliberately
      // This is a subtle UX improvement
    }
  });
}

// ============================================
// Pagination and Filtering Logic
// ============================================
let currentPage = 1;
let rowsPerPage = 10;
let filteredRows = [];

function filterTable() {
  const searchInput = document.getElementById("search").value.toLowerCase();
  const statusSelect = document.getElementById("statusFilter").value;

  const allRows = Array.from(
    document.querySelectorAll("#tickets-table tbody tr"),
  ).filter((tr) => !tr.querySelector(".sq-empty-state"));

  const sqMatchedHeader = document.getElementById("sqMatchedInHeader");
  const showMatchedIn = searchInput.length > 0;
  if (sqMatchedHeader) {
    sqMatchedHeader.style.display = showMatchedIn ? "" : "none";
  }
  document.querySelectorAll(".sq-matched-in-cell").forEach((td) => {
    td.style.display = showMatchedIn ? "table-cell" : "none";
  });

  filteredRows = allRows.filter((tr) => {
    const rowStatus = tr.dataset.status;
    const matchesStatus = statusSelect === "all" || rowStatus === statusSelect;
    const uniqueIdText = (tr.children[2]?.innerText || "").toLowerCase();
    const emailText = (tr.children[3]?.innerText || "").toLowerCase();
    const categoryText = (tr.children[4]?.innerText || "").toLowerCase();
    const priorityText = (tr.children[5]?.innerText || "").toLowerCase();
    const statusText = (tr.children[6]?.innerText || "").toLowerCase();

    const matchesSearch = [uniqueIdText, emailText, categoryText, priorityText, statusText].some(
      (t) => t.includes(searchInput),
    );

    // Populate matched-in column (shown only when search is non-empty).
    const matchedCell = tr.querySelector(".sq-matched-in-cell");
    if (matchedCell) {
      if (!showMatchedIn) {
        matchedCell.textContent = "";
      } else {
        const matchedCols = [];
        if (uniqueIdText.includes(searchInput)) matchedCols.push("unique_id");
        if (emailText.includes(searchInput)) matchedCols.push("email");
        if (categoryText.includes(searchInput)) matchedCols.push("category");
        if (priorityText.includes(searchInput)) matchedCols.push("priority");
        if (statusText.includes(searchInput)) matchedCols.push("status");

        matchedCell.textContent = matchedCols.length ? matchedCols.join(", ") : "-";
      }
    }

    return matchesStatus && matchesSearch;
  });

  // Update row numbers
  filteredRows.forEach((tr, i) => {
    const rowNumCell = tr.querySelector(".row-number");
    if (rowNumCell) {
      rowNumCell.textContent = i + 1;
    }
  });

  // Handle empty state
  const tbody = document.querySelector("#tickets-table tbody");
  const existingEmpty = tbody.querySelector(".sq-empty-row");

  if (filteredRows.length === 0) {
    if (!existingEmpty) {
      const columnCount = document.querySelectorAll("#tickets-table thead th").length || 9;
      const tr = document.createElement("tr");
      tr.className = "sq-empty-row";
      tr.innerHTML = `
                <td colspan="${columnCount}" class="sq-empty-state">
                    <div class="sq-empty-icon">
                        <i class="fas fa-search"></i>
                    </div>
                    <h3 class="sq-empty-title">No tickets found</h3>
                    <p class="sq-empty-text">Try adjusting your search or filter criteria.</p>
                </td>
            `;
      tbody.appendChild(tr);
    }
  } else if (existingEmpty) {
    existingEmpty.remove();
  }

  currentPage = 1;
  renderPagination();
}

function renderPagination() {
  // Hide all rows first
  document.querySelectorAll("#tickets-table tbody tr").forEach((tr) => {
    if (
      !tr.querySelector(".sq-empty-state") &&
      !tr.classList.contains("sq-empty-row")
    ) {
      tr.classList.add("hidden");
    }
  });

  if (filteredRows.length === 0) {
    document.getElementById("recordInfo").textContent = "Showing 0–0 of 0";
    document.getElementById("prevPage").disabled = true;
    document.getElementById("nextPage").disabled = true;
    return;
  }

  const totalRows = filteredRows.length;
  const perPage = rowsPerPage === "all" ? totalRows : parseInt(rowsPerPage);
  const totalPages = Math.ceil(totalRows / perPage);

  let start = (currentPage - 1) * perPage;
  let end = rowsPerPage === "all" ? totalRows : start + perPage;

  filteredRows.slice(start, end).forEach((tr) => tr.classList.remove("hidden"));

  document.getElementById("recordInfo").textContent =
    `Showing ${start + 1}–${Math.min(end, totalRows)} of ${totalRows}`;

  document.getElementById("prevPage").disabled = currentPage === 1;
  document.getElementById("nextPage").disabled =
    currentPage >= totalPages || totalPages === 0;
}

function clearFilters() {
  document.getElementById("search").value = "";
  const view = document.getElementById("viewFilter")?.value || "active";
  document.getElementById("statusFilter").value = view === "active" ? "open" : "all";
  filterTable();
}

function changeTicketView(view) {
  const url = new URL(window.location.href);
  url.searchParams.set("view", view);
  window.location.href = url.toString();
}

let sqFallbackResolver = null;

function getFallbackModalEls() {
  return {
    root: document.getElementById("sqFallbackModal"),
    icon: document.getElementById("sqFallbackModalIcon"),
    title: document.getElementById("sqFallbackModalTitle"),
    text: document.getElementById("sqFallbackModalText"),
    actions: document.getElementById("sqFallbackModalActions"),
    cancel: document.getElementById("sqFallbackModalCancel"),
    confirm: document.getElementById("sqFallbackModalConfirm"),
  };
}

function closeFallbackModal(result) {
  const els = getFallbackModalEls();
  if (els.root) els.root.style.display = "none";
  const resolver = sqFallbackResolver;
  sqFallbackResolver = null;
  if (resolver) resolver(result);
}

async function modalConfirm(options) {
  if (window.Swal && typeof window.Swal.fire === "function") {
    return window.Swal.fire(options);
  }
  const els = getFallbackModalEls();
  if (!els.root) return { isConfirmed: false };
  if (els.icon) els.icon.innerHTML = '<i class="fas fa-exclamation-triangle"></i>';
  if (els.title) els.title.textContent = options?.title || "Confirm action";
  if (els.text) els.text.textContent = options?.text || "Are you sure you want to continue?";
  if (els.cancel) {
    els.cancel.style.display = "";
    els.cancel.textContent = "Cancel";
    els.cancel.onclick = () => closeFallbackModal({ isConfirmed: false });
  }
  if (els.confirm) {
    els.confirm.className = "sq-btn sq-btn-primary";
    els.confirm.textContent = options?.confirmButtonText || "Confirm";
    // Apply custom confirm color when provided (e.g. red for destructive actions)
    if (options?.confirmButtonColor) {
      els.confirm.style.background = options.confirmButtonColor;
      els.confirm.style.borderColor = options.confirmButtonColor;
      els.confirm.style.color = "#fff";
    } else {
      els.confirm.style.background = "";
      els.confirm.style.borderColor = "";
      els.confirm.style.color = "";
    }
    els.confirm.onclick = () => closeFallbackModal({ isConfirmed: true });
  }
  els.root.style.display = "flex";
  return new Promise((resolve) => {
    sqFallbackResolver = resolve;
  });
}

async function modalNotice(options) {
  if (window.Swal && typeof window.Swal.fire === "function") {
    return window.Swal.fire(options);
  }
  const els = getFallbackModalEls();
  if (!els.root) return { isConfirmed: true };
  const isError = (options?.icon || "").toLowerCase() === "error";
  if (els.icon) {
    els.icon.innerHTML = isError
      ? '<i class="fas fa-times-circle"></i>'
      : '<i class="fas fa-check-circle"></i>';
  }
  if (els.title) els.title.textContent = options?.title || (isError ? "Error" : "Done");
  if (els.text) els.text.textContent = options?.text || "";
  if (els.cancel) {
    els.cancel.style.display = "none";
    els.cancel.onclick = null;
  }
  if (els.confirm) {
    els.confirm.className = "sq-btn sq-btn-primary";
    els.confirm.textContent = "OK";
    els.confirm.style.background = "";
    els.confirm.style.borderColor = "";
    els.confirm.style.color = "";
    els.confirm.onclick = () => closeFallbackModal({ isConfirmed: true });
  }
  els.root.style.display = "flex";
  return new Promise((resolve) => {
    sqFallbackResolver = resolve;
  });
}

async function restoreTicket(uniqueId, btnEl) {
  const result = await modalConfirm({
    title: "Restore ticket?",
    text: "This ticket will be moved back to active tickets.",
    icon: "question",
    showCancelButton: true,
    confirmButtonColor: "#10b981",
    cancelButtonColor: "#3b82f6",
    confirmButtonText: "Yes, restore",
  });

  if (!result.isConfirmed) return;

  const originalHtml = btnEl ? btnEl.innerHTML : "";
  if (btnEl) {
    btnEl.disabled = true;
    btnEl.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Restoring...';
  }

  try {
    const res = await fetch("../../PHP/Backend/ticket_actions.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ action: "restore", unique_id: uniqueId }),
    }).then((r) => r.json());

    if (res.status === "ok") {
      await modalNotice({
        title: "Restored",
        text: res.message || "Ticket restored successfully.",
        icon: "success",
        timer: 1400,
        showConfirmButton: false,
      });

      const row = btnEl?.closest("tr");
      if (row) row.remove();
      filterTable();
      return;
    }

    modalNotice({
      title: "Error",
      text: res.message || "Failed to restore ticket.",
      icon: "error",
    });
  } catch (e) {
    modalNotice({
      title: "Error",
      text: "Request failed. Please try again.",
      icon: "error",
    });
  } finally {
    if (btnEl) {
      btnEl.disabled = false;
      btnEl.innerHTML = originalHtml;
    }
  }
}

async function permanentDeleteTicket(uniqueId, btnEl) {
  const result = await modalConfirm({
    title: "Delete permanently?",
    text: "This will remove the ticket forever and cannot be undone.",
    icon: "warning",
    showCancelButton: true,
    confirmButtonColor: "#ef4444",
    cancelButtonColor: "#3b82f6",
    confirmButtonText: "Yes, delete forever",
  });

  if (!result.isConfirmed) return;

  const originalHtml = btnEl ? btnEl.innerHTML : "";
  if (btnEl) {
    btnEl.disabled = true;
    btnEl.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Deleting...';
  }

  try {
    const res = await fetch("../../PHP/Backend/ticket_actions.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ action: "permanent_delete", unique_id: uniqueId }),
    }).then((r) => r.json());

    if (res.status === "ok") {
      await modalNotice({
        title: "Deleted",
        text: res.message || "Ticket permanently deleted.",
        icon: "success",
        timer: 1400,
        showConfirmButton: false,
      });

      const row = btnEl?.closest("tr");
      if (row) row.remove();
      filterTable();
      return;
    }

    modalNotice({
      title: "Error",
      text: res.message || "Failed to delete ticket permanently.",
      icon: "error",
    });
  } catch (e) {
    modalNotice({
      title: "Error",
      text: "Request failed. Please try again.",
      icon: "error",
    });
  } finally {
    if (btnEl) {
      btnEl.disabled = false;
      btnEl.innerHTML = originalHtml;
    }
  }
}

// Ensure inline onclick handlers can always resolve these functions.
window.restoreTicket = restoreTicket;
window.permanentDeleteTicket = permanentDeleteTicket;
window.changeTicketView = changeTicketView;

document.addEventListener("keydown", (e) => {
  if (e.key === "Escape" && sqFallbackResolver) {
    closeFallbackModal({ isConfirmed: false });
  }
});

// Event Listeners
document.getElementById("search").addEventListener("keyup", filterTable);
document.getElementById("statusFilter").addEventListener("change", filterTable);

document.getElementById("rowsPerPage").addEventListener("change", function () {
  rowsPerPage = this.value;
  currentPage = 1;
  renderPagination();
});

document.getElementById("prevPage").addEventListener("click", function () {
  if (currentPage > 1) {
    currentPage--;
    renderPagination();
  }
});

document.getElementById("nextPage").addEventListener("click", function () {
  let perPage =
    rowsPerPage === "all" ? filteredRows.length : parseInt(rowsPerPage);
  let totalPages = Math.ceil(filteredRows.length / perPage);
  if (currentPage < totalPages) {
    currentPage++;
    renderPagination();
  }
});

// Initialize
window.onload = () => {
  filterTable();
  initClickableRows(); // Initialize row click handlers
};

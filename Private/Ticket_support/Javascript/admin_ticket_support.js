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
let rowsPerPage = 25;
let filteredRows = [];

function filterTable() {
  const searchInput = document.getElementById("search").value.toLowerCase();
  const statusSelect = document.getElementById("statusFilter").value;

  const allRows = Array.from(
    document.querySelectorAll("#tickets-table tbody tr"),
  ).filter((tr) => !tr.querySelector(".sq-empty-state"));

  filteredRows = allRows.filter((tr) => {
    const rowStatus = tr.dataset.status;
    const text = tr.textContent.toLowerCase();
    const matchesStatus = statusSelect === "all" || rowStatus === statusSelect;
    const matchesSearch = text.includes(searchInput);
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
      const tr = document.createElement("tr");
      tr.className = "sq-empty-row";
      tr.innerHTML = `
                <td colspan="8" class="sq-empty-state">
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
  document.getElementById("statusFilter").value = "open";
  filterTable();
}

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

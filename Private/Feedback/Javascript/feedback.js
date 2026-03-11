// Theme Toggle
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

// Select All Checkboxes
const sqSelectAll = document.getElementById("sqSelectAll");
const sqSelectAllHeader = document.getElementById("sqSelectAllHeader");
const sqItemCheckboxes = document.querySelectorAll(".sq-item-checkbox");

function toggleAll(checked) {
  sqItemCheckboxes.forEach((cb) => (cb.checked = checked));
  sqSelectAll.checked = checked;
  sqSelectAllHeader.checked = checked;
}

sqSelectAll?.addEventListener("change", (e) => toggleAll(e.target.checked));
sqSelectAllHeader?.addEventListener("change", (e) =>
  toggleAll(e.target.checked),
);

// View Modal
const sqViewModal = document.getElementById("sqViewModal");
const sqModalBody = document.getElementById("sqModalBody");

function sqViewFeedback(item) {
  const viewedBadge =
    item.is_viewed === "yes"
      ? '<span class="sq-status-badge sq-status-viewed"><i class="fas fa-check"></i> Viewed</span>'
      : '<span class="sq-status-badge sq-status-unviewed"><i class="fas fa-clock"></i> New</span>';

  const viewedInfo =
    item.is_viewed === "yes" && item.viewed_by
      ? `<div class="sq-detail-row">
                     <div class="sq-detail-label">Viewed By</div>
                     <div class="sq-detail-value">${escapeHtml(item.viewed_by)} on ${formatDate(item.viewed_at)}</div>
                   </div>`
      : "";

  sqModalBody.innerHTML = `
                <div class="sq-detail-row">
                    <div class="sq-detail-label">From</div>
                    <div class="sq-detail-value">
                        <strong>${escapeHtml(item.name)}</strong><br>
                        <a href="mailto:${escapeHtml(item.email)}" style="color: var(--sq-brand);">${escapeHtml(item.email)}</a>
                    </div>
                </div>
                <div class="sq-detail-row">
                    <div class="sq-detail-label">Subject</div>
                    <div class="sq-detail-value">${item.subject ? escapeHtml(item.subject) : "<em>No Subject</em>"}</div>
                </div>
                <div class="sq-detail-row">
                    <div class="sq-detail-label">Status</div>
                    <div class="sq-detail-value">${viewedBadge}</div>
                </div>
                ${viewedInfo}
                <div class="sq-detail-row">
                    <div class="sq-detail-label">Submitted</div>
                    <div class="sq-detail-value">${formatDate(item.submitted_at)}</div>
                </div>
                <div class="sq-detail-row">
                    <div class="sq-detail-label">Message</div>
                    <div class="sq-detail-message">${escapeHtml(item.message)}</div>
                </div>
            `;

  sqViewModal.classList.add("sq-modal--active");
  sqBody.style.overflow = "hidden";

  // Mark as viewed if currently unviewed
  if (item.is_viewed === "no") {
    fetch("", {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: `action=mark_viewed&feedback_id=${item.id}`,
    }).then(() => {
      // Update UI to reflect viewed status
      const row = document.querySelector(`tr[data-id="${item.id}"]`);
      if (row) {
        const statusCell = row.querySelector("td:nth-child(5)");
        statusCell.innerHTML =
          '<span class="sq-status-badge sq-status-viewed"><i class="fas fa-check"></i> Viewed</span>';
      }
    });
  }
}

function sqCloseModal() {
  sqViewModal.classList.remove("sq-modal--active");
  sqBody.style.overflow = "";
}

// Close modal on outside click
sqViewModal?.addEventListener("click", (e) => {
  if (e.target === sqViewModal) sqCloseModal();
});

// Escape key to close modal
document.addEventListener("keydown", (e) => {
  if (
    e.key === "Escape" &&
    sqViewModal.classList.contains("sq-modal--active")
  ) {
    sqCloseModal();
  }
});

// Helper functions
function escapeHtml(text) {
  const div = document.createElement("div");
  div.textContent = text;
  return div.innerHTML;
}

function formatDate(dateString) {
  const date = new Date(dateString);
  return date.toLocaleDateString("en-US", {
    year: "numeric",
    month: "long",
    day: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  });
}

// Auto-hide alerts after 5 seconds
document.querySelectorAll(".sq-admin-alert").forEach((alert) => {
  setTimeout(() => {
    alert.style.opacity = "0";
    alert.style.transform = "translateY(-10px)";
    setTimeout(() => alert.remove(), 300);
  }, 5000);
});

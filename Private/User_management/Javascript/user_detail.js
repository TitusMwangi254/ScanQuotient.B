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

// Modal Functions
function sqOpenModal(modalId) {
  document.getElementById(modalId).classList.add("sq-modal--active");
  sqBody.style.overflow = "hidden";
}

function sqCloseModal(modalId) {
  document.getElementById(modalId).classList.remove("sq-modal--active");
  sqBody.style.overflow = "";
}

// Close modals on outside click
document.querySelectorAll(".sq-modal").forEach((modal) => {
  modal.addEventListener("click", (e) => {
    if (e.target === modal) {
      modal.classList.remove("sq-modal--active");
      sqBody.style.overflow = "";
    }
  });
});

// Escape key to close modals
document.addEventListener("keydown", (e) => {
  if (e.key === "Escape") {
    document.querySelectorAll(".sq-modal--active").forEach((modal) => {
      modal.classList.remove("sq-modal--active");
      sqBody.style.overflow = "";
    });
  }
});

// Auto-hide alerts
document.querySelectorAll(".sq-admin-alert").forEach((alert) => {
  setTimeout(() => {
    alert.style.opacity = "0";
    alert.style.transform = "translateY(-10px)";
    setTimeout(() => alert.remove(), 300);
  }, 5000);
});

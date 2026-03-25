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

// Auto-hide alerts
document.querySelectorAll(".sq-admin-alert").forEach((alert) => {
  setTimeout(() => {
    alert.style.opacity = "0";
    alert.style.transform = "translateY(-10px)";
    setTimeout(() => alert.remove(), 300);
  }, 5000);
});

// ==========================
// Create User Modal
// ==========================
const sqCreateUserModal = document.getElementById("sqCreateUserModal");
const sqCreateUserOverlay = document.getElementById("sqCreateUserOverlay");
const sqProfilePhotoInput = document.getElementById("sqProfilePhotoInput");
const sqProfilePhotoPreview = document.getElementById("sqProfilePhotoPreview");
const sqProfilePhotoPlaceholder = document.getElementById("sqProfilePhotoPlaceholder");

function sqCloseCreateUserModal() {
  if (sqCreateUserModal) sqCreateUserModal.style.display = "none";
  if (sqCreateUserOverlay) sqCreateUserOverlay.style.display = "none";
}

function sqOpenCreateUserModal() {
  if (sqCreateUserModal) sqCreateUserModal.style.display = "block";
  if (sqCreateUserOverlay) sqCreateUserOverlay.style.display = "block";

  // Reset form + preview
  const form = document.getElementById("sqCreateUserForm");
  if (form) form.reset();
  if (sqProfilePhotoPreview) {
    sqProfilePhotoPreview.src = "";
    sqProfilePhotoPreview.style.display = "none";
  }
  if (sqProfilePhotoPlaceholder) {
    sqProfilePhotoPlaceholder.style.display = "flex";
  }
}

window.sqCloseCreateUserModal = sqCloseCreateUserModal;
window.sqOpenCreateUserModal = sqOpenCreateUserModal;

// Button trigger (if present)
const sqOpenCreateUserBtn = document.getElementById("sqOpenCreateUserBtn");
if (sqOpenCreateUserBtn) {
  sqOpenCreateUserBtn.addEventListener("click", sqOpenCreateUserModal);
}

// Overlay click closes modal
if (sqCreateUserOverlay) {
  sqCreateUserOverlay.addEventListener("click", sqCloseCreateUserModal);
}

// File preview
if (sqProfilePhotoInput && sqProfilePhotoPreview && sqProfilePhotoPlaceholder) {
  sqProfilePhotoInput.addEventListener("change", () => {
    const file = sqProfilePhotoInput.files && sqProfilePhotoInput.files[0] ? sqProfilePhotoInput.files[0] : null;
    if (!file) {
      sqProfilePhotoPreview.src = "";
      sqProfilePhotoPreview.style.display = "none";
      sqProfilePhotoPlaceholder.style.display = "flex";
      return;
    }

    const objectUrl = URL.createObjectURL(file);
    sqProfilePhotoPreview.src = objectUrl;
    sqProfilePhotoPreview.style.display = "block";
    sqProfilePhotoPlaceholder.style.display = "none";
  });
}

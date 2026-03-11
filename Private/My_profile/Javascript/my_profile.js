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
  localStorage.setItem("sq-profile-theme", current);
});

sqSetTheme(localStorage.getItem("sq-profile-theme") || "light");

// Photo Upload Modal
const sqUploadModal = document.getElementById("sqUploadModal");
const sqUploadInput = document.getElementById("sqUploadInput");
const sqUploadPreview = document.getElementById("sqUploadPreview");
const sqUploadPlaceholder = document.getElementById("sqUploadPlaceholder");
const sqUploadSubmit = document.getElementById("sqUploadSubmit");

function sqOpenUploadModal() {
  sqUploadModal.classList.add("sq-upload-modal--active");
  sqBody.style.overflow = "hidden";
}

function sqCloseUploadModal() {
  sqUploadModal.classList.remove("sq-upload-modal--active");
  sqBody.style.overflow = "";
  sqUploadInput.value = "";
  sqUploadPreview.src = "";
  sqUploadPreview.classList.remove("sq-upload-preview--active");
  sqUploadPlaceholder.classList.remove("sq-upload-placeholder--hidden");
  sqUploadSubmit.disabled = true;
}

sqUploadInput.addEventListener("change", function (e) {
  const file = e.target.files[0];
  if (file) {
    const reader = new FileReader();
    reader.onload = function (e) {
      sqUploadPreview.src = e.target.result;
      sqUploadPreview.classList.add("sq-upload-preview--active");
      sqUploadPlaceholder.classList.add("sq-upload-placeholder--hidden");
      sqUploadSubmit.disabled = false;
    };
    reader.readAsDataURL(file);
  }
});

sqUploadModal.addEventListener("click", (e) => {
  if (e.target === sqUploadModal) sqCloseUploadModal();
});

document.addEventListener("keydown", (e) => {
  if (
    e.key === "Escape" &&
    sqUploadModal.classList.contains("sq-upload-modal--active")
  ) {
    sqCloseUploadModal();
  }
});

// Auto-hide alerts after 5 seconds
document.querySelectorAll(".sq-profile-alert").forEach((alert) => {
  setTimeout(() => {
    alert.style.opacity = "0";
    alert.style.transform = "translateY(-10px)";
    setTimeout(() => alert.remove(), 300);
  }, 5000);
});

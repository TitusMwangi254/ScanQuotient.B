// Theme Toggle with Sun/Moon
const themeToggle = document.getElementById("theme-toggle");
const html = document.documentElement;

const savedTheme = localStorage.getItem("theme") || "light";
html.setAttribute("data-theme", savedTheme);
themeToggle.checked = savedTheme === "dark";

themeToggle.addEventListener("change", () => {
  const newTheme = themeToggle.checked ? "dark" : "light";
  html.setAttribute("data-theme", newTheme);
  localStorage.setItem("theme", newTheme);
});

// Toast Notification Function
function showToast(message, type = "success") {
  const container = document.getElementById("toastContainer");

  const toast = document.createElement("div");
  toast.className = `toast-notification toast-${type}`;
  toast.innerHTML = `
            <i class="fas fa-${type === "success" ? "check-circle" : "exclamation-circle"}"></i>
            <span>${message}</span>
        `;

  container.appendChild(toast);

  setTimeout(() => {
    toast.style.animation = "slideOut 0.3s ease forwards";
    setTimeout(() => toast.remove(), 300);
  }, 5000);
}

// Password Validation - FIXED VERSION
const currentPassword = document.getElementById("current_password");
const newPassword = document.getElementById("new_password");
const confirmPassword = document.getElementById("confirm_password");
const resetBtn = document.getElementById("resetBtn");

function validatePassword() {
  // Get values safely
  const currentVal = currentPassword ? currentPassword.value : "";
  const password = newPassword ? newPassword.value : "";
  const confirm = confirmPassword ? confirmPassword.value : "";

  console.log("Validating:", {
    current: currentVal.length,
    new: password.length,
    confirm: confirm.length,
  });

  // Check each criterion
  const criteria = {
    length: password.length >= 12,
    uppercase: /[A-Z]/.test(password),
    lowercase: /[a-z]/.test(password),
    number: /[0-9]/.test(password),
    special: /[!@#$%^&*()_+\-=\[\]{};':"\\|,.<>\/?]/.test(password),
    match: password === confirm && confirm.length > 0,
  };

  console.log("Criteria:", criteria);

  // Update validation list UI
  const criteriaMap = {
    lengthCriterion: "length",
    uppercaseCriterion: "uppercase",
    lowercaseCriterion: "lowercase",
    numberCriterion: "number",
    specialCharCriterion: "special",
    matchCriterion: "match",
  };

  Object.keys(criteriaMap).forEach((elementId) => {
    const li = document.getElementById(elementId);
    if (li) {
      const criterionKey = criteriaMap[elementId];
      const isMet = criteria[criterionKey];

      li.classList.toggle("met", isMet);

      const icon = li.querySelector("i");
      if (icon) {
        icon.className = isMet ? "fas fa-check-circle" : "fas fa-times-circle";
      }
    }
  });

  // Determine if button should be enabled
  const allMet = Object.values(criteria).every(Boolean);
  const currentFilled = currentVal.length > 0;
  const shouldEnable = allMet && currentFilled;

  console.log("Button state:", { allMet, currentFilled, shouldEnable });

  // Enable/disable button
  if (resetBtn) {
    resetBtn.disabled = !shouldEnable;

    // Visual feedback
    if (shouldEnable) {
      resetBtn.style.opacity = "1";
      resetBtn.style.cursor = "pointer";
    } else {
      resetBtn.style.opacity = "0.6";
      resetBtn.style.cursor = "not-allowed";
    }
  }
}

// Add event listeners with error handling
if (currentPassword) {
  currentPassword.addEventListener("input", validatePassword);
  currentPassword.addEventListener("blur", validatePassword);
}

if (newPassword) {
  newPassword.addEventListener("input", validatePassword);
  newPassword.addEventListener("blur", validatePassword);
}

if (confirmPassword) {
  confirmPassword.addEventListener("input", validatePassword);
  confirmPassword.addEventListener("blur", validatePassword);
}

// Toggle password visibility
document.querySelectorAll(".toggle-password").forEach((toggle) => {
  toggle.addEventListener("click", () => {
    const targetId = toggle.dataset.target;
    const target = document.getElementById(targetId);

    if (target) {
      if (target.type === "password") {
        target.type = "text";
        toggle.classList.remove("fa-eye-slash");
        toggle.classList.add("fa-eye");
      } else {
        target.type = "password";
        toggle.classList.remove("fa-eye");
        toggle.classList.add("fa-eye-slash");
      }
    }
  });
});

// AJAX Form Submission
const resetForm = document.getElementById("resetForm");
if (resetForm) {
  resetForm.addEventListener("submit", function (e) {
    e.preventDefault();

    const btn = document.getElementById("resetBtn");
    if (!btn) return;

    const originalText = btn.innerHTML;

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';

    const formData = new FormData(this);

    fetch("../../PHP/Backend/reset_password.php", {
      method: "POST",
      body: formData,
    })
      .then((response) => {
        if (!response.ok) {
          throw new Error("Network response was not ok");
        }
        return response.json();
      })
      .then((data) => {
        if (data.status === "success") {
          showToast(data.message, "success");
          setTimeout(() => {
            window.location.href = data.redirect;
          }, 2000);
        } else {
          showToast(data.message || "An error occurred", "error");
          btn.disabled = false;
          btn.innerHTML = originalText;
          // Re-validate to restore button state
          validatePassword();
        }
      })
      .catch((error) => {
        console.error("Error:", error);
        showToast("Network error. Please try again.", "error");
        btn.disabled = false;
        btn.innerHTML = originalText;
      });
  });
}

// Initialize validation on page load
console.log("Initializing password validation...");
validatePassword();

// Also run validation after a short delay to ensure DOM is ready
setTimeout(validatePassword, 100);

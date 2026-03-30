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
  // Remove existing toasts
  const existingToast = document.querySelector(".toast-notification");
  if (existingToast) {
    existingToast.remove();
  }

  // Create toast element
  const toast = document.createElement("div");
  toast.className = `toast-notification toast-${type}`;
  toast.innerHTML = `
                <i class="fas fa-${type === "success" ? "check-circle" : "exclamation-circle"}"></i>
                <span>${message}</span>
            `;

  document.body.appendChild(toast);

  // Auto remove after 5 seconds
  setTimeout(() => {
    toast.style.animation = "slideOut 0.3s ease forwards";
    setTimeout(() => toast.remove(), 300);
  }, 5000);
}

// Multi-step form logic (supports both full setup and agreements-only modes)
let currentStepIndex = 0;
const prevBtn = document.getElementById("prevBtn");
const nextBtn = document.getElementById("nextBtn");
const submitBtn = document.getElementById("submitBtn");
const completionForm = document.getElementById("completionForm");
const completionModeInput = document.querySelector('input[name="completion_mode"]');
const completionMode = completionModeInput ? completionModeInput.value : "pending_completion";

const visibleSteps = Array.from(document.querySelectorAll(".step-content")).filter(
  (step) => !step.hasAttribute("hidden"),
);
const progressSteps = Array.from(document.querySelectorAll(".progress-step"));

function getCurrentStepElement() {
  return visibleSteps[currentStepIndex] || null;
}

function validateCurrentStep() {
  const currentStep = getCurrentStepElement();
  if (!currentStep) return true;

  const kind = currentStep.dataset.kind || "";
  let isValid = false;

  switch (kind) {
    case "privacy": {
      const el = document.getElementById("agreePrivacy");
      isValid = !!el && el.checked;
      break;
    }
    case "terms": {
      const el = document.getElementById("agreeTerms");
      isValid = !!el && el.checked;
      break;
    }
    case "security": {
      const el = document.getElementById("agreeSecurity");
      isValid = !!el && el.checked;
      break;
    }
    case "username": {
      const usernameEl = document.getElementById("newUsername");
      const confirmEl = document.getElementById("confirmUsername");
      const username = usernameEl ? usernameEl.value : "";
      const confirmUsername = confirmEl ? confirmEl.value : "";
      const usernameValid = /^[a-zA-Z0-9_]{4,20}$/.test(username);
      isValid = usernameValid && username === confirmUsername;
      break;
    }
    case "password": {
      const passwordEl = document.getElementById("newPassword");
      const confirmEl = document.getElementById("confirmPassword");
      const password = passwordEl ? passwordEl.value : "";
      const confirmPassword = confirmEl ? confirmEl.value : "";
      isValid = validatePassword(password) && password === confirmPassword;
      break;
    }
    default:
      isValid = true;
      break;
  }

  if (nextBtn) nextBtn.disabled = !isValid;
  if (submitBtn) submitBtn.disabled = !isValid;
  return isValid;
}

function updateStep() {
  visibleSteps.forEach((step) => step.classList.remove("active"));
  const current = getCurrentStepElement();
  if (current) {
    current.classList.add("active");
  }

  progressSteps.forEach((step, index) => {
    step.classList.remove("active", "completed");
    if (index < currentStepIndex) {
      step.classList.add("completed");
    } else if (index === currentStepIndex) {
      step.classList.add("active");
    }
  });

  if (prevBtn) prevBtn.style.display = currentStepIndex === 0 ? "none" : "flex";
  const isLastStep = currentStepIndex === visibleSteps.length - 1;
  if (nextBtn) nextBtn.style.display = isLastStep ? "none" : "flex";
  if (submitBtn) submitBtn.style.display = isLastStep ? "flex" : "none";

  validateCurrentStep();
}

function validatePassword(password) {
  const criteria = {
    length: password.length >= 12,
    uppercase: /[A-Z]/.test(password),
    lowercase: /[a-z]/.test(password),
    number: /[0-9]/.test(password),
    special: /[!@#$%^&*()_+\-=\[\]{};':"\\|,.<>\/?]/.test(password),
  };

  document
    .getElementById("lengthCriterion")
    .classList.toggle("met", criteria.length);
  document
    .getElementById("uppercaseCriterion")
    .classList.toggle("met", criteria.uppercase);
  document
    .getElementById("lowercaseCriterion")
    .classList.toggle("met", criteria.lowercase);
  document
    .getElementById("numberCriterion")
    .classList.toggle("met", criteria.number);
  document
    .getElementById("specialCharCriterion")
    .classList.toggle("met", criteria.special);

  return Object.values(criteria).every(Boolean);
}

if (nextBtn) {
  nextBtn.addEventListener("click", () => {
    if (currentStepIndex < visibleSteps.length - 1) {
      currentStepIndex++;
      updateStep();
    }
  });
}

if (prevBtn) {
  prevBtn.addEventListener("click", () => {
    if (currentStepIndex > 0) {
      currentStepIndex--;
      updateStep();
    }
  });
}

["agreePrivacy", "agreeTerms", "agreeSecurity"].forEach((id) => {
  const el = document.getElementById(id);
  if (el) el.addEventListener("change", validateCurrentStep);
});

const usernameInput = document.getElementById("newUsername");
const confirmUsernameInput = document.getElementById("confirmUsername");

function validateUsername() {
  const username = usernameInput.value;
  const isValid = /^[a-zA-Z0-9_]{4,20}$/.test(username);

  usernameInput.parentElement.querySelector(".valid").style.display = isValid
    ? "block"
    : "none";
  usernameInput.parentElement.querySelector(".invalid").style.display = isValid
    ? "none"
    : "block";

  document
    .getElementById("usernameError")
    .classList.toggle("show", !isValid && username.length > 0);
  validateCurrentStep();
}

function validateUsernameMatch() {
  const match = usernameInput.value === confirmUsernameInput.value;
  confirmUsernameInput.parentElement.querySelector(".valid").style.display =
    match && confirmUsernameInput.value ? "block" : "none";
  confirmUsernameInput.parentElement.querySelector(".invalid").style.display =
    !match && confirmUsernameInput.value ? "block" : "none";
  document
    .getElementById("confirmUsernameError")
    .classList.toggle("show", !match);
  validateCurrentStep();
}

if (usernameInput) usernameInput.addEventListener("input", validateUsername);
if (confirmUsernameInput) confirmUsernameInput.addEventListener("input", validateUsernameMatch);

const passwordInput = document.getElementById("newPassword");
const confirmPasswordInput = document.getElementById("confirmPassword");

if (passwordInput) {
  passwordInput.addEventListener("input", () => {
    validatePassword(passwordInput.value);
    validateCurrentStep();
  });
}

if (confirmPasswordInput && passwordInput) {
  confirmPasswordInput.addEventListener("input", () => {
    const match = passwordInput.value === confirmPasswordInput.value;
    document
      .getElementById("confirmPasswordError")
      .classList.toggle("show", !match);
    validateCurrentStep();
  });
}

document.querySelectorAll(".toggle-visibility").forEach((toggle) => {
  toggle.addEventListener("click", () => {
    const target = document.getElementById(toggle.dataset.target);
    if (target.type === "password") {
      target.type = "text";
      toggle.classList.remove("fa-eye-slash");
      toggle.classList.add("fa-eye");
    } else {
      target.type = "password";
      toggle.classList.remove("fa-eye");
      toggle.classList.add("fa-eye-slash");
    }
  });
});

// AJAX Form Submission
completionForm.addEventListener("submit", function (e) {
    e.preventDefault();

    const submitBtn = document.getElementById("submitBtn");
    const originalText = submitBtn.innerHTML;

    // Show loading state
    submitBtn.disabled = true;
    submitBtn.innerHTML =
      completionMode === "agreements_only"
        ? '<i class="fas fa-spinner fa-spin"></i> Saving agreements...'
        : '<i class="fas fa-spinner fa-spin"></i> Completing setup...';

    // Collect form data
    const formData = new FormData(this);

    // Send AJAX request
    fetch(
      "/ScanQuotient.v2/ScanQuotient.B/Public/Registration_completion_page/PHP/Backend/complete_registration.php",
      {
        method: "POST",
        body: formData,
      },
    )
      .then((response) => response.json())
      .then((data) => {
        if (data.status === "success") {
          // Show success toast
          showToast(data.message, "success");

          // Redirect after short delay
          setTimeout(() => {
            window.location.href = data.redirect;
          }, 2000);
        } else {
          // Show error toast
          showToast(data.message, "error");
          submitBtn.disabled = false;
          submitBtn.innerHTML = originalText;
        }
      })
      .catch((error) => {
        console.error("Error:", error);
        showToast("Network error. Please try again.", "error");
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalText;
      });
});

// Initialize
updateStep();

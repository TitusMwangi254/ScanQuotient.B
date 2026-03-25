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

// Multi-step form logic
let currentStep = 1;
const totalSteps = 5;

const prevBtn = document.getElementById("prevBtn");
const nextBtn = document.getElementById("nextBtn");
const submitBtn = document.getElementById("submitBtn");

function updateStep() {
  document.querySelectorAll(".step-content").forEach((step) => {
    step.classList.remove("active");
  });

  document
    .querySelector(`.step-content[data-step="${currentStep}"]`)
    .classList.add("active");

  document.querySelectorAll(".progress-step").forEach((step, index) => {
    const stepNum = index + 1;
    step.classList.remove("active", "completed");
    if (stepNum < currentStep) {
      step.classList.add("completed");
    } else if (stepNum === currentStep) {
      step.classList.add("active");
    }
  });

  prevBtn.style.display = currentStep === 1 ? "none" : "flex";
  if (currentStep === totalSteps) {
    nextBtn.style.display = "none";
    submitBtn.style.display = "flex";
  } else {
    nextBtn.style.display = "flex";
    submitBtn.style.display = "none";
  }

  validateCurrentStep();
}

function validateCurrentStep() {
  let isValid = false;

  switch (currentStep) {
    case 1:
      isValid = document.getElementById("agreePrivacy").checked;
      break;
    case 2:
      isValid = document.getElementById("agreeTerms").checked;
      break;
    case 3:
      isValid = document.getElementById("agreeSecurity").checked;
      break;
    case 4:
      const username = document.getElementById("newUsername").value;
      const confirmUsername = document.getElementById("confirmUsername").value;
      const usernameValid = /^[a-zA-Z0-9_]{4,20}$/.test(username);
      isValid = usernameValid && username === confirmUsername;
      break;
    case 5:
      const password = document.getElementById("newPassword").value;
      const confirmPassword = document.getElementById("confirmPassword").value;
      isValid = validatePassword(password) && password === confirmPassword;
      break;
  }

  nextBtn.disabled = !isValid;
  submitBtn.disabled = !isValid;
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

nextBtn.addEventListener("click", () => {
  if (currentStep < totalSteps) {
    currentStep++;
    updateStep();
  }
});

prevBtn.addEventListener("click", () => {
  if (currentStep > 1) {
    currentStep--;
    updateStep();
  }
});

["agreePrivacy", "agreeTerms", "agreeSecurity"].forEach((id) => {
  document.getElementById(id).addEventListener("change", validateCurrentStep);
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

usernameInput.addEventListener("input", validateUsername);
confirmUsernameInput.addEventListener("input", validateUsernameMatch);

const passwordInput = document.getElementById("newPassword");
const confirmPasswordInput = document.getElementById("confirmPassword");

passwordInput.addEventListener("input", () => {
  validatePassword(passwordInput.value);
  validateCurrentStep();
});

confirmPasswordInput.addEventListener("input", () => {
  const match = passwordInput.value === confirmPasswordInput.value;
  document
    .getElementById("confirmPasswordError")
    .classList.toggle("show", !match);
  validateCurrentStep();
});

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
document
  .getElementById("completionForm")
  .addEventListener("submit", function (e) {
    e.preventDefault();

    const submitBtn = document.getElementById("submitBtn");
    const originalText = submitBtn.innerHTML;

    // Show loading state
    submitBtn.disabled = true;
    submitBtn.innerHTML =
      '<i class="fas fa-spinner fa-spin"></i> Completing setup...';

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

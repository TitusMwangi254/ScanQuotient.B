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

// OTP Input Handling
const inputs = document.querySelectorAll(".otp-input");
const hiddenInput = document.getElementById("otp_combined");
const form = document.getElementById("verify2FAForm");
const verifyBtn = document.getElementById("verifyBtn");

inputs.forEach((input, index) => {
  // Only allow numbers
  input.addEventListener("keypress", (e) => {
    if (!/[0-9]/.test(e.key)) {
      e.preventDefault();
    }
  });

  input.addEventListener("input", () => {
    // Ensure single digit
    if (input.value.length > 1) {
      input.value = input.value.slice(0, 1);
    }

    // Auto-focus next
    if (input.value && index < inputs.length - 1) {
      inputs[index + 1].focus();
    }

    combineCode();
  });

  input.addEventListener("keydown", (e) => {
    // Backspace to previous
    if (e.key === "Backspace" && !input.value && index > 0) {
      inputs[index - 1].focus();
    }
  });

  // Paste handling
  input.addEventListener("paste", (e) => {
    e.preventDefault();
    const pastedData = e.clipboardData
      .getData("text")
      .replace(/\D/g, "")
      .slice(0, 6);

    pastedData.split("").forEach((char, i) => {
      if (inputs[i]) inputs[i].value = char;
    });

    combineCode();

    // Focus last filled or next empty
    const lastIndex = Math.min(pastedData.length, inputs.length - 1);
    inputs[lastIndex].focus();
  });
});

function combineCode() {
  let code = "";
  inputs.forEach((i) => (code += i.value));
  hiddenInput.value = code;

  // Auto-submit when complete
  if (code.length === 6) {
    verifyBtn.focus();
  }
}

// Expiry Timer
let expiryTime = 600; // 10 minutes in seconds
const timerElement = document.getElementById("timer");
let timerInterval;

function startTimer() {
  clearInterval(timerInterval);
  expiryTime = 600;

  timerInterval = setInterval(() => {
    expiryTime--;

    const minutes = Math.floor(expiryTime / 60);
    const seconds = expiryTime % 60;

    timerElement.innerHTML = `
                    <i class="fas fa-clock"></i>
                    <span>Code expires in: ${String(minutes).padStart(2, "0")}:${String(seconds).padStart(2, "0")}</span>
                `;

    if (expiryTime <= 0) {
      clearInterval(timerInterval);
      timerElement.innerHTML = `
                        <i class="fas fa-exclamation-circle"></i>
                        <span>Code has expired. Please resend.</span>
                    `;
      timerElement.classList.add("expired");
      verifyBtn.disabled = true;
    }
  }, 1000);
}

startTimer();

// Resend Button — visible countdown on load and after each resend
const resendBtn = document.getElementById("resendBtn");
const RESEND_COOLDOWN_SEC = 30;
let resendCooldownTimer = null;

function clearResendCooldown() {
  if (resendCooldownTimer) {
    clearInterval(resendCooldownTimer);
    resendCooldownTimer = null;
  }
}

function startResendCooldown(seconds) {
  clearResendCooldown();
  resendBtn.disabled = true;
  let remaining = seconds;

  const tick = () => {
    if (remaining <= 0) {
      clearResendCooldown();
      resendBtn.disabled = false;
      resendBtn.innerHTML = '<i class="fas fa-redo"></i> Resend Code';
      return;
    }
    resendBtn.innerHTML = `<i class="fas fa-clock"></i> Resend in ${remaining}s`;
    remaining--;
  };

  tick();
  resendCooldownTimer = setInterval(tick, 1000);
}

startResendCooldown(RESEND_COOLDOWN_SEC);

resendBtn.addEventListener("click", function () {
  if (resendBtn.disabled) return;

  clearResendCooldown();
  resendBtn.disabled = true;
  resendBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';

  fetch("../../PHP/Backend/resend_2fa.php", {
    method: "POST",
  })
    .then((response) => response.json())
    .then((data) => {
      if (data.status === "success") {
        Swal.fire({
          icon: "success",
          title: "Code Resent!",
          text: "A new verification code has been sent to your email.",
          confirmButtonColor: "#2563eb",
        });

        // Reset timer
        timerElement.classList.remove("expired");
        startTimer();
        verifyBtn.disabled = false;

        // Clear inputs
        inputs.forEach((i) => (i.value = ""));
        inputs[0].focus();
      } else {
        Swal.fire({
          icon: "error",
          title: "Error",
          text: data.message || "Failed to resend code.",
          confirmButtonColor: "#2563eb",
        });
      }
    })
    .catch((error) => {
      console.error("Error:", error);
      Swal.fire({
        icon: "error",
        title: "Network Error",
        text: "Unable to resend code. Please try again.",
        confirmButtonColor: "#2563eb",
      });
    })
    .finally(() => {
      startResendCooldown(RESEND_COOLDOWN_SEC);
    });
});

// Verification full-screen overlay
const verifyOverlay = document.getElementById("verifyOverlay");
const verifyOverlayText = document.getElementById("verifyOverlayText");
/** Minimum time to show the spinner so fast responses still feel visible (ms). */
const VERIFY_SPINNER_MIN_MS = 2200;
let verifyOverlayOpenedAt = 0;

function showVerifyOverlay(message, options = {}) {
  if (!verifyOverlay) return;
  const success = options.success === true;
  if (success) {
    verifyOverlay.classList.add("is-success");
    verifyOverlay.setAttribute("aria-busy", "false");
  } else {
    verifyOverlay.classList.remove("is-success");
    verifyOverlay.setAttribute("aria-busy", "true");
    verifyOverlayOpenedAt = Date.now();
  }
  if (verifyOverlayText) verifyOverlayText.textContent = message;
  verifyOverlay.hidden = false;
  verifyOverlay.setAttribute("aria-hidden", "false");
  document.body.style.overflow = "hidden";
}

function hideVerifyOverlay() {
  if (!verifyOverlay) return;
  verifyOverlay.classList.remove("is-success");
  verifyOverlay.hidden = true;
  verifyOverlay.setAttribute("aria-hidden", "true");
  verifyOverlay.setAttribute("aria-busy", "false");
  document.body.style.overflow = "";
}

// Form Submission
form.addEventListener("submit", function (e) {
  e.preventDefault();
  combineCode();

  const code = hiddenInput.value;

  if (code.length !== 6) {
    Swal.fire({
      icon: "warning",
      title: "Incomplete Code",
      text: "Please enter all 6 digits.",
      confirmButtonColor: "#2563eb",
    });
    return;
  }

  verifyBtn.disabled = true;
  inputs.forEach((i) => (i.disabled = true));
  clearResendCooldown();
  resendBtn.disabled = true;
  resendBtn.innerHTML = '<i class="fas fa-redo"></i> Resend Code';
  showVerifyOverlay("Verifying your code…");

  fetch("../../PHP/Backend/verify_2fa.php", {
    method: "POST",
    headers: {
      "Content-Type": "application/x-www-form-urlencoded",
    },
    body: "code=" + encodeURIComponent(code),
  })
    .then((response) => response.json())
    .then((data) => {
      if (data.status === "success") {
        const redirectUrl = data.redirect;
        const elapsed = Date.now() - verifyOverlayOpenedAt;
        const waitForSpinner = Math.max(0, VERIFY_SPINNER_MIN_MS - elapsed);
        setTimeout(() => {
          showVerifyOverlay(
            "Code verified successfully. Redirecting to your account…",
            {
              success: true,
            }
          );
          setTimeout(() => {
            window.location.href = redirectUrl;
          }, 1000);
        }, waitForSpinner);
      } else {
        hideVerifyOverlay();
        inputs.forEach((i) => (i.disabled = false));
        clearResendCooldown();
        resendBtn.disabled = false;
        resendBtn.innerHTML = '<i class="fas fa-redo"></i> Resend Code';

        Swal.fire({
          icon: "error",
          title: "Invalid Code",
          text:
            data.message || "The code you entered is incorrect or has expired.",
          confirmButtonColor: "#2563eb",
        });

        verifyBtn.disabled = false;
        verifyBtn.innerHTML =
          '<i class="fas fa-check-circle"></i> Verify & Continue';

        // Clear inputs on error
        inputs.forEach((i) => (i.value = ""));
        inputs[0].focus();
      }
    })
    .catch((error) => {
      console.error("Error:", error);
      hideVerifyOverlay();
      inputs.forEach((i) => (i.disabled = false));
      clearResendCooldown();
      resendBtn.disabled = false;
      resendBtn.innerHTML = '<i class="fas fa-redo"></i> Resend Code';

      Swal.fire({
        icon: "error",
        title: "Server Error",
        text: "Unable to verify code. Please try again.",
        confirmButtonColor: "#2563eb",
      });

      verifyBtn.disabled = false;
      verifyBtn.innerHTML =
        '<i class="fas fa-check-circle"></i> Verify & Continue';
    });
});

// Get email from hidden input (passed from PHP)
const userEmail = document.getElementById("userEmail")?.value || "";

// Theme Toggle Functionality
const themeToggle = document.getElementById("theme-toggle");
const html = document.documentElement;
const icon = themeToggle?.querySelector("i");

if (themeToggle && icon) {
  const savedTheme = localStorage.getItem("theme") || "light";
  html.setAttribute("data-theme", savedTheme);
  updateIcon(savedTheme);

  themeToggle.addEventListener("click", () => {
    const currentTheme = html.getAttribute("data-theme");
    const newTheme = currentTheme === "light" ? "dark" : "light";
    html.setAttribute("data-theme", newTheme);
    localStorage.setItem("theme", newTheme);
    updateIcon(newTheme);
  });
}

function updateIcon(theme) {
  if (!icon) return;
  icon.className = theme === "light" ? "fas fa-moon" : "fas fa-sun";
}

// OTP Input Handling
const inputs = document.querySelectorAll(".otp-input");
const hiddenInput = document.getElementById("otp_combined");
const form = document.getElementById("verifyForm");
const verifyBtn = document.getElementById("verifyBtn");

inputs.forEach((input, index) => {
  input.addEventListener("input", () => {
    if (input.value.length > 1) {
      input.value = input.value.slice(0, 1);
    }
    if (input.value && index < inputs.length - 1) {
      inputs[index + 1].focus();
    }
    combineCode();
  });

  input.addEventListener("keydown", (e) => {
    if (e.key === "Backspace" && !input.value && index > 0) {
      inputs[index - 1].focus();
    }
    if (!/[0-9]/.test(e.key) && e.key !== "Backspace" && e.key !== "Tab") {
      e.preventDefault();
    }
  });

  input.addEventListener("paste", (e) => {
    e.preventDefault();
    const pastedData = e.clipboardData.getData("text").slice(0, 6);
    if (/^\d+$/.test(pastedData)) {
      pastedData.split("").forEach((char, i) => {
        if (inputs[i]) inputs[i].value = char;
      });
      combineCode();
      if (inputs[pastedData.length - 1]) inputs[pastedData.length - 1].focus();
    }
  });
});

function combineCode() {
  let code = "";
  inputs.forEach((i) => (code += i.value));
  hiddenInput.value = code;
}

let autoModalTimer = null;

function showAutoCloseModal(options) {
  const modal = document.getElementById("sqAutoModal");
  const iconWrap = document.getElementById("sqAutoModalIcon");
  const titleEl = document.getElementById("sqAutoModalTitle");
  const messageEl = document.getElementById("sqAutoModalMessage");
  const progressBar = document.getElementById("sqAutoModalProgressBar");
  const countdownWrap = document.getElementById("sqAutoModalCountdown");
  const countdownLabel = document.getElementById("sqAutoModalCountdownLabel");
  const secondsEl = document.getElementById("sqAutoModalSeconds");
  const loginBtn = document.getElementById("sqAutoModalLoginBtn");
  const closeBtn = document.getElementById("sqAutoModalCloseBtn");

  if (!modal) return;

  if (autoModalTimer) {
    window.clearInterval(autoModalTimer);
    autoModalTimer = null;
  }

  const variant = options.variant || "success";
  const totalSeconds = options.seconds || 8;
  const redirectUrl = options.redirectUrl || null;
  const showLoginButton = options.showLoginButton === true;
  const showCloseButton = options.showCloseButton === true;

  if (iconWrap) {
    iconWrap.classList.remove("sq-verify-success-icon--success", "sq-verify-success-icon--info");
    iconWrap.classList.add(variant === "info" ? "sq-verify-success-icon--info" : "sq-verify-success-icon--success");
    const icon = iconWrap.querySelector("i");
    if (icon) {
      icon.className = variant === "info" ? "fas fa-paper-plane" : "fas fa-circle-check";
    }
  }

  if (titleEl) titleEl.textContent = options.title || "Done";
  if (messageEl) messageEl.textContent = options.message || "";

  if (options.username) {
    modal.dataset.prefillUsername = options.username;
  } else {
    delete modal.dataset.prefillUsername;
  }

  if (countdownWrap) countdownWrap.hidden = false;
  if (countdownLabel) {
    countdownLabel.textContent = redirectUrl ? "Redirecting in" : "Closing in";
  }
  if (loginBtn) {
    if (showLoginButton && redirectUrl) {
      loginBtn.classList.remove("sq-modal-action--hidden");
      loginBtn.href = redirectUrl;
    } else {
      loginBtn.classList.add("sq-modal-action--hidden");
      loginBtn.removeAttribute("href");
    }
  }
  if (closeBtn) {
    if (showCloseButton) {
      closeBtn.classList.remove("sq-modal-action--hidden");
    } else {
      closeBtn.classList.add("sq-modal-action--hidden");
    }
  }

  modal.hidden = false;
  modal.removeAttribute("hidden");
  modal.classList.add("sq-verify-success-modal--visible");
  modal.setAttribute("aria-hidden", "false");
  document.body.style.overflow = "hidden";

  let remaining = totalSeconds;
  if (progressBar) progressBar.style.transform = "scaleX(1)";
  if (secondsEl) secondsEl.textContent = String(remaining);

  autoModalTimer = window.setInterval(() => {
    remaining -= 1;
    const progress = Math.max(0, remaining / totalSeconds);
    const secNode = document.getElementById("sqAutoModalSeconds");

    if (secNode) secNode.textContent = String(Math.max(0, remaining));
    if (progressBar) progressBar.style.transform = "scaleX(" + progress + ")";

    if (remaining <= 0) {
      window.clearInterval(autoModalTimer);
      autoModalTimer = null;
      closeAutoModal();
      if (redirectUrl) {
        if (options.username) {
          try {
            sessionStorage.setItem("sq_first_login_username", options.username);
          } catch (e) {
            /* ignore */
          }
        }
        window.location.href = redirectUrl;
      }
    }
  }, 1000);
}

function closeAutoModal() {
  if (autoModalTimer) {
    window.clearInterval(autoModalTimer);
    autoModalTimer = null;
  }
  const modal = document.getElementById("sqAutoModal");
  if (!modal) return;
  modal.classList.remove("sq-verify-success-modal--visible");
  modal.setAttribute("aria-hidden", "true");
  modal.hidden = true;
  document.body.style.overflow = "";
  document.getElementById("sqAutoModalLoginBtn")?.classList.add("sq-modal-action--hidden");
  document.getElementById("sqAutoModalCloseBtn")?.classList.add("sq-modal-action--hidden");
}

form.addEventListener("submit", function (e) {
  e.preventDefault();
  combineCode();

  if (hiddenInput.value.length !== 6) {
    Swal.fire({
      icon: "error",
      title: "Invalid Code",
      text: "Please enter the complete 6-digit code.",
      confirmButtonColor: "var(--accent-color)",
    });
    return;
  }

  verifyBtn.disabled = true;
  verifyBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Verifying...';

  fetch("../Backend/verify.php", {
    method: "POST",
    body: new FormData(this),
  })
    .then((res) => res.json())
    .then((data) => {
      verifyBtn.disabled = false;
      verifyBtn.innerHTML = '<i class="fas fa-shield-alt"></i> Verify Code';

      if (data.status === "success") {
        const redirectUrl =
          data.redirect || "../../../Login_page/PHP/Frontend/Login_page_site.php";
        showAutoCloseModal({
          variant: "success",
          title: "Email verified!",
          message:
            data.message ||
            "You're all set. Your sign-in details are in the email we sent, check your inbox or spam folder.",
          username: data.username || "",
          redirectUrl,
          showLoginButton: true,
          seconds: 10,
        });
      } else {
        Swal.fire({
          icon: "error",
          title: "Verification Failed",
          text: data.message,
          confirmButtonColor: "var(--accent-color)",
        });
      }
    })
    .catch(() => {
      verifyBtn.disabled = false;
      verifyBtn.innerHTML = '<i class="fas fa-shield-alt"></i> Verify Code';
      Swal.fire({
        icon: "error",
        title: "Server Error",
        text: "Unable to connect to server.",
        confirmButtonColor: "var(--accent-color)",
      });
    });
});

// Expiry Timer
let expiryTime = 300;
const timerElement = document.getElementById("timer");
let expiryInterval = null;

function startExpiryTimer() {
  if (expiryInterval) clearInterval(expiryInterval);
  expiryInterval = setInterval(() => {
    expiryTime--;
    const minutes = Math.floor(expiryTime / 60);
    const seconds = expiryTime % 60;
    timerElement.innerText = `Code expires in: ${String(minutes).padStart(2, "0")}:${String(seconds).padStart(2, "0")}`;
    if (expiryTime <= 0) {
      clearInterval(expiryInterval);
      timerElement.innerText = "Code has expired. Please resend.";
      timerElement.classList.add("expired");
    }
  }, 1000);
}

startExpiryTimer();

// Resend
const resendBtn = document.getElementById("resendBtn");
let resendCooldown = 60;
let resendInterval;

resendBtn.addEventListener("click", function () {
  resendBtn.disabled = true;
  resendBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Resending...';

  const formData = new FormData();
  formData.append("email", userEmail);

  fetch("../Backend/resend_verification.php", {
    method: "POST",
    body: formData,
  })
    .then((res) => res.json())
    .then((data) => {
      if (data.status === "success") {
        expiryTime = 300;
        timerElement.classList.remove("expired");
        startExpiryTimer();
        startResendCooldown();

        showAutoCloseModal({
          variant: "info",
          title: "Code sent!",
          message: data.message || "Check your inbox for the new verification code.",
          showCloseButton: true,
          seconds: 6,
        });
      } else {
        Swal.fire({
          icon: "error",
          title: "Error",
          text: data.message,
          confirmButtonColor: "var(--accent-color)",
        });
        resendBtn.disabled = false;
        resendBtn.innerHTML = '<i class="fas fa-redo"></i> Resend Code';
      }
    })
    .catch(() => {
      Swal.fire({
        icon: "error",
        title: "Server Error",
        text: "Unable to resend code.",
        confirmButtonColor: "var(--accent-color)",
      });
      resendBtn.disabled = false;
      resendBtn.innerHTML = '<i class="fas fa-redo"></i> Resend Code';
    });
});

function startResendCooldown() {
  let timeLeft = resendCooldown;
  resendBtn.innerHTML = `<i class="fas fa-clock"></i> Resend available in ${timeLeft}s`;
  resendInterval = setInterval(() => {
    timeLeft--;
    resendBtn.innerText = `Resend available in ${timeLeft}s`;
    if (timeLeft <= 0) {
      clearInterval(resendInterval);
      resendBtn.disabled = false;
      resendBtn.innerHTML = '<i class="fas fa-redo"></i> Resend Code';
    }
  }, 1000);
}

document.getElementById("sqAutoModalCloseBtn")?.addEventListener("click", function () {
  closeAutoModal();
});

document.getElementById("sqAutoModalLoginBtn")?.addEventListener("click", function (e) {
  const href = this.getAttribute("href");
  if (!href) return;
  e.preventDefault();
  if (autoModalTimer) {
    window.clearInterval(autoModalTimer);
    autoModalTimer = null;
  }
  try {
    const u = document.getElementById("sqAutoModal")?.dataset?.prefillUsername?.trim();
    if (u) sessionStorage.setItem("sq_first_login_username", u);
  } catch (err) {
    /* ignore */
  }
  window.location.href = href;
});

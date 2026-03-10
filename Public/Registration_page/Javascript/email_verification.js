// Get email from hidden input (passed from PHP)
const userEmail = document.getElementById("userEmail")?.value || "";

// Theme Toggle Functionality
const themeToggle = document.getElementById("theme-toggle");
const html = document.documentElement;
const icon = themeToggle.querySelector("i");

// Check for saved theme preference
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

function updateIcon(theme) {
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

    // Allow only numbers
    if (!/[0-9]/.test(e.key) && e.key !== "Backspace" && e.key !== "Tab") {
      e.preventDefault();
    }
  });

  // Paste handling
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

// Form Submission
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
        Swal.fire({
          icon: "success",
          title: "Verified!",
          text: data.message,
          confirmButtonColor: "var(--accent-color)",
        }).then(() => {
          window.location.href = data.redirect;
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

function startExpiryTimer() {
  const interval = setInterval(() => {
    expiryTime--;
    let minutes = Math.floor(expiryTime / 60);
    let seconds = expiryTime % 60;

    timerElement.innerText = `Code expires in: ${String(minutes).padStart(2, "0")}:${String(seconds).padStart(2, "0")}`;

    if (expiryTime <= 0) {
      clearInterval(interval);
      timerElement.innerText = "Code has expired. Please resend.";
      timerElement.classList.add("expired");
    }
  }, 1000);
}

startExpiryTimer();

// Resend Logic - USING HIDDEN INPUT EMAIL
const resendBtn = document.getElementById("resendBtn");
let resendCooldown = 60;
let resendInterval;

resendBtn.addEventListener("click", function () {
  resendBtn.disabled = true;
  resendBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Resending...';

  const formData = new FormData();
  formData.append("email", userEmail); // USING HIDDEN INPUT VALUE

  fetch("../Backend/resend_verification.php", {
    method: "POST",
    body: formData,
  })
    .then((res) => res.json())
    .then((data) => {
      if (data.status === "success") {
        Swal.fire({
          icon: "success",
          title: "Code Sent!",
          text: data.message,
          confirmButtonColor: "var(--accent-color)",
        });

        // Reset expiry timer
        expiryTime = 300;
        timerElement.classList.remove("expired");
        startExpiryTimer();

        // Start cooldown timer
        startResendCooldown();
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

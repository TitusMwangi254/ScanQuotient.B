// Theme Toggle Functionality
const themeToggle = document.getElementById("theme-toggle");
const html = document.documentElement;

// Load saved theme
const savedTheme = localStorage.getItem("theme") || "light";
html.setAttribute("data-theme", savedTheme);
themeToggle.checked = savedTheme === "dark";

// Toggle theme on change
themeToggle.addEventListener("change", function () {
  const newTheme = this.checked ? "dark" : "light";
  html.setAttribute("data-theme", newTheme);
  localStorage.setItem("theme", newTheme);
});

// Your existing JavaScript functions...
let resetData = {};
let expiryTimer;

function toggleVisibility(id, el) {
  const input = document.getElementById(id);
  if (input.type === "password") {
    input.type = "text";
    el.classList.replace("fa-eye-slash", "fa-eye"); // Show open eye when text visible
  } else {
    input.type = "password";
    el.classList.replace("fa-eye", "fa-eye-slash"); // Show slashed eye when text hidden
  }
}

function showStep(n) {
  document
    .querySelectorAll(".step")
    .forEach((s) => s.classList.remove("active"));
  document.getElementById("step" + n).classList.add("active");
}

function findAccount() {
  const id = document.getElementById("identifier").value;
  if (!id) return Swal.fire("Error", "Enter email or username", "error");

  fetch("../../PHP/Backend/find_user.php", {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    body: "identifier=" + encodeURIComponent(id),
  })
    .then((r) => r.json())
    .then((d) => {
      if (d.status === "success") {
        resetData = d.data;
        document.getElementById("maskedEmail").textContent =
          d.data.masked_email;
        const sel = document.getElementById("verifyMethod");
        sel.innerHTML = '<option value="">Select method...</option>';
        if (d.data.has_email)
          sel.innerHTML +=
            '<option value="email">Primary Email: ' +
            d.data.masked_email +
            "</option>";
        if (d.data.has_recovery)
          sel.innerHTML +=
            '<option value="recovery">Recovery Email: ' +
            d.data.masked_recovery +
            "</option>";
        if (d.data.has_question)
          sel.innerHTML +=
            '<option value="question">Security Question</option>';
        showStep(2);
      } else {
        Swal.fire("Not Found", d.message, "error");
      }
    });
}

function methodChanged() {
  const m = document.getElementById("verifyMethod").value;
  document.getElementById("questionBox").style.display =
    m === "question" ? "block" : "none";
  if (m === "question")
    document.getElementById("questionText").textContent = resetData.question;
  document.getElementById("verifyBtn").disabled = !m;
}

function verifyIdentity() {
  const m = document.getElementById("verifyMethod").value;
  const btn = document.getElementById("verifyBtn");
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';

  let body = "method=" + m;
  if (m === "question")
    body +=
      "&answer=" +
      encodeURIComponent(document.getElementById("securityAnswer").value);

  fetch("../../PHP/Backend/send_verify.php", {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    body: body,
  })
    .then((r) => r.json())
    .then((d) => {
      if (d.status === "success") {
        if (m === "question") {
          document.getElementById("codeEntry").style.display = "none";
          document.getElementById("passwordEntry").style.display = "block";
          showStep(3);
        } else {
          document.getElementById("codeEntry").style.display = "block";
          document.getElementById("passwordEntry").style.display = "none";
          startTimer(900);
          showStep(3);
        }
      } else {
        Swal.fire("Error", d.message, "error");
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-shield-alt"></i> Verify';
      }
    });
}

function moveNext(el, idx) {
  el.value = el.value.replace(/[^0-9]/, "");
  if (el.value && idx < 5)
    document.querySelectorAll(".otp-input")[idx + 1].focus();
}

function moveBack(e, idx) {
  if (e.key === "Backspace" && !e.target.value && idx > 0)
    document.querySelectorAll(".otp-input")[idx - 1].focus();
}

function getCode() {
  let c = "";
  document.querySelectorAll(".otp-input").forEach((i) => (c += i.value));
  return c;
}

function startTimer(s) {
  clearInterval(expiryTimer);
  let t = s;
  expiryTimer = setInterval(() => {
    t--;
    const m = Math.floor(t / 60),
      sec = t % 60;
    document.getElementById("timer").textContent =
      "Code expires in " +
      String(m).padStart(2, "0") +
      ":" +
      String(sec).padStart(2, "0");
    if (t <= 0) {
      clearInterval(expiryTimer);
      document.getElementById("timer").textContent = "Code expired";
    }
  }, 1000);
}

function verifyCode() {
  fetch("../../PHP/Backend/check_code.php", {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    body: "code=" + getCode(),
  })
    .then((r) => r.json())
    .then((d) => {
      if (d.status === "success") {
        document.getElementById("codeEntry").style.display = "none";
        document.getElementById("passwordEntry").style.display = "block";
      } else {
        Swal.fire("Invalid Code", d.message, "error");
      }
    });
}

function resendCode() {
  document.getElementById("resendBtn").disabled = true;
  verifyIdentity();
  setTimeout(
    () => (document.getElementById("resendBtn").disabled = false),
    30000,
  );
}

function checkPassword() {
  const p = document.getElementById("newPass").value;
  const c = document.getElementById("confirmPass").value;
  const checks = {
    len: p.length >= 12,
    upper: /[A-Z]/.test(p),
    lower: /[a-z]/.test(p),
    num: /[0-9]/.test(p),
    spec: /[!@#$%^&*]/.test(p),
    match: p === c && c.length > 0,
  };
  Object.keys(checks).forEach((k) => {
    const el = document.getElementById(k);
    el.innerHTML =
      '<i class="fas fa-' +
      (checks[k] ? "check" : "times") +
      '"></i> ' +
      el.textContent.replace(/^. /, "");
    el.style.color = checks[k] ? "#10b981" : "var(--text-muted)";
  });
  document.getElementById("finalBtn").disabled =
    !Object.values(checks).every(Boolean);
}

function setPassword() {
  const btn = document.getElementById("finalBtn");
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating...';

  fetch("../../PHP/Backend/final_reset.php", {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    body:
      "password=" +
      encodeURIComponent(document.getElementById("newPass").value),
  })
    .then((r) => r.json())
    .then((d) => {
      if (d.status === "success") {
        Swal.fire({
          icon: "success",
          title: "Password Updated!",
          html: "Login with your new password.<br><strong>Two-Factor Authentication (2FA) has been enabled as part of our security protocol</strong> for your account.",
          timer: 3000,
          showConfirmButton: false,
        }).then(() => {
          window.location.href =
            "../../../Login_page/PHP/Frontend/Login_page_site.php";
        });
      } else {
        Swal.fire("Error", d.message, "error");
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save"></i> Update Password';
      }
    });
}

function resetFlow() {
  resetData = {};
  clearInterval(expiryTimer);
  document.getElementById("identifier").value = "";
  document.querySelectorAll(".otp-input").forEach((i) => (i.value = ""));
  document.getElementById("newPass").value = "";
  document.getElementById("confirmPass").value = "";
  showStep(1);
}

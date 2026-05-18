// Theme Toggle Logic
const themeToggleBtn = document.getElementById("themeToggle");
const themeIcon = document.getElementById("themeIcon");
const htmlElement = document.documentElement;

// Check local storage or system preference
if (
  localStorage.theme === "dark" ||
  (!("theme" in localStorage) &&
    window.matchMedia("(prefers-color-scheme: dark)").matches)
) {
  htmlElement.classList.add("dark");
  themeIcon.classList.remove("fa-moon");
  themeIcon.classList.add("fa-sun");
} else {
  htmlElement.classList.remove("dark");
  themeIcon.classList.remove("fa-sun");
  themeIcon.classList.add("fa-moon");
}

themeToggleBtn.addEventListener("click", () => {
  htmlElement.classList.toggle("dark");
  if (htmlElement.classList.contains("dark")) {
    localStorage.theme = "dark";
    themeIcon.classList.remove("fa-moon");
    themeIcon.classList.add("fa-sun");
  } else {
    localStorage.theme = "light";
    themeIcon.classList.remove("fa-sun");
    themeIcon.classList.add("fa-moon");
  }
});

// Header scroll effect
const header = document.getElementById("header");
window.addEventListener("scroll", () => {
  if (window.scrollY > 50) {
    header.classList.add("scrolled");
  } else {
    header.classList.remove("scrolled");
  }
});

// Mobile menu toggle
function toggleMobileMenu() {
  document.getElementById("navLinks").classList.toggle("active");
}

// Intersection Observer for animations
const observerOptions = {
  threshold: 0.1,
  rootMargin: "0px 0px -50px 0px",
};

const observer = new IntersectionObserver((entries) => {
  entries.forEach((entry) => {
    if (entry.isIntersecting) {
      entry.target.classList.add("visible");
    }
  });
}, observerOptions);

// Observe all animated elements
document
  .querySelectorAll(
    ".section-header, .feature-card, .service-card, .timeline-item, .testimonial-card, .pricing-card, .contact-card, .contact-form-wrapper, .about-text, .about-visual",
  )
  .forEach((el) => {
    observer.observe(el);
  });

// Back to top button visibility
const backToTop = document.getElementById("backToTop");
window.addEventListener("scroll", () => {
  if (window.scrollY > 500) {
    backToTop.classList.add("visible");
  } else {
    backToTop.classList.remove("visible");
  }
});

// Modal functions
const modalData = {
  web: {
    title: "Web Application Scanning",
    content:
      '<p>Our comprehensive web application scanning module performs deep inspection of your web assets:</p><ul style="margin: 16px 0; padding-left: 20px;"><li>SQL Injection and XSS testing</li><li>CSRF and authentication bypass checks</li><li>API endpoint security validation</li><li>Custom payload generation</li></ul><p>Perfect for e-commerce platforms, SaaS applications, and enterprise web portals.</p>',
  },
  config: {
    title: "Configuration & Header Review",
    content:
      '<p>Server hardening assessment focusing on:</p><ul style="margin: 16px 0; padding-left: 20px;"><li>HTTP security header analysis (CSP, HSTS, X-Frame-Options)</li><li>Server software version detection</li><li>Insecure configuration flag identification</li><li>TLS/SSL cipher suite evaluation</li><li>Information disclosure checks</li></ul><p>Ensure your infrastructure follows security best practices.</p>',
  },
  ssl: {
    title: "SSL/TLS & Network Security",
    content:
      '<p>Comprehensive transport layer and network assessment:</p><ul style="margin: 16px 0; padding-left: 20px;"><li>Certificate validity and chain verification</li><li>Protocol version analysis (TLS 1.0-1.3)</li><li>Open port enumeration</li><li>Service banner grabbing</li><li>Weak encryption detection</li></ul><p>Protect data in transit and minimize network attack surfaces.</p>',
  },
};

function openModal(type) {
  const modal = document.getElementById("modalOverlay");
  const title = document.getElementById("modalTitle");
  const content = document.getElementById("modalContent");

  if (modalData[type]) {
    title.textContent = modalData[type].title;
    content.innerHTML = modalData[type].content;
    modal.classList.add("active");
    document.body.style.overflow = "hidden";
  }
}

function closeModal(event) {
  if (
    !event ||
    event.target.id === "modalOverlay" ||
    event.target.closest(".modal-close") ||
    event.target.closest(".btn")
  ) {
    document.getElementById("modalOverlay").classList.remove("active");
    document.body.style.overflow = "";
  }
}

// Close modals on Escape key
document.addEventListener("keydown", (e) => {
  if (e.key !== "Escape") return;
  const demoOverlay = document.getElementById("demoModalOverlay");
  if (demoOverlay && demoOverlay.classList.contains("is-open")) {
    closeDemoModal();
    return;
  }
  closeModal();
});

// Product demo modal
(function initDemoModal() {
  const overlay = document.getElementById("demoModalOverlay");
  const openBtn = document.getElementById("watchDemoBtn");
  const closeBtn = document.getElementById("demoModalClose");
  const prevBtn = document.getElementById("demoPrevBtn");
  const nextBtn = document.getElementById("demoNextBtn");
  const playPauseBtn = document.getElementById("demoPlayPauseBtn");
  const dotsWrap = document.getElementById("demoDots");

  if (!overlay || !openBtn) return;

  const stages = Array.from(overlay.querySelectorAll(".sq-demo-stage"));
  const total = stages.length;
  let current = 0;
  let timer = null;
  let playing = true;
  const STEP_MS = 4200;

  function buildDots() {
    if (!dotsWrap || dotsWrap.childElementCount) return;
    stages.forEach((stage, i) => {
      const btn = document.createElement("button");
      btn.type = "button";
      btn.className = "sq-demo-dot" + (i === 0 ? " is-active" : "");
      btn.setAttribute("role", "tab");
      btn.setAttribute("aria-label", stage.dataset.label || `Step ${i + 1}`);
      btn.setAttribute("aria-selected", i === 0 ? "true" : "false");
      btn.addEventListener("click", () => {
        setStep(i, true);
        resetAutoplay();
      });
      dotsWrap.appendChild(btn);
    });
  }

  function updateDots(index) {
    if (!dotsWrap) return;
    dotsWrap.querySelectorAll(".sq-demo-dot").forEach((dot, i) => {
      const active = i === index;
      dot.classList.toggle("is-active", active);
      dot.setAttribute("aria-selected", active ? "true" : "false");
    });
  }

  function updatePlayButton() {
    if (!playPauseBtn) return;
    const icon = playPauseBtn.querySelector("i");
    if (!icon) return;
    icon.className = playing ? "fas fa-pause" : "fas fa-play";
    playPauseBtn.setAttribute("aria-label", playing ? "Pause demo" : "Play demo");
  }

  function setStep(index, fromUser) {
    const next = ((index % total) + total) % total;
    stages.forEach((stage, i) => {
      stage.classList.remove("is-active", "is-exit");
      if (i === current && i !== next) stage.classList.add("is-exit");
      if (i === next) stage.classList.add("is-active");
    });
    current = next;
    updateDots(current);
    if (fromUser) resetAutoplay();
  }

  function clearAutoplay() {
    if (timer) {
      clearInterval(timer);
      timer = null;
    }
  }

  function startAutoplay() {
    clearAutoplay();
    if (!playing) return;
    timer = setInterval(() => setStep(current + 1, false), STEP_MS);
  }

  function resetAutoplay() {
    clearAutoplay();
    startAutoplay();
  }

  function openDemoModal() {
    buildDots();
    current = 0;
    stages.forEach((s, i) => {
      s.classList.toggle("is-active", i === 0);
      s.classList.remove("is-exit");
    });
    updateDots(0);
    playing = true;
    updatePlayButton();
    overlay.removeAttribute("hidden");
    requestAnimationFrame(() => overlay.classList.add("is-open"));
    document.body.style.overflow = "hidden";
    openBtn.setAttribute("aria-expanded", "true");
    startAutoplay();
    closeBtn?.focus();
  }

  function closeDemoModal() {
    clearAutoplay();
    overlay.classList.remove("is-open");
    document.body.style.overflow = "";
    openBtn.setAttribute("aria-expanded", "false");
    let hiddenSet = false;
    const applyHidden = () => {
      if (hiddenSet) return;
      hiddenSet = true;
      overlay.setAttribute("hidden", "");
    };
    const onEnd = (e) => {
      if (e.target !== overlay || e.propertyName !== "opacity") return;
      applyHidden();
      overlay.removeEventListener("transitionend", onEnd);
    };
    overlay.addEventListener("transitionend", onEnd);
    setTimeout(applyHidden, 400);
    openBtn.focus();
  }

  openBtn.addEventListener("click", openDemoModal);
  closeBtn?.addEventListener("click", closeDemoModal);
  overlay.addEventListener("click", (e) => {
    if (e.target === overlay) closeDemoModal();
  });

  prevBtn?.addEventListener("click", () => setStep(current - 1, true));
  nextBtn?.addEventListener("click", () => setStep(current + 1, true));

  playPauseBtn?.addEventListener("click", () => {
    playing = !playing;
    updatePlayButton();
    if (playing) startAutoplay();
    else clearAutoplay();
  });

  window.openDemoModal = openDemoModal;
  window.closeDemoModal = closeDemoModal;
})();

//Toast notification logic
window.addEventListener("DOMContentLoaded", () => {
  if (window.PHP_FEEDBACK) {
    const toast = document.getElementById("toast");
    if (toast) {
      toast.textContent = window.PHP_FEEDBACK;
      toast.classList.add("show");
      setTimeout(() => {
        toast.classList.remove("show");
      }, 3000);
    }
  }
});

// Contact form: disable submit and show spinner while posting
const contactForm = document.getElementById("contactForm");
const contactSubmitBtn = document.getElementById("contactSubmitBtn");

if (contactForm && contactSubmitBtn) {
  const contactBtnDefaultHtml = contactSubmitBtn.innerHTML;

  contactForm.addEventListener("submit", function () {
    if (contactSubmitBtn.disabled) return;

    contactSubmitBtn.disabled = true;
    contactSubmitBtn.setAttribute("aria-busy", "true");
    contactSubmitBtn.classList.add("sq-contact-submit-btn--loading");
    contactSubmitBtn.innerHTML =
      '<i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Sending...';
  });

  // Restore if user navigates back before reload completes
  window.addEventListener("pageshow", function (event) {
    if (event.persisted) {
      contactSubmitBtn.disabled = false;
      contactSubmitBtn.removeAttribute("aria-busy");
      contactSubmitBtn.classList.remove("sq-contact-submit-btn--loading");
      contactSubmitBtn.innerHTML = contactBtnDefaultHtml;
    }
  });
}

// Smooth scroll for anchor links
document.querySelectorAll('a[href^="#"]').forEach((anchor) => {
  anchor.addEventListener("click", function (e) {
    e.preventDefault();
    const target = document.querySelector(this.getAttribute("href"));
    if (target) {
      target.scrollIntoView({
        behavior: "smooth",
        block: "start",
      });
      // Close mobile menu if open
      document.getElementById("navLinks").classList.remove("active");
    }
  });
});

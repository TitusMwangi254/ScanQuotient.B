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
      '<p>Our comprehensive web application scanning module performs deep inspection of your web assets:</p><ul style="margin: 16px 0; padding-left: 20px;"><li>OWASP Top 10 vulnerability detection</li><li>SQL Injection and XSS testing</li><li>CSRF and authentication bypass checks</li><li>API endpoint security validation</li><li>Custom payload generation</li></ul><p>Perfect for e-commerce platforms, SaaS applications, and enterprise web portals.</p>',
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

// Close modal on Escape key
document.addEventListener("keydown", (e) => {
  if (e.key === "Escape") closeModal();
});

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

const themeToggle = document.getElementById("theme-toggle");
const themeIcon = document.getElementById("theme-icon");
const body = document.body;

const savedTheme = localStorage.getItem("theme") || "dark";
if (savedTheme === "light") {
  body.classList.add("light-theme");
  themeIcon.classList.remove("fa-moon");
  themeIcon.classList.add("fa-sun");
}

themeToggle.addEventListener("click", () => {
  body.classList.toggle("light-theme");
  const isLight = body.classList.contains("light-theme");

  if (isLight) {
    localStorage.setItem("theme", "light");
    themeIcon.classList.remove("fa-moon");
    themeIcon.classList.add("fa-sun");
  } else {
    localStorage.setItem("theme", "dark");
    themeIcon.classList.remove("fa-sun");
    themeIcon.classList.add("fa-moon");
  }
});

const successToast = document.getElementById("successToast");
if (successToast) {
  setTimeout(() => {
    successToast.classList.add("toast-hiding");
    setTimeout(() => {
      successToast.remove();
    }, 500);
  }, 4000);
}

const modal = document.getElementById("closeTicketModal");
const modalContent = document.getElementById("modalContent");
const openModalBtn = document.getElementById("openCloseModalBtn");
const cancelBtn = document.getElementById("cancelCloseBtn");

if (openModalBtn) {
  openModalBtn.addEventListener("click", () => {
    modal.classList.remove("hidden");
    setTimeout(() => {
      modalContent.classList.remove("scale-95", "opacity-0");
      modalContent.classList.add("scale-100", "opacity-100");
    }, 10);
  });
}

function closeModal() {
  modalContent.classList.remove("scale-100", "opacity-100");
  modalContent.classList.add("scale-95", "opacity-0");
  setTimeout(() => {
    modal.classList.add("hidden");
  }, 200);
}

if (cancelBtn) cancelBtn.addEventListener("click", closeModal);
modal.addEventListener("click", (e) => {
  if (e.target === modal) closeModal();
});

const backToTopBtn = document.getElementById("back-to-top");
window.addEventListener("scroll", () => {
  if (window.scrollY > 400) {
    backToTopBtn.classList.remove("translate-y-20", "opacity-0");
  } else {
    backToTopBtn.classList.add("translate-y-20", "opacity-0");
  }
});
backToTopBtn.addEventListener("click", () => {
  window.scrollTo({ top: 0, behavior: "smooth" });
});

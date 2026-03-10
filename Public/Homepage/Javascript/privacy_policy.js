document.addEventListener("DOMContentLoaded", () => {
  const themeToggle = document.getElementById("themeToggle");
  const themeIcon = document.getElementById("themeIcon");
  const body = document.body;
  const scrollToTopBtn = document.getElementById("scrollToTopBtn");

  // Load theme preference from local storage
  if (localStorage.getItem("theme") === "dark-mode") {
    body.classList.add("dark-mode");
    themeIcon.classList.remove("fa-moon");
    themeIcon.classList.add("fa-sun");
    themeToggle.setAttribute("data-tooltip", "Switch to Light Mode");
  } else {
    themeToggle.setAttribute("data-tooltip", "Switch to Dark Mode");
  }

  // Theme toggle functionality
  themeToggle.addEventListener("click", () => {
    body.classList.toggle("dark-mode");
    const isDark = body.classList.contains("dark-mode");

    // Toggle icon and save preference
    if (isDark) {
      themeIcon.classList.remove("fa-moon");
      themeIcon.classList.add("fa-sun");
      localStorage.setItem("theme", "dark-mode");
      themeToggle.setAttribute("data-tooltip", "Switch to Light Mode");
    } else {
      themeIcon.classList.remove("fa-sun");
      themeIcon.classList.add("fa-moon");
      localStorage.setItem("theme", "light-mode");
      themeToggle.setAttribute("data-tooltip", "Switch to Dark Mode");
    }
  });

  // Scroll-to-top functionality
  scrollToTopBtn.addEventListener("click", function () {
    window.scrollTo({
      top: 0,
      behavior: "smooth",
    });
  });
});

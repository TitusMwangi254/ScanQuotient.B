// site-behaviors.js

(function (window, document) {
  // --- Core Utility Functions ---

  // 1) Confirm before reloading the page
  function confirmReload() {
    return confirm("Are you sure you want to reload the page?");
  }

  // 2) Confirm before redirecting to the homepage
  function confirmHomeRedirect(e) {
    // Prevent default link behavior if this function is called via an event listener
    if (e) {
      e.preventDefault();
    }
    if (confirm("Are you sure you want to go back to the homepage?")) {
      window.location.href =
        "/final_year_project/WEB_PAGES/PUBLIC/Home_page/PHP/Frontend/home_page_site.php";
    }
    // Always return false for inline onclick compatibility and to ensure no default action
    return false;
  }

  // 3) Smooth scroll to the top of the page
  function scrollToTop() {
    window.scrollTo({ top: 0, behavior: "smooth" });
  }

  // 4) Reload page when navigating back from browser's BF cache (Back-Forward cache)
  function handlePageShow(event) {
    if (event.persisted) {
      window.location.reload();
    }
  }

  // --- Theme Toggle Functionality ---

  // Helper function to update the theme toggle's tooltip text
  function updateThemeToggleTooltip() {
    const themeToggleCheckbox = document.getElementById("themeToggle");
    // Safely get the parent label element, where the data-tooltip attribute is
    const themeSwitchLabel = themeToggleCheckbox
      ? themeToggleCheckbox.closest(".theme-switch")
      : null;

    if (themeToggleCheckbox && themeSwitchLabel) {
      if (themeToggleCheckbox.checked) {
        // If the checkbox is checked, it means the Light Mode is currently active.
        // So, the tooltip should instruct the user to switch to Dark Mode.
        themeSwitchLabel.setAttribute("data-tooltip", "Switch to Dark Mode");
      } else {
        // If the checkbox is unchecked, it means the Dark Mode is currently active.
        // So, the tooltip should instruct the user to switch to Light Mode.
        themeSwitchLabel.setAttribute("data-tooltip", "Switch to Light Mode");
      }
    }
  }

  // Function to apply a specific theme ('light' or 'dark')
  function applyTheme(theme) {
    const body = document.body;
    const themeToggleCheckbox = document.getElementById("themeToggle");

    if (theme === "light") {
      body.classList.add("light-theme");
      if (themeToggleCheckbox) {
        themeToggleCheckbox.checked = true; // Set checkbox to checked for light mode
      }
      localStorage.setItem("theme", "light"); // Save preference to local storage
    } else {
      body.classList.remove("light-theme");
      if (themeToggleCheckbox) {
        themeToggleCheckbox.checked = false; // Set checkbox to unchecked for dark mode
      }
      localStorage.setItem("theme", "dark"); // Save preference to local storage
    }
    // Crucially, update the tooltip text immediately after the theme changes
    updateThemeToggleTooltip();
  }

  // Event handler for when the theme toggle checkbox's state changes
  function handleThemeToggleChange() {
    // 'this' refers to the checkbox element because it's the event target
    const isLightModeSelected = this.checked;

    if (isLightModeSelected) {
      applyTheme("light");
    } else {
      applyTheme("dark");
    }
  }

  // --- Event Listeners & Initialization ---

  // Expose functions to the global window object if needed for inline event handlers
  // (though adding event listeners via JS is generally preferred)
  window.confirmReload = confirmReload;
  window.confirmHomeRedirect = confirmHomeRedirect;

  // DOMContentLoaded ensures the script runs after the HTML is fully loaded
  document.addEventListener("DOMContentLoaded", function () {
    // Event listener for the "Reload Page" link (if it exists)
    document
      .getElementById("reloadLink")
      ?.addEventListener("click", function (e) {
        if (!confirmReload()) {
          e.preventDefault(); // Prevent default link navigation if user cancels reload
        }
      });

    // Event listener for the "Back to Top" button (if it exists)
    document
      .querySelector(".back-to-top")
      ?.addEventListener("click", scrollToTop);

    // Initialize the theme on page load:
    // 1. Retrieve the saved theme preference from local storage.
    // 2. If no preference is found, default to 'dark' mode.
    const savedTheme = localStorage.getItem("theme") || "dark";
    applyTheme(savedTheme); // Apply the retrieved or default theme

    // Attach the change event listener to the theme toggle checkbox (if it exists)
    // This handles user interaction to switch themes
    document
      .getElementById("themeToggle")
      ?.addEventListener("change", handleThemeToggleChange);
  });

  // Attach event listener for the 'pageshow' event to handle browser BF cache
  window.addEventListener("pageshow", handlePageShow);
})(window, document);

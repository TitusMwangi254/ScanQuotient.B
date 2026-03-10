document.addEventListener("DOMContentLoaded", () => {
  // --- Toast Message Functionality (UPDATED) ---
  function showToast(message, type = "info") {
    const toastContainer = document.getElementById("toast-container");
    if (!toastContainer) {
      console.error("Toast container not found!");
      return;
    }

    const toastElement = document.createElement("div");
    toastElement.className = `toast ${type}`;
    toastElement.textContent = message;

    toastContainer.appendChild(toastElement);

    setTimeout(() => {
      toastElement.classList.add("show");
    }, 100);

    setTimeout(() => {
      toastElement.style.right = "-300px";
      toastElement.style.opacity = "0";

      toastElement.addEventListener(
        "transitionend",
        () => {
          if (toastContainer.contains(toastElement)) {
            toastContainer.removeChild(toastElement);
          }
        },
        { once: true },
      );
    }, 3000);
  }

  // Call the showToast function if there's a message from PHP
  const toastMessageData = window.toast_message_data;
  if (toastMessageData) {
    let toastType = "success";
    if (
      toastMessageData.includes("failed") ||
      toastMessageData.includes("error")
    ) {
      toastType = "error";
    } else if (toastMessageData.includes("but one confirmation email failed")) {
      toastType = "info";
    } else if (toastMessageData.includes("successfully")) {
      toastType = "success";
    }
    showToast(toastMessageData, toastType);
  }

  // --- Logout confirmation ---
  const logoutLink = document.getElementById("logout-link");
  if (logoutLink) {
    logoutLink.addEventListener("click", function (event) {
      if (!confirm("Are you sure you want to log out?")) {
        event.preventDefault();
      }
    });
  }

  // --- Home page reload confirmation (for header home button) ---
  const homeReloadLink = document.getElementById("home-reload-link");
  if (homeReloadLink) {
    homeReloadLink.addEventListener("click", function (event) {
      if (!confirm("Are you sure you want to go back to the home-page?")) {
        event.preventDefault();
      }
    });
  }

  // --- Attachment file list and remove functionality ---
  const attachmentInput = document.getElementById("attachment");
  if (attachmentInput) {
    attachmentInput.addEventListener("change", function (event) {
      const fileList = document.getElementById("file-list");
      fileList.innerHTML = "";

      let currentFiles = Array.from(event.target.files);

      const renderFileList = () => {
        fileList.innerHTML = "";
        currentFiles.forEach((file, index) => {
          const listItem = document.createElement("li");
          listItem.textContent = file.name + " ";

          const removeBtn = document.createElement("button");
          removeBtn.textContent = "Remove";
          removeBtn.type = "button";
          removeBtn.classList.add("remove-button");
          removeBtn.style.marginLeft = "10px";
          removeBtn.addEventListener("click", function () {
            currentFiles.splice(index, 1);

            const dt = new DataTransfer();
            currentFiles.forEach((f) => dt.items.add(f));
            event.target.files = dt.files;

            renderFileList();
          });

          listItem.appendChild(removeBtn);
          fileList.appendChild(listItem);
        });
      };

      renderFileList();
    });
  }

  // --- Theme Toggle Logic (ICON ONLY) ---
  const themeToggle = document.getElementById("theme-toggle");
  const themeIcon = document.getElementById("theme-icon");
  const body = document.body;

  const setTheme = (theme) => {
    if (theme === "light") {
      body.classList.add("light-theme");
      body.classList.remove("dark-theme");
      localStorage.setItem("theme", "light");
      if (themeIcon) {
        themeIcon.classList.remove("fa-sun");
        themeIcon.classList.add("fa-moon");
      }
      if (themeToggle) {
        themeToggle.setAttribute("data-tooltip", "Switch to Dark Mode");
      }
    } else {
      body.classList.remove("light-theme");
      body.classList.add("dark-theme");
      localStorage.setItem("theme", "dark");
      if (themeIcon) {
        themeIcon.classList.remove("fa-moon");
        themeIcon.classList.add("fa-sun");
      }
      if (themeToggle) {
        themeToggle.setAttribute("data-tooltip", "Switch to Light Mode");
      }
    }
  };

  // Initialize theme based on localStorage or OS preference
  const savedTheme = localStorage.getItem("theme");
  if (savedTheme) {
    setTheme(savedTheme);
  } else {
    const prefersDarkMode = window.matchMedia(
      "(prefers-color-scheme: dark)",
    ).matches;
    setTheme(prefersDarkMode ? "dark" : "light");
  }

  // Event listener for theme toggle (click instead of change)
  if (themeToggle) {
    themeToggle.addEventListener("click", () => {
      const isLight = body.classList.contains("light-theme");
      if (isLight) {
        setTheme("dark");
      } else {
        setTheme("light");
      }
    });
  }

  // --- Collapsible Menu Functionality ---
  const menuToggle = document.getElementById("menu-toggle");
  const leftSection = document.getElementById("left-section");
  const verticalDivider = document.getElementById("vertical-divider");

  if (menuToggle && leftSection && verticalDivider) {
    menuToggle.addEventListener("click", () => {
      leftSection.classList.toggle("collapsed");
      menuToggle.classList.toggle("collapsed");
      verticalDivider.classList.toggle("collapsed");
    });
  }

  // --- Main Content Section Toggling ---
  const welcomeMessageElement = document.getElementById("welcome-message");
  const notificationsContainer = document.getElementById(
    "notifications-container",
  );
  const urgentAssistance = document.getElementById("urgent-assistance");
  const ticketFormContainer = document.getElementById("ticket-form-container");
  const viewPreviousTicketsContainer = document.getElementById(
    "view-previous-tickets-container",
  );
  const trackTicketProgressContainer = document.getElementById(
    "track-ticket-progress-container",
  );

  const hideAllContentSections = () => {
    if (welcomeMessageElement) welcomeMessageElement.style.display = "none";
    if (notificationsContainer) notificationsContainer.style.display = "none";
    if (urgentAssistance) urgentAssistance.style.display = "none";
    if (ticketFormContainer) ticketFormContainer.style.display = "none";
    if (viewPreviousTicketsContainer)
      viewPreviousTicketsContainer.style.display = "none";
    if (trackTicketProgressContainer)
      trackTicketProgressContainer.style.display = "none";
  };

  const showContentSections = (sectionsToShow) => {
    hideAllContentSections();
    sectionsToShow.forEach((section) => {
      if (section) section.style.display = "block";
    });
  };

  // --- Navigation "Like-Boxes" Event Listeners ---
  const homeButton = document.getElementById("home-button");
  const createTicketBox = document.getElementById("create-ticket-box");
  const createTicketLink = document.getElementById("create-ticket-link");
  const viewPrevTicketsBox = document.getElementById("view-prev-tickets-box");
  const trackTicketBox = document.getElementById("track-ticket-box");
  const faqBox = document.getElementById("faq-box");

  if (homeButton) {
    homeButton.addEventListener("click", () => {
      showContentSections([
        welcomeMessageElement,
        notificationsContainer,
        urgentAssistance,
      ]);
    });
  }

  if (createTicketBox) {
    createTicketBox.addEventListener("click", () => {
      showContentSections([ticketFormContainer]);
    });
  }

  if (createTicketLink) {
    createTicketLink.addEventListener("click", (e) => {
      e.preventDefault();
      showContentSections([ticketFormContainer]);
    });
  }

  if (viewPrevTicketsBox) {
    viewPrevTicketsBox.addEventListener("click", () => {
      showContentSections([viewPreviousTicketsContainer]);
    });
  }

  if (trackTicketBox) {
    trackTicketBox.addEventListener("click", () => {
      showContentSections([trackTicketProgressContainer]);
    });
  }

  if (faqBox) {
    faqBox.addEventListener("click", () => {
      window.location.href = "../../../Homepage/PHP/Frontend/FAQ.php";
    });
  }

  // --- Ticket Tracking/Viewing Logic ---
  const viewTicketInput = document.getElementById("view-ticket-id-input");
  const viewTicketButton = document.getElementById("view-ticket-button");
  const trackTicketInput = document.getElementById("track-ticket-id-input");
  const trackTicketButton = document.getElementById(
    "track-ticket-progress-button",
  );

  const openTicketDetails = (ticketId, inputElement) => {
    if (!ticketId) {
      showToast("Please enter a Ticket ID.", "error");
      inputElement.focus();
      return;
    }

    const ticketDetailsPage = "../Frontend/user_ticket_tracking.php";
    const url = `${ticketDetailsPage}?id=${encodeURIComponent(ticketId)}`;

    window.location.href = url;
  };

  if (viewTicketButton && viewTicketInput) {
    viewTicketButton.addEventListener("click", () => {
      openTicketDetails(viewTicketInput.value.trim(), viewTicketInput);
    });
    viewTicketInput.addEventListener("keypress", (e) => {
      if (e.key === "Enter") {
        e.preventDefault();
        viewTicketButton.click();
      }
    });
  }

  if (trackTicketButton && trackTicketInput) {
    trackTicketButton.addEventListener("click", () => {
      openTicketDetails(trackTicketInput.value.trim(), trackTicketInput);
    });
    trackTicketInput.addEventListener("keypress", (e) => {
      if (e.key === "Enter") {
        e.preventDefault();
        trackTicketButton.click();
      }
    });
  }

  // --- Back to Top Button functionality ---
  const backToTopButton = document.querySelector(".back-to-top");
  if (backToTopButton) {
    backToTopButton.addEventListener("click", (e) => {
      e.preventDefault();
      window.scrollTo({
        top: 0,
        behavior: "smooth",
      });
    });
  }

  // --- Initial page state on load ---
  showContentSections([
    welcomeMessageElement,
    notificationsContainer,
    urgentAssistance,
  ]);
});

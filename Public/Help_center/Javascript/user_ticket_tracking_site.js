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

function openModal(modal, modalContent) {
  if (!modal || !modalContent) return;
  modal.classList.remove("hidden");
  setTimeout(() => {
    modalContent.classList.remove("scale-95", "opacity-0");
    modalContent.classList.add("scale-100", "opacity-100");
  }, 10);
}

function closeModal(modal, modalContent) {
  if (!modal || !modalContent) return;
  modalContent.classList.remove("scale-100", "opacity-100");
  modalContent.classList.add("scale-95", "opacity-0");
  setTimeout(() => {
    modal.classList.add("hidden");
  }, 200);
}

const closeTicketModal = document.getElementById("closeTicketModal");
const closeModalContent = document.getElementById("modalContent");
const openCloseModalBtn = document.getElementById("openCloseModalBtn");
const cancelCloseBtn = document.getElementById("cancelCloseBtn");

if (openCloseModalBtn) {
  openCloseModalBtn.addEventListener("click", () => {
    openModal(closeTicketModal, closeModalContent);
  });
}

if (cancelCloseBtn) {
  cancelCloseBtn.addEventListener("click", () => closeModal(closeTicketModal, closeModalContent));
}

if (closeTicketModal) {
  closeTicketModal.addEventListener("click", (e) => {
    if (e.target === closeTicketModal) closeModal(closeTicketModal, closeModalContent);
  });
}

const attachmentUploadModal = document.getElementById("attachmentUploadModal");
const attachmentModalContent = document.getElementById("attachmentModalContent");
const openAttachmentModalBtn = document.getElementById("openAttachmentModalBtn");
const cancelAttachmentBtn = document.getElementById("cancelAttachmentBtn");
const confirmAttachmentBtn = document.getElementById("confirmAttachmentBtn");
const addAttachmentsForm = document.getElementById("add-attachments-form");
const attachmentInput = document.getElementById("ticket-attachments");
const attachmentFileList = document.getElementById("attachment-file-list");

const MAX_FILE_SIZE = 5 * 1024 * 1024;
const ALLOWED_EXTENSIONS = ["jpg", "jpeg", "png", "pdf", "txt"];

function getSelectedAttachmentFiles() {
  if (!attachmentInput || !attachmentInput.files) return [];
  return Array.from(attachmentInput.files);
}

function validateAttachmentSelection(files) {
  if (!files.length) {
    return "Please select at least one file to upload.";
  }

  for (const file of files) {
    const ext = file.name.split(".").pop().toLowerCase();
    if (!ALLOWED_EXTENSIONS.includes(ext)) {
      return `File type not allowed: ${file.name}`;
    }
    if (file.size > MAX_FILE_SIZE) {
      return `File exceeds 5 MB: ${file.name}`;
    }
  }

  return "";
}

function renderAttachmentFileList() {
  if (!attachmentFileList || !attachmentInput) return;

  attachmentFileList.innerHTML = "";
  const files = getSelectedAttachmentFiles();

  files.forEach((file, index) => {
    const li = document.createElement("li");
    li.className = "attachment-file-list-item";

    const nameSpan = document.createElement("span");
    nameSpan.textContent = file.name;

    const removeBtn = document.createElement("button");
    removeBtn.type = "button";
    removeBtn.className = "attachment-file-remove";
    removeBtn.textContent = "Remove";
    removeBtn.addEventListener("click", () => {
      const dt = new DataTransfer();
      files.forEach((f, i) => {
        if (i !== index) dt.items.add(f);
      });
      attachmentInput.files = dt.files;
      renderAttachmentFileList();
    });

    li.appendChild(nameSpan);
    li.appendChild(removeBtn);
    attachmentFileList.appendChild(li);
  });
}

if (attachmentInput) {
  attachmentInput.addEventListener("change", renderAttachmentFileList);
}

if (openAttachmentModalBtn) {
  openAttachmentModalBtn.addEventListener("click", () => {
    const error = validateAttachmentSelection(getSelectedAttachmentFiles());
    if (error) {
      window.alert(error);
      return;
    }
    openModal(attachmentUploadModal, attachmentModalContent);
  });
}

if (cancelAttachmentBtn) {
  cancelAttachmentBtn.addEventListener("click", () => {
    closeModal(attachmentUploadModal, attachmentModalContent);
  });
}

if (attachmentUploadModal) {
  attachmentUploadModal.addEventListener("click", (e) => {
    if (e.target === attachmentUploadModal) {
      closeModal(attachmentUploadModal, attachmentModalContent);
    }
  });
}

if (confirmAttachmentBtn && addAttachmentsForm) {
  confirmAttachmentBtn.addEventListener("click", () => {
    const error = validateAttachmentSelection(getSelectedAttachmentFiles());
    if (error) {
      window.alert(error);
      return;
    }

    confirmAttachmentBtn.disabled = true;
    confirmAttachmentBtn.innerHTML =
      '<i class="fas fa-spinner fa-spin"></i> Uploading...';
    if (openAttachmentModalBtn) {
      openAttachmentModalBtn.disabled = true;
      openAttachmentModalBtn.classList.add("attachment-upload-btn--loading");
      openAttachmentModalBtn.innerHTML =
        '<i class="fas fa-spinner fa-spin"></i> Uploading...';
    }

    addAttachmentsForm.submit();
  });
}

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

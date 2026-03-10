// Validation Rules
const validationRules = {
  name: {
    pattern: /^[a-zA-Z\s'-]{2,50}$/,
    message: "Please enter a valid name (letters only, 2-50 characters)",
  },
  email: {
    pattern: /^[^\s@]+@[^\s@]+\.[^\s@]+$/,
    message: "Please enter a valid email address",
  },
  phone: {
    pattern: /^\+?[0-9\s-]{10,15}$/,
    message: "Please enter a valid phone number",
  },
  text: {
    pattern: /.*/,
    message: "This field is required",
  },
  radio: {
    validate: (group) => {
      return group.querySelector('input[type="radio"]:checked') !== null;
    },
    message: "Please select an option",
  },
  file: {
    validate: (input) => {
      if (!input.files || input.files.length === 0) return false;
      const file = input.files[0];
      const maxSize = parseInt(input.dataset.maxSize) || 5242880;
      const validTypes = ["image/jpeg", "image/png", "image/gif", "image/webp"];
      return file.size <= maxSize && validTypes.includes(file.type);
    },
    message: "Please upload a valid image file (max 5MB, JPG/PNG/GIF/WebP)",
  },
};

// Photo Preview Variables
let currentPhotoFile = null;

// Initialize validation on DOM load
document.addEventListener("DOMContentLoaded", function () {
  initializeValidation();
  setupRealTimeValidation();
  setupPhotoUpload();
});

// Setup Photo Upload and Preview
function setupPhotoUpload() {
  const fileInput = document.getElementById("passport-photo");
  const dropZone = document.getElementById("dropZone");
  const previewContainer = document.getElementById("photoPreviewContainer");
  const previewImg = document.getElementById("photoPreview");
  const placeholder = document.getElementById("photoPlaceholder");
  const filenameDiv = document.getElementById("photoFilename");
  const filesizeDiv = document.getElementById("photoFilesize");

  // File input change handler
  fileInput.addEventListener("change", function (e) {
    handleFileSelect(e.target.files[0]);
  });

  // Drag and drop handlers
  ["dragenter", "dragover", "dragleave", "drop"].forEach((eventName) => {
    dropZone.addEventListener(eventName, preventDefaults, false);
    document.body.addEventListener(eventName, preventDefaults, false);
  });

  ["dragenter", "dragover"].forEach((eventName) => {
    dropZone.addEventListener(eventName, highlight, false);
  });

  ["dragleave", "drop"].forEach((eventName) => {
    dropZone.addEventListener(eventName, unhighlight, false);
  });

  dropZone.addEventListener("drop", handleDrop, false);

  function preventDefaults(e) {
    e.preventDefault();
    e.stopPropagation();
  }

  function highlight() {
    dropZone.classList.add("drag-active");
  }

  function unhighlight() {
    dropZone.classList.remove("drag-active");
  }

  function handleDrop(e) {
    const dt = e.dataTransfer;
    const files = dt.files;
    if (files.length > 0) {
      handleFileSelect(files[0]);
    }
  }

  function handleFileSelect(file) {
    if (!file) return;

    // Validate file
    const maxSize = parseInt(fileInput.dataset.maxSize) || 5242880;
    const validTypes = ["image/jpeg", "image/png", "image/gif", "image/webp"];

    if (!validTypes.includes(file.type)) {
      showToast(
        "Invalid file type. Please use JPG, PNG, GIF, or WebP.",
        "error",
      );
      validateFileInput(fileInput);
      return;
    }

    if (file.size > maxSize) {
      showToast("File too large. Maximum size is 5MB.", "error");
      validateFileInput(fileInput);
      return;
    }

    // Store file reference
    currentPhotoFile = file;

    // Create preview
    const reader = new FileReader();
    reader.onload = function (e) {
      previewImg.src = e.target.result;
      previewImg.style.display = "block";
      placeholder.style.display = "none";

      // Show preview container
      previewContainer.classList.add("show", "has-image");

      // Update file info
      filenameDiv.textContent = file.name;
      filesizeDiv.textContent = formatFileSize(file.size);

      // Update label text
      const label = dropZone.querySelector(".file-input-label span");
      label.textContent = "Change Photo";

      // Clear any errors
      clearFileError(fileInput);
      showToast("Photo uploaded successfully!", "success");
    };

    reader.onerror = function () {
      showToast("Error reading file. Please try again.", "error");
    };

    reader.readAsDataURL(file);
  }
}

// Remove photo function
function removePhoto() {
  const fileInput = document.getElementById("passport-photo");
  const previewContainer = document.getElementById("photoPreviewContainer");
  const previewImg = document.getElementById("photoPreview");
  const placeholder = document.getElementById("photoPlaceholder");
  const filenameDiv = document.getElementById("photoFilename");
  const filesizeDiv = document.getElementById("photoFilesize");
  const dropZone = document.getElementById("dropZone");
  const label = dropZone.querySelector(".file-input-label span");

  // Reset file input
  fileInput.value = "";
  currentPhotoFile = null;

  // Reset preview
  previewImg.src = "";
  previewImg.style.display = "none";
  placeholder.style.display = "flex";

  // Hide preview container
  previewContainer.classList.remove("show", "has-image");

  // Reset file info
  filenameDiv.textContent = "No file selected";
  filesizeDiv.textContent = "";

  // Reset label
  label.textContent = "Choose Photo or Drag & Drop";

  // Trigger validation to show required error
  validateFileInput(fileInput);

  showToast("Photo removed", "error");
}

// Format file size
function formatFileSize(bytes) {
  if (bytes === 0) return "0 Bytes";
  const k = 1024;
  const sizes = ["Bytes", "KB", "MB"];
  const i = Math.floor(Math.log(bytes) / Math.log(k));
  return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + " " + sizes[i];
}

// Initialize validation setup
function initializeValidation() {
  const inputs = document.querySelectorAll("[data-validate]");
  inputs.forEach((input) => {
    if (input.type === "radio") return;

    input.addEventListener("blur", function () {
      if (this.type !== "file") {
        validateField(this);
      }
    });
  });

  const radioGroups = document.querySelectorAll('[data-validate="radio"]');
  radioGroups.forEach((group) => {
    const radios = group.querySelectorAll('input[type="radio"]');
    radios.forEach((radio) => {
      radio.addEventListener("change", function () {
        clearRadioError(group);
      });
    });
  });
}

// Real-time validation setup
function setupRealTimeValidation() {
  const textInputs = document.querySelectorAll(
    'input[type="text"], input[type="email"], input[type="tel"]',
  );
  textInputs.forEach((input) => {
    let debounceTimer;
    input.addEventListener("input", function () {
      clearTimeout(debounceTimer);
      debounceTimer = setTimeout(() => {
        if (this.value.length > 0) {
          validateField(this);
        } else {
          clearFieldError(this);
        }
      }, 300);
    });
  });
}

// Validate individual field
function validateField(field) {
  const validateType = field.dataset.validate;
  const isRequired = field.dataset.required === "true";
  const value = field.value.trim();
  const errorDiv = document.getElementById(`${field.id}-error`);
  const successIcon = field.parentElement.querySelector(
    ".validation-icon.success",
  );
  const errorIcon = field.parentElement.querySelector(".validation-icon.error");

  if (!value && isRequired) {
    showFieldError(field, errorDiv, "This field is required");
    return false;
  }

  if (!value && !isRequired) {
    clearFieldError(field);
    return true;
  }

  let isValid = true;
  let errorMessage = "";

  if (validateType === "text") {
    const minLength = parseInt(field.dataset.minLength) || 1;
    const maxLength = parseInt(field.dataset.maxLength) || 255;
    if (value.length < minLength || value.length > maxLength) {
      isValid = false;
      errorMessage = `Must be between ${minLength} and ${maxLength} characters`;
    }
  } else if (validationRules[validateType]) {
    const rule = validationRules[validateType];
    if (rule.pattern && !rule.pattern.test(value)) {
      isValid = false;
      errorMessage = rule.message;
    }
  }

  if (isValid) {
    showFieldSuccess(field, errorDiv, successIcon, errorIcon);
    return true;
  } else {
    showFieldError(field, errorDiv, errorMessage, successIcon, errorIcon);
    return false;
  }
}

// Validate file input
function validateFileInput(input) {
  const errorDiv = document.getElementById(`${input.id}-error`);
  const rule = validationRules.file;

  if (!rule.validate(input)) {
    // Don't show error border on the container, just the message
    if (errorDiv) errorDiv.classList.add("show");
    return false;
  }
  clearFileError(input);
  return true;
}

// Clear file error
function clearFileError(input) {
  const errorDiv = document.getElementById(`${input.id}-error`);
  if (errorDiv) errorDiv.classList.remove("show");
}

// Validate radio group
function validateRadioGroup(group) {
  const rule = validationRules.radio;
  const errorDiv = document.getElementById(
    `${group.id}-error`.replace("-group", "-error"),
  );

  if (!rule.validate(group)) {
    group.classList.add("error");
    if (errorDiv) errorDiv.classList.add("show");
    return false;
  }
  clearRadioError(group);
  return true;
}

// Show field error
function showFieldError(
  field,
  errorDiv,
  message,
  successIcon = null,
  errorIcon = null,
) {
  field.classList.add("input-error");
  field.classList.remove("input-success");

  if (errorDiv) {
    errorDiv.querySelector("span").textContent = message;
    errorDiv.classList.add("show");
  }

  if (successIcon) successIcon.style.display = "none";
  if (errorIcon) errorIcon.style.display = "block";
}

// Show field success
function showFieldSuccess(field, errorDiv, successIcon, errorIcon) {
  field.classList.remove("input-error");
  field.classList.add("input-success");
  if (errorDiv) errorDiv.classList.remove("show");
  if (successIcon) successIcon.style.display = "block";
  if (errorIcon) errorIcon.style.display = "none";
}

// Clear field error
function clearFieldError(field) {
  field.classList.remove("input-error", "input-success");
  const errorDiv = document.getElementById(`${field.id}-error`);
  const successIcon = field.parentElement.querySelector(
    ".validation-icon.success",
  );
  const errorIcon = field.parentElement.querySelector(".validation-icon.error");

  if (errorDiv) errorDiv.classList.remove("show");
  if (successIcon) successIcon.style.display = "none";
  if (errorIcon) errorIcon.style.display = "none";
}

// Clear radio error
function clearRadioError(group) {
  group.classList.remove("error");
  const errorDiv = document.getElementById(
    `${group.id}-error`.replace("-group", "-error"),
  );
  if (errorDiv) errorDiv.classList.remove("show");
}

// Validate entire form section
function validateSection(sectionId) {
  const section = document.getElementById(sectionId);
  const fields = section.querySelectorAll("[data-validate]");
  let isValid = true;
  const errors = [];

  fields.forEach((field) => {
    let fieldValid = true;

    if (field.type === "file") {
      fieldValid = validateFileInput(field);
    } else if (field.dataset.validate === "radio") {
      fieldValid = validateRadioGroup(field);
    } else {
      fieldValid = validateField(field);
    }

    if (!fieldValid) {
      isValid = false;
      const label = section.querySelector(`label[for="${field.id}"]`);
      errors.push(label ? label.textContent.replace(":", "") : field.name);
    }
  });

  if (!isValid) {
    section.classList.add("shake");
    setTimeout(() => section.classList.remove("shake"), 500);

    showToast(
      `Please complete all required fields: ${errors.join(", ")}`,
      "error",
    );

    const firstError = section.querySelector(
      '.input-error, .error, .show[id$="-error"]',
    );
    if (firstError) {
      if (firstError.id && firstError.id.includes("-error")) {
        const inputId = firstError.id.replace("-error", "");
        const input = document.getElementById(inputId);
        if (input) input.focus();
      } else {
        firstError.focus();
      }
    }
  }

  return isValid;
}

// Validate and proceed to next section
function validateAndProceed(nextFormId) {
  const currentForm = document.querySelector(
    '.form-container:not([style*="display: none"])',
  );
  if (!currentForm) return;

  if (validateSection(currentForm.id)) {
    showForm(nextFormId);
    updateProgress(2);
  }
}

// Show form - hides welcome content, shows form
function showForm(formId, btn = null) {
  // Hide welcome content (notifications + instructions)
  const welcomeContent = document.getElementById("welcomeContent");
  if (welcomeContent) {
    welcomeContent.style.display = "none";
  }

  // Show progress indicator
  const progress = document.getElementById("formProgress");
  if (progress) {
    progress.style.display = "flex";
  }

  // Hide all forms first
  document.querySelectorAll(".form-container").forEach((form) => {
    form.style.display = "none";
  });

  // Show target form
  const targetForm = document.getElementById(formId);
  if (targetForm) {
    targetForm.style.display = "block";

    // Update progress steps
    if (formId === "userForm") {
      updateProgress(1);
    } else if (formId === "emergencyForm") {
      updateProgress(2);
    }
  }

  // Update button states on left panel
  if (btn) {
    document
      .querySelectorAll(".left-section .btn")
      .forEach((b) => b.classList.remove("active"));
    btn.classList.add("active");
  }
}

// Go back to welcome content
function showWelcome() {
  document.querySelectorAll(".form-container").forEach((form) => {
    form.style.display = "none";
  });

  const progress = document.getElementById("formProgress");
  if (progress) {
    progress.style.display = "none";
  }

  const welcomeContent = document.getElementById("welcomeContent");
  if (welcomeContent) {
    welcomeContent.style.display = "block";
  }

  document
    .querySelectorAll(".left-section .btn")
    .forEach((b) => b.classList.remove("active"));
}

// Update progress indicator
function updateProgress(step) {
  const step1 = document.getElementById("step1");
  const step2 = document.getElementById("step2");
  const line1 = document.getElementById("line1");

  if (step === 1) {
    step1.classList.add("active");
    step1.classList.remove("completed");
    step2.classList.remove("active", "completed");
    line1.classList.remove("completed");
  } else if (step === 2) {
    step1.classList.remove("active");
    step1.classList.add("completed");
    step2.classList.add("active");
    line1.classList.add("completed");
  }
}

// Show toast notification
function showToast(message, type = "error") {
  const toast = document.getElementById("toast");
  const toastMessage = document.getElementById("toastMessage");
  const icon = toast.querySelector("i");

  toastMessage.textContent = message;
  toast.className = `toast ${type} show`;

  if (type === "success") {
    icon.className = "fas fa-check-circle";
  } else {
    icon.className = "fas fa-exclamation-circle";
  }

  setTimeout(() => {
    toast.classList.remove("show");
  }, 5000);
}

// Form submission handler
document
  .getElementById("registrationForm")
  .addEventListener("submit", function (e) {
    e.preventDefault(); // required because we are using fetch

    const userFormValid = validateSection("userForm");
    const emergencyFormValid = validateSection("emergencyForm");

    if (!userFormValid || !emergencyFormValid) {
      showToast("Please correct the errors before submitting.", "error");
      return;
    }

    const email = document.getElementById("email").value;
    const recoveryEmail = document.getElementById("recovery-email").value;

    if (email === recoveryEmail) {
      showToast(
        "Recovery email must be different from primary email.",
        "error",
      );
      return;
    }

    const formData = new FormData(this);
    const submitBtn = document.getElementById("submitBtn");

    submitBtn.disabled = true;

    fetch(this.action, {
      method: "POST",
      body: formData,
    })
      .then((response) => response.json())
      .then((data) => {
        submitBtn.disabled = false;

        if (data.status === "success") {
          // Redirect to email verification page
          window.location.href = data.redirect;
        } else {
          showToast(data.message, "error");

          if (data.back) {
            setTimeout(() => {
              window.location.href = data.back;
            }, 2500);
          }
        }
      })
      .catch(() => {
        submitBtn.disabled = false;
        showToast("Unexpected error occurred.", "error");
      });
  });

// Prevent form submission on Enter key
document.querySelectorAll("input").forEach((input) => {
  input.addEventListener("keypress", function (e) {
    if (e.key === "Enter" && this.type !== "submit") {
      e.preventDefault();
      const inputs = Array.from(
        document.querySelectorAll("#userForm input, #emergencyForm input"),
      );
      const currentIndex = inputs.indexOf(this);
      if (currentIndex < inputs.length - 1) {
        inputs[currentIndex + 1].focus();
      }
    }
  });
});

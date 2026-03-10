<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>ScanQuotient_Registration Page</title>
  <!-- Font Awesome for icons -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" />
  <link rel="stylesheet" href="../../CSS/registration_forms.css" type="text/css" />

  <style>
    /* Validation Styles */
    .error-message {
      color: #e74c3c;
      font-size: 0.85rem;
      margin-top: 5px;
      display: none;
      align-items: center;
      gap: 5px;
    }

    .error-message.show {
      display: flex;
    }

    .input-error {
      border-color: #e74c3c !important;
      background-color: rgba(231, 76, 60, 0.05);
    }

    .input-success {
      border-color: #27ae60 !important;
      background-color: rgba(39, 174, 96, 0.05);
    }

    .validation-icon {
      position: absolute;
      right: 10px;
      top: 50%;
      transform: translateY(-50%);
      font-size: 1rem;
    }

    .validation-icon.success {
      color: #27ae60;
    }

    .validation-icon.error {
      color: #e74c3c;
    }

    .input-wrapper {
      position: relative;
      margin-bottom: 15px;
    }

    .radio-group.error {
      border: 2px solid #e74c3c;
      border-radius: 8px;
      padding: 10px;
    }

    /* Progress indicator */
    .form-progress {
      display: flex;
      justify-content: center;
      margin-bottom: 20px;
      gap: 10px;
    }

    .progress-step {
      width: 30px;
      height: 30px;
      border-radius: 50%;
      background: #bdc3c7;
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: bold;
      color: white;
      transition: all 0.3s ease;
    }

    .progress-step.active {
      background: #3498db;
    }

    .progress-step.completed {
      background: #27ae60;
    }

    .progress-line {
      width: 50px;
      height: 3px;
      background: #bdc3c7;
      align-self: center;
      transition: all 0.3s ease;
    }

    .progress-line.completed {
      background: #27ae60;
    }

    /* Shake animation for errors */
    @keyframes shake {

      0%,
      100% {
        transform: translateX(0);
      }

      10%,
      30%,
      50%,
      70%,
      90% {
        transform: translateX(-5px);
      }

      20%,
      40%,
      60%,
      80% {
        transform: translateX(5px);
      }
    }

    .shake {
      animation: shake 0.5s ease-in-out;
    }

    /* Disabled button state */
    button:disabled {
      opacity: 0.6;
      cursor: not-allowed;
    }

    /* Toast notification */
    .toast {
      position: fixed;
      top: 20px;
      right: 20px;
      background: #e74c3c;
      color: white;
      padding: 15px 20px;
      border-radius: 8px;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
      display: none;
      align-items: center;
      gap: 10px;
      z-index: 1000;
      animation: slideIn 0.3s ease;
    }

    .toast.show {
      display: flex;
    }

    .toast.success {
      background: #27ae60;
    }

    @keyframes slideIn {
      from {
        transform: translateX(100%);
        opacity: 0;
      }

      to {
        transform: translateX(0);
        opacity: 1;
      }
    }

    /* Required field indicator */
    .required::after {
      content: " *";
      color: #e74c3c;
    }

    /* Loading state */
    .btn-loading {
      position: relative;
      color: transparent !important;
    }

    .btn-loading::after {
      content: "";
      position: absolute;
      width: 20px;
      height: 20px;
      top: 50%;
      left: 50%;
      margin-left: -10px;
      margin-top: -10px;
      border: 2px solid #ffffff;
      border-radius: 50%;
      border-top-color: transparent;
      animation: spinner 0.8s linear infinite;
    }

    @keyframes spinner {
      to {
        transform: rotate(360deg);
      }
    }

    /* Initial state - hide forms, show notifications */
    .form-container {
      display: none;
    }

    /* Welcome content visible by default */
    #welcomeContent {
      display: block;
    }

    /* Hidden state */
    .hidden {
      display: none !important;
    }

    /* Profile Photo Preview Styles */
    .photo-preview-container {
      margin-top: 10px;
      display: none;
      flex-direction: column;
      align-items: center;
      gap: 10px;
      padding: 15px;
      background: #f8f9fa;
      border-radius: 12px;
      border: 2px dashed #dee2e6;
      transition: all 0.3s ease;
    }

    .photo-preview-container.show {
      display: flex;
    }

    .photo-preview-container.has-image {
      border-style: solid;
      border-color: #27ae60;
      background: rgba(39, 174, 96, 0.05);
    }

    .photo-preview-wrapper {
      position: relative;
      width: 150px;
      height: 150px;
      border-radius: 50%;
      overflow: hidden;
      box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
      background: #fff;
    }

    .photo-preview-wrapper img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      display: block;
    }

    .photo-preview-placeholder {
      width: 100%;
      height: 100%;
      display: flex;
      align-items: center;
      justify-content: center;
      background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      color: white;
      font-size: 3rem;
    }

    .photo-remove-btn {
      position: absolute;
      top: -5px;
      right: -5px;
      width: 30px;
      height: 30px;
      border-radius: 50%;
      background: #e74c3c;
      color: white;
      border: 3px solid white;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 0.8rem;
      transition: all 0.2s ease;
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
    }

    .photo-remove-btn:hover {
      background: #c0392b;
      transform: scale(1.1);
    }

    .photo-info {
      text-align: center;
      font-size: 0.85rem;
      color: #666;
    }

    .photo-info .filename {
      font-weight: 600;
      color: #333;
      word-break: break-all;
      max-width: 200px;
    }

    .photo-info .filesize {
      color: #27ae60;
      font-weight: 500;
    }

    .file-input-wrapper {
      position: relative;
      overflow: hidden;
      display: inline-block;
      width: 100%;
    }

    .file-input-wrapper input[type=file] {
      position: absolute;
      left: -9999px;
    }

    .file-input-label {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 10px;
      padding: 12px 20px;
      background: #3498db;
      color: white;
      border-radius: 8px;
      cursor: pointer;
      transition: all 0.3s ease;
      font-weight: 500;
    }

    .file-input-label:hover {
      background: #2980b9;
      transform: translateY(-2px);
      box-shadow: 0 4px 12px rgba(52, 152, 219, 0.3);
    }

    .file-input-label i {
      font-size: 1.2rem;
    }

    .drag-active {
      border-color: #3498db !important;
      background: rgba(52, 152, 219, 0.1) !important;
    }

    /* Responsive adjustments */
    @media (max-width: 768px) {
      .photo-preview-wrapper {
        width: 120px;
        height: 120px;
      }
    }
  </style>
</head>

<body>

  <!-- Toast Notification -->
  <div id="toast" class="toast">
    <i class="fas fa-exclamation-circle"></i>
    <span id="toastMessage">Please fix the errors before proceeding</span>
  </div>

  <!-- Header -->
  <header class="header">
    <div id="reloadLink" class="home-link">
      <div class="brand-container">
        <div class="brand">ScanQuotient</div>
        <div class="tagline">Quantifying Risk.Strengthening Security</div>
      </div>
    </div>
    <div class="header-buttons">
      <label class="theme-switch" for="themeToggle" data-tooltip="Switch to Light Mode">
        <input type="checkbox" id="themeToggle" />
        <span class="slider round">
          <i class="fas fa-sun sun-icon"></i>
          <i class="fas fa-moon moon-icon"></i>
        </span>
      </label>
      <div class="header-buttons">
        <button id="helpBtn" class="application-btn"
          onclick="window.location.href='/ScanQuotient/ScanQuotient/Publicpages/Ticket_page/PHP/Frontend/tickets_page_site.php';"
          data-tooltip="Help" aria-label="Help">
          <i class="fas fa-question-circle"></i>
        </button>

        <button id="homeBtn" class="home-btn"
          onclick="window.location.href='../../../Home_page/PHP/Frontend/homepage.php';" data-tooltip="Back to homepage"
          aria-label="Back to homepage">
          <i class="fas fa-home"></i>
        </button>
      </div>
    </div>
  </header>

  <!-- Page Title -->
  <div class="page-title">Registration Form</div>

  <div class="container">
    <!-- Left Section -->
    <div class="left-section">
      <p class="left-message">Click the button to display the corresponding form.</p>
      <button class="btn" id="userRegBtn" onclick="showForm('userForm', this)">User Registration</button>
    </div>

    <!-- Right Section -->
    <div class="right-section">

      <!-- Welcome Content (Notifications + Instructions) - Shown by default -->
      <div id="welcomeContent">
        <!-- Notifications -->
        <div id="alertsSection">
          <h2>Notifications</h2>
          <p><i class="fas fa-bell-slash"></i>No notifications yet.</p>
        </div>

        <!-- Instruction Card -->
        <div class="instructions card">
          <strong>Note:</strong>
          Use the buttons on the left to register your details.<br />
          Already registered? <a
            href="/ScanQuotient/ScanQuotient/Publicpages/Login_Page/PHP/Frontend/login_page_site.php">Log in
            now</a>.<br />
          <strong>Already applied?</strong> Check application status via the "Application Status" button above.<br />
          Need support? <a
            href="/ScanQuotient/ScanQuotient/Publicpages/Ticket_page/PHP/Frontend/tickets_page_site.php">We're here to
            help</a>.
        </div>
      </div>

      <!-- Progress Indicator - Hidden by default -->
      <div class="form-progress" id="formProgress" style="display: none;">
        <div class="progress-step active" id="step1">1</div>
        <div class="progress-line" id="line1"></div>
        <div class="progress-step" id="step2">2</div>
      </div>

      <form id="registrationForm" action="../Backend/submit_user_registration_details.php" method="post"
        enctype="multipart/form-data" novalidate>

        <!-- User Registration Form -->
        <div id="userForm" class="form-container">
          <h2>User Registration</h2>

          <div class="input-wrapper">
            <label for="first-name" class="required">First Name:</label>
            <input type="text" id="first-name" name="first-name" placeholder="Enter first name" data-validate="name"
              data-required="true" />
            <i class="fas fa-check-circle validation-icon success" style="display: none;"></i>
            <i class="fas fa-times-circle validation-icon error" style="display: none;"></i>
            <div class="error-message" id="first-name-error">
              <i class="fas fa-exclamation-triangle"></i>
              <span>Please enter a valid first name (letters only, 2-50 characters)</span>
            </div>
          </div>

          <div class="input-wrapper">
            <label for="middle-name">Middle Name:</label>
            <input type="text" id="middle-name" name="middle-name" placeholder="Enter middle name" data-validate="name"
              data-required="false" />
            <i class="fas fa-check-circle validation-icon success" style="display: none;"></i>
            <i class="fas fa-times-circle validation-icon error" style="display: none;"></i>
            <div class="error-message" id="middle-name-error">
              <i class="fas fa-exclamation-triangle"></i>
              <span>Please enter a valid middle name (letters only)</span>
            </div>
          </div>

          <div class="input-wrapper">
            <label for="surname" class="required">Surname:</label>
            <input type="text" id="surname" name="surname" placeholder="Enter surname" data-validate="name"
              data-required="true" />
            <i class="fas fa-check-circle validation-icon success" style="display: none;"></i>
            <i class="fas fa-times-circle validation-icon error" style="display: none;"></i>
            <div class="error-message" id="surname-error">
              <i class="fas fa-exclamation-triangle"></i>
              <span>Please enter a valid surname (letters only, 2-50 characters)</span>
            </div>
          </div>

          <!-- Gender Radios -->
          <div class="input-wrapper">
            <label for="gender-group" id="gender-group-label" class="required">Gender:</label>
            <div class="radio-group" id="gender-group" data-validate="radio" data-required="true">
              <label class="radio-wrapper">
                <input type="radio" id="gender-male" name="gender" value="male" />
                <span class="checkmark"></span> Male
              </label>
              <label class="radio-wrapper">
                <input type="radio" id="gender-female" name="gender" value="female" />
                <span class="checkmark"></span> Female
              </label>
              <label class="radio-wrapper">
                <input type="radio" id="gender-other" name="gender" value="other" />
                <span class="checkmark"></span> Other
              </label>
            </div>
            <div class="error-message" id="gender-error">
              <i class="fas fa-exclamation-triangle"></i>
              <span>Please select a gender</span>
            </div>
          </div>

          <div class="input-wrapper">
            <label for="phone" class="required">Phone Number:</label>
            <input type="tel" id="phone" name="phone" placeholder="Enter phone number (e.g., +254712345678)"
              data-validate="phone" data-required="true" />
            <i class="fas fa-check-circle validation-icon success" style="display: none;"></i>
            <i class="fas fa-times-circle validation-icon error" style="display: none;"></i>
            <div class="error-message" id="phone-error">
              <i class="fas fa-exclamation-triangle"></i>
              <span>Please enter a valid phone number (10-15 digits, optional + prefix)</span>
            </div>
          </div>

          <div class="input-wrapper">
            <label for="email" class="required">Email:</label>
            <input type="email" id="email" name="email" placeholder="Enter email address" data-validate="email"
              data-required="true" />
            <i class="fas fa-check-circle validation-icon success" style="display: none;"></i>
            <i class="fas fa-times-circle validation-icon error" style="display: none;"></i>
            <div class="error-message" id="email-error">
              <i class="fas fa-exclamation-triangle"></i>
              <span>Please enter a valid email address</span>
            </div>
          </div>

          <!-- Enhanced Profile Photo Upload with Preview -->
          <div class="input-wrapper">
            <label for="passport-photo" class="required">Profile Photo:</label>

            <!-- File Input with Custom Styling -->
            <div class="file-input-wrapper" id="dropZone">
              <input type="file" id="passport-photo" name="passport-photo"
                accept="image/jpeg,image/png,image/gif,image/webp" data-validate="file" data-required="true"
                data-max-size="5242880" />
              <label for="passport-photo" class="file-input-label">
                <i class="fas fa-cloud-upload-alt"></i>
                <span>Choose Photo or Drag & Drop</span>
              </label>
            </div>

            <!-- Photo Preview Container -->
            <div class="photo-preview-container" id="photoPreviewContainer">
              <div class="photo-preview-wrapper" id="photoPreviewWrapper">
                <div class="photo-preview-placeholder" id="photoPlaceholder">
                  <i class="fas fa-user"></i>
                </div>
                <img id="photoPreview" src="" alt="Profile Preview" style="display: none;" />
                <button type="button" class="photo-remove-btn" id="removePhoto" onclick="removePhoto()"
                  title="Remove photo">
                  <i class="fas fa-times"></i>
                </button>
              </div>
              <div class="photo-info">
                <div class="filename" id="photoFilename">No file selected</div>
                <div class="filesize" id="photoFilesize"></div>
              </div>
            </div>

            <div class="error-message" id="passport-photo-error">
              <i class="fas fa-exclamation-triangle"></i>
              <span>Please upload a valid image file (max 5MB, JPG/PNG/GIF/WebP)</span>
            </div>
            <small style="color: #666; font-size: 0.8rem; display: block; margin-top: 5px;">
              <i class="fas fa-info-circle"></i> Accepted formats: JPG, PNG, GIF, WebP (Max 5MB)
            </small>
          </div>

          <div class="form-buttons">
            <button id="userNext" class="btn" type="button" onclick="validateAndProceed('emergencyForm')">
              Next <i class="fas fa-arrow-right"></i>
            </button>
          </div>
          <p class="already-account">Already have an account? <a href="login.php">Login</a></p>
        </div>

        <!-- Emergency Information Form -->
        <div id="emergencyForm" class="form-container" style="display: none;">
          <h2>Recovery Information</h2>

          <div class="input-wrapper">
            <label for="recovery-email" class="required">Recovery Email:</label>
            <input type="email" id="recovery-email" name="recovery-email" placeholder="Enter recovery email"
              data-validate="email" data-required="true" />
            <i class="fas fa-check-circle validation-icon success" style="display: none;"></i>
            <i class="fas fa-times-circle validation-icon error" style="display: none;"></i>
            <div class="error-message" id="recovery-email-error">
              <i class="fas fa-exclamation-triangle"></i>
              <span>Please enter a valid recovery email address</span>
            </div>
          </div>

          <div class="input-wrapper">
            <label for="security-question" class="required">Security Question:</label>
            <input type="text" id="security-question" name="security-question" placeholder="Enter a security question"
              data-validate="text" data-required="true" data-min-length="10" data-max-length="200" />
            <i class="fas fa-check-circle validation-icon success" style="display: none;"></i>
            <i class="fas fa-times-circle validation-icon error" style="display: none;"></i>
            <div class="error-message" id="security-question-error">
              <i class="fas fa-exclamation-triangle"></i>
              <span>Security question must be between 10 and 200 characters</span>
            </div>
          </div>

          <div class="input-wrapper">
            <label for="security-answer" class="required">Security Answer:</label>
            <input type="text" id="security-answer" name="security-answer" placeholder="Enter your answer"
              data-validate="text" data-required="true" data-min-length="2" data-max-length="100" />
            <i class="fas fa-check-circle validation-icon success" style="display: none;"></i>
            <i class="fas fa-times-circle validation-icon error" style="display: none;"></i>
            <div class="error-message" id="security-answer-error">
              <i class="fas fa-exclamation-triangle"></i>
              <span>Security answer must be between 2 and 100 characters</span>
            </div>
          </div>

          <div class="form-buttons">
            <button class="btn btn-back" type="button" onclick="showForm('userForm')">
              <i class="fas fa-arrow-left"></i> Back
            </button><br><br>
            <button class="btn" type="submit" id="submitBtn">Register</button>
          </div>
        </div>
      </form>
    </div>
  </div>

  <hr class="footer-separator" />

  <footer>
    <div class="footer-brand">
      <div id="reloadLinkFooter" class="footer-link">
        <div class="brand">ScanQuotient</div>
        <div class="tagline">Quantifying Risk.Strengthening Security</div>
      </div>
    </div>

    <div class="footer-center">
      <div class="authenticity">© 2026 ScanQuotient.All rights reserved</div>
    </div>

    <div class="footer-contact">

      <a href="../../../Home_page/PHP/Frontend/privacy_policy.php" class="footer-link">Privacy Policy</a>
      <a href="../../../Home_page/PHP/Frontend/terms_of_service.php" class="footer-link">Terms of Service</a>
    </div>
  </footer>

  <!-- Back to Top Button -->
  <button type="button" class="back-to-top" aria-label="Back to Top">
    <i class="fas fa-arrow-up"></i>
  </button>

  <script>
    // Validation Rules
    const validationRules = {
      name: {
        pattern: /^[a-zA-Z\s'-]{2,50}$/,
        message: "Please enter a valid name (letters only, 2-50 characters)"
      },
      email: {
        pattern: /^[^\s@]+@[^\s@]+\.[^\s@]+$/,
        message: "Please enter a valid email address"
      },
      phone: {
        pattern: /^\+?[0-9\s-]{10,15}$/,
        message: "Please enter a valid phone number"
      },
      text: {
        pattern: /.*/,
        message: "This field is required"
      },
      radio: {
        validate: (group) => {
          return group.querySelector('input[type="radio"]:checked') !== null;
        },
        message: "Please select an option"
      },
      file: {
        validate: (input) => {
          if (!input.files || input.files.length === 0) return false;
          const file = input.files[0];
          const maxSize = parseInt(input.dataset.maxSize) || 5242880;
          const validTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
          return file.size <= maxSize && validTypes.includes(file.type);
        },
        message: "Please upload a valid image file (max 5MB, JPG/PNG/GIF/WebP)"
      }
    };

    // Photo Preview Variables
    let currentPhotoFile = null;

    // Initialize validation on DOM load
    document.addEventListener('DOMContentLoaded', function () {
      initializeValidation();
      setupRealTimeValidation();
      setupPhotoUpload();
    });

    // Setup Photo Upload and Preview
    function setupPhotoUpload() {
      const fileInput = document.getElementById('passport-photo');
      const dropZone = document.getElementById('dropZone');
      const previewContainer = document.getElementById('photoPreviewContainer');
      const previewImg = document.getElementById('photoPreview');
      const placeholder = document.getElementById('photoPlaceholder');
      const filenameDiv = document.getElementById('photoFilename');
      const filesizeDiv = document.getElementById('photoFilesize');

      // File input change handler
      fileInput.addEventListener('change', function (e) {
        handleFileSelect(e.target.files[0]);
      });

      // Drag and drop handlers
      ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
        dropZone.addEventListener(eventName, preventDefaults, false);
        document.body.addEventListener(eventName, preventDefaults, false);
      });

      ['dragenter', 'dragover'].forEach(eventName => {
        dropZone.addEventListener(eventName, highlight, false);
      });

      ['dragleave', 'drop'].forEach(eventName => {
        dropZone.addEventListener(eventName, unhighlight, false);
      });

      dropZone.addEventListener('drop', handleDrop, false);

      function preventDefaults(e) {
        e.preventDefault();
        e.stopPropagation();
      }

      function highlight() {
        dropZone.classList.add('drag-active');
      }

      function unhighlight() {
        dropZone.classList.remove('drag-active');
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
        const validTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

        if (!validTypes.includes(file.type)) {
          showToast('Invalid file type. Please use JPG, PNG, GIF, or WebP.', 'error');
          validateFileInput(fileInput);
          return;
        }

        if (file.size > maxSize) {
          showToast('File too large. Maximum size is 5MB.', 'error');
          validateFileInput(fileInput);
          return;
        }

        // Store file reference
        currentPhotoFile = file;

        // Create preview
        const reader = new FileReader();
        reader.onload = function (e) {
          previewImg.src = e.target.result;
          previewImg.style.display = 'block';
          placeholder.style.display = 'none';

          // Show preview container
          previewContainer.classList.add('show', 'has-image');

          // Update file info
          filenameDiv.textContent = file.name;
          filesizeDiv.textContent = formatFileSize(file.size);

          // Update label text
          const label = dropZone.querySelector('.file-input-label span');
          label.textContent = 'Change Photo';

          // Clear any errors
          clearFileError(fileInput);
          showToast('Photo uploaded successfully!', 'success');
        };

        reader.onerror = function () {
          showToast('Error reading file. Please try again.', 'error');
        };

        reader.readAsDataURL(file);
      }
    }

    // Remove photo function
    function removePhoto() {
      const fileInput = document.getElementById('passport-photo');
      const previewContainer = document.getElementById('photoPreviewContainer');
      const previewImg = document.getElementById('photoPreview');
      const placeholder = document.getElementById('photoPlaceholder');
      const filenameDiv = document.getElementById('photoFilename');
      const filesizeDiv = document.getElementById('photoFilesize');
      const dropZone = document.getElementById('dropZone');
      const label = dropZone.querySelector('.file-input-label span');

      // Reset file input
      fileInput.value = '';
      currentPhotoFile = null;

      // Reset preview
      previewImg.src = '';
      previewImg.style.display = 'none';
      placeholder.style.display = 'flex';

      // Hide preview container
      previewContainer.classList.remove('show', 'has-image');

      // Reset file info
      filenameDiv.textContent = 'No file selected';
      filesizeDiv.textContent = '';

      // Reset label
      label.textContent = 'Choose Photo or Drag & Drop';

      // Trigger validation to show required error
      validateFileInput(fileInput);

      showToast('Photo removed', 'error');
    }

    // Format file size
    function formatFileSize(bytes) {
      if (bytes === 0) return '0 Bytes';
      const k = 1024;
      const sizes = ['Bytes', 'KB', 'MB'];
      const i = Math.floor(Math.log(bytes) / Math.log(k));
      return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }

    // Initialize validation setup
    function initializeValidation() {
      const inputs = document.querySelectorAll('[data-validate]');
      inputs.forEach(input => {
        if (input.type === 'radio') return;

        input.addEventListener('blur', function () {
          if (this.type !== 'file') {
            validateField(this);
          }
        });
      });

      const radioGroups = document.querySelectorAll('[data-validate="radio"]');
      radioGroups.forEach(group => {
        const radios = group.querySelectorAll('input[type="radio"]');
        radios.forEach(radio => {
          radio.addEventListener('change', function () {
            clearRadioError(group);
          });
        });
      });
    }

    // Real-time validation setup
    function setupRealTimeValidation() {
      const textInputs = document.querySelectorAll('input[type="text"], input[type="email"], input[type="tel"]');
      textInputs.forEach(input => {
        let debounceTimer;
        input.addEventListener('input', function () {
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
      const isRequired = field.dataset.required === 'true';
      const value = field.value.trim();
      const errorDiv = document.getElementById(`${field.id}-error`);
      const successIcon = field.parentElement.querySelector('.validation-icon.success');
      const errorIcon = field.parentElement.querySelector('.validation-icon.error');

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

      if (validateType === 'text') {
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
        if (errorDiv) errorDiv.classList.add('show');
        return false;
      }
      clearFileError(input);
      return true;
    }

    // Clear file error
    function clearFileError(input) {
      const errorDiv = document.getElementById(`${input.id}-error`);
      if (errorDiv) errorDiv.classList.remove('show');
    }

    // Validate radio group
    function validateRadioGroup(group) {
      const rule = validationRules.radio;
      const errorDiv = document.getElementById(`${group.id}-error`.replace('-group', '-error'));

      if (!rule.validate(group)) {
        group.classList.add('error');
        if (errorDiv) errorDiv.classList.add('show');
        return false;
      }
      clearRadioError(group);
      return true;
    }

    // Show field error
    function showFieldError(field, errorDiv, message, successIcon = null, errorIcon = null) {
      field.classList.add('input-error');
      field.classList.remove('input-success');

      if (errorDiv) {
        errorDiv.querySelector('span').textContent = message;
        errorDiv.classList.add('show');
      }

      if (successIcon) successIcon.style.display = 'none';
      if (errorIcon) errorIcon.style.display = 'block';
    }

    // Show field success
    function showFieldSuccess(field, errorDiv, successIcon, errorIcon) {
      field.classList.remove('input-error');
      field.classList.add('input-success');
      if (errorDiv) errorDiv.classList.remove('show');
      if (successIcon) successIcon.style.display = 'block';
      if (errorIcon) errorIcon.style.display = 'none';
    }

    // Clear field error
    function clearFieldError(field) {
      field.classList.remove('input-error', 'input-success');
      const errorDiv = document.getElementById(`${field.id}-error`);
      const successIcon = field.parentElement.querySelector('.validation-icon.success');
      const errorIcon = field.parentElement.querySelector('.validation-icon.error');

      if (errorDiv) errorDiv.classList.remove('show');
      if (successIcon) successIcon.style.display = 'none';
      if (errorIcon) errorIcon.style.display = 'none';
    }

    // Clear radio error
    function clearRadioError(group) {
      group.classList.remove('error');
      const errorDiv = document.getElementById(`${group.id}-error`.replace('-group', '-error'));
      if (errorDiv) errorDiv.classList.remove('show');
    }

    // Validate entire form section
    function validateSection(sectionId) {
      const section = document.getElementById(sectionId);
      const fields = section.querySelectorAll('[data-validate]');
      let isValid = true;
      const errors = [];

      fields.forEach(field => {
        let fieldValid = true;

        if (field.type === 'file') {
          fieldValid = validateFileInput(field);
        } else if (field.dataset.validate === 'radio') {
          fieldValid = validateRadioGroup(field);
        } else {
          fieldValid = validateField(field);
        }

        if (!fieldValid) {
          isValid = false;
          const label = section.querySelector(`label[for="${field.id}"]`);
          errors.push(label ? label.textContent.replace(':', '') : field.name);
        }
      });

      if (!isValid) {
        section.classList.add('shake');
        setTimeout(() => section.classList.remove('shake'), 500);

        showToast(`Please complete all required fields: ${errors.join(', ')}`, 'error');

        const firstError = section.querySelector('.input-error, .error, .show[id$="-error"]');
        if (firstError) {
          if (firstError.id && firstError.id.includes('-error')) {
            const inputId = firstError.id.replace('-error', '');
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
      const currentForm = document.querySelector('.form-container:not([style*="display: none"])');
      if (!currentForm) return;

      if (validateSection(currentForm.id)) {
        showForm(nextFormId);
        updateProgress(2);
      }
    }

    // Show form - hides welcome content, shows form
    function showForm(formId, btn = null) {
      // Hide welcome content (notifications + instructions)
      const welcomeContent = document.getElementById('welcomeContent');
      if (welcomeContent) {
        welcomeContent.style.display = 'none';
      }

      // Show progress indicator
      const progress = document.getElementById('formProgress');
      if (progress) {
        progress.style.display = 'flex';
      }

      // Hide all forms first
      document.querySelectorAll('.form-container').forEach(form => {
        form.style.display = 'none';
      });

      // Show target form
      const targetForm = document.getElementById(formId);
      if (targetForm) {
        targetForm.style.display = 'block';

        // Update progress steps
        if (formId === 'userForm') {
          updateProgress(1);
        } else if (formId === 'emergencyForm') {
          updateProgress(2);
        }
      }

      // Update button states on left panel
      if (btn) {
        document.querySelectorAll('.left-section .btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
      }
    }

    // Go back to welcome content
    function showWelcome() {
      document.querySelectorAll('.form-container').forEach(form => {
        form.style.display = 'none';
      });

      const progress = document.getElementById('formProgress');
      if (progress) {
        progress.style.display = 'none';
      }

      const welcomeContent = document.getElementById('welcomeContent');
      if (welcomeContent) {
        welcomeContent.style.display = 'block';
      }

      document.querySelectorAll('.left-section .btn').forEach(b => b.classList.remove('active'));
    }

    // Update progress indicator
    function updateProgress(step) {
      const step1 = document.getElementById('step1');
      const step2 = document.getElementById('step2');
      const line1 = document.getElementById('line1');

      if (step === 1) {
        step1.classList.add('active');
        step1.classList.remove('completed');
        step2.classList.remove('active', 'completed');
        line1.classList.remove('completed');
      } else if (step === 2) {
        step1.classList.remove('active');
        step1.classList.add('completed');
        step2.classList.add('active');
        line1.classList.add('completed');
      }
    }

    // Show toast notification
    function showToast(message, type = 'error') {
      const toast = document.getElementById('toast');
      const toastMessage = document.getElementById('toastMessage');
      const icon = toast.querySelector('i');

      toastMessage.textContent = message;
      toast.className = `toast ${type} show`;

      if (type === 'success') {
        icon.className = 'fas fa-check-circle';
      } else {
        icon.className = 'fas fa-exclamation-circle';
      }

      setTimeout(() => {
        toast.classList.remove('show');
      }, 5000);
    }

    // Form submission handler
    document.getElementById('registrationForm').addEventListener('submit', function (e) {

      e.preventDefault(); // required because we are using fetch

      const userFormValid = validateSection('userForm');
      const emergencyFormValid = validateSection('emergencyForm');

      if (!userFormValid || !emergencyFormValid) {
        showToast('Please correct the errors before submitting.', 'error');
        return;
      }

      const email = document.getElementById('email').value;
      const recoveryEmail = document.getElementById('recovery-email').value;

      if (email === recoveryEmail) {
        showToast('Recovery email must be different from primary email.', 'error');
        return;
      }

      const formData = new FormData(this);
      const submitBtn = document.getElementById('submitBtn');

      submitBtn.disabled = true;

      fetch(this.action, {
        method: "POST",
        body: formData
      })
        .then(response => response.json())
        .then(data => {

          submitBtn.disabled = false;

          if (data.status === "success") {

            // Redirect to email verification page
            window.location.href = data.redirect;

          } else {

            showToast(data.message, 'error');

            if (data.back) {
              setTimeout(() => {
                window.location.href = data.back;
              }, 2500);
            }
          }

        })
        .catch(() => {

          submitBtn.disabled = false;
          showToast('Unexpected error occurred.', 'error');

        });

    });

    // Prevent form submission on Enter key
    document.querySelectorAll('input').forEach(input => {
      input.addEventListener('keypress', function (e) {
        if (e.key === 'Enter' && this.type !== 'submit') {
          e.preventDefault();
          const inputs = Array.from(document.querySelectorAll('#userForm input, #emergencyForm input'));
          const currentIndex = inputs.indexOf(this);
          if (currentIndex < inputs.length - 1) {
            inputs[currentIndex + 1].focus();
          }
        }
      });
    });
  </script>

  <script src="../../Javascript/Site_Behaviour/behaviour.js"></script>
</body>

</html>
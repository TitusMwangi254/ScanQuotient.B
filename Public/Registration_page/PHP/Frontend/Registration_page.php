<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>ScanQuotient | Registration Page</title>
  <link rel="icon" type="image/png" href="../../../../Storage/Public_images/page_icon.png" />
  <!-- Font Awesome for icons -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" />
  <link rel="stylesheet" href="../../CSS/registration_forms.css" type="text/css" />

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
        <span class="icon-toggle">
          <i class="fas fa-sun sun-icon"></i>
          <i class="fas fa-moon moon-icon"></i>
        </span>
      </label>
      <div class="header-buttons">
        <a href="../../../Help_center/PHP/Frontend/Help_center.php" id="helpBtn" class="icon-link" data-tooltip="Help"
          aria-label="Help">
          <i class="fas fa-question-circle"></i>
        </a>

        <a href="../../../Homepage/PHP/Frontend/Homepage.php" id="homeBtn" class="icon-link"
          data-tooltip="Back to homepage" aria-label="Back to homepage">
          <i class="fas fa-home"></i>
        </a>
      </div>
    </div>
  </header>

  <!-- Page Title -->
  <div class="page-title">Registration Form</div>

  <div class="container">
    <!-- Left Section -->
    <div class="left-section">
      <p class="left-message">Click the button below to display the user registration form.</p>
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
          Use the button on the left to register your details.<br />
          Already registered?
          <a href="../../../Login_page/PHP/Frontend/Login_page_site.php">
            Log in now
          </a>.<br /><br />
          <strong>Important:</strong> Please ensure that all the information you provide during registration is accurate
          and up to date.
          Need support?
          <a href="../../../Help_center/PHP/Frontend/Help_center.php">
            We're here to help
          </a>.
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
      <p>Contact us at: <a href="mailto:scanquotient@gmail.com">scanquotient@gmail.com</a></p>
    </div>
  </footer>

  <!-- Back to Top Button -->
  <button type="button" class="back-to-top" aria-label="Back to Top">
    <i class="fas fa-arrow-up"></i>
  </button>


  <script src="../../Javascript/registration_form.js" defer></script>
  <script src="../../Javascript/behaviour.js"></script>
</body>

</html>
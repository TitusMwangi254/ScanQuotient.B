<?php
// registration_completion_site.php - ScanQuotient Account Completion
session_start();
require_once __DIR__ . '/../../../security_headers.php';

// Check if user is in account completion flow
if (!isset($_SESSION['user_id']) || !isset($_SESSION['auth_mode']) || $_SESSION['auth_mode'] !== 'account_completion') {
    if (isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true) {
        $redirect = ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'super_admin')
            ? '#'
            : '#';
        header("Location: $redirect");
        exit();
    }
    header("Location: ../../../Login_page/PHP/Frontend/Login_page_site.php");
    exit();
}

$pendingUserId = $_SESSION['user_id'];
$pendingEmail = $_SESSION['user_email'] ?? '';
$firstName = $_SESSION['first_name'] ?? '';
$surname = $_SESSION['surname'] ?? '';
$authStage = $_SESSION['auth_stage'] ?? 'pending_completion';
$isAgreementsOnly = ($authStage === 'agreements_only');
$pendingAgreements = is_array($_SESSION['pending_agreements'] ?? null) ? $_SESSION['pending_agreements'] : [];

$showPrivacyStep = !$isAgreementsOnly || !empty($pendingAgreements['privacy']);
$showTermsStep = !$isAgreementsOnly || !empty($pendingAgreements['terms']);
$showSecurityStep = !$isAgreementsOnly || !empty($pendingAgreements['security']);
$showUsernameStep = !$isAgreementsOnly;
$showPasswordStep = !$isAgreementsOnly;
?>

<!DOCTYPE html>
<html lang="en" data-theme="light">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ScanQuotient | Complete Your Setup</title>
    <link rel="icon" type="image/png" href="../../../../Storage/Public_images/page_icon.png" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <link rel="stylesheet" href="../../CSS/registration_completion.css" />
</head>

<body>

    <!-- SEETHROUGH TRANSPARENT HEADER -->
    <header class="header">
        <div class="nav-container">
            <div class="brand">
                <h1>ScanQuotient</h1>
                <span class="tagline">Quantifying risk. Strengthening security.</span>
            </div>
            <div class="header-actions">

                <div class="theme-switch-wrapper">
                    <label class="theme-switch" for="theme-toggle" title="Toggle theme">
                        <input type="checkbox" id="theme-toggle">
                    </label>
                </div>

                <a href="../../../Help_center/PHP/Frontend/Help_center.php" class="icon-btn" title="Help">
                    <i class="fas fa-question-circle"></i>
                </a>
                <a href="../../../Homepage/PHP/Frontend/Homepage.php" class="icon-btn" title="Home">
                    <i class="fas fa-home"></i>
                </a>
            </div>
        </div>
    </header>

    <!-- MAIN CONTENT -->
    <main class="main-content">
        <div class="container">
            <div class="completion-card">
                <div class="page-header">
                    <h2><?php echo $isAgreementsOnly ? 'Policy Update Required' : 'Complete Your Account Setup'; ?></h2>
                    <p>
                        <?php echo $isAgreementsOnly
                            ? 'Before you continue, please review and accept the required policies.'
                            : 'Welcome to ScanQuotient! Finish setting up your account to access your security dashboard.'; ?>
                    </p>
                </div>

                <div class="user-info-banner">
                    <i class="fas fa-user-shield"></i>
                    <div class="user-info-text">
                        <h4>Setting up account for:
                            <?php echo htmlspecialchars($firstName . ' ' . $surname); ?>
                        </h4>
                        <p>
                            <?php echo htmlspecialchars($pendingEmail); ?>
                        </p>
                    </div>
                </div>

                <div class="security-tips">
                    <i class="fas fa-shield-alt"></i>
                    <p>
                        <?php if ($isAgreementsOnly): ?>
                            <strong>Notice:</strong> Your account access is temporarily paused until required policy acknowledgments are completed.
                        <?php else: ?>
                            <strong>Security tip:</strong> Choose a strong, unique password. Never reuse passwords from other
                            sites. Enable two-factor authentication when available.
                        <?php endif; ?>
                    </p>
                </div>

                <div class="progress-indicator">
                    <?php $stepNum = 1; ?>
                    <?php if ($showPrivacyStep): ?>
                        <div class="progress-step" data-step="<?php echo $stepNum; ?>">
                            <div class="progress-step-circle"><?php echo $stepNum; ?></div>
                            <div class="progress-step-label">Privacy</div>
                        </div>
                        <?php $stepNum++; ?>
                    <?php endif; ?>
                    <?php if ($showTermsStep): ?>
                        <div class="progress-step" data-step="<?php echo $stepNum; ?>">
                            <div class="progress-step-circle"><?php echo $stepNum; ?></div>
                            <div class="progress-step-label">Terms</div>
                        </div>
                        <?php $stepNum++; ?>
                    <?php endif; ?>
                    <?php if ($showSecurityStep): ?>
                        <div class="progress-step" data-step="<?php echo $stepNum; ?>">
                            <div class="progress-step-circle"><?php echo $stepNum; ?></div>
                            <div class="progress-step-label">Agreement</div>
                        </div>
                        <?php $stepNum++; ?>
                    <?php endif; ?>
                    <?php if ($showUsernameStep): ?>
                        <div class="progress-step" data-step="<?php echo $stepNum; ?>">
                            <div class="progress-step-circle"><?php echo $stepNum; ?></div>
                            <div class="progress-step-label">Username</div>
                        </div>
                        <?php $stepNum++; ?>
                    <?php endif; ?>
                    <?php if ($showPasswordStep): ?>
                        <div class="progress-step" data-step="<?php echo $stepNum; ?>">
                            <div class="progress-step-circle"><?php echo $stepNum; ?></div>
                            <div class="progress-step-label">Password</div>
                        </div>
                    <?php endif; ?>
                </div>

                <form id="completionForm"
                    action="/ScanQuotient.v2/ScanQuotient.B/Public/Registration_completion_page/PHP/Backend/complete_registration.php"
                    method="POST">
                    <input type="hidden" name="user_id" value="<?php echo htmlspecialchars($pendingUserId); ?>">
                    <input type="hidden" name="completion_mode" value="<?php echo $isAgreementsOnly ? 'agreements_only' : 'pending_completion'; ?>">

                    <!-- Step 1: Privacy Policy -->
                    <div class="step-content" data-step="1" data-kind="privacy" <?php echo $showPrivacyStep ? '' : 'hidden'; ?>>
                        <h3><i class="fas fa-lock"></i> Privacy Policy</h3>
                        <div class="policy-box">
                            <h4>1. Information We Collect</h4>
                            <p>ScanQuotient collects information necessary to provide vulnerability scanning and
                                security assessment services, including:</p>
                            <ul>
                                <li>Account information (name, email, phone)</li>
                                <li>Scan targets and results (stored securely)</li>
                                <li>Usage analytics to improve our services</li>
                                <li>IP addresses and device information for security monitoring</li>
                            </ul>

                            <h4>2. How We Use Your Data</h4>
                            <p>Your data is used exclusively for:</p>
                            <ul>
                                <li>Providing security scanning services</li>
                                <li>Generating vulnerability reports</li>
                                <li>Account authentication and security</li>
                                <li>Compliance with legal obligations</li>
                            </ul>

                            <h4>3. Data Security</h4>
                            <p>We implement industry-standard encryption, access controls, and regular security audits.
                                All scan data is encrypted at rest and in transit.</p>

                            <h4>4. Your Rights</h4>
                            <p>You have the right to access, modify, or delete your personal data. Contact
                                support@scanquotient.com for data requests.</p>
                        </div>

                        <div class="checkbox-group">
                            <input type="checkbox" id="agreePrivacy" name="agree_privacy" <?php echo $showPrivacyStep ? 'required' : ''; ?>>
                            <label for="agreePrivacy">I have read and agree to the <a href="#"
                                    onclick="return false;">Privacy Policy</a></label>
                        </div>
                    </div>

                    <!-- Step 2: Terms of Service -->
                    <div class="step-content" data-step="2" data-kind="terms" <?php echo $showTermsStep ? '' : 'hidden'; ?>>
                        <h3><i class="fas fa-file-contract"></i> Terms of Service</h3>
                        <div class="policy-box">
                            <h4>1. Service Description</h4>
                            <p>ScanQuotient provides automated vulnerability scanning and security assessment tools.
                                Users are responsible for ensuring they have authorization to scan any targets.</p>

                            <h4>2. Acceptable Use</h4>
                            <p>Users agree to:</p>
                            <ul>
                                <li>Only scan systems they own or have explicit permission to test</li>
                                <li>Not use the service for malicious purposes</li>
                                <li>Not attempt to bypass security controls</li>
                                <li>Report vulnerabilities responsibly</li>
                            </ul>

                            <h4>3. Liability Limitation</h4>
                            <p>ScanQuotient is not liable for damages arising from use of our reports. Users are
                                responsible for verifying findings before taking action.</p>

                            <h4>4. Account Security</h4>
                            <p>Users are responsible for maintaining password confidentiality and must report
                                unauthorized access immediately.</p>
                        </div>

                        <div class="checkbox-group">
                            <input type="checkbox" id="agreeTerms" name="agree_terms" <?php echo $showTermsStep ? 'required' : ''; ?>>
                            <label for="agreeTerms">I have read and agree to the <a href="#"
                                    onclick="return false;">Terms of Service</a></label>
                        </div>
                    </div>

                    <!-- Step 3: User Agreement -->
                    <div class="step-content" data-step="3" data-kind="security" <?php echo $showSecurityStep ? '' : 'hidden'; ?>>
                        <h3><i class="fas fa-handshake"></i> Security Agreement</h3>
                        <div class="policy-box">
                            <h4>Responsible Disclosure Commitment</h4>
                            <p>By using ScanQuotient, you agree to:</p>
                            <ul>
                                <li>Use vulnerability information ethically and legally</li>
                                <li>Not exploit discovered vulnerabilities without authorization</li>
                                <li>Allow reasonable time for remediation before public disclosure</li>
                                <li>Coordinate with affected parties when vulnerabilities are found</li>
                            </ul>

                            <h4>Data Handling</h4>
                            <p>You understand that:</p>
                            <ul>
                                <li>Scan results contain sensitive security information</li>
                                <li>You are responsible for securing exported reports</li>
                                <li>Sharing credentials or results with unauthorized parties is prohibited</li>
                            </ul>

                            <h4>Compliance</h4>
                            <p>You will comply with all applicable laws regarding computer access, data protection, and
                                cybersecurity in your jurisdiction.</p>
                        </div>

                        <div class="checkbox-group">
                            <input type="checkbox" id="agreeSecurity" name="agree_security" <?php echo $showSecurityStep ? 'required' : ''; ?>>
                            <label for="agreeSecurity">I agree to abide by the security and ethical use policies</label>
                        </div>
                    </div>

                    <!-- Step 4: Username -->
                    <div class="step-content" data-step="4" data-kind="username" <?php echo $showUsernameStep ? '' : 'hidden'; ?>>
                        <h3><i class="fas fa-user"></i> Create Username</h3>
                        <p>Choose a unique username for your ScanQuotient account. This will be your login identifier.
                        </p>

                        <div class="form-group">
                            <label for="newUsername">Username</label>
                            <div class="input-wrapper">
                                <input type="text" id="newUsername" name="username" class="form-control"
                                    placeholder="Enter username (e.g., johndoe2024)" autocomplete="off" <?php echo $showUsernameStep ? 'required' : ''; ?>
                                    minlength="4" maxlength="20" pattern="[a-zA-Z0-9_]+">
                                <i class="fas fa-check-circle validation-icon valid"></i>
                                <i class="fas fa-times-circle validation-icon invalid"></i>
                            </div>
                            <small class="info-text">4-20 characters, letters, numbers, and underscores only</small>
                            <div class="error-message" id="usernameError">Username must be 4-20 characters, alphanumeric
                                only</div>
                        </div>

                        <div class="form-group">
                            <label for="confirmUsername">Confirm Username</label>
                            <div class="input-wrapper">
                                <input type="text" id="confirmUsername" class="form-control"
                                    placeholder="Re-enter username" autocomplete="off" <?php echo $showUsernameStep ? 'required' : ''; ?>>
                                <i class="fas fa-check-circle validation-icon valid"></i>
                                <i class="fas fa-times-circle validation-icon invalid"></i>
                            </div>
                            <div class="error-message" id="confirmUsernameError">Usernames do not match</div>
                        </div>
                    </div>

                    <!-- Step 5: Password -->
                    <div class="step-content" data-step="5" data-kind="password" <?php echo $showPasswordStep ? '' : 'hidden'; ?>>
                        <h3><i class="fas fa-key"></i> Set Secure Password</h3>
                        <p>Create a strong password to protect your security dashboard.</p>

                        <div class="form-group">
                            <label>Password Requirements</label>
                            <ul class="password-criteria" id="passwordCriteria">
                                <li id="lengthCriterion"><i class="fas fa-times-circle"></i> At least 12 characters</li>
                                <li id="uppercaseCriterion"><i class="fas fa-times-circle"></i> One uppercase letter
                                    (A-Z)</li>
                                <li id="lowercaseCriterion"><i class="fas fa-times-circle"></i> One lowercase letter
                                    (a-z)</li>
                                <li id="numberCriterion"><i class="fas fa-times-circle"></i> One number (0-9)</li>
                                <li id="specialCharCriterion"><i class="fas fa-times-circle"></i> One special character
                                    (!@#$%^&*)</li>
                            </ul>
                        </div>

                        <div class="form-group">
                            <label for="newPassword">Password</label>
                            <div class="input-wrapper">
                                <input type="password" id="newPassword" name="password" class="form-control"
                                    placeholder="Enter strong password" autocomplete="new-password" <?php echo $showPasswordStep ? 'required' : ''; ?>>
                                <i class="fas fa-eye-slash toggle-visibility" data-target="newPassword"></i>
                                <i class="fas fa-check-circle validation-icon valid"></i>
                                <i class="fas fa-times-circle validation-icon invalid"></i>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="confirmPassword">Confirm Password</label>
                            <div class="input-wrapper">
                                <input type="password" id="confirmPassword" name="confirm_password" class="form-control"
                                    placeholder="Confirm password" autocomplete="new-password" <?php echo $showPasswordStep ? 'required' : ''; ?>>
                                <i class="fas fa-eye-slash toggle-visibility" data-target="confirmPassword"></i>
                                <i class="fas fa-check-circle validation-icon valid"></i>
                                <i class="fas fa-times-circle validation-icon invalid"></i>
                            </div>
                            <div class="error-message" id="confirmPasswordError">Passwords do not match</div>
                        </div>
                    </div>

                    <div class="form-navigation">
                        <button type="button" class="btn btn-secondary" id="prevBtn" style="display: none;">
                            <i class="fas fa-arrow-left"></i> Previous
                        </button>
                        <button type="button" class="btn btn-primary" id="nextBtn">
                            Next <i class="fas fa-arrow-right"></i>
                        </button>
                        <button type="submit" class="btn btn-success" id="submitBtn" style="display: none;">
                            <i class="fas fa-check"></i> <?php echo $isAgreementsOnly ? 'Save Agreements' : 'Complete Setup'; ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </main>

    <!-- FOOTER -->
    <footer class="footer">
        <div class="footer-content">
            <div class="footer-brand">
                <h3>ScanQuotient</h3>
                <p>Quantifying risk. Strengthening security.</p>
            </div>



            <div class="footer-social">
                <a href="#" title="Twitter"><i class="fab fa-twitter"></i></a>
                <a href="#" title="LinkedIn"><i class="fab fa-linkedin-in"></i></a>
                <a href="#" title="GitHub"><i class="fab fa-github"></i></a>
                <a href="mailto:support@scanquotient.com" title="Email"><i class="fas fa-envelope"></i></a>
            </div>
        </div>

        <div class="footer-bottom">
            <p>&copy; 2026 ScanQuotient. All rights reserved. | Securing your digital assets.</p>
        </div>
    </footer>

</body>
<script src="/ScanQuotient.v2/ScanQuotient.B/Public/Registration_completion_page/Javascript/registration_completion.js"
    defer></script>

</html>
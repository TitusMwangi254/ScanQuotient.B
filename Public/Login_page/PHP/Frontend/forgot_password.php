<?php
session_start();
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">

<head>
    <meta charset="UTF-8">
    <title>ScanQuotient | Forgot Password</title>
    <link rel="icon" type="image/png" href="../../../../Storage/Public_images/page_icon.png" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="../../CSS/forgot_password.css" />
</head>

<body>
    <header class="header">
        <div class="nav-container">
            <div class="brand">
                <h1>ScanQuotient</h1>
                <span class="tagline">Quantifying risk. Strengthening security.</span>
            </div>
            <div class="header-actions">

                <div class="theme-switch-wrapper">
                    <label class="theme-switch" for="theme-toggle" title="Toggle Dark Mode">
                        <input type="checkbox" id="theme-toggle">
                        <i class="fas fa-moon moon-icon theme-icon"></i>
                        <i class="fas fa-sun sun-icon theme-icon"></i>
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

    <main class="main-content">
        <div class="card">
            <h2><i class="fas fa-key"></i> Reset Password</h2>

            <div class="step active" id="step1">
                <p style="text-align:center;color:var(--text-muted);margin-bottom:1.5rem">Enter your email or username
                </p>
                <div class="form-group">
                    <input type="text" id="identifier" placeholder="Email or username" required>
                </div>
                <button class="btn" onclick="findAccount()">
                    <i class="fas fa-search"></i> Find Account
                </button>
                <div class="links">
                    <a href="../../../Login_page/PHP/Frontend/Login_page_site.php">
                        <i class="fas fa-arrow-left"></i> Back to Login
                    </a>
                </div>
            </div>

            <div class="step" id="step2">
                <p style="text-align:center;color:var(--text-muted);margin-bottom:1rem">Choose verification method</p>
                <div class="masked-email" id="maskedEmail"></div>

                <div class="form-group">
                    <select id="verifyMethod" onchange="methodChanged()">
                        <option value="">Select method...</option>
                        <option value="email">Send code to email</option>
                        <option value="recovery">Send code to recovery email</option>
                        <option value="question">Answer security question</option>
                    </select>
                </div>

                <div id="questionBox" style="display:none;margin-bottom:1.5rem">
                    <div class="form-group">
                        <label id="questionText"></label>
                        <input type="text" id="securityAnswer" placeholder="Your answer" autocomplete="off">
                    </div>
                </div>

                <button class="btn" id="verifyBtn" onclick="verifyIdentity()" disabled>
                    <i class="fas fa-shield-alt"></i> Verify
                </button>
                <button class="btn btn-secondary" onclick="resetFlow()">
                    <i class="fas fa-times"></i> Cancel
                </button>
            </div>

            <div class="step" id="step3">
                <div id="codeEntry">
                    <p style="text-align:center;color:var(--text-muted)">Enter 6-digit code</p>
                    <div class="otp-inputs">
                        <input type="text" maxlength="1" class="otp-input" oninput="moveNext(this,0)"
                            onkeydown="moveBack(event,0)">
                        <input type="text" maxlength="1" class="otp-input" oninput="moveNext(this,1)"
                            onkeydown="moveBack(event,1)">
                        <input type="text" maxlength="1" class="otp-input" oninput="moveNext(this,2)"
                            onkeydown="moveBack(event,2)">
                        <input type="text" maxlength="1" class="otp-input" oninput="moveNext(this,3)"
                            onkeydown="moveBack(event,3)">
                        <input type="text" maxlength="1" class="otp-input" oninput="moveNext(this,4)"
                            onkeydown="moveBack(event,4)">
                        <input type="text" maxlength="1" class="otp-input" oninput="moveNext(this,5)"
                            onkeydown="moveBack(event,5)">
                    </div>
                    <div id="timer">Code expires in 15:00</div>
                    <button class="btn" onclick="verifyCode()">
                        <i class="fas fa-check"></i> Verify Code
                    </button>
                    <button class="btn btn-secondary" onclick="resendCode()" id="resendBtn">
                        <i class="fas fa-redo"></i> Resend Code
                    </button>
                </div>

                <div id="passwordEntry" style="display:none">
                    <!-- 2FA Notice -->
                    <div
                        style="background:var(--info-bg, #e0f2fe); border-left:4px solid var(--info-color, #0ea5e9); padding:0.75rem 1rem; margin-bottom:1rem; border-radius:0 4px 4px 0; font-size:0.9rem; color:var(--text-color, #1e293b);">
                        <i class="fas fa-shield-alt" style="color:var(--info-color, #0ea5e9); margin-right:0.5rem;"></i>
                        <strong>Security Notice:</strong> Updating your password will automatically enable
                        <strong>Two-Factor Authentication (2FA)</strong> on your account for added security.
                    </div>

                    <div class="form-group">
                        <label>New Password (min 12 chars)</label>
                        <div class="password-wrapper">
                            <input type="password" id="newPass" oninput="checkPassword()">
                            <i class="fas fa-eye-slash toggle-password" onclick="toggleVisibility('newPass', this)"></i>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Confirm Password</label>
                        <div class="password-wrapper">
                            <input type="password" id="confirmPass" oninput="checkPassword()">
                            <i class="fas fa-eye-slash toggle-password"
                                onclick="toggleVisibility('confirmPass', this)"></i>
                        </div>
                    </div>
                    <div id="passCriteria" style="font-size:0.85rem;color:var(--text-muted);margin-bottom:1rem">
                        <div id="len"><i class="fas fa-times"></i> 12+ characters</div>
                        <div id="upper"><i class="fas fa-times"></i> Uppercase</div>
                        <div id="lower"><i class="fas fa-times"></i> Lowercase</div>
                        <div id="num"><i class="fas fa-times"></i> Number</div>
                        <div id="spec"><i class="fas fa-times"></i> Special char</div>
                        <div id="match"><i class="fas fa-times"></i> Match</div>
                    </div>
                    <button class="btn" id="finalBtn" onclick="setPassword()" disabled>
                        <i class="fas fa-save"></i> Update Password
                    </button>
                </div>
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
    <script src="../../Javascript/forgot_password.js" defer></script>
</body>

</html>
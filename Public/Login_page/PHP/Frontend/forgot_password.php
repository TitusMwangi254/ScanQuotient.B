<?php
session_start();
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">

<head>
    <meta charset="UTF-8">
    <title>Forgot Password - ScanQuotient</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --bg-primary: #f0f4f8;
            --bg-secondary: #fff;
            --accent: #2563eb;
            --border: #e2e8f0;
            --text-muted: #4a5568;
        }

        [data-theme="dark"] {
            --bg-primary: #0f172a;
            --bg-secondary: #1e293b;
            --accent: #3b82f6;
            --border: #334155;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box
        }

        body {
            font-family: 'Segoe UI', system-ui, sans-serif;
            background: var(--bg-primary);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem
        }

        .card {
            background: var(--bg-secondary);
            padding: 2.5rem;
            border-radius: 20px;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 450px
        }

        h2 {
            text-align: center;
            margin-bottom: 1.5rem;
            color: #1a202c
        }

        .step {
            display: none
        }

        .step.active {
            display: block;
            animation: fadeIn 0.3s
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px)
            }

            to {
                opacity: 1;
                transform: translateY(0)
            }
        }

        .form-group {
            margin-bottom: 1.5rem
        }

        /* Password Toggle Styling */
        .password-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }

        .password-wrapper i.toggle-password {
            position: absolute;
            right: 1rem;
            cursor: pointer;
            color: var(--text-muted);
            z-index: 10;
        }

        label {
            display: block;
            margin-bottom: 0.5rem;
            color: var(--text-muted);
            font-weight: 600
        }

        input,
        select {
            width: 100%;
            padding: 1rem;
            border: 2px solid var(--border);
            border-radius: 12px;
            background: var(--bg-primary);
            font-size: 1rem;
            color: inherit;
        }

        input:focus,
        select:focus {
            outline: none;
            border-color: var(--accent)
        }

        .btn {
            width: 100%;
            padding: 1rem;
            background: linear-gradient(135deg, var(--accent), #7c3aed);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer
        }

        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed
        }

        .btn-secondary {
            background: transparent;
            border: 2px solid var(--accent);
            color: var(--accent);
            margin-top: 0.5rem
        }

        .links {
            text-align: center;
            margin-top: 1rem
        }

        .links a {
            color: var(--accent);
            text-decoration: none
        }

        .otp-inputs {
            display: flex;
            gap: 0.5rem;
            justify-content: center;
            margin: 1.5rem 0
        }

        .otp-input {
            width: 50px;
            height: 60px;
            border: 2px solid var(--border);
            border-radius: 12px;
            text-align: center;
            font-size: 1.5rem;
            font-weight: 700
        }

        #timer {
            text-align: center;
            color: var(--text-muted);
            margin-bottom: 1rem
        }

        .masked-email {
            background: var(--bg-primary);
            padding: 1rem;
            border-radius: 12px;
            text-align: center;
            font-family: monospace;
            margin-bottom: 1rem
        }
    </style>
</head>

<body>
    <div class="card">
        <h2><i class="fas fa-key"></i> Reset Password</h2>

        <div class="step active" id="step1">
            <p style="text-align:center;color:#4a5568;margin-bottom:1.5rem">Enter your email or username</p>
            <div class="form-group">
                <input type="text" id="identifier" placeholder="Email or username" required>
            </div>
            <button class="btn" onclick="findAccount()">
                <i class="fas fa-search"></i> Find Account
            </button>
            <div class="links">
                <a href="/ScanQuotient/ScanQuotient/Publicpages/Login_Page/PHP/Frontend/login_page_site.php">
                    <i class="fas fa-arrow-left"></i> Back to Login
                </a>
            </div>
        </div>

        <div class="step" id="step2">
            <p style="text-align:center;color:#4a5568;margin-bottom:1rem">Choose verification method</p>
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
                <p style="text-align:center;color:#4a5568">Enter 6-digit code</p>
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
                <div class="form-group">
                    <label>New Password (min 12 chars)</label>
                    <div class="password-wrapper">
                        <input type="password" id="newPass" oninput="checkPassword()">
                        <i class="fas fa-eye toggle-password" onclick="toggleVisibility('newPass', this)"></i>
                    </div>
                </div>
                <div class="form-group">
                    <label>Confirm Password</label>
                    <div class="password-wrapper">
                        <input type="password" id="confirmPass" oninput="checkPassword()">
                        <i class="fas fa-eye toggle-password" onclick="toggleVisibility('confirmPass', this)"></i>
                    </div>
                </div>
                <div id="passCriteria" style="font-size:0.85rem;color:#4a5568;margin-bottom:1rem">
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

    <script>
        let resetData = {};
        let expiryTimer;

        // Toggle Password Visibility Logic
        function toggleVisibility(id, el) {
            const input = document.getElementById(id);
            if (input.type === "password") {
                input.type = "text";
                el.classList.replace("fa-eye", "fa-eye-slash");
            } else {
                input.type = "password";
                el.classList.replace("fa-eye-slash", "fa-eye");
            }
        }

        function showStep(n) { document.querySelectorAll('.step').forEach(s => s.classList.remove('active')); document.getElementById('step' + n).classList.add('active') }

        function findAccount() {
            const id = document.getElementById('identifier').value;
            if (!id) return Swal.fire('Error', 'Enter email or username', 'error');

            fetch('/ScanQuotient/ScanQuotient/Publicpages/Login_Page/PHP/Backend/find_user.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'identifier=' + encodeURIComponent(id)
            })
                .then(r => r.json())
                .then(d => {
                    if (d.status === 'success') {
                        resetData = d.data;
                        document.getElementById('maskedEmail').textContent = d.data.masked_email;
                        const sel = document.getElementById('verifyMethod');
                        sel.innerHTML = '<option value="">Select method...</option>';
                        if (d.data.has_email) sel.innerHTML += '<option value="email">Primary Email: ' + d.data.masked_email + '</option>';
                        if (d.data.has_recovery) sel.innerHTML += '<option value="recovery">Recovery Email: ' + d.data.masked_recovery + '</option>';
                        if (d.data.has_question) sel.innerHTML += '<option value="question">Security Question</option>';
                        showStep(2);
                    } else {
                        Swal.fire('Not Found', d.message, 'error');
                    }
                });
        }

        function methodChanged() {
            const m = document.getElementById('verifyMethod').value;
            document.getElementById('questionBox').style.display = m === 'question' ? 'block' : 'none';
            if (m === 'question') document.getElementById('questionText').textContent = resetData.question;
            document.getElementById('verifyBtn').disabled = !m;
        }

        function verifyIdentity() {
            const m = document.getElementById('verifyMethod').value;
            const btn = document.getElementById('verifyBtn');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';

            let body = 'method=' + m;
            if (m === 'question') body += '&answer=' + encodeURIComponent(document.getElementById('securityAnswer').value);

            fetch('/ScanQuotient/ScanQuotient/Publicpages/Login_Page/PHP/Backend/send_verify.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body
            })
                .then(r => r.json())
                .then(d => {
                    if (d.status === 'success') {
                        if (m === 'question') {
                            document.getElementById('codeEntry').style.display = 'none';
                            document.getElementById('passwordEntry').style.display = 'block';
                            showStep(3);
                        } else {
                            document.getElementById('codeEntry').style.display = 'block';
                            document.getElementById('passwordEntry').style.display = 'none';
                            startTimer(900);
                            showStep(3);
                        }
                    } else {
                        Swal.fire('Error', d.message, 'error');
                        btn.disabled = false;
                        btn.innerHTML = '<i class="fas fa-shield-alt"></i> Verify';
                    }
                });
        }

        function moveNext(el, idx) {
            el.value = el.value.replace(/[^0-9]/, '');
            if (el.value && idx < 5) document.querySelectorAll('.otp-input')[idx + 1].focus();
        }
        function moveBack(e, idx) { if (e.key === 'Backspace' && !e.target.value && idx > 0) document.querySelectorAll('.otp-input')[idx - 1].focus() }

        function getCode() { let c = ''; document.querySelectorAll('.otp-input').forEach(i => c += i.value); return c }

        function startTimer(s) {
            clearInterval(expiryTimer);
            let t = s;
            expiryTimer = setInterval(() => {
                t--;
                const m = Math.floor(t / 60), sec = t % 60;
                document.getElementById('timer').textContent = 'Code expires in ' + String(m).padStart(2, '0') + ':' + String(sec).padStart(2, '0');
                if (t <= 0) { clearInterval(expiryTimer); document.getElementById('timer').textContent = 'Code expired'; }
            }, 1000);
        }

        function verifyCode() {
            fetch('/ScanQuotient/ScanQuotient/Publicpages/Login_Page/PHP/Backend/check_code.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'code=' + getCode()
            })
                .then(r => r.json())
                .then(d => {
                    if (d.status === 'success') {
                        document.getElementById('codeEntry').style.display = 'none';
                        document.getElementById('passwordEntry').style.display = 'block';
                    } else {
                        Swal.fire('Invalid Code', d.message, 'error');
                    }
                });
        }

        function resendCode() {
            document.getElementById('resendBtn').disabled = true;
            verifyIdentity();
            setTimeout(() => document.getElementById('resendBtn').disabled = false, 30000);
        }

        function checkPassword() {
            const p = document.getElementById('newPass').value;
            const c = document.getElementById('confirmPass').value;
            const checks = {
                len: p.length >= 12,
                upper: /[A-Z]/.test(p),
                lower: /[a-z]/.test(p),
                num: /[0-9]/.test(p),
                spec: /[!@#$%^&*]/.test(p),
                match: p === c && c.length > 0
            };
            Object.keys(checks).forEach(k => {
                const el = document.getElementById(k);
                el.innerHTML = '<i class="fas fa-' + (checks[k] ? 'check' : 'times') + '"></i> ' + el.textContent.replace(/^. /, '');
                el.style.color = checks[k] ? '#10b981' : '#4a5568';
            });
            document.getElementById('finalBtn').disabled = !Object.values(checks).every(Boolean);
        }

        function setPassword() {
            const btn = document.getElementById('finalBtn');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating...';

            fetch('/ScanQuotient/ScanQuotient/Publicpages/Login_Page/PHP/Backend/final_reset.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'password=' + encodeURIComponent(document.getElementById('newPass').value)
            })
                .then(r => r.json())
                .then(d => {
                    if (d.status === 'success') {
                        Swal.fire({
                            icon: 'success',
                            title: 'Password Updated!',
                            text: 'Login with your new password',
                            timer: 2000,
                            showConfirmButton: false
                        }).then(() => {
                            window.location.href = '/ScanQuotient/ScanQuotient/Publicpages/Login_Page/PHP/Frontend/login_page_site.php';
                        });
                    } else {
                        Swal.fire('Error', d.message, 'error');
                        btn.disabled = false;
                        btn.innerHTML = '<i class="fas fa-save"></i> Update Password';
                    }
                });
        }

        function resetFlow() {
            resetData = {};
            clearInterval(expiryTimer);
            document.getElementById('identifier').value = '';
            document.querySelectorAll('.otp-input').forEach(i => i.value = '');
            document.getElementById('newPass').value = '';
            document.getElementById('confirmPass').value = '';
            showStep(1);
        }
    </script>
</body>

</html>
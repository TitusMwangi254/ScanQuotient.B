// login.js
const SIGN_IN_LABEL = 'Sign In';
const SIGN_IN_PENDING_LABEL = 'Signing in…';

document.addEventListener('DOMContentLoaded', function() {
    const loginForm = document.querySelector('.container form[action*="login_handler"]');
    const signInBtn = loginForm && loginForm.querySelector('button.sign-in-btn[type="submit"]');

    function resetSignInButton() {
        if (!signInBtn) return;
        signInBtn.disabled = false;
        signInBtn.removeAttribute('aria-busy');
        signInBtn.textContent = SIGN_IN_LABEL;
    }

    if (loginForm && signInBtn) {
        loginForm.addEventListener('submit', function() {
            signInBtn.disabled = true;
            signInBtn.setAttribute('aria-busy', 'true');
            signInBtn.textContent = SIGN_IN_PENDING_LABEL;
        });
    }

    window.addEventListener('pageshow', function(e) {
        if (e.persisted) {
            resetSignInButton();
        }
    });

    // --- Password Visibility Toggle ---
    document.querySelectorAll('.toggle-visibility').forEach(function(icon) {
        icon.addEventListener('click', function() {
            var targetId = this.getAttribute('data-target');
            var inputEl = document.getElementById(targetId);
            if (!inputEl) return;

            if (inputEl.type === 'text') {
                // Currently visible → hide
                inputEl.type = 'password';
                this.classList.remove('fa-eye');
                this.classList.add('fa-eye-slash');
            } else {
                // Currently hidden → show
                inputEl.type = 'text';
                this.classList.remove('fa-eye-slash');
                this.classList.add('fa-eye');
            }
        });
    });

    // --- Light/Dark Mode Toggle Logic ---
    const themeToggle = document.getElementById('checkbox');
    const body = document.body;

    // Function to set theme
    function setTheme(isLightMode) {
        if (isLightMode) {
            body.classList.add('light-mode');
            localStorage.setItem('theme', 'light');
        } else {
            body.classList.remove('light-mode');
            localStorage.setItem('theme', 'dark');
        }
    }

    // Check for saved theme preference on page load
    const savedTheme = localStorage.getItem('theme');
    if (savedTheme) {
        if (savedTheme === 'light') {
            themeToggle.checked = true; // Set toggle switch to 'on'
            setTheme(true);
        } else {
            themeToggle.checked = false; // Set toggle switch to 'off'
            setTheme(false);
        }
    } else {
        // If no saved theme, default to dark mode and set toggle accordingly
        themeToggle.checked = false;
        setTheme(false);
    }

    // Listen for changes on the toggle switch
    themeToggle.addEventListener('change', function() {
        setTheme(this.checked);
    });

    // --- Error Box Fade Out Logic ---
    const errorBox = document.getElementById('loginErrorBox');
    if (errorBox) {
        // Start fade out after 3.5 seconds
        setTimeout(() => {
            errorBox.classList.add('hide');
        }, 3500); // 3500 milliseconds = 3.5 seconds before starting fade

        // Completely remove from DOM after transition finishes (0.5s fade + 3.5s initial delay = 4s total)
        errorBox.addEventListener('transitionend', function() {
            if (errorBox.classList.contains('hide')) {
                errorBox.remove();
            }
        });
    }
});
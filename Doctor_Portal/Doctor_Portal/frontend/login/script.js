let activeRole = 'Physician';
let pwVisible = false;

// Initialize form placeholder / labels based on selected role
function setRole(roleName, btn) {
    document.querySelectorAll('.seg-item').forEach(b => {
        b.classList.remove('active');
        b.setAttribute('aria-pressed', 'false');
    });
    btn.classList.add('active');
    btn.setAttribute('aria-pressed', 'true');
    activeRole = roleName;
    clearErrors();

    // Customize input fields based on role selection
    const emailInput = document.getElementById('email');
    const emailLabel = document.getElementById('email-label');
    if (roleName === 'Admin') {
        emailLabel.textContent = 'Admin Username';
        emailInput.placeholder = 'e.g. admin';
        emailInput.type = 'text';
    } else {
        emailLabel.textContent = 'Staff Email / ID';
        emailInput.placeholder = 'doctor@medical.com';
        emailInput.type = 'email';
    }
}

// Password toggle eye icon
function togglePw() {
    pwVisible = !pwVisible;
    const inp = document.getElementById('password');
    const icon = document.getElementById('eyeIcon');
    inp.type = pwVisible ? 'text' : 'password';
    icon.innerHTML = pwVisible
        ? `<path d="M2 2l12 12M6.5 6.6A2 2 0 009.4 9.4M4.5 4.6C2.8 5.8 1.6 7 1 8c1.4 2.3 4.1 4.5 7 4.5a7.4 7.4 0 003.3-.8M7.3 3.5c.2 0 .5-.1.7-.1C11 3.4 13.6 5.6 15 8c-.5.8-1.1 1.5-1.8 2.1"
            stroke="currentColor" stroke-width="1.35" stroke-linecap="round" stroke-linejoin="round"/>`
        : `<path d="M1 8s2.5-5 7-5 7 5 7 5-2.5 5-7 5-7-5-7-5z"/><circle cx="8" cy="8" r="2"/>`;
}

// Clear visual errors
function clearErrors() {
    ['email', 'password'].forEach(id => {
        document.getElementById(id).classList.remove('border-red-500');
    });
    document.getElementById('err-id').style.display = 'none';
    document.getElementById('err-pw').style.display = 'none';
    document.getElementById('client-error-banner').classList.add('hidden');
}

// Form submit handler
function handleFormSubmit(event) {
    event.preventDefault();
    clearErrors();

    const emailVal = document.getElementById('email').value.trim();
    const pwVal = document.getElementById('password').value;
    let ok = true;

    // Validate inputs aren't empty
    if (!emailVal) {
        document.getElementById('email').classList.add('border-red-500');
        document.getElementById('err-id').style.display = 'flex';
        ok = false;
    }
    if (!pwVal) {
        document.getElementById('password').classList.add('border-red-500');
        document.getElementById('err-pw').style.display = 'flex';
        ok = false;
    }

    if (!ok) return false;

    // Role restriction check (only Admin / Physician are simulated)
    if (activeRole !== 'Physician' && activeRole !== 'Admin') {
        document.getElementById('client-error-text').textContent = `Access Restricted: The ${activeRole} portal is currently in development. Please sign in as a Physician or Admin.`;
        document.getElementById('client-error-banner').classList.remove('hidden');
        return false;
    }

    // Try authenticating through mock database
    const doctorObj = window.db.verifyCredentials(emailVal, pwVal, activeRole);
    if (!doctorObj) {
        document.getElementById('email').classList.add('border-red-500');
        document.getElementById('password').classList.add('border-red-500');
        document.getElementById('client-error-text').textContent = 'Invalid credentials. Please use "doctor@medical.com" / "password123" for Physician, or "admin" / "admin" for Admin.';
        document.getElementById('client-error-banner').classList.remove('hidden');
        return false;
    }

    // Save logged-in doctor state
    window.db.setCurrentUser(doctorObj);

    // Disable button and animate login
    const btn = document.getElementById('signInBtn');
    btn.textContent = 'Authenticating…';
    btn.disabled = true;

    setTimeout(() => {
        btn.textContent = 'Redirecting...';
        window.location.href = '../dashboard/index.html';
    }, 1000);
}

function handleSSO() {
    alert('SSO is disabled for this demonstration.');
}

// Global hookups
window.setRole = setRole;
window.togglePw = togglePw;
window.handleFormSubmit = handleFormSubmit;
window.handleSSO = handleSSO;

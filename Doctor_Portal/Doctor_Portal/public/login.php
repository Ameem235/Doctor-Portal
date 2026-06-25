<?php
/**
 * Doctor Login Page
 * 
 * Handles doctor authentication.
 */

// Start the session to track logged-in status
session_start();

// If the doctor is already logged in, redirect them directly to the dashboard
if (isset($_SESSION['doctor_id'])) {
    header("Location: dashboard.php");
    exit();
}

// Check if the form was submitted
$error = '';
$email = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Include database connection
    require_once __DIR__ . '/../config/db.php';

    // Retrieve and sanitize credentials
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = "Please enter both email and password.";
    } else {
        try {
            // Retrieve doctor credentials securely via prepared statements
            $stmt = $pdo->prepare("SELECT * FROM doctors WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
            $doctor = $stmt->fetch();

            // Verify if the doctor exists and the password matches the BCrypt hash
            if ($doctor && password_verify($password, $doctor['password_hash'])) {
                session_regenerate_id(true);

                // Set session variables
                $_SESSION['doctor_id'] = $doctor['doctor_id'];
                $_SESSION['doctor_name'] = $doctor['name'];

                header("Location: dashboard.php");
                exit();
            } else {
                $error = "Invalid email or password.";
            }
        } catch (PDOException $e) {
            $error = "An error occurred. Please try again later.";
        }
    }
}

// Layout configuration
$pageTitle = "Doctor Login";
include_once __DIR__ . '/../includes/header.php';
?>
<!-- Custom Style Overrides for Split Layout -->
<style>
    html, body { height: 100%; }
    .layout {
      display: flex;
      min-height: 100vh;
    }
    .panel {
      width: 44%;
      background: #E4EBF4;
      position: relative;
      overflow: hidden;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      padding: 3.25rem 3.5rem 3rem;
    }
    .panel-mark {
      position: absolute;
      top: 50%;
      left: 50%;
      transform: translate(-50%, -50%);
      width: 320px;
      height: 320px;
      pointer-events: none;
      opacity: 1;
    }
    .panel-vignette {
      position: absolute;
      top: -80px;
      right: -80px;
      width: 360px;
      height: 360px;
      border-radius: 50%;
      background: radial-gradient(circle, rgba(79,124,172,0.07) 0%, transparent 70%);
      pointer-events: none;
    }
    .panel-rule {
      display: block;
      width: 28px;
      height: 1px;
      background: rgba(79,124,172,0.25);
      margin-bottom: 1.5rem;
    }
    .side {
      flex: 1;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      padding: 2.5rem 1.5rem;
      background: #F7F9FC;
    }
    .login-card {
      width: 100%;
      max-width: 420px;
      background: #FFFFFF;
      border: 1px solid #E5EAF0;
      border-radius: 12px;
      padding: 2.5rem 2.25rem 2.25rem;
      box-shadow:
        0 1px 2px rgba(31,41,55,0.04),
        0 4px 16px rgba(31,41,55,0.06);
    }
    .seg {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      background: #F7F9FC;
      border: 1px solid #E5EAF0;
      border-radius: 8px;
      padding: 3px;
      gap: 2px;
    }
    .seg-item {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 3px;
      padding: 7px 4px 6px;
      border-radius: 5px;
      border: none;
      background: transparent;
      cursor: pointer;
      font-size: 0.6875rem;
      font-weight: 500;
      color: #6B7280;
      letter-spacing: 0.01em;
      line-height: 1;
      transition: all 0.2s ease;
    }
    .seg-item:hover:not(.active) {
      background: rgba(255,255,255,0.7);
      color: #374151;
    }
    .seg-item.active {
      background: #FFFFFF;
      color: #4F7CAC;
      box-shadow:
        0 1px 2px rgba(31,41,55,0.08),
        0 0 0 1px rgba(79,124,172,0.14);
    }
    .input-field {
      width: 100%;
      padding: 0.6875rem 0.875rem;
      border: 1px solid #E5EAF0;
      border-radius: 7px;
      font-size: 0.9375rem;
      color: #1F2937;
      background: #FFFFFF;
      outline: none;
      letter-spacing: 0.01em;
      transition: all 0.2s ease;
    }
    .input-field:focus {
      border-color: #4F7CAC;
      box-shadow: 0 0 0 3px rgba(79,124,172,0.11);
    }
    .pw-wrap { position: relative; }
    .eye {
      position: absolute;
      right: 10px;
      top: 50%;
      transform: translateY(-50%);
      background: none;
      border: none;
      padding: 4px;
      cursor: pointer;
      color: #9CA3AF;
      display: flex;
      align-items: center;
    }
    .eye:hover { color: #4F7CAC; }
    .btn-action {
      width: 100%;
      padding: 0.75rem 1rem;
      background: #4F7CAC;
      color: #FFFFFF;
      border: none;
      border-radius: 7px;
      font-size: 0.9375rem;
      font-weight: 500;
      cursor: pointer;
      letter-spacing: 0.015em;
      box-shadow: 0 1px 3px rgba(31,41,55,0.12), 0 2px 8px rgba(79,124,172,0.20);
      transition: background 0.2s ease;
    }
    .btn-action:hover { background: #3D6490; }
    .btn-action:active { background: #335680; }
    .btn-sso {
      width: 100%;
      padding: 0.71rem 1rem;
      background: #FFFFFF;
      border: 1px solid #E5EAF0;
      border-radius: 7px;
      font-size: 0.875rem;
      font-weight: 500;
      color: #374151;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      transition: all 0.2s ease;
    }
    .btn-sso:hover {
      background: #F7F9FC;
      border-color: #C8D5E3;
      color: #1F2937;
    }
    .errmsg {
      display: none;
      align-items: center;
      gap: 5px;
      font-size: 0.75rem;
      color: #DC2626;
      margin-top: 5px;
    }
    .check {
      width: 15px;
      height: 15px;
      border: 1px solid #D1D5DB;
      border-radius: 3px;
      background: #fff;
      cursor: pointer;
      appearance: none;
      -webkit-appearance: none;
      flex-shrink: 0;
    }
    .check:checked {
      background: #4F7CAC;
      border-color: #4F7CAC;
      background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 12 10' fill='none' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M1 5l3.5 3.5L11 1' stroke='white' stroke-width='1.75' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
      background-repeat: no-repeat;
      background-position: center;
      background-size: 10px;
    }
    .sdot {
      width: 6px;
      height: 6px;
      border-radius: 50%;
      background: #4ADE80;
      flex-shrink: 0;
    }
    .sdivider {
      border-top: 1px solid rgba(79,124,172,0.15);
      padding-top: 1.75rem;
    }
    @media (max-width: 768px) {
      .panel { display: none !important; }
      body { background: #E4EBF4; }
      .side {
        background: #F7F9FC;
        border-radius: 20px 20px 0 0;
        min-height: 100vh;
        justify-content: flex-start;
        padding-top: 3rem;
      }
      .mob-logo { display: flex !important; }
    }
</style>
</head>
<body>

<div class="layout">
  <!-- Left Side Brand Panel -->
  <div class="panel">
    <div class="panel-vignette" aria-hidden="true"></div>

    <!-- Background Cross Vector -->
    <svg class="panel-mark" viewBox="0 0 320 320" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
      <rect x="120" y="20"  width="80" height="280" rx="12" fill="#4F7CAC" opacity="0.04"/>
      <rect x="20"  y="120" width="280" height="80"  rx="12" fill="#4F7CAC" opacity="0.04"/>
      <rect x="120" y="20"  width="80" height="280" rx="12" fill="none" stroke="#4F7CAC" stroke-width="0.5" opacity="0.1"/>
      <rect x="20"  y="120" width="280" height="80"  rx="12" fill="none" stroke="#4F7CAC" stroke-width="0.5" opacity="0.1"/>
    </svg>

    <!-- Header Logo -->
    <div style="position:relative; z-index:1;">
      <div style="display:flex; align-items:center; gap:10px; margin-bottom:6px;">
        <svg width="30" height="30" viewBox="0 0 30 30" fill="none">
          <rect width="30" height="30" rx="7" fill="#4F7CAC" opacity="0.95"/>
          <rect x="12" y="5"  width="6" height="20" rx="1.5" fill="white"/>
          <rect x="5"  y="12" width="20" height="6"  rx="1.5" fill="white"/>
        </svg>
        <span class="font-serif" style="font-size:1.3125rem; color:#1F2937; letter-spacing:0.01em; font-weight:500;">MedCore</span>
      </div>
      <p style="font-size:0.6875rem; color:#6B7280; letter-spacing:0.09em; text-transform:uppercase; padding-left:40px; font-weight:500; font-family:'Inter',sans-serif;">Hospital Management System</p>
    </div>

    <!-- Headline -->
    <div style="position:relative; z-index:1;">
      <span class="panel-rule"></span>
      <h1 style="font-size:2.125rem; color:#111827; line-height:1.22; font-weight:500; margin-bottom:1.125rem;">
        Coordinated care<br/>
        <em style="color:#4F7CAC; font-style:italic;">starts here.</em>
      </h1>
      <p style="font-size:0.9rem; color:#4B5563; line-height:1.75; max-width:290px; font-weight:400; font-family:'Inter',sans-serif;">
        Secure staff access to patient records, clinical scheduling, and cross-department workflows.
      </p>
    </div>

    <!-- Statistics & Status Footer -->
    <div style="position:relative; z-index:1;">
      <div class="sdivider">
        <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:1.5rem; margin-bottom:1.625rem;">
          <div>
            <p style="font-size:1.5rem; color:#1F2937; margin-bottom:4px; font-weight:500; line-height:1;">12 k+</p>
            <p style="font-size:0.6875rem; color:#6B7280; line-height:1.45; letter-spacing:0.02em; font-weight:500; font-family:'Inter',sans-serif;">Active patients</p>
          </div>
          <div>
            <p style="font-size:1.5rem; color:#1F2937; margin-bottom:4px; font-weight:500; line-height:1;">840</p>
            <p style="font-size:0.6875rem; color:#6B7280; line-height:1.45; letter-spacing:0.02em; font-weight:500; font-family:'Inter',sans-serif;">Staff members</p>
          </div>
          <div>
            <p style="font-size:1.5rem; color:#1F2937; margin-bottom:4px; font-weight:500; line-height:1;">18</p>
            <p style="font-size:0.6875rem; color:#6B7280; line-height:1.45; letter-spacing:0.02em; font-weight:500; font-family:'Inter',sans-serif;">Departments</p>
          </div>
        </div>

        <div style="display:flex; align-items:center; gap:7px;">
          <span class="sdot"></span>
          <span style="font-size:0.75rem; color:#6B7280; letter-spacing:0.01em; font-weight:500; font-family:'Inter',sans-serif;">All systems operational</span>
        </div>
      </div>
    </div>
  </div>

  <!-- Right Side Form Card Panel -->
  <div class="side">
    <div class="mob-logo" style="display:none; align-items:center; gap:9px; margin-bottom:2rem;">
      <svg width="28" height="28" viewBox="0 0 30 30" fill="none">
        <rect width="30" height="30" rx="7" fill="#4F7CAC"/>
        <rect x="12" y="5"  width="6" height="20" rx="1.5" fill="white"/>
        <rect x="5"  y="12" width="20" height="6"  rx="1.5" fill="white"/>
      </svg>
      <span style="font-size:1.25rem; color:#1F2937; font-weight:500;">MedCore</span>
    </div>

    <div class="login-card">
      <div style="margin-bottom:1.875rem; padding-bottom:1.625rem; border-bottom:1px solid #F0F3F7;">
        <p style="font-size:0.6875rem; font-weight:600; color:#4F7CAC; letter-spacing:0.09em; text-transform:uppercase; margin-bottom:10px; font-family:'Inter',sans-serif;">Staff Access</p>
        <h2 style="font-size:1.25rem; font-weight:600; color:#1F2937; letter-spacing:-0.018em; margin-bottom:5px; line-height:1.25;">Sign in to your account</h2>
        <p style="font-size:0.875rem; color:#6B7280; line-height:1.55; font-family:'Inter',sans-serif;">Enter your credentials to access the HMS portal.</p>
      </div>

      <!-- Segmented Role Selector -->
      <div style="margin-bottom:1.625rem;">
        <p style="margin-bottom:8px; font-size:0.75rem; color:#6B7280; font-weight:500; letter-spacing:0.04em; text-transform:uppercase; font-family:'Inter',sans-serif;">Sign in as</p>
        <div class="seg" role="group" aria-label="Select your role">
          <button class="seg-item active" onclick="setRole(this, 'Physician')" aria-pressed="true">
            <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.25" stroke-linecap="round">
              <circle cx="8" cy="5" r="2.5"/>
              <path d="M3 14c0-2.76 2.24-5 5-5s5 2.24 5 5"/>
              <path d="M11.5 10v2.5a1.5 1.5 0 003 0V10" stroke-width="1.1"/>
              <circle cx="13" cy="13.5" r=".6" fill="currentColor" stroke="none"/>
            </svg>
            Physician
          </button>

          <button class="seg-item" onclick="setRole(this, 'Nurse')" aria-pressed="false">
            <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.25" stroke-linecap="round">
              <circle cx="8" cy="5" r="2.5"/>
              <path d="M3 14c0-2.76 2.24-5 5-5s5 2.24 5 5"/>
              <path d="M8 10v3.5M6.25 11.75h3.5" stroke-width="1.3"/>
            </svg>
            Nurse
          </button>

          <button class="seg-item" onclick="setRole(this, 'Admin')" aria-pressed="false">
            <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.25" stroke-linecap="round">
              <rect x="2" y="3" width="12" height="10" rx="1.5"/>
              <path d="M5 7h6M5 10h4"/>
            </svg>
            Admin
          </button>

          <button class="seg-item" onclick="setRole(this, 'Technician')" aria-pressed="false">
            <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.25" stroke-linecap="round">
              <circle cx="8" cy="8" r="2"/>
              <path d="M8 2v1.5M8 12.5V14M2 8h1.5M12.5 8H14M3.93 3.93l1.06 1.06M11 11l1.07 1.07M3.93 12.07l1.06-1.06M11 5l1.07-1.07"/>
            </svg>
            Technician
          </button>
        </div>
      </div>

      <!-- Backend Server-side Error Alert -->
      <?php if (!empty($error)): ?>
          <div class="mb-4 p-3 bg-red-50 border-l-4 border-red-500 rounded text-red-700 text-sm font-medium" id="server-error-banner">
              <?php echo htmlspecialchars($error); ?>
          </div>
      <?php endif; ?>

      <!-- Client-side Custom Error Alerts -->
      <div class="mb-4 p-3 bg-red-50 border-l-4 border-red-500 rounded text-red-700 text-sm font-medium hidden" id="client-error-banner">
          <span id="client-error-text"></span>
      </div>

      <!-- Form Submission -->
      <form action="login.php" method="POST" id="loginForm" autocomplete="off" onsubmit="return handleFormSubmit(event)">
        <!-- Email Input -->
        <div style="margin-bottom:1rem;">
          <label class="label font-medium text-xs text-gray-700" for="email">Staff Email / ID</label>
          <input class="input-field" id="email" name="email" type="email" placeholder="doctor@medical.com" required value="<?php echo htmlspecialchars($email); ?>"/>
          <p class="errmsg" id="err-id" role="alert">
            <svg width="12" height="12" viewBox="0 0 12 12" fill="none" stroke="#DC2626" stroke-width="1.4" stroke-linecap="round"><circle cx="6" cy="6" r="5"/><path d="M6 3.5v3M6 8v.5"/></svg>
            <span id="err-id-text">Please enter a valid email address.</span>
          </p>
        </div>

        <!-- Password Input -->
        <div style="margin-bottom:0.25rem;">
          <label class="label font-medium text-xs text-gray-700" for="password">Password</label>
          <div class="pw-wrap">
            <input class="input-field" id="password" name="password" type="password" placeholder="••••••••" required style="padding-right:2.5rem;"/>
            <button class="eye" onclick="togglePw()" type="button" aria-label="Toggle password visibility">
              <svg id="eyeIcon" width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.35" stroke-linecap="round" stroke-linejoin="round">
                <path d="M1 8s2.5-5 7-5 7 5 7 5-2.5 5-7 5-7-5-7-5z"/>
                <circle cx="8" cy="8" r="2"/>
              </svg>
            </button>
          </div>
          <p class="errmsg" id="err-pw" role="alert">
            <svg width="12" height="12" viewBox="0 0 12 12" fill="none" stroke="#DC2626" stroke-width="1.4" stroke-linecap="round"><circle cx="6" cy="6" r="5"/><path d="M6 3.5v3M6 8v.5"/></svg>
            <span id="err-pw-text">Please enter your password.</span>
          </p>
        </div>

        <!-- Options -->
        <div style="display:flex; align-items:center; justify-content:space-between; margin:0.875rem 0 1.5rem;">
          <label style="display:flex; align-items:center; gap:7px; cursor:pointer; font-size:0.8125rem; color:#6B7280; user-select:none; font-family:'Inter',sans-serif;">
            <input type="checkbox" class="check" id="remember"/>
            Remember this device
          </label>
          <a href="#" class="bl" style="font-size:0.8125rem; font-weight:500; color:#4F7CAC; font-family:'Inter',sans-serif;">Forgot password?</a>
        </div>

        <!-- Submit Button -->
        <button type="submit" class="btn-action" id="signInBtn">Sign in</button>
      </form>

      <!-- SSO Divider -->
      <div style="display:flex; align-items:center; gap:10px; margin:1.25rem 0;">
        <div style="flex:1; height:1px; background:#EEF1F5;"></div>
        <span style="font-size:0.75rem; color:#CBD5E1; letter-spacing:0.02em;">or</span>
        <div style="flex:1; height:1px; background:#EEF1F5;"></div>
      </div>

      <!-- SSO Button -->
      <button class="btn-sso" onclick="handleSSO()">
        <svg width="15" height="15" viewBox="0 0 16 16" fill="none" stroke="#6B7280" stroke-width="1.3" stroke-linecap="round">
          <circle cx="8" cy="8" r="6.5"/>
          <path d="M1.5 8h13M8 1.5C6.2 3.5 5.3 5.6 5.3 8s.9 4.5 2.7 6.5M8 1.5c1.8 2 2.7 4.1 2.7 6.5S9.8 12.5 8 14.5"/>
        </svg>
        Continue with Hospital SSO
      </button>
    </div>

    <!-- Help Footer Links -->
    <p style="margin-top:1.5rem; font-size:0.75rem; color:#9CA3AF; text-align:center; line-height:1.7; font-family:'Inter',sans-serif;">
      Need help?&ensp;<a href="mailto:it@medcore.hospital" class="bl" style="font-size:0.75rem; font-weight:500; color:#4F7CAC;">it@medcore.hospital</a>
      <span style="margin:0 4px; color:#D1D5DB;">·</span>
      <a href="#" style="color:#9CA3AF; text-decoration:none; font-size:0.75rem;">Privacy Policy</a>
      <span style="margin:0 4px; color:#D1D5DB;">·</span>
      <span style="font-size:0.75rem; color:#C8D0DA;">© 2026 MedCore</span>
    </p>
  </div>
</div>

<script>
  let activeRole = 'Physician';

  /* ── Role segmented control ── */
  function setRole(btn, roleName) {
    document.querySelectorAll('.seg-item').forEach(b => {
      b.classList.remove('active');
      b.setAttribute('aria-pressed', 'false');
    });
    btn.classList.add('active');
    btn.setAttribute('aria-pressed', 'true');
    activeRole = roleName;
    clearErrors();
  }

  /* ── Password visibility toggle ── */
  let pwVisible = false;
  function togglePw() {
    pwVisible = !pwVisible;
    const inp  = document.getElementById('password');
    const icon = document.getElementById('eyeIcon');
    inp.type = pwVisible ? 'text' : 'password';
    icon.innerHTML = pwVisible
      ? `<path d="M2 2l12 12M6.5 6.6A2 2 0 009.4 9.4M4.5 4.6C2.8 5.8 1.6 7 1 8c1.4 2.3 4.1 4.5 7 4.5a7.4 7.4 0 003.3-.8M7.3 3.5c.2 0 .5-.1.7-.1C11 3.4 13.6 5.6 15 8c-.5.8-1.1 1.5-1.8 2.1"
            stroke="currentColor" stroke-width="1.35" stroke-linecap="round" stroke-linejoin="round"/>`
      : `<path d="M1 8s2.5-5 7-5 7 5 7 5-2.5 5-7 5-7-5-7-5z"/><circle cx="8" cy="8" r="2"/>`;
  }

  /* ── Error displays ── */
  function clearErrors() {
    ['email', 'password'].forEach(id => {
      document.getElementById(id).classList.remove('border-red-500');
    });
    document.getElementById('err-id').style.display = 'none';
    document.getElementById('err-pw').style.display = 'none';
    document.getElementById('client-error-banner').classList.add('hidden');
    const serverErr = document.getElementById('server-error-banner');
    if (serverErr) {
        serverErr.classList.add('hidden');
    }
  }

  function handleFormSubmit(event) {
    clearErrors();
    const emailVal = document.getElementById('email').value.trim();
    const pwVal = document.getElementById('password').value;
    let ok = true;

    // 1. Role Enforcement (Only Physician/Doctor allowed)
    if (activeRole !== 'Physician') {
      document.getElementById('client-error-text').textContent = 'Access Restricted: Only Physician login is currently active. Nurse, Admin, and Technician portals are in development.';
      document.getElementById('client-error-banner').classList.remove('hidden');
      event.preventDefault();
      return false;
    }

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

    if (!ok) {
        event.preventDefault();
        return false;
    }

    // Submit animation
    const btn = document.getElementById('signInBtn');
    btn.textContent = 'Authenticating…';
    btn.disabled = true;
    return true;
  }

  function handleSSO() {
    alert('SSO is disabled for this demonstration.');
  }
</script>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>

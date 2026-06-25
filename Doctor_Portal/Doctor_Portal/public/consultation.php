<?php
/**
 * Patient Consultation Case Sheet
 * 
 * Renders an interactive clinical record form structured in 7 separate tabs.
 * Doctors record vitals, medical history, clinical notes, search and select diagnoses,
 * prescribe medications with instructions, and order lab tests with priorities and categories.
 * Integrates a live session timer, patient timeline history, and draft saving capabilities.
 * 
 * Emoji-Free Version with MedCore UI/UX styling.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Verify doctor authentication
if (!isset($_SESSION['doctor_id'])) {
    header("Location: login.php");
    exit();
}

// Check for appointment_id in GET parameters
$appointment_id = filter_input(INPUT_GET, 'appointment_id', FILTER_VALIDATE_INT) ?: (filter_var($_GET['appointment_id'] ?? null, FILTER_VALIDATE_INT) ?: null);
if (!$appointment_id) {
    $_SESSION['error_msg'] = "Invalid appointment ID.";
    header("Location: dashboard.php");
    exit();
}

// Include database connection
require_once __DIR__ . '/../config/db.php';

try {
    // 1. Retrieve appointment and patient information
    $stmt = $pdo->prepare("
        SELECT a.appointment_id, a.appointment_date, a.appointment_time, a.status, a.doctor_id,
               p.patient_id, p.name AS patient_name, p.dob, p.gender
        FROM appointments a
        INNER JOIN patients p ON a.patient_id = p.patient_id
        WHERE a.appointment_id = ?
    ");
    $stmt->execute([$appointment_id]);
    $appointment = $stmt->fetch();

    // Check if appointment exists
    if (!$appointment) {
        $_SESSION['error_msg'] = "Appointment record not found.";
        header("Location: dashboard.php");
        exit();
    }

    // Verify ownership security
    if ($appointment['doctor_id'] != $_SESSION['doctor_id']) {
        $_SESSION['error_msg'] = "Access denied: You are not assigned to this appointment.";
        header("Location: dashboard.php");
        exit();
    }

    // Check status: must be Accepted to start/resume consultation
    if ($appointment['status'] === 'Scheduled') {
        $_SESSION['error_msg'] = "You must accept the appointment before starting the consultation.";
        header("Location: dashboard.php?selected_id=" . $appointment_id);
        exit();
    }
    if ($appointment['status'] === 'Completed') {
        $_SESSION['error_msg'] = "Consultation for this appointment has already been completed.";
        header("Location: dashboard.php?selected_id=" . $appointment_id);
        exit();
    }

    // Calculate age
    $birthDate = new DateTime($appointment['dob']);
    $todayDate = new DateTime();
    $patient_age = $todayDate->diff($birthDate)->y;

    // Fetch all doctors for referral purposes
    $stmtDocs = $pdo->query("SELECT doctor_id, name FROM doctors ORDER BY name ASC");
    $all_doctors = $stmtDocs->fetchAll();

    // 2. Try to load existing consultation details (if it is a draft)
    $existing_consultation = null;
    $existing_diagnoses = [];
    $existing_prescriptions = [];
    $existing_tests = [];

    $stmt = $pdo->prepare("SELECT * FROM consultations WHERE appointment_id = ?");
    $stmt->execute([$appointment_id]);
    $existing_consultation = $stmt->fetch();

    if ($existing_consultation) {
        $c_id = $existing_consultation['consultation_id'];
        
        $stmt = $pdo->prepare("SELECT * FROM consultation_diagnoses WHERE consultation_id = ?");
        $stmt->execute([$c_id]);
        $existing_diagnoses = $stmt->fetchAll();

        $stmt = $pdo->prepare("SELECT * FROM consultation_prescriptions WHERE consultation_id = ?");
        $stmt->execute([$c_id]);
        $existing_prescriptions = $stmt->fetchAll();

        $stmt = $pdo->prepare("SELECT * FROM consultation_tests WHERE consultation_id = ?");
        $stmt->execute([$c_id]);
        $existing_tests = $stmt->fetchAll();
    } else {
        $existing_consultation = [];
    }

    $ros_draft = [];
    if (!empty($existing_consultation['ros_data'])) {
        $ros_draft = json_decode($existing_consultation['ros_data'], true);
    }
    $exam_draft = [];
    if (!empty($existing_consultation['exam_data'])) {
        $exam_draft = json_decode($existing_consultation['exam_data'], true);
    }
    $nursing_plan = [];
    if (!empty($existing_consultation['nursing_plan'])) {
        $nursing_plan = json_decode($existing_consultation['nursing_plan'], true);
    }

    // Helper to query if a lab test is active in draft
    function getDraftTest($existing_tests, $test_name) {
        foreach ($existing_tests as $t) {
            if ($t['test_name'] === $test_name) {
                return $t;
            }
        }
        return null;
    }

    // 3. Fetch patient's medical timeline (previous completed/finalized visits)
    $patient_id = $appointment['patient_id'];
    $past_visits = [];
    
    $stmt = $pdo->prepare("
        SELECT c.consultation_id, c.blood_pressure, c.temperature, c.pain_scale, c.narrative_diagnosis, c.chief_complaint, a.appointment_date, a.appointment_time
        FROM consultations c
        INNER JOIN appointments a ON c.appointment_id = a.appointment_id
        WHERE a.patient_id = ? AND a.appointment_id != ? AND c.status = 'Finalized'
        ORDER BY a.appointment_date DESC, a.appointment_time DESC
    ");
    $stmt->execute([$patient_id, $appointment_id]);
    $past_visits = $stmt->fetchAll();

    for ($i = 0; $i < count($past_visits); $i++) {
        $v_id = $past_visits[$i]['consultation_id'];
        
        $stmt = $pdo->prepare("SELECT * FROM consultation_diagnoses WHERE consultation_id = ?");
        $stmt->execute([$v_id]);
        $past_visits[$i]['diagnoses'] = $stmt->fetchAll();

        $stmt = $pdo->prepare("SELECT * FROM consultation_prescriptions WHERE consultation_id = ?");
        $stmt->execute([$v_id]);
        $past_visits[$i]['prescriptions'] = $stmt->fetchAll();

        $stmt = $pdo->prepare("SELECT * FROM consultation_tests WHERE consultation_id = ?");
        $stmt->execute([$v_id]);
        $past_visits[$i]['tests'] = $stmt->fetchAll();
    }

} catch (PDOException $e) {
    $_SESSION['error_msg'] = "Database error: " . $e->getMessage();
    header("Location: dashboard.php");
    exit();
}
?>
<?php
$pageTitle = "Consultation Case Sheet";
include_once __DIR__ . '/../includes/header.php';
?>
<!-- Custom styling for form elements -->
<style>
    .symptom-card {
        transition: all 0.2s ease-in-out;
        background-color: #FFFFFF;
        border-color: #E5EAF0;
    }
    .symptom-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.03);
        border-color: #CBD5E1;
    }
    .symptom-card.active {
        background-color: #E4EBF4;
        border-color: #4F7CAC !important;
        box-shadow: 0 4px 12px rgba(79, 124, 172, 0.1);
    }
    .symptom-card.alert-pulse {
        border-color: #DC2626;
        animation: pulse-border 1.5s infinite;
    }
    @keyframes pulse-border {
        0% { box-shadow: 0 0 0 0 rgba(220, 38, 38, 0.4); }
        70% { box-shadow: 0 0 0 10px rgba(220, 38, 38, 0); }
        100% { box-shadow: 0 0 0 0 rgba(220, 38, 38, 0); }
    }
    input[type=number]::-webkit-inner-spin-button, 
    input[type=number]::-webkit-outer-spin-button { 
        -webkit-appearance: none;
        margin: 0; 
    }
    input[type=number] {
        -moz-appearance: textfield;
    }

    /* Beating heart animation */
    @keyframes heart-beat {
        0% { transform: scale(1); }
        14% { transform: scale(1.18); }
        28% { transform: scale(1); }
        42% { transform: scale(1.18); }
        70% { transform: scale(1); }
    }
    .beating-heart {
        animation: heart-beat 0.83s infinite ease-in-out;
        transform-origin: center;
    }
    
    /* Sleek range inputs */
    input[type=range] {
        -webkit-appearance: none;
        width: 100%;
        background: transparent;
    }
    input[type=range]:focus {
        outline: none;
    }
    input[type=range]::-webkit-slider-runnable-track {
        width: 100%;
        height: 6px;
        cursor: pointer;
        background: #E5EAF0;
        border-radius: 3px;
        transition: background 0.15s ease;
    }
    input[type=range]::-webkit-slider-thumb {
        height: 18px;
        width: 18px;
        border-radius: 50%;
        background: #4F7CAC;
        cursor: pointer;
        -webkit-appearance: none;
        margin-top: -6px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        transition: transform 0.1s ease, background 0.15s ease;
    }
    input[type=range]::-webkit-slider-thumb:hover {
        transform: scale(1.15);
        background: #3D6490;
    }
    input[type=range]::-moz-range-track {
        width: 100%;
        height: 6px;
        cursor: pointer;
        background: #E5EAF0;
        border-radius: 3px;
    }
    input[type=range]::-moz-range-thumb {
        height: 18px;
        width: 18px;
        border: none;
        border-radius: 50%;
        background: #4F7CAC;
        cursor: pointer;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        transition: transform 0.1s ease, background 0.15s ease;
    }
    input[type=range]::-moz-range-thumb:hover {
        transform: scale(1.15);
        background: #3D6490;
    }

    /* Pain level buttons */
    .pain-circle {
        transition: all 0.2s ease-in-out;
        cursor: pointer;
    }
    .pain-circle:hover {
        transform: scale(1.12);
        box-shadow: 0 4px 8px rgba(0,0,0,0.1);
    }
    .pain-circle.selected {
        transform: scale(1.2);
        box-shadow: 0 0 0 3px #FFFFFF, 0 0 0 5px currentColor;
    }
</style>
</head>
<body class="bg-hms-bg min-h-screen">
<?php
$backToDashboard = true;
include_once __DIR__ . '/../includes/navbar.php';
?>

    <!-- Content Container -->
    <div class="w-full px-4 md:px-8 pb-12">
        <div class="w-full">
            
            <!-- Patient Context Banner -->
            <div class="bg-white border border-hms-border rounded-xl p-5 mb-6 shadow-sm">
                <div class="bg-hms-panel border-l-4 border-hms-accent rounded p-4">
                    <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
                        <div>
                            <h3 class="font-serif text-2xl font-bold text-hms-dark"><?php echo htmlspecialchars($appointment['patient_name']); ?></h3>
                            <div class="text-hms-mid text-xs font-medium mt-1">
                                <span class="bg-hms-accent text-white px-2.5 py-0.5 rounded-full text-xxs mr-2 font-bold"><?php echo htmlspecialchars($appointment['gender']); ?></span>
                                <span class="mr-4"><strong>Age:</strong> <?php echo $patient_age; ?> Years (DOB: <?php echo date('d-M-Y', strtotime($appointment['dob'])); ?>)</span>
                                <span><strong>Patient ID:</strong> #<?php echo htmlspecialchars($appointment['patient_id']); ?></span>
                            </div>
                        </div>
                        <div class="flex items-center gap-3 self-stretch md:self-auto justify-between md:justify-end">
                            <div class="bg-white text-hms-dark border border-hms-border font-mono text-sm px-4 py-1.5 rounded-full font-bold shadow-sm" id="sessionTimerBadge" title="Consultation Session Active Timer">
                                Session: <span id="sessionTimer">00:00</span>
                            </div>
                            <span class="bg-white text-hms-dark border border-hms-border px-3 py-1.5 rounded-full text-xs font-semibold shadow-sm">Appointment ID: #<?php echo $appointment_id; ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Consultation Form -->
            <form action="../actions/save_consultation.php" method="POST" id="consultationForm" novalidate>
                <!-- Hidden field passing appointment context -->
                <input type="hidden" name="appointment_id" value="<?php echo $appointment_id; ?>">
                <input type="hidden" name="session_duration" id="session_duration" value="0">

                <div class="bg-white border border-hms-border rounded-xl p-6 shadow-sm">
                    
                    <!-- Active Section Header & Offcanvas Menu Trigger -->
                    <div class="flex justify-between items-center mb-6 pb-2 border-b border-hms-border">
                        <div class="flex items-center gap-2">
                            <span class="text-hms-muted font-bold text-xxs uppercase tracking-wider">Active Section:</span>
                            <h4 class="m-0 font-serif text-lg font-bold text-hms-accent" id="currentSectionTitle">1. Vitals</h4>
                        </div>
                        <button class="border border-hms-accent text-hms-accent hover:bg-hms-accent hover:text-white rounded-full px-4 py-2 text-xs font-semibold tracking-wide transition duration-150 flex items-center gap-2" type="button" id="sectionsMenuBtn" onclick="if(document.getElementById('consultationDrawer').style.left==='0px'){closeDrawer();}else{openDrawer();}">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                                <path fill-rule="evenodd" d="M2.5 12a.5.5 0 0 1 .5-.5h10a.5.5 0 0 1 0 1H3a.5.5 0 0 1-.5-.5zm0-4a.5.5 0 0 1 .5-.5h10a.5.5 0 0 1 0 1H3a.5.5 0 0 1-.5-.5zm0-4a.5.5 0 0 1 .5-.5h10a.5.5 0 0 1 0 1H3a.5.5 0 0 1-.5-.5z"/>
                            </svg>
                            <span>Sections Menu</span>
                        </button>
                    </div>

                    <div class="tab-content" id="consultationTabContent">
                        
                        <!-- TAB 1: VITALS MANAGEMENT -->
                        <div class="tab-pane fade show active" id="vitals-pane" role="tabpanel" aria-labelledby="drawer-vitals-tab">
                            <?php
                            $bp_systolic = 120;
                            $bp_diastolic = 80;
                            if (!empty($existing_consultation['blood_pressure'])) {
                                $parts = explode('/', $existing_consultation['blood_pressure']);
                                if (count($parts) === 2) {
                                    $bp_systolic = (int)$parts[0];
                                    $bp_diastolic = (int)$parts[1];
                                }
                            }
                            ?>
                            <h4 class="font-serif text-lg font-semibold text-hms-accent mb-4 border-b border-hms-border pb-1">Patient Vitals Gathering</h4>
                            
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                                <!-- Blood Pressure Card -->
                                <div class="bg-white border border-hms-border p-5 rounded-2xl shadow-sm transition hover:shadow flex flex-col justify-between h-full">
                                    <div class="flex justify-between items-center mb-3">
                                        <div class="flex items-center gap-2">
                                            <div class="p-2 bg-blue-50 rounded-xl">
                                                <svg class="w-5 h-5 text-hms-accent" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                                                    <circle cx="12" cy="12" r="9"></circle>
                                                    <path d="M12 7v5l3 3"></path>
                                                </svg>
                                            </div>
                                            <label for="blood_pressure" class="text-xs font-bold text-hms-mid cursor-pointer">Blood Pressure * (Required)</label>
                                        </div>
                                        <span id="bp_status_badge" class="px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-100 text-emerald-800">Normal</span>
                                    </div>
                                    <div class="mb-4">
                                        <span id="bp_val_display" class="text-2xl font-bold font-mono text-hms-dark">120/80</span>
                                        <span class="text-xs text-hms-muted font-medium ml-1">mmHg</span>
                                    </div>
                                    <div class="space-y-3">
                                        <div>
                                            <div class="flex justify-between text-[10px] font-bold text-hms-muted mb-1">
                                                <span>Systolic</span>
                                                <span id="bp_sys_display">120 mmHg</span>
                                            </div>
                                            <input type="range" min="70" max="200" step="1" value="<?php echo $bp_systolic; ?>" id="bp_systolic" class="w-full">
                                        </div>
                                        <div>
                                            <div class="flex justify-between text-[10px] font-bold text-hms-muted mb-1">
                                                <span>Diastolic</span>
                                                <span id="bp_dia_display">80 mmHg</span>
                                            </div>
                                            <input type="range" min="40" max="130" step="1" value="<?php echo $bp_diastolic; ?>" id="bp_diastolic" class="w-full">
                                        </div>
                                    </div>
                                    <input type="hidden" id="blood_pressure" name="blood_pressure" value="<?php echo htmlspecialchars($existing_consultation['blood_pressure'] ?? '120/80'); ?>" required>
                                    <div class="flex flex-wrap gap-1 mt-4 pt-3 border-t border-dashed border-hms-border">
                                        <button type="button" class="bp-preset text-[9px] bg-gray-50 hover:bg-hms-accent hover:text-white px-2 py-1 rounded transition duration-150 border border-hms-border font-semibold text-hms-dark" data-val="120/80">Normal</button>
                                        <button type="button" class="bp-preset text-[9px] bg-gray-50 hover:bg-hms-accent hover:text-white px-2 py-1 rounded transition duration-150 border border-hms-border font-semibold text-hms-dark" data-val="130/85">Elevated</button>
                                        <button type="button" class="bp-preset text-[9px] bg-gray-50 hover:bg-hms-accent hover:text-white px-2 py-1 rounded transition duration-150 border border-hms-border font-semibold text-hms-dark" data-val="140/90">HTN Stage 1</button>
                                        <button type="button" class="bp-preset text-[9px] bg-gray-50 hover:bg-hms-accent hover:text-white px-2 py-1 rounded transition duration-150 border border-hms-border font-semibold text-hms-dark" data-val="90/60">Hypotension</button>
                                    </div>
                                </div>

                                <!-- Temperature Card -->
                                <div class="bg-white border border-hms-border p-5 rounded-2xl shadow-sm transition hover:shadow flex flex-col justify-between h-full">
                                    <div class="flex justify-between items-center mb-3">
                                        <div class="flex items-center gap-2">
                                            <div class="p-2 bg-amber-50 rounded-xl">
                                                <svg class="w-5 h-5 text-amber-500" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                                                    <path d="M14 14.76V3.5a2.5 2.5 0 0 0-5 0v11.26a4.5 4.5 0 1 0 5 0z"></path>
                                                </svg>
                                            </div>
                                            <label for="temperature" class="text-xs font-bold text-hms-mid cursor-pointer">Temperature * (Required)</label>
                                        </div>
                                        <span id="temp_status_badge" class="px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-100 text-emerald-800">Normal</span>
                                    </div>
                                    <div class="mb-4">
                                        <span id="temp_val_display" class="text-2xl font-bold font-mono text-hms-dark"><?php echo htmlspecialchars($existing_consultation['temperature'] ?? '36.8'); ?></span>
                                        <span class="text-xs text-hms-muted font-medium ml-1">°C</span>
                                    </div>
                                    <div class="mb-4">
                                        <input type="range" min="30.0" max="45.0" step="0.1" value="<?php echo htmlspecialchars($existing_consultation['temperature'] ?? '36.8'); ?>" id="temperature" name="temperature" class="w-full" required>
                                        <div class="flex justify-between items-center mt-3">
                                            <button type="button" class="decrement-btn border border-hms-border hover:bg-gray-100 px-3 py-1.5 rounded bg-white text-xs font-bold select-none text-hms-dark" data-target="temperature" data-step="0.1">- 0.1°C</button>
                                            <button type="button" class="increment-btn border border-hms-border hover:bg-gray-100 px-3 py-1.5 rounded bg-white text-xs font-bold select-none text-hms-dark" data-target="temperature" data-step="0.1">+ 0.1°C</button>
                                        </div>
                                    </div>
                                    <div class="flex flex-wrap gap-1 mt-auto pt-3 border-t border-dashed border-hms-border">
                                        <button type="button" class="temp-preset text-[9px] bg-gray-50 hover:bg-hms-accent hover:text-white px-2 py-1 rounded transition duration-150 border border-hms-border font-semibold text-hms-dark" data-val="36.8">Normal (36.8°C)</button>
                                        <button type="button" class="temp-preset text-[9px] bg-gray-50 hover:bg-hms-accent hover:text-white px-2 py-1 rounded transition duration-150 border border-hms-border font-semibold text-hms-dark" data-val="37.8">Low Fever</button>
                                        <button type="button" class="temp-preset text-[9px] bg-gray-50 hover:bg-hms-accent hover:text-white px-2 py-1 rounded transition duration-150 border border-hms-border font-semibold text-hms-dark" data-val="38.8">High Fever</button>
                                    </div>
                                </div>

                                <!-- Heart Rate Card -->
                                <div class="bg-white border border-hms-border p-5 rounded-2xl shadow-sm transition hover:shadow flex flex-col justify-between h-full">
                                    <div class="flex justify-between items-center mb-3">
                                        <div class="flex items-center gap-2">
                                            <div class="p-2 bg-red-50 rounded-xl">
                                                <svg class="w-5 h-5 text-red-500 beating-heart" id="beatingHeartIcon" fill="currentColor" viewBox="0 0 24 24">
                                                    <path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"></path>
                                                </svg>
                                            </div>
                                            <label for="heart_rate" class="text-xs font-bold text-hms-mid cursor-pointer">Heart Rate * (Required)</label>
                                        </div>
                                        <span id="hr_status_badge" class="px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-100 text-emerald-800">Normal</span>
                                    </div>
                                    <div class="mb-4">
                                        <span id="hr_val_display" class="text-2xl font-bold font-mono text-hms-dark"><?php echo htmlspecialchars($existing_consultation['heart_rate'] ?? '72'); ?></span>
                                        <span class="text-xs text-hms-muted font-medium ml-1">bpm</span>
                                    </div>
                                    <div class="mb-4">
                                        <input type="range" min="30" max="250" step="1" value="<?php echo htmlspecialchars($existing_consultation['heart_rate'] ?? '72'); ?>" id="heart_rate" name="heart_rate" class="w-full" required>
                                        <div class="flex justify-between items-center mt-3">
                                            <button type="button" class="decrement-btn border border-hms-border hover:bg-gray-100 px-3 py-1.5 rounded bg-white text-xs font-bold select-none text-hms-dark" data-target="heart_rate" data-step="1">- 1 bpm</button>
                                            <button type="button" class="increment-btn border border-hms-border hover:bg-gray-100 px-3 py-1.5 rounded bg-white text-xs font-bold select-none text-hms-dark" data-target="heart_rate" data-step="1">+ 1 bpm</button>
                                        </div>
                                    </div>
                                    <div class="flex flex-wrap gap-1 mt-auto pt-3 border-t border-dashed border-hms-border">
                                        <button type="button" class="hr-preset text-[9px] bg-gray-50 hover:bg-hms-accent hover:text-white px-2 py-1 rounded transition duration-150 border border-hms-border font-semibold text-hms-dark" data-val="72">Normal (72)</button>
                                        <button type="button" class="hr-preset text-[9px] bg-gray-50 hover:bg-hms-accent hover:text-white px-2 py-1 rounded transition duration-150 border border-hms-border font-semibold text-hms-dark" data-val="55">Bradycardia</button>
                                        <button type="button" class="hr-preset text-[9px] bg-gray-50 hover:bg-hms-accent hover:text-white px-2 py-1 rounded transition duration-150 border border-hms-border font-semibold text-hms-dark" data-val="105">Tachycardia</button>
                                    </div>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                                <!-- Weight Card -->
                                <div class="bg-white border border-hms-border p-5 rounded-2xl shadow-sm transition hover:shadow flex flex-col justify-between h-full">
                                    <div class="flex justify-between items-center mb-3">
                                        <div class="flex items-center gap-2">
                                            <div class="p-2 bg-indigo-50 rounded-xl">
                                                <svg class="w-5 h-5 text-indigo-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                    <rect x="5" y="5" width="14" height="14" rx="2" stroke-width="2"></rect>
                                                    <circle cx="12" cy="12" r="3" stroke-width="2"></circle>
                                                    <path d="M12 12l2-2" stroke-width="2"></path>
                                                </svg>
                                            </div>
                                            <label for="weight" class="text-xs font-bold text-hms-mid cursor-pointer">Weight * (Required)</label>
                                        </div>
                                        <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-indigo-50 text-indigo-700">Patient weight</span>
                                    </div>
                                    <div class="mb-4">
                                        <span id="weight_val_display" class="text-2xl font-bold font-mono text-hms-dark"><?php echo htmlspecialchars($existing_consultation['weight'] ?? '70.0'); ?></span>
                                        <span class="text-xs text-hms-muted font-medium ml-1">kg</span>
                                    </div>
                                    <div class="mb-4">
                                        <input type="range" min="1.0" max="400.0" step="0.5" value="<?php echo htmlspecialchars($existing_consultation['weight'] ?? '70.0'); ?>" id="weight" name="weight" class="w-full" required>
                                        <div class="flex justify-between items-center mt-3">
                                            <button type="button" class="decrement-btn border border-hms-border hover:bg-gray-100 px-3 py-1.5 rounded bg-white text-xs font-bold select-none text-hms-dark" data-target="weight" data-step="0.5">- 0.5 kg</button>
                                            <button type="button" class="increment-btn border border-hms-border hover:bg-gray-100 px-3 py-1.5 rounded bg-white text-xs font-bold select-none text-hms-dark" data-target="weight" data-step="0.5">+ 0.5 kg</button>
                                        </div>
                                    </div>
                                    <div class="flex flex-wrap gap-1 mt-auto pt-3 border-t border-dashed border-hms-border">
                                        <button type="button" class="weight-preset text-[9px] bg-gray-50 hover:bg-hms-accent hover:text-white px-2 py-1 rounded transition duration-150 border border-hms-border font-semibold text-hms-dark" data-val="55">55 kg</button>
                                        <button type="button" class="weight-preset text-[9px] bg-gray-50 hover:bg-hms-accent hover:text-white px-2 py-1 rounded transition duration-150 border border-hms-border font-semibold text-hms-dark" data-val="70">70 kg</button>
                                        <button type="button" class="weight-preset text-[9px] bg-gray-50 hover:bg-hms-accent hover:text-white px-2 py-1 rounded transition duration-150 border border-hms-border font-semibold text-hms-dark" data-val="85">85 kg</button>
                                        <button type="button" class="weight-preset text-[9px] bg-gray-50 hover:bg-hms-accent hover:text-white px-2 py-1 rounded transition duration-150 border border-hms-border font-semibold text-hms-dark" data-val="100">100 kg</button>
                                    </div>
                                </div>

                                <!-- Oxygen Saturation Card -->
                                <div class="bg-white border border-hms-border p-5 rounded-2xl shadow-sm transition hover:shadow flex flex-col justify-between h-full">
                                    <div class="flex justify-between items-center mb-3">
                                        <div class="flex items-center gap-2">
                                            <div class="p-2 bg-teal-50 rounded-xl">
                                                <svg class="w-5 h-5 text-teal-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                    <path d="M12 2.69l5.66 5.66a8 8 0 1 1-11.31 0z"></path>
                                                </svg>
                                            </div>
                                            <label for="oxygen_saturation" class="text-xs font-bold text-hms-mid cursor-pointer">Oxygen Saturation * (Required)</label>
                                        </div>
                                        <span id="spo2_status_badge" class="px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-100 text-emerald-800">Normal</span>
                                    </div>
                                    <div class="mb-4">
                                        <span id="spo2_val_display" class="text-2xl font-bold font-mono text-hms-dark"><?php echo htmlspecialchars($existing_consultation['oxygen_saturation'] ?? '98'); ?></span>
                                        <span class="text-xs text-hms-muted font-medium ml-1">% SpO2</span>
                                    </div>
                                    <div class="mb-4">
                                        <input type="range" min="50" max="100" step="1" value="<?php echo htmlspecialchars($existing_consultation['oxygen_saturation'] ?? '98'); ?>" id="oxygen_saturation" name="oxygen_saturation" class="w-full" required>
                                        <div class="flex justify-between items-center mt-3">
                                            <button type="button" class="decrement-btn border border-hms-border hover:bg-gray-100 px-3 py-1.5 rounded bg-white text-xs font-bold select-none text-hms-dark" data-target="oxygen_saturation" data-step="1">- 1%</button>
                                            <button type="button" class="increment-btn border border-hms-border hover:bg-gray-100 px-3 py-1.5 rounded bg-white text-xs font-bold select-none text-hms-dark" data-target="oxygen_saturation" data-step="1">+ 1%</button>
                                        </div>
                                    </div>
                                    <div class="flex flex-wrap gap-1 mt-auto pt-3 border-t border-dashed border-hms-border">
                                        <button type="button" class="spo2-preset text-[9px] bg-gray-50 hover:bg-hms-accent hover:text-white px-2 py-1 rounded transition duration-150 border border-hms-border font-semibold text-hms-dark" data-val="98">Normal (98%)</button>
                                        <button type="button" class="spo2-preset text-[9px] bg-gray-50 hover:bg-hms-accent hover:text-white px-2 py-1 rounded transition duration-150 border border-hms-border font-semibold text-hms-dark" data-val="94">Mild Hypoxia</button>
                                        <button type="button" class="spo2-preset text-[9px] bg-gray-50 hover:bg-hms-accent hover:text-white px-2 py-1 rounded transition duration-150 border border-hms-border font-semibold text-hms-dark" data-val="88">Severe Hypoxia</button>
                                    </div>
                                </div>

                                <!-- Height Card -->
                                <div class="bg-white border border-hms-border p-5 rounded-2xl shadow-sm transition hover:shadow flex flex-col justify-between h-full">
                                    <div class="flex justify-between items-center mb-3">
                                        <div class="flex items-center gap-2">
                                            <div class="p-2 bg-indigo-50 rounded-xl">
                                                <svg class="w-5 h-5 text-indigo-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                    <path d="M5 3h14v2H5V3zm0 14h14v2H5v-2zm7-10V7h2V7a3 3 0 00-6 0v1h1v9h2v-9h1z"></path>
                                                </svg>
                                            </div>
                                            <label for="height" class="text-xs font-bold text-hms-mid cursor-pointer">Height * (Required)</label>
                                        </div>
                                        <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-indigo-50 text-indigo-700">Patient height</span>
                                    </div>
                                    <div class="mb-4">
                                        <span id="height_val_display" class="text-2xl font-bold font-mono text-hms-dark"><?php echo htmlspecialchars($existing_consultation['height'] ?? '160.0'); ?></span>
                                        <span class="text-xs text-hms-muted font-medium ml-1">cm</span>
                                    </div>
                                    <div class="mb-4">
                                        <input type="range" min="30.0" max="250.0" step="0.5" value="<?php echo htmlspecialchars($existing_consultation['height'] ?? '160.0'); ?>" id="height" name="height" class="w-full" required>
                                        <div class="flex justify-between items-center mt-3">
                                            <button type="button" class="decrement-btn border border-hms-border hover:bg-gray-100 px-3 py-1.5 rounded bg-white text-xs font-bold select-none text-hms-dark" data-target="height" data-step="1.0">- 1.0 cm</button>
                                            <button type="button" class="increment-btn border border-hms-border hover:bg-gray-100 px-3 py-1.5 rounded bg-white text-xs font-bold select-none text-hms-dark" data-target="height" data-step="1.0">+ 1.0 cm</button>
                                        </div>
                                    </div>
                                    <div class="flex flex-wrap gap-1 mt-auto pt-3 border-t border-dashed border-hms-border">
                                        <button type="button" class="height-preset text-[9px] bg-gray-50 hover:bg-hms-accent hover:text-white px-2 py-1 rounded transition duration-150 border border-hms-border font-semibold text-hms-dark" data-val="150">150 cm</button>
                                        <button type="button" class="height-preset text-[9px] bg-gray-50 hover:bg-hms-accent hover:text-white px-2 py-1 rounded transition duration-150 border border-hms-border font-semibold text-hms-dark" data-val="160">160 cm</button>
                                        <button type="button" class="height-preset text-[9px] bg-gray-50 hover:bg-hms-accent hover:text-white px-2 py-1 rounded transition duration-150 border border-hms-border font-semibold text-hms-dark" data-val="170">170 cm</button>
                                        <button type="button" class="height-preset text-[9px] bg-gray-50 hover:bg-hms-accent hover:text-white px-2 py-1 rounded transition duration-150 border border-hms-border font-semibold text-hms-dark" data-val="180">180 cm</button>
                                    </div>
                                </div>

                                <!-- Respiratory Rate Card -->
                                <div class="bg-white border border-hms-border p-5 rounded-2xl shadow-sm transition hover:shadow flex flex-col justify-between h-full">
                                    <div class="flex justify-between items-center mb-3">
                                        <div class="flex items-center gap-2">
                                            <div class="p-2 bg-emerald-50 rounded-xl">
                                                <svg class="w-5 h-5 text-emerald-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                    <path d="M12 3v18M3 12h18"></path>
                                                </svg>
                                            </div>
                                            <label for="respiratory_rate" class="text-xs font-bold text-hms-mid cursor-pointer">Respiratory Rate * (Required)</label>
                                        </div>
                                        <span id="resp_status_badge" class="px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-100 text-emerald-800">Normal</span>
                                    </div>
                                    <div class="mb-4">
                                        <span id="respiratory_rate_val_display" class="text-2xl font-bold font-mono text-hms-dark"><?php echo htmlspecialchars($existing_consultation['respiratory_rate'] ?? '18'); ?></span>
                                        <span class="text-xs text-hms-muted font-medium ml-1">breaths/min</span>
                                    </div>
                                    <div class="mb-4">
                                        <input type="range" min="5" max="60" step="1" value="<?php echo htmlspecialchars($existing_consultation['respiratory_rate'] ?? '18'); ?>" id="respiratory_rate" name="respiratory_rate" class="w-full" required>
                                        <div class="flex justify-between items-center mt-3">
                                            <button type="button" class="decrement-btn border border-hms-border hover:bg-gray-100 px-3 py-1.5 rounded bg-white text-xs font-bold select-none text-hms-dark" data-target="respiratory_rate" data-step="1">- 1</button>
                                            <button type="button" class="increment-btn border border-hms-border hover:bg-gray-100 px-3 py-1.5 rounded bg-white text-xs font-bold select-none text-hms-dark" data-target="respiratory_rate" data-step="1">+ 1</button>
                                        </div>
                                    </div>
                                    <div class="flex flex-wrap gap-1 mt-auto pt-3 border-t border-dashed border-hms-border">
                                        <button type="button" class="resp-preset text-[9px] bg-gray-50 hover:bg-hms-accent hover:text-white px-2 py-1 rounded transition duration-150 border border-hms-border font-semibold text-hms-dark" data-val="12">12 /min</button>
                                        <button type="button" class="resp-preset text-[9px] bg-gray-50 hover:bg-hms-accent hover:text-white px-2 py-1 rounded transition duration-150 border border-hms-border font-semibold text-hms-dark" data-val="16">16 /min</button>
                                        <button type="button" class="resp-preset text-[9px] bg-gray-50 hover:bg-hms-accent hover:text-white px-2 py-1 rounded transition duration-150 border border-hms-border font-semibold text-hms-dark" data-val="20">20 /min</button>
                                    </div>
                                </div>

                                <!-- BMI Card (Calculated) -->
                                <div class="bg-white border border-hms-border p-5 rounded-2xl shadow-sm transition hover:shadow flex flex-col justify-between h-full">
                                    <div class="flex justify-between items-center mb-3">
                                        <div class="flex items-center gap-2">
                                            <div class="p-2 bg-sky-50 rounded-xl">
                                                <svg class="w-5 h-5 text-sky-500" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                                                    <path d="M12 2a10 10 0 100 20 10 10 0 000-20zm0 18a8 8 0 110-16 8 8 0 010 16z"></path>
                                                </svg>
                                            </div>
                                            <span class="text-xs font-bold text-hms-mid">Body Mass Index (BMI)</span>
                                        </div>
                                        <span id="bmi_status_badge" class="px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-100 text-emerald-800">Normal</span>
                                    </div>
                                    <div class="mb-4">
                                        <span id="bmi_val_display" class="text-2xl font-bold font-mono text-hms-dark">--</span>
                                        <span class="text-xs text-hms-muted font-medium ml-1">kg/m&sup2;</span>
                                    </div>
                                    <div class="text-xxs text-hms-muted leading-normal mt-auto pt-3 border-t border-dashed border-hms-border">
                                        Calculated dynamically from height and weight parameters.
                                    </div>
                                </div>

                                <!-- Pain Scale Card (Spans across all cols) -->
                                <div class="md:col-span-3 bg-white border border-hms-border p-5 rounded-2xl shadow-sm transition hover:shadow flex flex-col justify-between">
                                    <div class="flex justify-between items-center mb-3">
                                        <div class="flex items-center gap-2">
                                            <div class="p-2 bg-purple-50 rounded-xl">
                                                <svg class="w-5 h-5 text-purple-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                    <circle cx="12" cy="12" r="10"></circle>
                                                    <path d="M8 14s1.5 2 4 2 4-2 4-2"></path>
                                                    <line x1="9" y1="9" x2="9.01" y2="9"></line>
                                                    <line x1="15" y1="9" x2="15.01" y2="9"></line>
                                                </svg>
                                            </div>
                                            <label for="pain_scale" class="text-xs font-bold text-hms-mid cursor-pointer">Pain Scale Level (Wong-Baker Scale) * (Required)</label>
                                        </div>
                                        <span id="pain_status_badge" class="px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-100 text-emerald-800">Mild / No Pain</span>
                                    </div>
                                    <div class="mb-4">
                                        <span id="pain_val_display" class="text-2xl font-bold font-mono text-hms-dark"><?php echo htmlspecialchars($existing_consultation['pain_scale'] ?? '5'); ?></span>
                                        <span class="text-xs text-hms-muted font-medium ml-1">/ 10</span>
                                    </div>
                                    <div class="mb-2">
                                        <!-- Pain clickable scale grid -->
                                        <div class="flex justify-between items-center gap-2 md:gap-4 flex-wrap md:flex-nowrap mb-3" id="pain_circles_container">
                                            <?php
                                            $current_pain = (int)($existing_consultation['pain_scale'] ?? 5);
                                            // 10 level colors: green to red gradient (and 0 as gray)
                                            $pain_colors = [
                                                0 => 'bg-slate-500 hover:bg-slate-600',
                                                1 => 'bg-emerald-500 hover:bg-emerald-600',
                                                2 => 'bg-emerald-400 hover:bg-emerald-500',
                                                3 => 'bg-lime-500 hover:bg-lime-600',
                                                4 => 'bg-lime-400 hover:bg-lime-500',
                                                5 => 'bg-yellow-500 hover:bg-yellow-600',
                                                6 => 'bg-yellow-400 hover:bg-yellow-500',
                                                7 => 'bg-orange-500 hover:bg-orange-600',
                                                8 => 'bg-orange-400 hover:bg-orange-500',
                                                9 => 'bg-red-500 hover:bg-red-600',
                                                10 => 'bg-red-600 hover:bg-red-700',
                                            ];
                                            for ($p = 0; $p <= 10; $p++):
                                                $color = $pain_colors[$p];
                                                $isSelected = ($p === $current_pain) ? 'selected' : '';
                                            ?>
                                                <button type="button" class="pain-circle w-10 h-10 rounded-full font-bold flex items-center justify-center border-2 border-white text-white shadow-sm text-sm <?php echo $color; ?> <?php echo $isSelected; ?>" data-val="<?php echo $p; ?>">
                                                    <?php echo $p; ?>
                                                </button>
                                            <?php endfor; ?>
                                        </div>
                                        <div class="flex justify-between text-[10px] font-bold text-hms-muted px-1">
                                            <span>0 - No Pain</span>
                                            <span>3 - Mild</span>
                                            <span>5 - Moderate</span>
                                            <span>7 - Severe</span>
                                            <span>10 - Unbearable</span>
                                        </div>
                                    </div>
                                    <input type="hidden" id="pain_scale" name="pain_scale" value="<?php echo htmlspecialchars($existing_consultation['pain_scale'] ?? '5'); ?>">
                                </div>
                            </div>

                            <!-- Allergy Notes -->
                            <div class="mb-4">
                                <label for="allergy_notes" class="block text-xs font-semibold text-hms-mid mb-1">Allergy Records / Contraindications</label>
                                <textarea class="w-full border border-hms-border rounded-lg p-2.5 text-sm outline-none focus:border-hms-accent focus:ring-2 focus:ring-hms-accent/10" id="allergy_notes" name="allergy_notes" rows="2" placeholder="Record any known allergies, drug reactions, or write 'No known drug allergies' (NKDA)."><?php echo htmlspecialchars($existing_consultation['allergy_notes'] ?? ''); ?></textarea>
                            </div>

                            <div class="flex justify-end mt-6 pt-4 border-t border-hms-border">
                                <button type="button" class="border-0 bg-hms-accent hover:bg-hms-accentDim text-white rounded-lg px-6 py-2.5 text-sm font-semibold shadow-sm transition duration-150" onclick="goToTab('history', 'vitals-pane')">Continue to History</button>
                            </div>
                        </div>

                        <!-- TAB 2: MEDICAL HISTORY -->
                        <div class="tab-pane fade" id="history-pane" role="tabpanel" aria-labelledby="drawer-history-tab">
                            <h4 class="font-serif text-lg font-semibold text-hms-accent mb-4 border-b border-hms-border pb-1">Medical History Management</h4>
                            
                            <div class="mb-4">
                                <label for="referred_by" class="block text-xs font-semibold text-hms-mid mb-1">Referred By</label>
                                <input type="text" class="w-full border border-hms-border rounded-lg p-2.5 text-sm outline-none focus:border-hms-accent focus:ring-2 focus:ring-hms-accent/10" id="referred_by" name="referred_by" placeholder="e.g., Self, Dr. Smith" value="<?php echo htmlspecialchars($existing_consultation['referred_by'] ?? ''); ?>">
                            </div>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                                <div>
                                    <label for="medical_history" class="block text-xs font-semibold text-hms-mid mb-1">Medical History</label>
                                    <textarea class="w-full border border-hms-border rounded-lg p-2.5 text-sm outline-none focus:border-hms-accent focus:ring-2 focus:ring-hms-accent/10" id="medical_history" name="medical_history" rows="3" placeholder="Previous medical conditions, illnesses, chronic problems..."><?php echo htmlspecialchars($existing_consultation['medical_history'] ?? ''); ?></textarea>
                                </div>
                                <div>
                                    <label for="surgical_history" class="block text-xs font-semibold text-hms-mid mb-1">Surgical History</label>
                                    <textarea class="w-full border border-hms-border rounded-lg p-2.5 text-sm outline-none focus:border-hms-accent focus:ring-2 focus:ring-hms-accent/10" id="surgical_history" name="surgical_history" rows="3" placeholder="Details of previous surgical operations..."><?php echo htmlspecialchars($existing_consultation['surgical_history'] ?? ''); ?></textarea>
                                </div>
                            </div>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                                <div>
                                    <label for="family_history" class="block text-xs font-semibold text-hms-mid mb-1">Family History</label>
                                    <textarea class="w-full border border-hms-border rounded-lg p-2.5 text-sm outline-none focus:border-hms-accent focus:ring-2 focus:ring-hms-accent/10" id="family_history" name="family_history" rows="3" placeholder="Hereditary conditions, diabetes, cardiac diseases in family..."><?php echo htmlspecialchars($existing_consultation['family_history'] ?? ''); ?></textarea>
                                </div>
                                <div>
                                    <label for="social_history" class="block text-xs font-semibold text-hms-mid mb-1">Social History</label>
                                    <textarea class="w-full border border-hms-border rounded-lg p-2.5 text-sm outline-none focus:border-hms-accent focus:ring-2 focus:ring-hms-accent/10" id="social_history" name="social_history" rows="3" placeholder="Lifestyle habits, smoking status, alcohol usage, exercise..."><?php echo htmlspecialchars($existing_consultation['social_history'] ?? ''); ?></textarea>
                                </div>
                            </div>

                            <div class="flex justify-between mt-6 pt-4 border-t border-hms-border">
                                <button type="button" class="border border-hms-border text-hms-mid hover:bg-gray-50 rounded-lg px-6 py-2.5 text-sm font-semibold transition duration-150" onclick="goToTab('vitals')">Back</button>
                                <button type="button" class="border-0 bg-hms-accent hover:bg-hms-accentDim text-white rounded-lg px-6 py-2.5 text-sm font-semibold shadow-sm transition duration-150" onclick="goToTab('notes', 'history-pane')">Continue to Notes</button>
                            </div>
                        </div>
                        <div class="tab-pane fade" id="notes-pane" role="tabpanel" aria-labelledby="drawer-notes-tab">
                            <h4 class="font-serif text-lg font-semibold text-hms-accent mb-4 border-b border-hms-border pb-1">Clinical Notes &amp; Diagnosis</h4>
                            
                            <!-- Sub-tab Navigation -->
                            <ul class="nav nav-tabs mb-4 text-xs font-semibold" id="notesSubTabList" role="tablist">
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link active" id="notes-hpi-tab" data-bs-toggle="tab" data-bs-target="#notes-hpi-pane" type="button" role="tab" aria-controls="notes-hpi-pane" aria-selected="true">Chief Complaint &amp; HPI</button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="notes-ros-tab" data-bs-toggle="tab" data-bs-target="#notes-ros-pane" type="button" role="tab" aria-controls="notes-ros-pane" aria-selected="false">ROS &amp; Clinical Exam</button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="notes-disease-tab" data-bs-toggle="tab" data-bs-target="#notes-disease-pane" type="button" role="tab" aria-controls="notes-disease-pane" aria-selected="false">Disease &amp; Detailed Analysis</button>
                                </li>
                            </ul>

                            <div class="tab-content" id="notesSubTabContent">
                                <!-- SUB-TAB 1: Chief Complaint & HPI -->
                                <div class="tab-pane fade show active" id="notes-hpi-pane" role="tabpanel" aria-labelledby="notes-hpi-tab">
                                    <!-- chief complaint -->
                                    <div class="mb-4">
                                        <label for="chief_complaint" class="block text-xs font-semibold text-hms-mid mb-1">Chief Complaint * (Required)</label>
                                        <textarea class="w-full border border-hms-border rounded-lg p-2.5 text-sm outline-none focus:border-hms-accent focus:ring-2 focus:ring-hms-accent/10" id="chief_complaint" name="chief_complaint" rows="3" placeholder="Describe patient symptoms and the primary reason for visiting..." required><?php echo htmlspecialchars($existing_consultation['chief_complaint'] ?? ''); ?></textarea>
                                        <div class="text-hms-muted text-xxs mt-1">Brief summary of symptoms in patient's words (Required)</div>
                                    </div>

                                    <!-- HPI Structured Fields -->
                                    <div class="border-t border-hms-border pt-4 mt-4 mb-4">
                                        <h5 class="font-serif font-bold text-hms-accent text-sm mb-3">History of Present Illness (HPI)</h5>
                                        <div class="bg-gray-50 border border-hms-border rounded-xl p-4 shadow-sm">
                                            <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-3">
                                                <div>
                                                    <label for="pain_scale_type" class="block text-[11px] font-bold text-hms-mid mb-1">Pain Scale</label>
                                                    <select name="pain_scale_type" id="pain_scale_type" class="w-full border border-hms-border rounded-lg px-3 py-2 text-xs outline-none focus:border-hms-accent bg-white">
                                                        <?php
                                                        $pain_types = ['', 'FLACC Pain Scale', 'NRS (Numeric Rating)', 'VAS (Visual Analog)', 'Wong-Baker FACES'];
                                                        $current_pst = $existing_consultation['pain_scale_type'] ?? '';
                                                        foreach ($pain_types as $pt) {
                                                            $selected = ($pt === $current_pst) ? 'selected' : '';
                                                            $label = $pt ?: '-- Select Pain Scale --';
                                                            echo "<option value=\"" . htmlspecialchars($pt) . "\" $selected>" . htmlspecialchars($label) . "</option>";
                                                        }
                                                        ?>
                                                    </select>
                                                </div>
                                                <div>
                                                    <label for="hpi_location" class="block text-[11px] font-bold text-hms-mid mb-1">Location * (Required)</label>
                                                    <input type="text" name="hpi_location" id="hpi_location" value="<?php echo htmlspecialchars($existing_consultation['hpi_location'] ?? ''); ?>" placeholder="e.g. Head, Abdomen, Full body" class="w-full border border-hms-border rounded-lg px-3 py-2 text-xs outline-none focus:border-hms-accent bg-white" required>
                                                </div>
                                                <div>
                                                    <label for="hpi_quality" class="block text-[11px] font-bold text-hms-mid mb-1">Quality</label>
                                                    <input type="text" name="hpi_quality" id="hpi_quality" value="<?php echo htmlspecialchars($existing_consultation['hpi_quality'] ?? ''); ?>" placeholder="e.g. Sharp, Dull, Throbbing, Burning" class="w-full border border-hms-border rounded-lg px-3 py-2 text-xs outline-none focus:border-hms-accent bg-white">
                                                </div>
                                                <div>
                                                    <label for="hpi_duration" class="block text-[11px] font-bold text-hms-mid mb-1">Duration * (Required)</label>
                                                    <input type="text" name="hpi_duration" id="hpi_duration" value="<?php echo htmlspecialchars($existing_consultation['hpi_duration'] ?? ''); ?>" placeholder="e.g. 3 days, Long time, 2 weeks" class="w-full border border-hms-border rounded-lg px-3 py-2 text-xs outline-none focus:border-hms-accent bg-white" required>
                                                </div>
                                                <div>
                                                    <label for="hpi_timing" class="block text-[11px] font-bold text-hms-mid mb-1">Timing</label>
                                                    <input type="text" name="hpi_timing" id="hpi_timing" value="<?php echo htmlspecialchars($existing_consultation['hpi_timing'] ?? ''); ?>" placeholder="e.g. Continuous, Intermittent, Morning only" class="w-full border border-hms-border rounded-lg px-3 py-2 text-xs outline-none focus:border-hms-accent bg-white">
                                                </div>
                                                <div>
                                                    <label for="hpi_context" class="block text-[11px] font-bold text-hms-mid mb-1">Context</label>
                                                    <input type="text" name="hpi_context" id="hpi_context" value="<?php echo htmlspecialchars($existing_consultation['hpi_context'] ?? ''); ?>" placeholder="e.g. After meals, During exercise, At rest" class="w-full border border-hms-border rounded-lg px-3 py-2 text-xs outline-none focus:border-hms-accent bg-white">
                                                </div>
                                                <div class="md:col-span-2">
                                                    <label for="hpi_modifying_factor" class="block text-[11px] font-bold text-hms-mid mb-1">Modifying Factor</label>
                                                    <input type="text" name="hpi_modifying_factor" id="hpi_modifying_factor" value="<?php echo htmlspecialchars($existing_consultation['hpi_modifying_factor'] ?? ''); ?>" placeholder="e.g. Rest improves, Ibuprofen helps, Worsens with activity" class="w-full border border-hms-border rounded-lg px-3 py-2 text-xs outline-none focus:border-hms-accent bg-white">
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="flex justify-between mt-6 pt-4 border-t border-hms-border">
                                        <button type="button" class="border border-hms-border text-hms-mid hover:bg-gray-50 rounded-lg px-6 py-2.5 text-sm font-semibold transition duration-150" onclick="goToTab('history')">Back</button>
                                        <button type="button" class="border-0 bg-hms-accent hover:bg-hms-accentDim text-white rounded-lg px-6 py-2.5 text-sm font-semibold shadow-sm transition duration-150" onclick="goToSubTab('notes-ros-tab')">Continue to ROS &amp; Exam</button>
                                    </div>
                                </div>

                                <!-- SUB-TAB 2: Review of Systems & Clinical Examination -->
                                <div class="tab-pane fade" id="notes-ros-pane" role="tabpanel" aria-labelledby="notes-ros-tab">
                                    <!-- Physical Examination -->
                                    <div class="mb-4">
                                        <label for="physical_examination" class="block text-xs font-semibold text-hms-mid mb-1">Physical Examination</label>
                                        <textarea class="w-full border border-hms-border rounded-lg p-2.5 text-sm outline-none focus:border-hms-accent focus:ring-2 focus:ring-hms-accent/10" id="physical_examination" name="physical_examination" rows="3" placeholder="General appearance, vitals verification, systems assessments (HEENT, cardiac, respiratory, abdomen, neurological)..."><?php echo htmlspecialchars($existing_consultation['physical_examination'] ?? ''); ?></textarea>
                                    </div>

                                    <!-- Review of Systems (ROS) Extension -->
                                    <div class="border-t border-hms-border pt-4 mt-4 mb-4">
                                        <h5 class="font-serif font-bold text-hms-accent text-sm mb-3">Review of Systems (ROS)</h5>
                                        <div class="bg-gray-50 border border-hms-border rounded-xl p-4 shadow-sm">
                                            <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-3">
                                                <?php
                                                $ros_systems = [
                                                    'integumentary' => 'Integumentary',
                                                    'constitutional' => 'Constitutional Symptoms',
                                                    'eyes' => 'Eyes',
                                                    'enmt' => 'E N M T',
                                                    'cardiovascular' => 'Cardiovascular',
                                                    'respiratory' => 'Respiratory',
                                                    'gastrointestinal' => 'Gastrointestinal',
                                                    'genitourinary' => 'Genitourinary',
                                                    'musculoskeletal' => 'Musculoskeletal',
                                                    'neurological' => 'Neurological',
                                                    'psychiatric' => 'Psychiatric',
                                                    'endocrine' => 'Endocrine',
                                                    'hem_lymph' => 'Hematologic/Lymphatic',
                                                    'allergic_immuno' => 'Allergic/Immunologic'
                                                ];
                                                foreach ($ros_systems as $key => $label) {
                                                    $val = htmlspecialchars($ros_draft[$key] ?? 'No Complaints');
                                                    echo '<div>';
                                                    echo '  <label for="ros_' . $key . '" class="block text-[11px] font-bold text-hms-mid mb-1">' . $label . '</label>';
                                                    echo '  <input type="text" name="ros_' . $key . '" id="ros_' . $key . '" value="' . $val . '" class="w-full border border-hms-border rounded-lg px-3 py-2 text-xs outline-none focus:border-hms-accent bg-white">';
                                                    echo '</div>';
                                                }
                                                ?>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Clinical Examination Extension -->
                                    <div class="border-t border-hms-border pt-4 mt-4 mb-4">
                                        <h5 class="font-serif font-bold text-hms-accent text-sm mb-3">Clinical Examination</h5>
                                        <div class="bg-gray-50 border border-hms-border rounded-xl p-4 shadow-sm grid grid-cols-1 md:grid-cols-2 gap-4">
                                            <div>
                                                <label for="exam_general" class="block text-[11px] font-bold text-hms-mid mb-1">General Appearance</label>
                                                <input type="text" name="exam_general" id="exam_general" value="<?php echo htmlspecialchars($exam_draft['general'] ?? 'Normal'); ?>" class="w-full border border-hms-border rounded-lg px-3 py-2 text-xs outline-none focus:border-hms-accent bg-white">
                                            </div>
                                            <div>
                                                <label for="exam_skin" class="block text-[11px] font-bold text-hms-mid mb-1">Skin Assessment</label>
                                                <input type="text" name="exam_skin" id="exam_skin" value="<?php echo htmlspecialchars($exam_draft['skin'] ?? 'Normal'); ?>" class="w-full border border-hms-border rounded-lg px-3 py-2 text-xs outline-none focus:border-hms-accent bg-white">
                                            </div>
                                            <div class="md:col-span-2">
                                                <label for="exam_notes" class="block text-[11px] font-bold text-hms-mid mb-1">Clinical Examination Notes &amp; Findings</label>
                                                <textarea name="exam_notes" id="exam_notes" rows="2" placeholder="Describe general clinical findings..." class="w-full border border-hms-border rounded-lg p-2.5 text-xs outline-none focus:border-hms-accent bg-white"><?php echo htmlspecialchars($exam_draft['notes'] ?? ''); ?></textarea>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="flex justify-between mt-6 pt-4 border-t border-hms-border">
                                        <button type="button" class="border border-hms-border text-hms-mid hover:bg-gray-50 rounded-lg px-6 py-2.5 text-sm font-semibold transition duration-150" onclick="goToSubTab('notes-hpi-tab')">Back</button>
                                        <button type="button" class="border-0 bg-hms-accent hover:bg-hms-accentDim text-white rounded-lg px-6 py-2.5 text-sm font-semibold shadow-sm transition duration-150" onclick="goToSubTab('notes-disease-tab')">Continue to Disease Analysis</button>
                                    </div>
                                </div>

                                <!-- SUB-TAB 3: Disease & Detailed Analysis -->
                                <div class="tab-pane fade" id="notes-disease-pane" role="tabpanel" aria-labelledby="notes-disease-tab">
                                    <!-- Narrative Diagnosis Textarea -->
                                    <div class="mb-4">
                                        <label for="narrative_diagnosis" class="block text-xs font-semibold text-hms-mid mb-1">Narrative Diagnosis * (Required)</label>
                                        <textarea class="w-full border border-hms-border rounded-lg p-2.5 text-sm outline-none focus:border-hms-accent focus:ring-2 focus:ring-hms-accent/10" id="narrative_diagnosis" name="narrative_diagnosis" rows="4" placeholder="Assessments, differential diagnoses, treatment plans, clinical notes, and narratives..." required><?php echo htmlspecialchars($existing_consultation['narrative_diagnosis'] ?? ''); ?></textarea>
                                    </div>

                                    <!-- Vitals Warnings Banners -->
                                    <div class="grid grid-cols-1 gap-3 mb-4">
                                        <div class="p-3 bg-red-50 text-red-800 text-xs font-medium rounded-lg border-l-4 border-red-500 hidden" id="feverSuggestionBanner">
                                            Patient has a fever (Temp &gt; 37.5°C). Ensure you check the "Fever" card if relevant.
                                        </div>
                                        <div class="p-3 bg-orange-50 text-orange-800 text-xs font-medium rounded-lg border-l-4 border-orange-500 hidden" id="painSuggestionBanner">
                                            Patient is reporting severe pain scale &gt;= 6. Ensure pain medication and triggers are addressed.
                                        </div>
                                        <div class="p-3 bg-blue-50 text-blue-800 text-xs font-medium rounded-lg border-l-4 border-blue-500 hidden" id="allergyWarningBanner">
                                            Allergy record alert: <span class="font-bold" id="allergyWarningText"></span>. Take care when prescribing.
                                        </div>
                                    </div>

                                    <!-- Selected Diagnoses & Custom Codes -->
                                    <div class="mb-4">
                                        <div class="p-4 bg-white border border-hms-border rounded-xl shadow-sm">
                                            <div class="flex justify-between items-center mb-3">
                                                <label class="text-xs font-semibold text-hms-dark">Selected Diagnoses &amp; Custom Codes</label>
                                                <button type="button" class="border border-hms-accent text-hms-accent hover:bg-hms-accent hover:text-white rounded px-3 py-1.5 text-xs font-semibold tracking-wide transition duration-150" id="addDiagnosisBtn">Add Custom Diagnosis</button>
                                            </div>
                                            <!-- Active Diagnoses rows container -->
                                            <div id="diagnosesContainer" class="max-h-[180px] overflow-y-auto space-y-2">
                                                <!-- Appended rows go here dynamically -->
                                            </div>
                                        </div>
                                    </div>

                                    <!-- GUI Symptom Checklist -->
                                    <div class="mb-4">
                                        <div class="flex items-center justify-between mb-2">
                                            <label class="block text-xs font-semibold text-hms-mid">GUI Symptom Checklist (Click to select &amp; map ICD code)</label>
                                            <div class="w-1/3">
                                                <input type="text" class="w-full border border-hms-border rounded-lg px-3 py-1.5 text-xs outline-none focus:border-hms-accent bg-white" id="diagSearchInput" placeholder="Filter symptom cards...">
                                            </div>
                                        </div>
                                        <!-- Symptoms GUI Cards -->
                                        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-3" id="symptomsGrid">
                                            <div class="symptom-card border rounded-xl p-3.5 cursor-pointer text-center select-none" data-symptom-id="hair_loss" data-icd="L65.9" data-desc="Hair Loss;">
                                                <div class="font-serif font-bold text-hms-dark text-sm">Hair Loss</div>
                                                <span class="text-hms-muted text-xxs font-mono block mt-1">ICD-10: L65.9</span>
                                            </div>
                                            <div class="symptom-card border rounded-xl p-3.5 cursor-pointer text-center select-none" data-symptom-id="excessive_hair" data-icd="L68.0" data-desc="Excessive Hair growth">
                                                <div class="font-serif font-bold text-hms-dark text-sm">Excessive Hair growth</div>
                                                <span class="text-hms-muted text-xxs font-mono block mt-1">ICD-10: L68.0</span>
                                            </div>
                                            <div class="symptom-card border rounded-xl p-3.5 cursor-pointer text-center select-none" data-symptom-id="unwanted_hair" data-icd="L68.9" data-desc="Unwanted hair:">
                                                <div class="font-serif font-bold text-hms-dark text-sm">Unwanted hair</div>
                                                <span class="text-hms-muted text-xxs font-mono block mt-1">ICD-10: L68.9</span>
                                            </div>
                                            <div class="symptom-card border rounded-xl p-3.5 cursor-pointer text-center select-none" data-symptom-id="wrinkle" data-icd="98.8" data-desc="Wrinkle:">
                                                <div class="font-serif font-bold text-hms-dark text-sm">Wrinkle</div>
                                                <span class="text-hms-muted text-xxs font-mono block mt-1">ICD-10: 98.8</span>
                                            </div>
                                            <div class="symptom-card border rounded-xl p-3.5 cursor-pointer text-center select-none" data-symptom-id="botox" data-icd="Z41.1" data-desc="Botox:">
                                                <div class="font-serif font-bold text-hms-dark text-sm">Botox</div>
                                                <span class="text-hms-muted text-xxs font-mono block mt-1">ICD-10: Z41.1</span>
                                            </div>
                                            <div class="symptom-card border rounded-xl p-3.5 cursor-pointer text-center select-none" data-symptom-id="filler" data-icd="Z41.1" data-desc="Filler">
                                                <div class="font-serif font-bold text-hms-dark text-sm">Filler</div>
                                                <span class="text-hms-muted text-xxs font-mono block mt-1">ICD-10: Z41.1</span>
                                            </div>
                                            <div class="symptom-card border rounded-xl p-3.5 cursor-pointer text-center select-none" data-symptom-id="consultation" data-icd="Z41.8" data-desc="Consultation:">
                                                <div class="font-serif font-bold text-hms-dark text-sm">Consultation</div>
                                                <span class="text-hms-muted text-xxs font-mono block mt-1">ICD-10: Z41.8</span>
                                            </div>
                                            <div class="symptom-card border rounded-xl p-3.5 cursor-pointer text-center select-none" data-symptom-id="derma_pen" data-icd="Z41.1" data-desc="Derma pen:">
                                                <div class="font-serif font-bold text-hms-dark text-sm">Derma pen</div>
                                                <span class="text-hms-muted text-xxs font-mono block mt-1">ICD-10: Z41.1</span>
                                            </div>
                                            <div class="symptom-card border rounded-xl p-3.5 cursor-pointer text-center select-none" data-symptom-id="skin_booster" data-icd="Z41.1" data-desc="Skin booster:">
                                                <div class="font-serif font-bold text-hms-dark text-sm">Skin booster</div>
                                                <span class="text-hms-muted text-xxs font-mono block mt-1">ICD-10: Z41.1</span>
                                            </div>
                                            <div class="symptom-card border rounded-xl p-3.5 cursor-pointer text-center select-none" data-symptom-id="prp" data-icd="Z41.1" data-desc="PRP:">
                                                <div class="font-serif font-bold text-hms-dark text-sm">PRP</div>
                                                <span class="text-hms-muted text-xxs font-mono block mt-1">ICD-10: Z41.1</span>
                                            </div>
                                            <div class="symptom-card border rounded-xl p-3.5 cursor-pointer text-center select-none" data-symptom-id="mesotherapy" data-icd="Z41.1" data-desc="Mesotherapy:">
                                                <div class="font-serif font-bold text-hms-dark text-sm">Mesotherapy</div>
                                                <span class="text-hms-muted text-xxs font-mono block mt-1">ICD-10: Z41.1</span>
                                            </div>
                                            <div class="symptom-card border rounded-xl p-3.5 cursor-pointer text-center select-none" data-symptom-id="weight_loss" data-icd="R63.4" data-desc="Weight Loss:">
                                                <div class="font-serif font-bold text-hms-dark text-sm">Weight Loss</div>
                                                <span class="text-hms-muted text-xxs font-mono block mt-1">ICD-10: R63.4</span>
                                            </div>
                                            <div class="symptom-card border rounded-xl p-3.5 cursor-pointer text-center select-none" data-symptom-id="pigmentation" data-icd="L81.9" data-desc="Pigmentation:">
                                                <div class="font-serif font-bold text-hms-dark text-sm">Pigmentation</div>
                                                <span class="text-hms-muted text-xxs font-mono block mt-1">ICD-10: L81.9</span>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Practitioner / Specialty Referral Section -->
                                    <div class="mb-4">
                                        <div class="bg-hms-panel border border-hms-border rounded-xl p-5 shadow-sm">
                                            <h5 class="font-serif font-bold text-hms-accent text-sm mb-3 flex items-center gap-2">
                                                <svg class="w-4.5 h-5 text-hms-accent" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="width: 1.125rem; height: 1.25rem;">
                                                    <path d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                                                </svg>
                                                <span>Practitioner / Specialty Referral &amp; Finalization</span>
                                            </h5>
                                            <p class="text-hms-mid text-xs mb-4 font-medium">To refer this patient to another specialty or practitioner and finalize the case immediately, configure the referral details below and click <strong>Refer &amp; Finalize Case</strong>.</p>
                                            
                                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                                                <div>
                                                    <label for="diag_referral_doctor_id" class="block text-xs font-semibold text-hms-mid mb-1">Referral Specialist / Practitioner</label>
                                                    <select id="diag_referral_doctor_id" class="w-full border border-hms-border rounded-lg p-2.5 text-sm outline-none focus:border-hms-accent focus:ring-2 focus:ring-hms-accent/10 bg-white">
                                                        <option value="" disabled selected>-- Select Specialty / Practitioner --</option>
                                                        <?php
                                                        foreach ($all_doctors as $doc) {
                                                            if ($doc['doctor_id'] == $_SESSION['doctor_id']) {
                                                                continue;
                                                            }
                                                            echo '<option value="' . htmlspecialchars($doc['doctor_id']) . '">' . htmlspecialchars($doc['name']) . '</option>';
                                                        }
                                                        ?>
                                                    </select>
                                                </div>
                                                <div>
                                                    <label for="diag_referral_date" class="block text-xs font-semibold text-hms-mid mb-1">Referral / Follow-up Date</label>
                                                    <input type="date" id="diag_referral_date" class="w-full border border-hms-border rounded-lg p-2.5 text-sm outline-none focus:border-hms-accent focus:ring-2 focus:ring-hms-accent/10" min="<?php echo date('Y-m-d'); ?>" value="<?php echo date('Y-m-d', strtotime('+7 days')); ?>">
                                                </div>
                                                <div>
                                                    <label for="diag_referral_time" class="block text-xs font-semibold text-hms-mid mb-1">Referral / Follow-up Time</label>
                                                    <input type="time" id="diag_referral_time" class="w-full border border-hms-border rounded-lg p-2.5 text-sm outline-none focus:border-hms-accent focus:ring-2 focus:ring-hms-accent/10" value="10:00">
                                                </div>
                                            </div>

                                            <div class="flex justify-end">
                                                <button type="button" class="bg-red-600 hover:bg-red-700 text-white rounded-lg px-6 py-2.5 text-sm font-semibold shadow-sm transition duration-150 flex items-center gap-2" onclick="validateAndFinalizeReferral()">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="width: 1rem; height: 1rem;">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                                    </svg>
                                                    <span>Refer &amp; Finalize Case</span>
                                                </button>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="flex justify-between mt-6 pt-4 border-t border-hms-border">
                                        <button type="button" class="border border-hms-border text-hms-mid hover:bg-gray-50 rounded-lg px-6 py-2.5 text-sm font-semibold transition duration-150" onclick="goToSubTab('notes-ros-tab')">Back</button>
                                        <button type="button" class="border-0 bg-hms-accent hover:bg-hms-accentDim text-white rounded-lg px-6 py-2.5 text-sm font-semibold shadow-sm transition duration-150" onclick="goToTab('medications', 'notes-pane')">Continue to Prescriptions</button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- TAB 5: PRESCRIPTION SYSTEM -->
                        <div class="tab-pane fade" id="medications-pane" role="tabpanel" aria-labelledby="drawer-medications-tab">
                            <h4 class="font-serif text-lg font-semibold text-hms-accent mb-4 border-b border-hms-border pb-1">Prescriptions &amp; Treatment Plan</h4>
                            
                            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                                <!-- Left Column: Clickable Medication Formulary -->
                                <div class="bg-hms-panel border border-hms-border rounded-xl p-4 shadow-sm flex flex-col gap-4">
                                    <div class="flex items-center gap-2 pb-1.5 border-b border-hms-border">
                                        <h5 class="font-serif font-bold text-hms-dark text-sm">Medication Formulary Presets</h5>
                                        <span class="text-[10px] bg-hms-accent/15 text-hms-accent font-bold px-2 py-0.5 rounded">Quick Add</span>
                                    </div>
                                    
                                    <div class="pb-3 border-b border-hms-border">
                                        <div class="text-[11px] font-bold text-hms-mid uppercase tracking-wider mb-2">Common Case Prescription Bundles</div>
                                        <div class="flex flex-wrap gap-2">
                                            <button type="button" class="med-bundle-btn px-3 py-1.5 bg-hms-accent text-white rounded-full text-xs font-semibold hover:bg-hms-accentDim transition duration-150" data-bundle="flu">
                                                Acute Fever / Flu Case Bundle
                                            </button>
                                            <button type="button" class="med-bundle-btn px-3 py-1.5 bg-hms-accent text-white rounded-full text-xs font-semibold hover:bg-hms-accentDim transition duration-150" data-bundle="cold">
                                                Common Cold Case Bundle
                                            </button>
                                            <button type="button" class="med-bundle-btn px-3 py-1.5 bg-hms-accent text-white rounded-full text-xs font-semibold hover:bg-hms-accentDim transition duration-150" data-bundle="gastro">
                                                Gastroenteritis Case Bundle
                                            </button>
                                            <button type="button" class="med-bundle-btn px-3 py-1.5 bg-hms-accent text-white rounded-full text-xs font-semibold hover:bg-hms-accentDim transition duration-150" data-bundle="htn">
                                                Hypertension Case Bundle
                                            </button>
                                            <button type="button" class="med-bundle-btn px-3 py-1.5 bg-hms-accent text-white rounded-full text-xs font-semibold hover:bg-hms-accentDim transition duration-150" data-bundle="diabetes">
                                                Diabetes Case Bundle
                                            </button>
                                        </div>
                                    </div>
                                    
                                    <div class="space-y-4">
                                        <!-- Therapeutic Classes -->
                                        <div>
                                            <div class="text-[11px] font-bold text-hms-mid uppercase tracking-wider mb-2">Analgesics &amp; Antipyretics</div>
                                            <div class="flex flex-wrap gap-2">
                                                <button type="button" class="med-pill px-3 py-1.5 bg-white border border-hms-border hover:border-hms-accent hover:bg-hms-accent/5 rounded-full text-xs font-semibold text-hms-dark transition duration-150" 
                                                        data-med="Paracetamol 500mg" data-dosage="1 tablet three times daily" data-duration="5 days" data-inst="Take after meals.">
                                                    Paracetamol
                                                </button>
                                                <button type="button" class="med-pill px-3 py-1.5 bg-white border border-hms-border hover:border-hms-accent hover:bg-hms-accent/5 rounded-full text-xs font-semibold text-hms-dark transition duration-150" 
                                                        data-med="Ibuprofen 400mg" data-dosage="1 tablet twice daily" data-duration="5 days" data-inst="Take with food or milk to prevent stomach upset.">
                                                    Ibuprofen
                                                </button>
                                                <button type="button" class="med-pill px-3 py-1.5 bg-white border border-hms-border hover:border-hms-accent hover:bg-hms-accent/5 rounded-full text-xs font-semibold text-hms-dark transition duration-150" 
                                                        data-med="Tramadol 50mg" data-dosage="1 capsule every 6 hours as needed for severe pain" data-duration="3 days" data-inst="May cause drowsiness. Avoid driving or operating machinery.">
                                                    Tramadol
                                                </button>
                                            </div>
                                        </div>
                                        <div>
                                            <div class="text-[11px] font-bold text-hms-mid uppercase tracking-wider mb-2">Antibiotics</div>
                                            <div class="flex flex-wrap gap-2">
                                                <button type="button" class="med-pill px-3 py-1.5 bg-white border border-hms-border hover:border-hms-accent hover:bg-hms-accent/5 rounded-full text-xs font-semibold text-hms-dark transition duration-150" 
                                                        data-med="Amoxicillin 500mg" data-dosage="1 capsule three times daily" data-duration="7 days" data-inst="Complete the full course as prescribed.">
                                                    Amoxicillin
                                                </button>
                                                <button type="button" class="med-pill px-3 py-1.5 bg-white border border-hms-border hover:border-hms-accent hover:bg-hms-accent/5 rounded-full text-xs font-semibold text-hms-dark transition duration-150" 
                                                        data-med="Azithromycin 500mg" data-dosage="1 tablet daily" data-duration="3 days" data-inst="Take 1 hour before or 2 hours after meals.">
                                                    Azithromycin
                                                </button>
                                                <button type="button" class="med-pill px-3 py-1.5 bg-white border border-hms-border hover:border-hms-accent hover:bg-hms-accent/5 rounded-full text-xs font-semibold text-hms-dark transition duration-150" 
                                                        data-med="Ciprofloxacin 500mg" data-dosage="1 tablet twice daily" data-duration="7 days" data-inst="Avoid taking with dairy products or antacids. Drink plenty of water.">
                                                    Ciprofloxacin
                                                </button>
                                            </div>
                                        </div>
                                        <div>
                                            <div class="text-[11px] font-bold text-hms-mid uppercase tracking-wider mb-2">Antihypertensives &amp; Cardiovascular</div>
                                            <div class="flex flex-wrap gap-2">
                                                <button type="button" class="med-pill px-3 py-1.5 bg-white border border-hms-border hover:border-hms-accent hover:bg-hms-accent/5 rounded-full text-xs font-semibold text-hms-dark transition duration-150" 
                                                        data-med="Amlodipine 5mg" data-dosage="1 tablet once daily" data-duration="30 days" data-inst="Take at the same time every morning.">
                                                    Amlodipine
                                                </button>
                                                <button type="button" class="med-pill px-3 py-1.5 bg-white border border-hms-border hover:border-hms-accent hover:bg-hms-accent/5 rounded-full text-xs font-semibold text-hms-dark transition duration-150" 
                                                        data-med="Lisinopril 10mg" data-dosage="1 tablet once daily" data-duration="30 days" data-inst="Monitor blood pressure regularly. Report dry cough if it develops.">
                                                    Lisinopril
                                                </button>
                                                <button type="button" class="med-pill px-3 py-1.5 bg-white border border-hms-border hover:border-hms-accent hover:bg-hms-accent/5 rounded-full text-xs font-semibold text-hms-dark transition duration-150" 
                                                        data-med="Atorvastatin 20mg" data-dosage="1 tablet at bedtime" data-duration="30 days" data-inst="Take at night. Avoid grapefruit juice.">
                                                    Atorvastatin
                                                </button>
                                            </div>
                                        </div>
                                        <div>
                                            <div class="text-[11px] font-bold text-hms-mid uppercase tracking-wider mb-2">Antidiabetics</div>
                                            <div class="flex flex-wrap gap-2">
                                                <button type="button" class="med-pill px-3 py-1.5 bg-white border border-hms-border hover:border-hms-accent hover:bg-hms-accent/5 rounded-full text-xs font-semibold text-hms-dark transition duration-150" 
                                                        data-med="Metformin 500mg" data-dosage="1 tablet twice daily with meals" data-duration="30 days" data-inst="Take with breakfast and dinner.">
                                                    Metformin
                                                </button>
                                                <button type="button" class="med-pill px-3 py-1.5 bg-white border border-hms-border hover:border-hms-accent hover:bg-hms-accent/5 rounded-full text-xs font-semibold text-hms-dark transition duration-150" 
                                                        data-med="Glimepiride 2mg" data-dosage="1 tablet once daily before breakfast" data-duration="30 days" data-inst="Monitor for symptoms of hypoglycemia.">
                                                    Glimepiride
                                                </button>
                                            </div>
                                        </div>
                                        <div>
                                            <div class="text-[11px] font-bold text-hms-mid uppercase tracking-wider mb-2">Gastrointestinal &amp; Others</div>
                                            <div class="flex flex-wrap gap-2">
                                                <button type="button" class="med-pill px-3 py-1.5 bg-white border border-hms-border hover:border-hms-accent hover:bg-hms-accent/5 rounded-full text-xs font-semibold text-hms-dark transition duration-150" 
                                                        data-med="Omeprazole 20mg" data-dosage="1 capsule daily 30 mins before breakfast" data-duration="14 days" data-inst="Take on an empty stomach first thing in the morning.">
                                                    Omeprazole
                                                </button>
                                                <button type="button" class="med-pill px-3 py-1.5 bg-white border border-hms-border hover:border-hms-accent hover:bg-hms-accent/5 rounded-full text-xs font-semibold text-hms-dark transition duration-150" 
                                                        data-med="Cetirizine 10mg" data-dosage="1 tablet daily at bedtime" data-duration="10 days" data-inst="Take at night. May cause minor drowsiness.">
                                                    Cetirizine
                                                </button>
                                                <button type="button" class="med-pill px-3 py-1.5 bg-white border border-hms-border hover:border-hms-accent hover:bg-hms-accent/5 rounded-full text-xs font-semibold text-hms-dark transition duration-150" 
                                                        data-med="Multivitamin" data-dosage="1 tablet daily" data-duration="30 days" data-inst="Take after breakfast.">
                                                    Multivitamin
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Right Column: Active Medications List -->
                                <div class="bg-white border border-hms-border rounded-xl p-4 shadow-sm flex flex-col">
                                    <div class="flex justify-between items-center mb-4 pb-1.5 border-b border-hms-border">
                                        <label class="font-serif font-bold text-hms-dark text-sm">Active Medications List</label>
                                        <button type="button" class="border border-hms-accent text-hms-accent hover:bg-hms-accent hover:text-white rounded px-3 py-1.5 text-xs font-semibold tracking-wide transition duration-150" id="addMedicineBtn">Add Medicine</button>
                                    </div>
                                    
                                    <div id="prescriptionsContainer" class="flex-grow overflow-y-auto max-h-[600px] pr-2">
                                        <!-- Dynamic Medicine rows go here -->
                                        <?php if (!empty($existing_prescriptions)): ?>
                                            <?php foreach ($existing_prescriptions as $idx => $pres): ?>
                                                <div class="prescription-row border border-hms-border p-4 rounded-xl mb-4 bg-hms-bg">
                                                    <div class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end mb-3">
                                                        <div class="md:col-span-4">
                                                            <label class="block text-xxs font-bold text-hms-mid mb-1">Medicine Name</label>
                                                            <input type="text" class="w-full border border-hms-border rounded-lg p-2.5 text-sm outline-none focus:border-hms-accent" name="medicine_name[]" placeholder="e.g., Amoxicillin 500mg" value="<?php echo htmlspecialchars($pres['medicine_name']); ?>" required>
                                                        </div>
                                                        <div class="md:col-span-4">
                                                            <label class="block text-xxs font-bold text-hms-mid mb-1">Dosage / Frequency</label>
                                                            <input type="text" class="w-full border border-hms-border rounded-lg p-2.5 text-sm outline-none focus:border-hms-accent" name="medicine_dosage[]" placeholder="e.g., 1 tablet three times daily" value="<?php echo htmlspecialchars($pres['dosage']); ?>" required>
                                                        </div>
                                                        <div class="md:col-span-3">
                                                            <label class="block text-xxs font-bold text-hms-mid mb-1">Duration</label>
                                                            <input type="text" class="w-full border border-hms-border rounded-lg p-2.5 text-sm outline-none focus:border-hms-accent" name="medicine_duration[]" placeholder="e.g., 7 days" value="<?php echo htmlspecialchars($pres['duration']); ?>" required>
                                                        </div>
                                                        <div class="md:col-span-1">
                                                            <button type="button" class="w-full border border-red-200 text-red-500 hover:bg-red-500 hover:text-white rounded-lg py-2.5 text-sm font-semibold remove-medicine-btn transition duration-150" title="Delete Prescription">×</button>
                                                        </div>
                                                    </div>
                                                    <div>
                                                        <label class="block text-xxs font-bold text-hms-muted mb-1">Special Instructions</label>
                                                        <textarea class="w-full border border-hms-border rounded-lg p-2 text-xs outline-none focus:border-hms-accent" name="medicine_instructions[]" rows="1" placeholder="e.g., Take after meals, avoid alcohol..."><?php echo htmlspecialchars($pres['instructions'] ?? ''); ?></textarea>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <div class="flex justify-between mt-6 pt-4 border-t border-hms-border">
                                <button type="button" class="border border-hms-border text-hms-mid hover:bg-gray-50 rounded-lg px-6 py-2.5 text-sm font-semibold transition duration-150" onclick="goToTab('diagnosis')">Back</button>
                                <button type="button" class="border-0 bg-hms-accent hover:bg-hms-accentDim text-white rounded-lg px-6 py-2.5 text-sm font-semibold shadow-sm transition duration-150" onclick="goToTab('lab', 'medications-pane')">Continue to Lab</button>
                            </div>
                        </div>

                        <!-- TAB 6: LABORATORY ORDER SYSTEM -->
                        <div class="tab-pane fade" id="lab-pane" role="tabpanel" aria-labelledby="drawer-lab-tab">
                            <h4 class="font-serif text-lg font-semibold text-hms-accent mb-4 border-b border-hms-border pb-1">Laboratory Diagnostics Order System</h4>
                            
                            <p class="text-hms-mid text-xs mb-4">Select and configure the laboratory diagnostic tests you wish to order for this patient:</p>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <!-- Hematology Section -->
                                <div class="border border-hms-border rounded-xl p-4 shadow-sm bg-gray-50/50">
                                    <h5 class="font-serif font-bold text-hms-dark text-sm border-b border-hms-border pb-1.5 mb-3 flex items-center justify-between">
                                        <span>Hematology Tests</span>
                                        <span class="text-xxs uppercase bg-blue-100 text-blue-700 px-2 py-0.5 rounded-full font-bold">Blood Lab</span>
                                    </h5>
                                    
                                    <div class="space-y-3">
                                        <!-- CBC -->
                                        <?php $cbc = getDraftTest($existing_tests, 'Complete Blood Count (CBC)'); ?>
                                        <div class="flex items-start gap-2">
                                            <input type="checkbox" class="check mt-1" name="lab_tests[]" value="Complete Blood Count (CBC)" id="lab_cbc" <?php echo ($cbc) ? 'checked' : ''; ?>>
                                            <div class="flex-grow">
                                                <label for="lab_cbc" class="text-xs font-semibold text-hms-dark cursor-pointer block leading-none">Complete Blood Count (CBC)</label>
                                                <span class="text-hms-muted text-xxs">Evaluates red cells, white cells, platelets.</span>
                                            </div>
                                        </div>
                                        
                                        <!-- HbA1c -->
                                        <?php $hba1c = getDraftTest($existing_tests, 'HbA1c (Glycated Hemoglobin)'); ?>
                                        <div class="flex items-start gap-2">
                                            <input type="checkbox" class="check mt-1" name="lab_tests[]" value="HbA1c (Glycated Hemoglobin)" id="lab_hba1c" <?php echo ($hba1c) ? 'checked' : ''; ?>>
                                            <div class="flex-grow">
                                                <label for="lab_hba1c" class="text-xs font-semibold text-hms-dark cursor-pointer block leading-none">HbA1c (Glycated Hemoglobin)</label>
                                                <span class="text-hms-muted text-xxs">Monitors 3-month blood sugar control.</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Biochemistry & Pathology Section -->
                                <div class="border border-hms-border rounded-xl p-4 shadow-sm bg-gray-50/50">
                                    <h5 class="font-serif font-bold text-hms-dark text-sm border-b border-hms-border pb-1.5 mb-3 flex items-center justify-between">
                                        <span>Biochemistry & Pathology</span>
                                        <span class="text-xxs uppercase bg-purple-100 text-purple-700 px-2 py-0.5 rounded-full font-bold">Organs / Metabolic</span>
                                    </h5>
                                    
                                    <div class="space-y-3">
                                        <!-- Liver Function Test -->
                                        <?php $lft = getDraftTest($existing_tests, 'Liver Function Test (LFT)'); ?>
                                        <div class="flex items-start gap-2">
                                            <input type="checkbox" class="check mt-1" name="lab_tests[]" value="Liver Function Test (LFT)" id="lab_lft" <?php echo ($lft) ? 'checked' : ''; ?>>
                                            <div class="flex-grow">
                                                <label for="lab_lft" class="text-xs font-semibold text-hms-dark cursor-pointer block leading-none">Liver Function Test (LFT)</label>
                                                <span class="text-hms-muted text-xxs">Evaluates bilirubin, ALT, AST, alkaline phosphatase.</span>
                                            </div>
                                        </div>
                                        
                                        <!-- Renal Profile -->
                                        <?php $kft = getDraftTest($existing_tests, 'Renal Profile / Kidney Function'); ?>
                                        <div class="flex items-start gap-2">
                                            <input type="checkbox" class="check mt-1" name="lab_tests[]" value="Renal Profile / Kidney Function" id="lab_kft" <?php echo ($kft) ? 'checked' : ''; ?>>
                                            <div class="flex-grow">
                                                <label for="lab_kft" class="text-xs font-semibold text-hms-dark cursor-pointer block leading-none">Renal Profile / Kidney Function</label>
                                                <span class="text-hms-muted text-xxs">Measures blood urea nitrogen, creatinine, electrolytes.</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Laboratory priority settings -->
                            <div class="mt-4 p-4 border border-hms-border rounded-xl bg-gray-50/50">
                                <h5 class="font-serif font-bold text-hms-dark text-sm mb-3">Order Queue Priority</h5>
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                    <div>
                                        <label for="lab_category" class="block text-xs font-semibold text-hms-mid mb-1">Default Category</label>
                                        <select class="w-full border border-hms-border rounded-lg p-2.5 text-xs outline-none focus:border-hms-accent bg-white" name="lab_category" id="lab_category">
                                            <option value="Hematology">Hematology</option>
                                            <option value="Biochemistry">Biochemistry</option>
                                            <option value="Radiology">Radiology</option>
                                            <option value="Pathology">Pathology</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label for="lab_priority" class="block text-xs font-semibold text-hms-mid mb-1">Priority Level</label>
                                        <select class="w-full border border-hms-border rounded-lg p-2.5 text-xs outline-none focus:border-hms-accent bg-white" name="lab_priority" id="lab_priority">
                                            <option value="Routine">Routine (24 Hour turnaround)</option>
                                            <option value="Urgent">Urgent (4-6 Hour turnaround)</option>
                                            <option value="STAT">STAT (Immediate clinical review)</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <div class="flex justify-between mt-6 pt-4 border-t border-hms-border">
                                <button type="button" class="border border-hms-border text-hms-mid hover:bg-gray-50 rounded-lg px-6 py-2.5 text-sm font-semibold transition duration-150" onclick="goToTab('medications')">Back</button>
                                <button type="button" class="border-0 bg-hms-accent hover:bg-hms-accentDim text-white rounded-lg px-6 py-2.5 text-sm font-semibold shadow-sm transition duration-150" onclick="goToTab('nursing', 'lab-pane')">Continue to Nursing Planning</button>
                            </div>
                        </div>

                        <!-- TAB 6: CLINICAL TREATMENT & NURSING PLANNING -->
                        <div class="tab-pane fade" id="nursing-pane" role="tabpanel" aria-labelledby="drawer-nursing-tab">
                            <h4 class="font-serif text-lg font-semibold text-hms-accent mb-4 border-b border-hms-border pb-1">Clinical Treatment &amp; Nursing Planning</h4>

                            <!-- Sub-tab Navigation -->
                            <ul class="nav nav-tabs mb-4 text-xs font-semibold" id="nursingSubTabList" role="tablist">
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link active" id="nrs-prep-tab" data-bs-toggle="tab" data-bs-target="#nrs-prep-pane" type="button" role="tab">6.1 — Pre-Treatment Prep &amp; Doctor Assessment</button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="nrs-treatment-tab" data-bs-toggle="tab" data-bs-target="#nrs-treatment-pane" type="button" role="tab">6.2 — Clinical Treatment &amp; Session Tolerance</button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="nrs-advisory-tab" data-bs-toggle="tab" data-bs-target="#nrs-advisory-pane" type="button" role="tab">6.3 — Advisory Measures &amp; Discharge Plan</button>
                                </li>
                            </ul>

                            <div class="tab-content" id="nursingSubTabContent">

                                <!-- Tab 6.1: Pre-Treatment Prep & Doctor Assessment -->
                                <div class="tab-pane fade show active" id="nrs-prep-pane" role="tabpanel">
                                    
                                    <!-- A. Doctor Assessment Fields -->
                                    <div class="bg-hms-panel border border-hms-border rounded-xl p-5 mb-4 shadow-sm">
                                        <h5 class="font-serif font-bold text-hms-accent text-sm mb-3 flex items-center gap-2">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                            Doctor Examination &amp; Assessment Records
                                        </h5>
                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                                            <div>
                                                <label for="nrs_exam_doctor" class="block text-xs font-semibold text-hms-mid mb-1">Examining Doctor Name</label>
                                                <input type="text" id="nrs_exam_doctor" name="nrs_exam_doctor" placeholder="e.g., Dr. Ahmed Khan" class="w-full border border-hms-border rounded-lg p-2.5 text-sm outline-none focus:border-hms-accent focus:ring-2 focus:ring-hms-accent/10 bg-white" value="<?php echo htmlspecialchars(!empty($nursing_plan['exam_doctor']) ? $nursing_plan['exam_doctor'] : ($_SESSION['doctor_name'] ?? '')); ?>">
                                            </div>
                                            <div>
                                                <label for="nrs_exam_date" class="block text-xs font-semibold text-hms-mid mb-1">Examination Date &amp; Time</label>
                                                <input type="<?php echo !empty($nursing_plan['exam_date']) ? 'datetime-local' : 'text'; ?>" id="nrs_exam_date" name="nrs_exam_date" placeholder="Click here" class="w-full border border-hms-border rounded-lg p-2.5 text-sm outline-none focus:border-hms-accent focus:ring-2 focus:ring-hms-accent/10 bg-white cursor-pointer" value="<?php echo htmlspecialchars($nursing_plan['exam_date'] ?? ''); ?>" onfocus="this.type='datetime-local'; if(!this.value){ const d = new Date(); const pad = (n) => String(n).padStart(2, '0'); this.value = d.getFullYear() + '-' + pad(d.getMonth()+1) + '-' + pad(d.getDate()) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes()); } try { this.showPicker(); } catch(e) {}" onclick="this.type='datetime-local'; if(!this.value){ const d = new Date(); const pad = (n) => String(n).padStart(2, '0'); this.value = d.getFullYear() + '-' + pad(d.getMonth()+1) + '-' + pad(d.getDate()) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes()); } try { this.showPicker(); } catch(e) {}" onblur="if(!this.value){ this.type='text'; }">
                                            </div>
                                        </div>
                                        <div class="mb-0">
                                            <label for="nrs_exam_findings" class="block text-xs font-semibold text-hms-mid mb-1">Doctor Examination Findings</label>
                                            <textarea id="nrs_exam_findings" name="nrs_exam_findings" rows="3" placeholder="Record physician's key examination findings, instruction details, etc..." class="w-full border border-hms-border rounded-lg p-2.5 text-sm outline-none focus:border-hms-accent focus:ring-2 focus:ring-hms-accent/10 bg-white"><?php echo htmlspecialchars($nursing_plan['exam_findings'] ?? ''); ?></textarea>
                                        </div>
                                    </div>

                                    <!-- B. Skin Assessment & Core Trigger Dropdown -->
                                    <div class="bg-white border border-hms-border rounded-xl p-5 mb-4 shadow-sm">
                                        
                                        <!-- Skin Assessment Section (preserved for data model completeness) -->
                                        <div class="border-b border-hms-border pb-4 mb-4">
                                            <div class="flex items-center gap-2 mb-3">
                                                <input type="checkbox" id="prep_assessment_done" name="prep_assessment_done" class="w-4 h-4 accent-hms-accent rounded cursor-pointer" <?php echo !empty($nursing_plan['prep_assessment_done']) ? 'checked' : ''; ?>>
                                                <label for="prep_assessment_done" class="text-xs font-bold text-hms-dark cursor-pointer select-none">Skin Assessment done</label>
                                            </div>
                                            <div class="mb-2">
                                                <label for="nrs_prep_skin_type" class="block text-xs font-semibold text-hms-mid mb-1">Skin Type &amp; Details</label>
                                                <input type="text" id="nrs_prep_skin_type" name="nrs_prep_skin_type" placeholder="e.g., Skin type - 3 thin - medium hairs" class="w-full border border-hms-border rounded-lg p-2.5 text-sm outline-none focus:border-hms-accent focus:ring-2 focus:ring-hms-accent/10 bg-white" value="<?php echo htmlspecialchars($nursing_plan['prep_skin_type'] ?? ''); ?>">
                                            </div>
                                            <div class="flex flex-wrap gap-1.5 mt-2 items-center">
                                                <span class="text-[10px] font-bold text-hms-muted mr-1">Quick Presets:</span>
                                                <button type="button" class="prep-skin-preset px-2.5 py-1 bg-gray-50 border border-hms-border hover:bg-hms-accent hover:text-white hover:border-hms-accent rounded text-[10px] font-semibold text-hms-dark transition" data-val="Skin type - 3 thin - medium hairs">Skin Type III (Thin-Med)</button>
                                                <button type="button" class="prep-skin-preset px-2.5 py-1 bg-gray-50 border border-hms-border hover:bg-hms-accent hover:text-white hover:border-hms-accent rounded text-[10px] font-semibold text-hms-dark transition" data-val="Skin type - 4 medium - thick hairs">Skin Type IV (Med-Thick)</button>
                                                <button type="button" class="prep-skin-preset px-2.5 py-1 bg-gray-50 border border-hms-border hover:bg-hms-accent hover:text-white hover:border-hms-accent rounded text-[10px] font-semibold text-hms-dark transition" data-val="Skin type - 2 thin hairs">Skin Type II (Thin)</button>
                                            </div>
                                        </div>

                                        <!-- Core Dynamic Trigger Dropdown -->
                                        <div class="mb-4">
                                            <label for="nrs_target_procedure" class="block text-xs font-bold text-hms-accent mb-1.5 uppercase tracking-wide">Select Targeted Treatment Procedure</label>
                                            <?php $selected_proc = $nursing_plan['target_procedure'] ?? ''; ?>
                                            <select id="nrs_target_procedure" name="nrs_target_procedure" class="w-full border border-hms-border rounded-lg p-2.5 text-sm font-semibold outline-none focus:border-hms-accent focus:ring-2 focus:ring-hms-accent/10 bg-white">
                                                <option value="">-- Click to Select targeted procedure presets --</option>
                                                <option value="Rhinoplasty / Liposuction / Facelift / Tummy Tuck" <?php echo $selected_proc === 'Rhinoplasty / Liposuction / Facelift / Tummy Tuck' ? 'selected' : ''; ?>>Rhinoplasty / Liposuction / Facelift / Tummy Tuck (Surgical)</option>
                                                <option value="Botox / Dysport / Dermal Fillers / Sculptra" <?php echo $selected_proc === 'Botox / Dysport / Dermal Fillers / Sculptra' ? 'selected' : ''; ?>>Botox / Dysport / Dermal Fillers / Sculptra (Injectable)</option>
                                                <option value="Fractional CO2 Laser / Softlight Laser Peel" <?php echo $selected_proc === 'Fractional CO2 Laser / Softlight Laser Peel' ? 'selected' : ''; ?>>Fractional CO2 Laser / Softlight Laser Peel (Laser)</option>
                                                <option value="IPL / Laser Hair Removal" <?php echo $selected_proc === 'IPL / Laser Hair Removal' ? 'selected' : ''; ?>>IPL / Laser Hair Removal (Laser Hair)</option>
                                                <option value="Mesotherapy / Microneedling / Chemical Peels" <?php echo $selected_proc === 'Mesotherapy / Microneedling / Chemical Peels' ? 'selected' : ''; ?>>Mesotherapy / Microneedling / Chemical Peels (Skin Resurfacing)</option>
                                                <option value="PRP Treatment (Platelet-Rich Plasma)" <?php echo $selected_proc === 'PRP Treatment (Platelet-Rich Plasma)' ? 'selected' : ''; ?>>PRP Treatment (Platelet-Rich Plasma) (PRP)</option>
                                            </select>
                                        </div>

                                        <!-- Pre-Treatment Checklist Grid (13-item wrapper) -->
                                        <div>
                                            <div class="flex justify-between items-center mb-3">
                                                <label class="block text-xs font-semibold text-hms-mid">Pre-Treatment Checklist</label>
                                                <button type="button" id="btnSelectStandardPrep" class="border border-hms-accent text-hms-accent hover:bg-hms-accent hover:text-white rounded px-2.5 py-1 text-xxs font-semibold transition">Select Standard Prep</button>
                                            </div>
                                            
                                            <!-- 13-item grid exactly styled in cards -->
                                            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                                                <!-- 1. Patient identity verified -->
                                                <div class="prep-chk-wrapper bg-white border border-hms-border rounded-xl p-3 flex items-start gap-3 shadow-sm select-none transition hover:border-hms-accent hover:shadow-md">
                                                    <input type="checkbox" id="prep_id_verified" name="prep_id_verified" class="w-4 h-4 accent-hms-accent rounded mt-0.5 cursor-pointer" <?php echo !empty($nursing_plan['prep_checklist']['id_verified']) ? 'checked' : ''; ?>>
                                                    <div class="flex-grow">
                                                        <label for="prep_id_verified" class="text-xs font-semibold text-hms-dark cursor-pointer block leading-tight">Patient identity verified (wristband)</label>
                                                    </div>
                                                </div>

                                                <!-- 2. Consent signed -->
                                                <div class="prep-chk-wrapper bg-white border border-hms-border rounded-xl p-3 flex items-start gap-3 shadow-sm select-none transition hover:border-hms-accent hover:shadow-md">
                                                    <input type="checkbox" id="prep_consent" name="prep_consent" class="w-4 h-4 accent-hms-accent rounded mt-0.5 cursor-pointer" <?php echo !empty($nursing_plan['prep_checklist']['consent']) ? 'checked' : ''; ?>>
                                                    <div class="flex-grow">
                                                        <label for="prep_consent" class="text-xs font-semibold text-hms-dark cursor-pointer block leading-tight">Consent signed and secured</label>
                                                    </div>
                                                </div>

                                                <!-- 3. Allergies reviewed -->
                                                <div class="prep-chk-wrapper bg-white border border-hms-border rounded-xl p-3 flex items-start gap-3 shadow-sm select-none transition hover:border-hms-accent hover:shadow-md">
                                                    <input type="checkbox" id="prep_allergies_checked" name="prep_allergies_checked" class="w-4 h-4 accent-hms-accent rounded mt-0.5 cursor-pointer" <?php echo !empty($nursing_plan['prep_checklist']['allergies_checked']) ? 'checked' : ''; ?>>
                                                    <div class="flex-grow">
                                                        <label for="prep_allergies_checked" class="text-xs font-semibold text-hms-dark cursor-pointer block leading-tight">Allergies reviewed &amp; confirmed</label>
                                                    </div>
                                                </div>

                                                <!-- 4. Explained procedure -->
                                                <div class="prep-chk-wrapper bg-white border border-hms-border rounded-xl p-3 flex items-start gap-3 shadow-sm select-none transition hover:border-hms-accent hover:shadow-md">
                                                    <input type="checkbox" id="prep_procedure_explained" name="prep_procedure_explained" class="w-4 h-4 accent-hms-accent rounded mt-0.5 cursor-pointer" <?php echo !empty($nursing_plan['prep_checklist']['procedure_explained']) ? 'checked' : ''; ?>>
                                                    <div class="flex-grow">
                                                        <label for="prep_procedure_explained" class="text-xs font-semibold text-hms-dark cursor-pointer block leading-tight">Explained procedure &amp; outcomes</label>
                                                    </div>
                                                </div>

                                                <!-- 5. Patient positioned -->
                                                <div class="prep-chk-wrapper bg-white border border-hms-border rounded-xl p-3 flex items-start gap-3 shadow-sm select-none transition hover:border-hms-accent hover:shadow-md">
                                                    <input type="checkbox" id="prep_positioning" name="prep_positioning" class="w-4 h-4 accent-hms-accent rounded mt-0.5 cursor-pointer" <?php echo !empty($nursing_plan['prep_checklist']['positioning']) ? 'checked' : ''; ?>>
                                                    <div class="flex-grow">
                                                        <label for="prep_positioning" class="text-xs font-semibold text-hms-dark cursor-pointer block leading-tight">Patient positioned appropriately</label>
                                                    </div>
                                                </div>

                                                <!-- 6. Emergency kit available -->
                                                <div class="prep-chk-wrapper bg-white border border-hms-border rounded-xl p-3 flex items-start gap-3 shadow-sm select-none transition hover:border-hms-accent hover:shadow-md">
                                                    <input type="checkbox" id="prep_emergency_kit" name="prep_emergency_kit" class="w-4 h-4 accent-hms-accent rounded mt-0.5 cursor-pointer" <?php echo !empty($nursing_plan['prep_checklist']['emergency_kit']) ? 'checked' : ''; ?>>
                                                    <div class="flex-grow">
                                                        <label for="prep_emergency_kit" class="text-xs font-semibold text-hms-dark cursor-pointer block leading-tight">Emergency kit &amp; resuscitation available</label>
                                                    </div>
                                                </div>

                                                <!-- 7. Fasting confirmed -->
                                                <div class="prep-chk-wrapper bg-white border border-hms-border rounded-xl p-3 flex items-start gap-3 shadow-sm select-none transition hover:border-hms-accent hover:shadow-md">
                                                    <input type="checkbox" id="prep_fasting" name="prep_fasting" class="w-4 h-4 accent-hms-accent rounded mt-0.5 cursor-pointer" <?php echo !empty($nursing_plan['prep_checklist']['fasting']) ? 'checked' : ''; ?>>
                                                    <div class="flex-grow">
                                                        <label for="prep_fasting" class="text-xs font-semibold text-hms-dark cursor-pointer block leading-tight">Fasting / dietary status confirmed</label>
                                                    </div>
                                                </div>

                                                <!-- 8. IV access established -->
                                                <div class="prep-chk-wrapper bg-white border border-hms-border rounded-xl p-3 flex items-start gap-3 shadow-sm select-none transition hover:border-hms-accent hover:shadow-md">
                                                    <input type="checkbox" id="prep_iv_access" name="prep_iv_access" class="w-4 h-4 accent-hms-accent rounded mt-0.5 cursor-pointer" <?php echo !empty($nursing_plan['prep_checklist']['iv_access']) ? 'checked' : ''; ?>>
                                                    <div class="flex-grow">
                                                        <label for="prep_iv_access" class="text-xs font-semibold text-hms-dark cursor-pointer block leading-tight">IV access / cannula established</label>
                                                    </div>
                                                </div>

                                                <!-- 9. Monitoring equipment connected -->
                                                <div class="prep-chk-wrapper bg-white border border-hms-border rounded-xl p-3 flex items-start gap-3 shadow-sm select-none transition hover:border-hms-accent hover:shadow-md">
                                                    <input type="checkbox" id="prep_monitoring" name="prep_monitoring" class="w-4 h-4 accent-hms-accent rounded mt-0.5 cursor-pointer" <?php echo !empty($nursing_plan['prep_checklist']['monitoring']) ? 'checked' : ''; ?>>
                                                    <div class="flex-grow">
                                                        <label for="prep_monitoring" class="text-xs font-semibold text-hms-dark cursor-pointer block leading-tight">Monitoring equipment connected</label>
                                                    </div>
                                                </div>

                                                <!-- 10. Baseline vitals recorded -->
                                                <div class="prep-chk-wrapper bg-white border border-hms-border rounded-xl p-3 flex items-start gap-3 shadow-sm select-none transition hover:border-hms-accent hover:shadow-md">
                                                    <input type="checkbox" id="prep_baseline_vitals" name="prep_baseline_vitals" class="w-4 h-4 accent-hms-accent rounded mt-0.5 cursor-pointer" <?php echo !empty($nursing_plan['prep_checklist']['baseline_vitals']) ? 'checked' : ''; ?>>
                                                    <div class="flex-grow">
                                                        <label for="prep_baseline_vitals" class="text-xs font-semibold text-hms-dark cursor-pointer block leading-tight">Baseline vitals recorded pre-treatment</label>
                                                    </div>
                                                </div>

                                                <!-- 11. Required lab work completed -->
                                                <div class="prep-chk-wrapper bg-white border border-hms-border rounded-xl p-3 flex items-start gap-3 shadow-sm select-none transition hover:border-hms-accent hover:shadow-md">
                                                    <input type="checkbox" id="prep_labwork" name="prep_labwork" class="w-4 h-4 accent-hms-accent rounded mt-0.5 cursor-pointer" <?php echo !empty($nursing_plan['prep_checklist']['labwork']) ? 'checked' : ''; ?>>
                                                    <div class="flex-grow">
                                                        <label for="prep_labwork" class="text-xs font-semibold text-hms-dark cursor-pointer block leading-tight">Required lab work completed</label>
                                                    </div>
                                                </div>

                                                <!-- 12. Markings and shaving done -->
                                                <div class="prep-chk-wrapper bg-white border border-hms-border rounded-xl p-3 flex items-start gap-3 shadow-sm select-none transition hover:border-hms-accent hover:shadow-md">
                                                    <input type="checkbox" id="prep_markings_shaving" name="prep_markings_shaving" class="w-4 h-4 accent-hms-accent rounded mt-0.5 cursor-pointer" <?php echo !empty($nursing_plan['prep_checklist']['markings_shaving']) ? 'checked' : ''; ?>>
                                                    <div class="flex-grow">
                                                        <label for="prep_markings_shaving" class="text-xs font-semibold text-hms-dark cursor-pointer block leading-tight">Markings and shaving done</label>
                                                    </div>
                                                </div>

                                                <!-- 13. Protective goggles provided -->
                                                <div class="prep-chk-wrapper bg-white border border-hms-border rounded-xl p-3 flex items-start gap-3 shadow-sm select-none transition hover:border-hms-accent hover:shadow-md">
                                                    <input type="checkbox" id="prep_goggles_provided" name="prep_goggles_provided" class="w-4 h-4 accent-hms-accent rounded mt-0.5 cursor-pointer" <?php echo !empty($nursing_plan['prep_checklist']['goggles_provided']) ? 'checked' : ''; ?>>
                                                    <div class="flex-grow">
                                                        <label for="prep_goggles_provided" class="text-xs font-semibold text-hms-dark cursor-pointer block leading-tight">Protective eye goggles provided</label>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>



                                    <!-- Navigation Action -->
                                    <div class="flex justify-end mt-4">
                                        <button type="button" class="border-0 bg-hms-accent hover:bg-hms-accentDim text-white rounded-lg px-6 py-2.5 text-sm font-semibold shadow-sm transition duration-150" onclick="goToSubTab('nrs-treatment-tab')">Continue to Clinical Parameters &rarr;</button>
                                    </div>
                                </div>

                                <!-- Tab 6.2: Clinical Treatment & Session Tolerance -->
                                <div class="tab-pane fade" id="nrs-treatment-pane" role="tabpanel">

                                    <!-- Session Tolerated Well Top Checkbox -->
                                    <div class="mb-4 bg-white border border-hms-border rounded-xl p-4 flex items-center justify-between shadow-sm">
                                        <div class="flex items-center gap-3">
                                            <input type="checkbox" id="top_session_tolerated" name="nrs_tolerance" value="Tolerated Well" class="w-5 h-5 accent-hms-accent rounded cursor-pointer" <?php echo (($nursing_plan['tolerance'] ?? 'Tolerated Well') === 'Tolerated Well') ? 'checked' : ''; ?>>
                                            <div>
                                                <label for="top_session_tolerated" class="text-sm font-bold text-hms-dark cursor-pointer select-none">Session Tolerated Well</label>
                                                <span class="text-hms-muted text-[10px] block">Quick check if the patient tolerated the procedure without any issues.</span>
                                            </div>
                                        </div>
                                        <span class="text-xs font-semibold px-3 py-1 bg-green-50 text-green-700 border border-green-200 rounded-full" id="top_tolerance_badge">Yes</span>
                                    </div>

                                    <!-- 6.2.A — Dynamic Treatment Location Parameters Table -->
                                    <div class="bg-white border border-hms-border rounded-xl p-5 mb-4 shadow-sm">
                                        <h5 class="font-serif font-bold text-hms-accent text-sm mb-1 flex items-center gap-2">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                            6.2.A — Laser Procedure Parameters ("Parameters used")
                                        </h5>
                                        <p class="text-hms-muted text-xs mb-3">Add treatment parameter records. Use the quick-add area pills to instantly append preset rows, or click "Add Row".</p>
                                        
                                        <!-- Quick Add Pills Panel -->
                                        <div class="mb-4 bg-gray-50 border border-hms-border rounded-xl p-3">
                                            <span class="text-[10px] font-bold text-hms-mid uppercase tracking-wider block mb-2">Quick Add Treatment Area Presets:</span>
                                            <div class="flex flex-wrap gap-2">
                                                <button type="button" class="quick-add-param-pill px-3 py-1 bg-white border border-hms-border hover:border-hms-accent hover:bg-hms-accent/5 rounded-full text-xs font-semibold text-hms-dark transition" data-area="3/4 Legs" data-laser="alex 755nm" data-fluence="12j/cm2" data-spot="18mm" data-pulse="3ms">+ 3/4 Legs</button>
                                                <button type="button" class="quick-add-param-pill px-3 py-1 bg-white border border-hms-border hover:border-hms-accent hover:bg-hms-accent/5 rounded-full text-xs font-semibold text-hms-dark transition" data-area="Full arms" data-laser="alex 755nm" data-fluence="12j/cm2" data-spot="18mm" data-pulse="3ms">+ Full Arms</button>
                                                <button type="button" class="quick-add-param-pill px-3 py-1 bg-white border border-hms-border hover:border-hms-accent hover:bg-hms-accent/5 rounded-full text-xs font-semibold text-hms-dark transition" data-area="Underarms" data-laser="alex 755nm" data-fluence="10j/cm2" data-spot="18mm" data-pulse="3ms">+ Underarms</button>
                                                <button type="button" class="quick-add-param-pill px-3 py-1 bg-white border border-hms-border hover:border-hms-accent hover:bg-hms-accent/5 rounded-full text-xs font-semibold text-hms-dark transition" data-area="Uppertip" data-laser="alex 755nm" data-fluence="10j/cm2" data-spot="18mm" data-pulse="3ms">+ Uppertip</button>
                                                <button type="button" class="quick-add-param-pill px-3 py-1 bg-white border border-hms-border hover:border-hms-accent hover:bg-hms-accent/5 rounded-full text-xs font-semibold text-hms-dark transition" data-area="Bikini" data-laser="alex 755nm" data-fluence="10j/cm2" data-spot="18mm" data-pulse="3ms" data-notes="Not doing bikini">+ Bikini Preset</button>
                                                <button type="button" class="quick-add-param-pill px-3 py-1 bg-white border border-hms-border hover:border-hms-accent hover:bg-hms-accent/5 rounded-full text-xs font-semibold text-hms-dark transition" data-area="Chin" data-laser="alex 755nm" data-fluence="10j/cm2" data-spot="15mm" data-pulse="3ms">+ Chin</button>
                                                <button type="button" class="quick-add-param-pill px-3 py-1 bg-white border border-hms-border hover:border-hms-accent hover:bg-hms-accent/5 rounded-full text-xs font-semibold text-hms-dark transition" data-area="Full Body" data-laser="alex 755nm" data-fluence="12j/cm2" data-spot="18mm" data-pulse="3ms" data-notes="All areas">+ Full Body</button>
                                            </div>
                                        </div>

                                        <!-- Table Container -->
                                        <div class="overflow-x-auto">
                                            <table class="w-full text-left border-collapse" style="min-width: 700px;">
                                                <thead>
                                                    <tr class="bg-gray-50 border-b border-hms-border text-hms-mid text-xs font-semibold uppercase">
                                                        <th class="py-2 px-3">Treatment Area</th>
                                                        <th class="py-2 px-3" style="width: 150px;">Laser/Machine</th>
                                                        <th class="py-2 px-3" style="width: 100px;">Fluence</th>
                                                        <th class="py-2 px-3" style="width: 90px;">Spot Size</th>
                                                        <th class="py-2 px-3" style="width: 90px;">Pulse Dur.</th>
                                                        <th class="py-2 px-3">Status / Notes</th>
                                                        <th class="py-2 px-3 text-center" style="width: 50px;">Del</th>
                                                    </tr>
                                                </thead>
                                                <tbody id="laserParamsTableBody">
                                                    <?php 
                                                    $params_used = $nursing_plan['procedure_parameters'] ?? [];
                                                    if (!empty($params_used)):
                                                        foreach ($params_used as $pIndex => $p):
                                                    ?>
                                                        <tr class="border-b border-hms-border laser-param-row">
                                                            <td class="py-2 px-2">
                                                                <input type="text" class="w-full border border-hms-border rounded-lg p-1.5 text-xs outline-none focus:border-hms-accent nrs-param-area" name="nrs_param_area[]" placeholder="e.g. Underarms" value="<?php echo htmlspecialchars($p['area'] ?? ''); ?>" required>
                                                            </td>
                                                            <td class="py-2 px-2">
                                                                <input type="text" class="w-full border border-hms-border rounded-lg p-1.5 text-xs outline-none focus:border-hms-accent nrs-param-laser" name="nrs_param_laser[]" placeholder="alex 755nm" value="<?php echo htmlspecialchars($p['laser'] ?? ''); ?>">
                                                            </td>
                                                            <td class="py-2 px-2">
                                                                <input type="text" class="w-full border border-hms-border rounded-lg p-1.5 text-xs outline-none focus:border-hms-accent nrs-param-fluence" name="nrs_param_fluence[]" placeholder="12j/cm2" value="<?php echo htmlspecialchars($p['fluence'] ?? ''); ?>">
                                                            </td>
                                                            <td class="py-2 px-2">
                                                                <input type="text" class="w-full border border-hms-border rounded-lg p-1.5 text-xs outline-none focus:border-hms-accent nrs-param-spot" name="nrs_param_spot[]" placeholder="18mm" value="<?php echo htmlspecialchars($p['spot'] ?? ''); ?>">
                                                            </td>
                                                            <td class="py-2 px-2">
                                                                <input type="text" class="w-full border border-hms-border rounded-lg p-1.5 text-xs outline-none focus:border-hms-accent nrs-param-pulse" name="nrs_param_pulse[]" placeholder="3ms" value="<?php echo htmlspecialchars($p['pulse'] ?? ''); ?>">
                                                            </td>
                                                            <td class="py-2 px-2">
                                                                <input type="text" class="w-full border border-hms-border rounded-lg p-1.5 text-xs outline-none focus:border-hms-accent nrs-param-notes" name="nrs_param_notes[]" placeholder="Status or notes" value="<?php echo htmlspecialchars($p['notes'] ?? ''); ?>">
                                                            </td>
                                                            <td class="py-2 px-2 text-center">
                                                                <button type="button" class="text-red-500 hover:text-red-700 text-lg font-bold remove-param-row-btn" onclick="this.closest('tr').remove()">&times;</button>
                                                            </td>
                                                        </tr>
                                                    <?php 
                                                        endforeach;
                                                    endif; 
                                                    ?>
                                                </tbody>
                                            </table>
                                        </div>
                                        
                                        <div class="flex justify-between items-center mt-3 pt-3 border-t border-dashed border-hms-border">
                                            <p class="text-hms-muted text-xxs italic">Parameters compile into clean formatted report (e.g. 3/4 Legs : alex 755nm: 12j/cm2 18mm 3ms)</p>
                                            <button type="button" class="border border-hms-accent text-hms-accent hover:bg-hms-accent hover:text-white rounded px-3 py-1.5 text-xs font-semibold transition" id="addLaserParamRowBtn">+ Add Parameter Row</button>
                                        </div>
                                    </div>

                                    <!-- 6.2.B — Observations & Actions -->
                                    <div class="bg-white border border-hms-border rounded-xl p-5 mb-4 shadow-sm">
                                        <h5 class="font-serif font-bold text-hms-accent text-sm mb-1 flex items-center gap-2">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                            6.2.B — Post-Procedure Observations &amp; Actions
                                        </h5>
                                        <p class="text-hms-muted text-xs mb-3">Document clinical observations and immediate post-treatment interventions.</p>
                                        
                                        <div class="bg-hms-panel border border-hms-border rounded-xl p-4 mb-4">
                                            <div class="flex justify-between items-center mb-3">
                                                <label class="block text-xs font-semibold text-hms-dark">Observation Checklist</label>
                                                <button type="button" id="btnSelectStandardObs" class="border border-hms-accent text-hms-accent hover:bg-hms-accent hover:text-white rounded px-2.5 py-1 text-xxs font-semibold transition">Select Standard Observations</button>
                                            </div>
                                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                                                <label class="flex items-center gap-2 text-xs text-hms-dark cursor-pointer select-none">
                                                    <input type="checkbox" id="obs_procedure_done" name="obs_procedure_done" class="w-4 h-4 accent-hms-accent rounded cursor-pointer" <?php echo !empty($nursing_plan['post_procedure_checklist']['procedure_done']) ? 'checked' : ''; ?>>
                                                    <span>Laser hair reduction done.</span>
                                                </label>
                                                <label class="flex items-center gap-2 text-xs text-hms-dark cursor-pointer select-none">
                                                    <input type="checkbox" id="obs_erythema_edema" name="obs_erythema_edema" class="w-4 h-4 accent-hms-accent rounded cursor-pointer" <?php echo !empty($nursing_plan['post_procedure_checklist']['erythema_edema']) ? 'checked' : ''; ?>>
                                                    <span>Mild erythema and perifollicular edema noted.</span>
                                                </label>
                                                <label class="flex items-center gap-2 text-xs text-hms-dark cursor-pointer select-none">
                                                    <input type="checkbox" id="obs_no_complaints" name="obs_no_complaints" class="w-4 h-4 accent-hms-accent rounded cursor-pointer" <?php echo !empty($nursing_plan['post_procedure_checklist']['no_complaints']) ? 'checked' : ''; ?>>
                                                    <span>No complaints of pain or burn sensation.</span>
                                                </label>
                                                <label class="flex items-center gap-2 text-xs text-hms-dark cursor-pointer select-none">
                                                    <input type="checkbox" id="obs_fucicort_applied" name="obs_fucicort_applied" class="w-4 h-4 accent-hms-accent rounded cursor-pointer" <?php echo !empty($nursing_plan['post_procedure_checklist']['fucicort_applied']) ? 'checked' : ''; ?>>
                                                    <span>Fucicort cream applied.</span>
                                                </label>
                                                <label class="flex items-center gap-2 text-xs text-hms-dark cursor-pointer select-none">
                                                    <input type="checkbox" id="obs_fucidin_applied" name="obs_fucidin_applied" class="w-4 h-4 accent-hms-accent rounded cursor-pointer" <?php echo !empty($nursing_plan['post_procedure_checklist']['fucidin_applied']) ? 'checked' : ''; ?>>
                                                    <span>Fucidin cream applied.</span>
                                                </label>
                                                <label class="flex items-center gap-2 text-xs text-hms-dark cursor-pointer select-none">
                                                    <input type="checkbox" id="obs_cold_compress" name="obs_cold_compress" class="w-4 h-4 accent-hms-accent rounded cursor-pointer" <?php echo !empty($nursing_plan['post_procedure_checklist']['cold_compress']) ? 'checked' : ''; ?>>
                                                    <span>Cold compress applied post-treatment.</span>
                                                </label>
                                            </div>
                                        </div>

                                        <div>
                                            <label for="nrs_changes_performed" class="block text-xs font-semibold text-hms-mid mb-1">Custom Procedure / Clinical Treatment Notes (Overrides standard checklist if filled)</label>
                                            <textarea id="nrs_changes_performed" name="nrs_changes_performed" rows="3" placeholder="e.g., Laser hair reduction done. Fucicort cream applied. Mild redness noted but settled in 5 mins..." class="w-full border border-hms-border rounded-lg p-2.5 text-sm outline-none focus:border-hms-accent focus:ring-2 focus:ring-hms-accent/10 bg-white"><?php echo htmlspecialchars($nursing_plan['changes_performed'] ?? ''); ?></textarea>
                                        </div>
                                    </div>

                                    <div class="flex justify-between mt-4">
                                        <button type="button" class="border border-hms-border text-hms-mid hover:bg-gray-50 rounded-lg px-6 py-2.5 text-sm font-semibold transition duration-150" onclick="goToSubTab('nrs-prep-tab')">&larr; Back to Pre-Treatment Prep</button>
                                        <button type="button" class="border-0 bg-hms-accent hover:bg-hms-accentDim text-white rounded-lg px-6 py-2.5 text-sm font-semibold shadow-sm transition duration-150" onclick="goToSubTab('nrs-advisory-tab')">Continue to Advisory Measures &rarr;</button>
                                    </div>
                                </div>

                                <!-- Tab 6.3: Advisory Measures & Discharge Plan -->
                                <div class="tab-pane fade" id="nrs-advisory-pane" role="tabpanel">
                                    <div class="bg-hms-panel border border-hms-border rounded-xl p-5 mb-4 shadow-sm">
                                        <h5 class="font-serif font-bold text-hms-accent text-sm mb-1 flex items-center gap-2">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                                            6.3 — Advisory Measures &amp; Discharge Plan
                                        </h5>
                                        <p class="text-hms-muted text-xs mb-4">Document safety and discharge instructions given to the patient and/or caregivers.</p>

                                        <!-- Safety & Advisory Checklist Grid -->
                                        <div class="bg-white border border-hms-border rounded-xl p-4 mb-4">
                                            <div class="flex justify-between items-center mb-3">
                                                <label class="block text-xs font-semibold text-hms-mid">Standard Safety &amp; Advisory Checklist</label>
                                                <div class="flex gap-2">
                                                    <button type="button" id="btnSelectAllAdvisory" class="border border-hms-accent text-hms-accent hover:bg-hms-accent hover:text-white rounded px-2.5 py-1 text-xxs font-semibold transition">Select All</button>
                                                    <button type="button" id="btnClearAllAdvisory" class="border border-gray-300 text-gray-500 hover:bg-gray-100 rounded px-2.5 py-1 text-xxs font-semibold transition">Clear All</button>
                                                </div>
                                            </div>
                                            
                                            <!-- Responsive grid container for safety cards -->
                                            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                                                <!-- 1. Fall prevention -->
                                                <div class="adv-chk-wrapper bg-white border border-hms-border rounded-xl p-3 flex items-start gap-3 shadow-sm select-none transition hover:border-hms-accent hover:shadow-md">
                                                    <input type="checkbox" id="adv_fall_prevention" name="adv_fall_prevention" class="w-4 h-4 accent-hms-accent rounded mt-0.5 cursor-pointer" <?php echo !empty($nursing_plan['advisory_checklist']['fall_prevention']) ? 'checked' : ''; ?>>
                                                    <div class="flex-grow">
                                                        <label for="adv_fall_prevention" class="text-xs font-semibold text-hms-dark cursor-pointer block leading-tight">Fall prevention measures explained</label>
                                                    </div>
                                                </div>

                                                <!-- 2. Medication schedule -->
                                                <div class="adv-chk-wrapper bg-white border border-hms-border rounded-xl p-3 flex items-start gap-3 shadow-sm select-none transition hover:border-hms-accent hover:shadow-md">
                                                    <input type="checkbox" id="adv_medication_schedule" name="adv_medication_schedule" class="w-4 h-4 accent-hms-accent rounded mt-0.5 cursor-pointer" <?php echo !empty($nursing_plan['advisory_checklist']['medication_schedule']) ? 'checked' : ''; ?>>
                                                    <div class="flex-grow">
                                                        <label for="adv_medication_schedule" class="text-xs font-semibold text-hms-dark cursor-pointer block leading-tight">Medication schedule explained</label>
                                                    </div>
                                                </div>

                                                <!-- 3. Dietary restrictions -->
                                                <div class="adv-chk-wrapper bg-white border border-hms-border rounded-xl p-3 flex items-start gap-3 shadow-sm select-none transition hover:border-hms-accent hover:shadow-md">
                                                    <input type="checkbox" id="adv_diet_restrictions" name="adv_diet_restrictions" class="w-4 h-4 accent-hms-accent rounded mt-0.5 cursor-pointer" <?php echo !empty($nursing_plan['advisory_checklist']['diet_restrictions']) ? 'checked' : ''; ?>>
                                                    <div class="flex-grow">
                                                        <label for="adv_diet_restrictions" class="text-xs font-semibold text-hms-dark cursor-pointer block leading-tight">Dietary restrictions communicated</label>
                                                    </div>
                                                </div>

                                                <!-- 4. Activity limitations -->
                                                <div class="adv-chk-wrapper bg-white border border-hms-border rounded-xl p-3 flex items-start gap-3 shadow-sm select-none transition hover:border-hms-accent hover:shadow-md">
                                                    <input type="checkbox" id="adv_activity_limits" name="adv_activity_limits" class="w-4 h-4 accent-hms-accent rounded mt-0.5 cursor-pointer" <?php echo !empty($nursing_plan['advisory_checklist']['activity_limits']) ? 'checked' : ''; ?>>
                                                    <div class="flex-grow">
                                                        <label for="adv_activity_limits" class="text-xs font-semibold text-hms-dark cursor-pointer block leading-tight">Activity limitations advised</label>
                                                    </div>
                                                </div>

                                                <!-- 5. Wound site care -->
                                                <div class="adv-chk-wrapper bg-white border border-hms-border rounded-xl p-3 flex items-start gap-3 shadow-sm select-none transition hover:border-hms-accent hover:shadow-md">
                                                    <input type="checkbox" id="adv_wound_care" name="adv_wound_care" class="w-4 h-4 accent-hms-accent rounded mt-0.5 cursor-pointer" <?php echo !empty($nursing_plan['advisory_checklist']['wound_care']) ? 'checked' : ''; ?>>
                                                    <div class="flex-grow">
                                                        <label for="adv_wound_care" class="text-xs font-semibold text-hms-dark cursor-pointer block leading-tight">Wound / site care instructions given</label>
                                                    </div>
                                                </div>

                                                <!-- 6. Red flag symptoms -->
                                                <div class="adv-chk-wrapper bg-white border border-hms-border rounded-xl p-3 flex items-start gap-3 shadow-sm select-none transition hover:border-hms-accent hover:shadow-md">
                                                    <input type="checkbox" id="adv_red_flags" name="adv_red_flags" class="w-4 h-4 accent-hms-accent rounded mt-0.5 cursor-pointer" <?php echo !empty($nursing_plan['advisory_checklist']['red_flags']) ? 'checked' : ''; ?>>
                                                    <div class="flex-grow">
                                                        <label for="adv_red_flags" class="text-xs font-semibold text-hms-dark cursor-pointer block leading-tight">Red-flag symptoms to watch for explained</label>
                                                    </div>
                                                </div>

                                                <!-- 7. Hydration fluid intake -->
                                                <div class="adv-chk-wrapper bg-white border border-hms-border rounded-xl p-3 flex items-start gap-3 shadow-sm select-none transition hover:border-hms-accent hover:shadow-md">
                                                    <input type="checkbox" id="adv_hydration" name="adv_hydration" class="w-4 h-4 accent-hms-accent rounded mt-0.5 cursor-pointer" <?php echo !empty($nursing_plan['advisory_checklist']['hydration']) ? 'checked' : ''; ?>>
                                                    <div class="flex-grow">
                                                        <label for="adv_hydration" class="text-xs font-semibold text-hms-dark cursor-pointer block leading-tight">Hydration &amp; fluid intake advised</label>
                                                    </div>
                                                </div>

                                                <!-- 8. Follow up reminder -->
                                                <div class="adv-chk-wrapper bg-white border border-hms-border rounded-xl p-3 flex items-start gap-3 shadow-sm select-none transition hover:border-hms-accent hover:shadow-md">
                                                    <input type="checkbox" id="adv_followup_reminder" name="adv_followup_reminder" class="w-4 h-4 accent-hms-accent rounded mt-0.5 cursor-pointer" <?php echo !empty($nursing_plan['advisory_checklist']['followup_reminder']) ? 'checked' : ''; ?>>
                                                    <div class="flex-grow">
                                                        <label for="adv_followup_reminder" class="text-xs font-semibold text-hms-dark cursor-pointer block leading-tight">Follow-up appointment reminder given</label>
                                                    </div>
                                                </div>

                                                <!-- 9. Emergency contact provided -->
                                                <div class="adv-chk-wrapper bg-white border border-hms-border rounded-xl p-3 flex items-start gap-3 shadow-sm select-none transition hover:border-hms-accent hover:shadow-md">
                                                    <input type="checkbox" id="adv_emergency_contact" name="adv_emergency_contact" class="w-4 h-4 accent-hms-accent rounded mt-0.5 cursor-pointer" <?php echo !empty($nursing_plan['advisory_checklist']['emergency_contact']) ? 'checked' : ''; ?>>
                                                    <div class="flex-grow">
                                                        <label for="adv_emergency_contact" class="text-xs font-semibold text-hms-dark cursor-pointer block leading-tight">Emergency contact number provided</label>
                                                    </div>
                                                </div>

                                                <!-- 10. Not to self medicate -->
                                                <div class="adv-chk-wrapper bg-white border border-hms-border rounded-xl p-3 flex items-start gap-3 shadow-sm select-none transition hover:border-hms-accent hover:shadow-md">
                                                    <input type="checkbox" id="adv_no_self_medicate" name="adv_no_self_medicate" class="w-4 h-4 accent-hms-accent rounded mt-0.5 cursor-pointer" <?php echo !empty($nursing_plan['advisory_checklist']['no_self_medicate']) ? 'checked' : ''; ?>>
                                                    <div class="flex-grow">
                                                        <label for="adv_no_self_medicate" class="text-xs font-semibold text-hms-dark cursor-pointer block leading-tight">Advised not to self-medicate</label>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="mb-0">
                                            <label for="nrs_advisory_notes" class="block text-xs font-semibold text-hms-mid mb-1">Detailed Advisory Notes</label>
                                            <textarea id="nrs_advisory_notes" name="nrs_advisory_notes" rows="4" placeholder="Document any specific safety or discharge instructions given..." class="w-full border border-hms-border rounded-lg p-2.5 text-sm outline-none focus:border-hms-accent focus:ring-2 focus:ring-hms-accent/10 bg-white"><?php echo htmlspecialchars($nursing_plan['advisory_notes'] ?? ''); ?></textarea>
                                        </div>
                                    </div>

                                    <div class="flex justify-between mt-4">
                                        <button type="button" class="border border-hms-border text-hms-mid hover:bg-gray-50 rounded-lg px-6 py-2.5 text-sm font-semibold transition duration-150" onclick="goToSubTab('nrs-treatment-tab')">&larr; Back to Clinical Treatment</button>
                                    </div>
                                </div>

                            </div><!-- end nursingSubTabContent -->

                            <div class="flex justify-between mt-6 pt-4 border-t border-hms-border">
                                <button type="button" class="border border-hms-border text-hms-mid hover:bg-gray-50 rounded-lg px-6 py-2.5 text-sm font-semibold transition duration-150" onclick="goToTab('lab')">Back</button>
                                <button type="button" class="border-0 bg-hms-accent hover:bg-hms-accentDim text-white rounded-lg px-6 py-2.5 text-sm font-semibold shadow-sm transition duration-150" onclick="goToTab('timeline', 'nursing-pane')">Continue to Timeline &amp; Finalize</button>
                            </div>
                        </div>

                        <!-- TAB 7: PATIENT MEDICAL HISTORY TIMELINE -->
                        <div class="tab-pane fade" id="timeline-pane" role="tabpanel" aria-labelledby="drawer-timeline-tab">
                            <h4 class="font-serif text-lg font-semibold text-hms-accent mb-4 border-b border-hms-border pb-1">Patient Medical Timeline (Historical EHR Visits)</h4>
                            
                            <div class="mb-4">
                                <?php if (empty($past_visits)): ?>
                                    <p class="text-hms-muted text-xs italic">No previous finalized clinical case sheets are registered for this patient.</p>
                                <?php else: ?>
                                    <div class="timeline-container">
                                        <?php foreach ($past_visits as $visit): ?>
                                            <div class="timeline-item pb-4">
                                                <div class="font-serif font-bold text-hms-accent mb-1">
                                                    <?php echo date('M d, Y', strtotime($visit['appointment_date'])) . ' at ' . date('h:i A', strtotime($visit['appointment_time'])); ?>
                                                </div>
                                                <div class="bg-gray-50 border border-hms-border p-4 rounded-xl text-xs text-hms-dark space-y-1.5">
                                                    <div><strong>Chief Complaint:</strong> <?php echo htmlspecialchars($visit['chief_complaint'] ?? 'Not recorded'); ?></div>
                                                    <div><strong>Vitals Check:</strong> BP: <?php echo htmlspecialchars($visit['blood_pressure'] ?? 'N/A'); ?> | Temp: <?php echo htmlspecialchars($visit['temperature'] ?? 'N/A'); ?> °C | HR: <?php echo htmlspecialchars($visit['heart_rate'] ?? 'N/A'); ?> bpm | Pain Scale: <?php echo htmlspecialchars($visit['pain_scale'] ?? 'N/A'); ?>/10</div>
                                                    
                                                    <?php if (!empty($visit['diagnoses'])): ?>
                                                        <div class="flex flex-wrap gap-1 items-center mt-1">
                                                            <strong class="mr-1">Diagnoses:</strong>
                                                            <?php foreach ($visit['diagnoses'] as $vd): ?>
                                                                <span class="bg-hms-panel text-hms-accent text-[10px] font-bold px-2 py-0.5 rounded-full border border-blue-200"><?php echo htmlspecialchars($vd['icd_code']); ?>: <?php echo htmlspecialchars($vd['description']); ?></span>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    <?php endif; ?>

                                                    <?php if (!empty($visit['prescriptions'])): ?>
                                                        <div class="mt-1">
                                                            <strong>Medications Handout:</strong>
                                                            <ul class="list-disc pl-4 space-y-0.5 mt-0.5">
                                                                <?php foreach ($visit['prescriptions'] as $vp): ?>
                                                                    <li><?php echo htmlspecialchars($vp['medicine_name']); ?> (<?php echo htmlspecialchars($vp['dosage']); ?>, <?php echo htmlspecialchars($vp['duration']); ?>)</li>
                                                                <?php endforeach; ?>
                                                            </ul>
                                                        </div>
                                                    <?php endif; ?>

                                                    <?php if (!empty($visit['tests'])): ?>
                                                        <div class="mt-1">
                                                            <strong>Laboratory Orders:</strong>
                                                            <ul class="list-disc pl-4 space-y-0.5 mt-0.5">
                                                                <?php foreach ($visit['tests'] as $vt): ?>
                                                                    <li><?php echo htmlspecialchars($vt['test_name']); ?> (Category: <?php echo htmlspecialchars($vt['category']); ?>, Priority: <?php echo htmlspecialchars($vt['priority']); ?>)</li>
                                                                <?php endforeach; ?>
                                                            </ul>
                                                        </div>
                                                    <?php endif; ?>

                                                    <?php if (!empty($visit['narrative_diagnosis'])): ?>
                                                         <div class="mt-1 text-hms-mid border-t border-dashed border-hms-border pt-1">
                                                             <strong>Narrative Diagnosis:</strong> <?php echo htmlspecialchars($visit['narrative_diagnosis']); ?>
                                                         </div>
                                                     <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Follow-up Appointment Scheduling -->
                            <div class="mt-6 pt-4 border-t border-hms-border">
                                <div class="flex items-center gap-3 mb-4">
                                    <input type="checkbox" id="followup_enabled" name="followup_enabled" value="1" class="w-4 h-4 accent-hms-accent cursor-pointer rounded" onchange="toggleFollowup(this)" <?php echo !empty($existing_consultation['followup_date']) ? 'checked' : ''; ?>>
                                    <label for="followup_enabled" class="font-serif font-semibold text-hms-dark text-sm cursor-pointer select-none">Schedule a Follow-up Appointment for this Patient</label>
                                </div>
                                <div id="followupFields" class="<?php echo !empty($existing_consultation['followup_date']) ? '' : 'hidden'; ?> bg-hms-panel border border-hms-border rounded-xl p-5 mb-4">
                                    <p class="text-hms-muted text-xs mb-4 font-medium">The follow-up appointment will be automatically created and scheduled under the selected practitioner when you save as draft or finalize.</p>
                                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                        <div>
                                            <label for="followup_date" class="block text-xs font-semibold text-hms-mid mb-1">Follow-up Date</label>
                                            <input type="<?php echo !empty($existing_consultation['followup_date']) ? 'date' : 'text'; ?>" id="followup_date" name="followup_date" placeholder="Click here" class="w-full border border-hms-border rounded-lg p-2.5 text-sm outline-none focus:border-hms-accent focus:ring-2 focus:ring-hms-accent/10 cursor-pointer bg-white" min="<?php echo date('Y-m-d'); ?>" value="<?php echo htmlspecialchars($existing_consultation['followup_date'] ?? ''); ?>" onfocus="this.type='date'; if(!this.value){ const d = new Date(); const pad = (n) => String(n).padStart(2, '0'); this.value = d.getFullYear() + '-' + pad(d.getMonth()+1) + '-' + pad(d.getDate()); } try { this.showPicker(); } catch(e) {}" onclick="this.type='date'; if(!this.value){ const d = new Date(); const pad = (n) => String(n).padStart(2, '0'); this.value = d.getFullYear() + '-' + pad(d.getMonth()+1) + '-' + pad(d.getDate()); } try { this.showPicker(); } catch(e) {}" onblur="if(!this.value){ this.type='text'; }">
                                        </div>
                                        <div>
                                            <label for="followup_time" class="block text-xs font-semibold text-hms-mid mb-1">Follow-up Time</label>
                                            <input type="<?php echo !empty($existing_consultation['followup_time']) ? 'time' : 'text'; ?>" id="followup_time" name="followup_time" placeholder="Click here" class="w-full border border-hms-border rounded-lg p-2.5 text-sm outline-none focus:border-hms-accent focus:ring-2 focus:ring-hms-accent/10 cursor-pointer bg-white" value="<?php echo htmlspecialchars(!empty($existing_consultation['followup_time']) ? date('H:i', strtotime($existing_consultation['followup_time'])) : ''); ?>" onfocus="this.type='time'; if(!this.value){ const d = new Date(); const pad = (n) => String(n).padStart(2, '0'); this.value = pad(d.getHours()) + ':' + pad(d.getMinutes()); } try { this.showPicker(); } catch(e) {}" onclick="this.type='time'; if(!this.value){ const d = new Date(); const pad = (n) => String(n).padStart(2, '0'); this.value = pad(d.getHours()) + ':' + pad(d.getMinutes()); } try { this.showPicker(); } catch(e) {}" onblur="if(!this.value){ this.type='text'; }">
                                        </div>
                                        <div>
                                            <label for="followup_doctor_id" class="block text-xs font-semibold text-hms-mid mb-1">Practitioner / Specialty Referral</label>
                                            <select id="followup_doctor_id" name="followup_doctor_id" class="w-full border border-hms-border rounded-lg p-2.5 text-sm outline-none focus:border-hms-accent focus:ring-2 focus:ring-hms-accent/10 bg-white">
                                                <?php
                                                $selected_doc = !empty($existing_consultation['followup_doctor_id']) ? intval($existing_consultation['followup_doctor_id']) : intval($_SESSION['doctor_id']);
                                                foreach ($all_doctors as $doc) {
                                                    $isSel = ($doc['doctor_id'] == $selected_doc) ? 'selected' : '';
                                                    echo '<option value="' . htmlspecialchars($doc['doctor_id']) . '" ' . $isSel . '>' . htmlspecialchars($doc['name']) . '</option>';
                                                }
                                                ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="flex justify-between mt-4 pt-4 border-t border-hms-border">
                                <button type="button" class="border border-hms-border text-hms-mid hover:bg-gray-50 rounded-lg px-6 py-2.5 text-sm font-semibold transition duration-150" onclick="goToTab('nursing')">Back</button>
                                <div class="flex gap-2">
                                    <button type="submit" name="submit_type" value="draft" class="border border-yellow-300 text-yellow-600 hover:bg-yellow-50 rounded-lg px-5 py-2.5 text-sm font-semibold transition duration-150">Save as Draft</button>
                                    <button type="submit" name="submit_type" value="finalize" class="border-0 bg-hms-accent hover:bg-hms-accentDim text-white rounded-lg px-6 py-2.5 text-sm font-semibold shadow-sm transition duration-150">Finalize Consultation</button>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>
            </form>

        </div>
    </div>

    <!-- Hover-Activated Side Drawer for Tabs Navigation -->
    <!-- Edge trigger strip: hover over to show drawer -->
    <div id="drawerEdgeTrigger" style="position:fixed;top:0;left:0;width:10px;height:100vh;z-index:1040;cursor:pointer;" aria-hidden="true"></div>

    <!-- Side Drawer Panel -->
    <div id="consultationDrawer" style="position:fixed;top:0;left:-300px;width:300px;height:100vh;z-index:1041;background:#E4EBF4;border-right:1px solid #E5EAF0;box-shadow:4px 0 24px rgba(31,41,55,0.09);transition:left 0.28s cubic-bezier(0.4,0,0.2,1);display:flex;flex-direction:column;" aria-label="Consultation Navigation Drawer">
        <div style="background:#FFFFFF;border-bottom:1px solid #E5EAF0;padding:1.25rem 1.25rem 1rem;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;">
            <h5 class="font-serif font-bold text-hms-accent" style="margin:0;font-size:1rem;">Consultation Sections</h5>
            <button type="button" onclick="closeDrawer()" style="background:none;border:none;cursor:pointer;color:#9CA3AF;font-size:1.25rem;line-height:1;padding:2px 6px;" aria-label="Close navigation drawer">&times;</button>
        </div>
        <div style="padding:1rem;overflow-y:auto;flex:1;">
            <div class="nav flex-column nav-pills gap-1.5" id="consultationDrawerTabs" role="tablist">
                <button class="nav-link active text-left py-3.5 px-4 font-semibold flex items-center justify-between transition duration-200" id="drawer-vitals-tab" data-bs-toggle="pill" data-bs-target="#vitals-pane" type="button" role="tab" aria-controls="vitals-pane" aria-selected="true" onclick="closeDrawer()">
                    <span>1. Vitals</span>
                </button>
                <button class="nav-link text-left py-3.5 px-4 font-semibold flex items-center justify-between transition duration-200" id="drawer-history-tab" data-bs-toggle="pill" data-bs-target="#history-pane" type="button" role="tab" aria-controls="history-pane" aria-selected="false" onclick="closeDrawer()">
                    <span>2. History</span>
                </button>
                <button class="nav-link text-left py-3.5 px-4 font-semibold flex items-center justify-between transition duration-200" id="drawer-notes-tab" data-bs-toggle="pill" data-bs-target="#notes-pane" type="button" role="tab" aria-controls="notes-pane" aria-selected="false" onclick="closeDrawer()">
                    <span>3. Notes &amp; Diagnosis</span>
                </button>
                <button class="nav-link text-left py-3.5 px-4 font-semibold flex items-center justify-between transition duration-200" id="drawer-medications-tab" data-bs-toggle="pill" data-bs-target="#medications-pane" type="button" role="tab" aria-controls="medications-pane" aria-selected="false" onclick="closeDrawer()">
                    <span>4. Prescriptions &amp; Treatment Plan</span>
                </button>
                <button class="nav-link text-left py-3.5 px-4 font-semibold flex items-center justify-between transition duration-200" id="drawer-lab-tab" data-bs-toggle="pill" data-bs-target="#lab-pane" type="button" role="tab" aria-controls="lab-pane" aria-selected="false" onclick="closeDrawer()">
                    <span>5. Laboratory</span>
                </button>
                <button class="nav-link text-left py-3.5 px-4 font-semibold flex items-center justify-between transition duration-200" id="drawer-nursing-tab" data-bs-toggle="pill" data-bs-target="#nursing-pane" type="button" role="tab" aria-controls="nursing-pane" aria-selected="false" onclick="closeDrawer()">
                    <span>6. Clinical Treatment &amp; Nursing Planning</span>
                </button>
                <button class="nav-link text-left py-3.5 px-4 font-semibold flex items-center justify-between transition duration-200" id="drawer-timeline-tab" data-bs-toggle="pill" data-bs-target="#timeline-pane" type="button" role="tab" aria-controls="timeline-pane" aria-selected="false" onclick="closeDrawer()">
                    <span>7. Timeline &amp; Finalize</span>
                </button>
            </div>
        </div>
    </div>
    <!-- Backdrop (click to close) -->
    <div id="drawerBackdrop" onclick="closeDrawer()" style="display:none;position:fixed;top:0;left:0;width:100vw;height:100vh;z-index:1040;background:rgba(31,41,55,0.15);"></div>

    <!-- Interactive Logic Javascript -->
    <script>
        // ── Hover-activated Drawer Controls ──────────────────────────
        const drawer = document.getElementById('consultationDrawer');
        const edgeTrigger = document.getElementById('drawerEdgeTrigger');
        const backdrop = document.getElementById('drawerBackdrop');
        let drawerHoverTimeout = null;

        function openDrawer() {
            clearTimeout(drawerHoverTimeout);
            drawer.style.left = '0px';
            backdrop.style.display = 'block';
        }
        function closeDrawer() {
            drawer.style.left = '-300px';
            backdrop.style.display = 'none';
        }
        window.openDrawer = openDrawer;
        window.closeDrawer = closeDrawer;

        // Open on hover over edge strip
        edgeTrigger.addEventListener('mouseenter', openDrawer);

        // Close when mouse leaves both drawer and edge strip
        drawer.addEventListener('mouseleave', function(e) {
            if (!edgeTrigger.matches(':hover')) {
                drawerHoverTimeout = setTimeout(closeDrawer, 120);
            }
        });
        edgeTrigger.addEventListener('mouseleave', function() {
            if (!drawer.matches(':hover')) {
                drawerHoverTimeout = setTimeout(closeDrawer, 120);
            }
        });
        drawer.addEventListener('mouseenter', function() {
            clearTimeout(drawerHoverTimeout);
        });

        // Sections Menu button (click fallback)
        const menuBtn = document.getElementById('sectionsMenuBtn');
        if (menuBtn) {
            menuBtn.addEventListener('click', function() {
                if (drawer.style.left === '0px') { closeDrawer(); } else { openDrawer(); }
            });
        }

        // ── Follow-up Toggle ──────────────────────────────────────────
        window.toggleFollowup = function(checkbox) {
            const fields = document.getElementById('followupFields');
            if (checkbox.checked) {
                fields.classList.remove('hidden');
                document.getElementById('followup_date').setAttribute('required', 'required');
                document.getElementById('followup_time').setAttribute('required', 'required');
            } else {
                fields.classList.add('hidden');
                document.getElementById('followup_date').removeAttribute('required');
                document.getElementById('followup_time').removeAttribute('required');
            }
        };

        document.addEventListener('DOMContentLoaded', function() {
            
            // --- Live Session Timer (Module 9) & Auto-Extension ---
            let seconds = 0;
            let currentSlots = 1;
            let lastExtendedSlot = 1;
            let promptedSlots = {};
            const timerDisplay = document.getElementById('sessionTimer');
            
            function autoExtendAppointmentSlot(elapsedSeconds) {
                const apptIdInput = document.querySelector('input[name="appointment_id"]');
                const appointmentId = apptIdInput ? apptIdInput.value : null;
                if (!appointmentId) return;

                fetch('../actions/auto_extend_appointment.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        appointment_id: appointmentId,
                        session_duration: elapsedSeconds
                    })
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        console.log('Real-time slot extended successfully:', data);
                        const timerBadge = document.getElementById('sessionTimerBadge');
                        if (timerBadge) {
                            timerBadge.title = `Consultation Session Active Timer - ${data.slots_reserved} slot(s) reserved`;
                        }
                    } else {
                        console.error('Error auto-extending slot:', data.error);
                    }
                })
                .catch(err => {
                    console.error('Network error during auto-extend:', err);
                });
            }

            // Confirm Button for extension modal
            const confirmBtn = document.getElementById('confirmExtensionBtn');
            if (confirmBtn) {
                confirmBtn.addEventListener('click', function() {
                    const upcomingSlot = currentSlots + 1;
                    const elapsedSeconds = (upcomingSlot - 1) * 1800 + 1;
                    autoExtendAppointmentSlot(elapsedSeconds);
                    lastExtendedSlot = upcomingSlot;
                    promptedSlots[upcomingSlot] = true;
                    const modal = document.getElementById('extensionModal');
                    if (modal) modal.classList.add('hidden');
                });
            }

            // Cancel Button for extension modal
            const cancelBtn = document.getElementById('cancelExtensionBtn');
            if (cancelBtn) {
                cancelBtn.addEventListener('click', function() {
                    const upcomingSlot = currentSlots + 1;
                    promptedSlots[upcomingSlot] = true; // Mark prompted so we don't ask again
                    const modal = document.getElementById('extensionModal');
                    if (modal) modal.classList.add('hidden');
                });
            }

            setInterval(() => {
                seconds++;
                const mins = String(Math.floor(seconds / 60)).padStart(2, '0');
                const secs = String(seconds % 60).padStart(2, '0');
                timerDisplay.textContent = `${mins}:${secs}`;
                const durationInput = document.getElementById('session_duration');
                if (durationInput) {
                    durationInput.value = seconds;
                }

                // Check if current slot is about to end (e.g. 2 minutes / 120 seconds before 30 min boundary)
                const upcomingSlot = currentSlots + 1;
                const boundarySeconds = currentSlots * 1800;

                if (seconds >= (boundarySeconds - 120) && seconds < boundarySeconds) {
                    if (!promptedSlots[upcomingSlot] && lastExtendedSlot < upcomingSlot) {
                        const modal = document.getElementById('extensionModal');
                        if (modal) {
                            modal.classList.remove('hidden');
                        }
                    }
                }

                // If they overrun the boundary (seconds > boundarySeconds), update currentSlots
                if (seconds > boundarySeconds) {
                    currentSlots = Math.max(1, Math.ceil(seconds / 1800));
                }
            }, 1000);

            // --- Session Tolerance Checkbox Top Sync ---
            const topTolerated = document.getElementById('top_session_tolerated');
            const topBadge = document.getElementById('top_tolerance_badge');

            function syncToleranceFromCheckbox() {
                if (!topTolerated || !topBadge) return;
                if (topTolerated.checked) {
                    topBadge.innerText = "Yes";
                    topBadge.className = "text-xs font-semibold px-3 py-1 bg-green-50 text-green-700 border border-green-200 rounded-full";
                } else {
                    topBadge.innerText = "No";
                    topBadge.className = "text-xs font-semibold px-3 py-1 bg-red-50 text-red-700 border border-red-200 rounded-full";
                }
            }

            if (topTolerated) {
                topTolerated.addEventListener('change', syncToleranceFromCheckbox);
            }

            // Run initial sync
            syncToleranceFromCheckbox();

            // --- Auto-check Prep & Parameters Table on typing ---
            let lastAutoProc = null;
            function autoFillPrepAndParameters(text) {
                const clean = text.toLowerCase();
                let matchingProc = null;
                if (clean.includes("body lift")) {
                    matchingProc = "body_lift";
                } else if (clean.includes("laser")) {
                    matchingProc = "laser";
                } else if (clean.includes("botox")) {
                    matchingProc = "botox";
                }
                
                if (!matchingProc || matchingProc === lastAutoProc) return;
                lastAutoProc = matchingProc;
                
                // Check prep checklist boxes
                if (matchingProc === "body_lift") {
                    const markingBox = document.getElementById('prep_markings_shaving');
                    const consentBox = document.getElementById('prep_consent');
                    const idBox = document.getElementById('prep_id_verified');
                    const allergyBox = document.getElementById('prep_allergies_checked');
                    if (markingBox) markingBox.checked = true;
                    if (consentBox) consentBox.checked = true;
                    if (idBox) idBox.checked = true;
                    if (allergyBox) allergyBox.checked = true;
                    
                    // Clear parameters table first
                    const laserParamsTableBody = document.getElementById('laserParamsTableBody');
                    if (laserParamsTableBody) {
                        laserParamsTableBody.innerHTML = '';
                        if (typeof window.addLaserParamRow === 'function') {
                            window.addLaserParamRow("Abdomen", "None", "N/A", "N/A", "N/A", "Post-op body lift checks");
                            window.addLaserParamRow("Flanks", "None", "N/A", "N/A", "N/A", "Post-op body lift checks");
                        }
                    }
                } else if (matchingProc === "laser") {
                    const gogglesBox = document.getElementById('prep_goggles_provided');
                    const consentBox = document.getElementById('prep_consent');
                    const idBox = document.getElementById('prep_id_verified');
                    const allergyBox = document.getElementById('prep_allergies_checked');
                    if (gogglesBox) gogglesBox.checked = true;
                    if (consentBox) consentBox.checked = true;
                    if (idBox) idBox.checked = true;
                    if (allergyBox) allergyBox.checked = true;
                    
                    // Clear parameters table first
                    const laserParamsTableBody = document.getElementById('laserParamsTableBody');
                    if (laserParamsTableBody) {
                        laserParamsTableBody.innerHTML = '';
                        if (typeof window.addLaserParamRow === 'function') {
                            window.addLaserParamRow("Underarms", "alex 755nm", "10j/cm2", "18mm", "3ms", "Standard Laser");
                            window.addLaserParamRow("Full Arms", "alex 755nm", "12j/cm2", "18mm", "3ms", "Standard Laser");
                        }
                    }
                } else if (matchingProc === "botox") {
                    const consentBox = document.getElementById('prep_consent');
                    const idBox = document.getElementById('prep_id_verified');
                    const allergyBox = document.getElementById('prep_allergies_checked');
                    if (consentBox) consentBox.checked = true;
                    if (idBox) idBox.checked = true;
                    if (allergyBox) allergyBox.checked = true;
                    
                    // Clear parameters table first
                    const laserParamsTableBody = document.getElementById('laserParamsTableBody');
                    if (laserParamsTableBody) {
                        laserParamsTableBody.innerHTML = '';
                        if (typeof window.addLaserParamRow === 'function') {
                            window.addLaserParamRow("Face / Forehead", "Botox Injection", "N/A", "N/A", "N/A", "Wrinkles treatment");
                        }
                    }
                }
            }

            const narrativeInput = document.getElementById('narrative_diagnosis');
            if (narrativeInput) {
                narrativeInput.addEventListener('input', function() {
                    autoFillPrepAndParameters(this.value);
                });
            }
            const changesPerformedInput = document.getElementById('nrs_changes_performed');
            if (changesPerformedInput) {
                changesPerformedInput.addEventListener('input', function() {
                    autoFillPrepAndParameters(this.value);
                });
            }

            // --- Section 6 Clinical Treatment & Nursing Plan Lookups and Rules ---
            const procedureTemplates = [
              {
                "name": "Rhinoplasty / Liposuction / Facelift / Tummy Tuck",
                "category": "surgical",
                "pre_prep_checks": ["patient_identity_verified", "consent_signed", "allergies_reviewed", "explained_procedure", "patient_positioned", "emergency_kit_available", "fasting_confirmed", "iv_access_established", "monitoring_equipment_connected", "baseline_vitals_recorded", "required_lab_work_completed", "markings_and_shaving_done"],
                "advisory_checks": ["wound_site_care", "medication_schedule", "activity_limitations", "red_flag_symptoms", "follow_up_reminder", "not_to_self_medicate", "fall_prevention", "dietary_restrictions"]
              },
              {
                "name": "Botox / Dysport / Dermal Fillers / Sculptra",
                "category": "injectable",
                "pre_prep_checks": ["patient_identity_verified", "consent_signed", "allergies_reviewed", "explained_procedure", "patient_positioned", "emergency_kit_available"],
                "advisory_checks": ["not_to_self_medicate", "follow_up_reminder", "hydration_fluid_intake"]
              },
              {
                "name": "Fractional CO2 Laser / Softlight Laser Peel",
                "category": "laser",
                "pre_prep_checks": ["patient_identity_verified", "consent_signed", "allergies_reviewed", "explained_procedure", "patient_positioned", "emergency_kit_available", "protective_goggles_provided"],
                "advisory_checks": ["wound_site_care", "hydration_fluid_intake", "red_flag_symptoms", "follow_up_reminder"]
              },
              {
                "name": "IPL / Laser Hair Removal",
                "category": "laser_hair",
                "pre_prep_checks": ["patient_identity_verified", "consent_signed", "allergies_reviewed", "explained_procedure", "patient_positioned", "emergency_kit_available", "protective_goggles_provided", "markings_and_shaving_done"],
                "advisory_checks": ["hydration_fluid_intake", "follow_up_reminder"]
              },
              {
                "name": "Mesotherapy / Microneedling / Chemical Peels",
                "category": "skin_resurfacing",
                "pre_prep_checks": ["patient_identity_verified", "consent_signed", "allergies_reviewed", "explained_procedure", "patient_positioned", "emergency_kit_available"],
                "advisory_checks": ["wound_site_care", "hydration_fluid_intake", "follow_up_reminder"]
              },
              {
                "name": "PRP Treatment (Platelet-Rich Plasma)",
                "category": "prp",
                "pre_prep_checks": ["patient_identity_verified", "consent_signed", "allergies_reviewed", "explained_procedure", "patient_positioned", "emergency_kit_available", "required_lab_work_completed"],
                "advisory_checks": ["wound_site_care", "hydration_fluid_intake", "not_to_self_medicate", "follow_up_reminder"]
              }
            ];

            const prePrepMapping = {
              "patient_identity_verified": "prep_id_verified",
              "consent_signed": "prep_consent",
              "allergies_reviewed": "prep_allergies_checked",
              "explained_procedure": "prep_procedure_explained",
              "patient_positioned": "prep_positioning",
              "emergency_kit_available": "prep_emergency_kit",
              "fasting_confirmed": "prep_fasting",
              "iv_access_established": "prep_iv_access",
              "monitoring_equipment_connected": "prep_monitoring",
              "baseline_vitals_recorded": "prep_baseline_vitals",
              "required_lab_work_completed": "prep_labwork",
              "markings_and_shaving_done": "prep_markings_shaving",
              "protective_goggles_provided": "prep_goggles_provided"
            };

            const advisoryMapping = {
              "wound_site_care": "adv_wound_care",
              "medication_schedule": "adv_medication_schedule",
              "activity_limitations": "adv_activity_limits",
              "red_flag_symptoms": "adv_red_flags",
              "follow_up_reminder": "adv_followup_reminder",
              "not_to_self_medicate": "adv_no_self_medicate",
              "fall_prevention": "adv_fall_prevention",
              "dietary_restrictions": "adv_diet_restrictions",
              "hydration_fluid_intake": "adv_hydration",
              "emergency_contact_provided": "adv_emergency_contact"
            };

            window.applyProcedureRules = function(procedureName, isUserChange = false) {
                const procedure = procedureTemplates.find(p => p.name === procedureName);
                
                // Process Pre-Treatment Prep checks
                for (const [apiKey, elementId] of Object.entries(prePrepMapping)) {
                    const checkbox = document.getElementById(elementId);
                    if (!checkbox) continue;
                    const wrapper = checkbox.closest('.prep-chk-wrapper');
                    
                    if (procedure) {
                        const isIncluded = procedure.pre_prep_checks.includes(apiKey);
                        if (isIncluded) {
                            if (wrapper) wrapper.style.display = 'flex';
                            if (isUserChange) {
                                checkbox.checked = true;
                            }
                        } else {
                            if (wrapper) wrapper.style.display = 'none';
                            checkbox.checked = false; // State sanitization: wipe old value
                        }
                    } else {
                        // If no procedure is selected, show all as fallback but do not auto-check
                        if (wrapper) wrapper.style.display = 'flex';
                        if (isUserChange) {
                            checkbox.checked = false;
                        }
                    }
                }

                // Process Advisory checks
                for (const [apiKey, elementId] of Object.entries(advisoryMapping)) {
                    const checkbox = document.getElementById(elementId);
                    if (!checkbox) continue;
                    const wrapper = checkbox.closest('.adv-chk-wrapper');
                    
                    if (apiKey === 'emergency_contact_provided') {
                        // General fallback indicators are always visible and auto-checked
                        if (wrapper) wrapper.style.display = 'flex';
                        checkbox.checked = true;
                        continue;
                    }

                    if (procedure) {
                        const isIncluded = procedure.advisory_checks.includes(apiKey);
                        if (isIncluded) {
                            if (wrapper) wrapper.style.display = 'flex';
                            if (isUserChange) {
                                checkbox.checked = true;
                            }
                        } else {
                            if (wrapper) wrapper.style.display = 'none';
                            checkbox.checked = false; // State sanitization: wipe old value
                        }
                    } else {
                        // If no procedure is selected, show all as fallback
                        if (wrapper) wrapper.style.display = 'flex';
                        if (isUserChange) {
                            checkbox.checked = false;
                        }
                    }
                }
            };

            const procDropdown = document.getElementById('nrs_target_procedure');
            if (procDropdown) {
                procDropdown.addEventListener('change', function() {
                    window.applyProcedureRules(this.value, true);
                });
                // Initialize on page load (do not force auto-check to preserve draft state details)
                window.applyProcedureRules(procDropdown.value, false);
            }


            // Intercept form submit to validate required fields on Finalize
            const form = document.getElementById('consultationForm');
            if (form) {
                form.addEventListener('submit', function(e) {
                    const submitter = e.submitter;
                    const submitType = submitter ? submitter.value : 'draft';
                    if (submitType === 'finalize') {
                        if (!window.validateConsultationForm()) {
                            e.preventDefault();
                        }
                    }
                });
            }

            // Listen for tab switch to update current title in card header
            const drawerPills = document.querySelectorAll('#consultationDrawerTabs button[data-bs-toggle="pill"]');
            drawerPills.forEach(pill => {
                pill.addEventListener('shown.bs.tab', function (event) {
                    const targetText = event.target.querySelector('span').textContent;
                    document.getElementById('currentSectionTitle').textContent = targetText;
                });
            });

            // Prevent navigating away from a tab if its required fields are empty
            document.querySelectorAll('[data-bs-toggle="tab"], [data-bs-toggle="pill"]').forEach(tabButton => {
                tabButton.addEventListener('show.bs.tab', function(e) {
                    const previousTabButton = e.relatedTarget;
                    if (!previousTabButton) return;
                    
                    const targetSelector = previousTabButton.getAttribute('data-bs-target');
                    if (!targetSelector) return;
                    const previousPane = document.querySelector(targetSelector);
                    if (!previousPane) return;
                    
                    const inputs = previousPane.querySelectorAll('input[required], textarea[required], select[required]');
                    for (const input of inputs) {
                        if (!input.checkValidity()) {
                            e.preventDefault();
                            
                            let fieldName = '';
                            const label = document.querySelector(`label[for="${input.id}"]`);
                            if (label) {
                                fieldName = label.textContent.trim();
                            } else if (input.placeholder) {
                                fieldName = input.placeholder.trim();
                            } else if (input.name) {
                                fieldName = input.name;
                            } else {
                                fieldName = 'Required field';
                            }
                            fieldName = fieldName.replace(/[:*]/g, '').trim();
                            
                            alert(`"${fieldName}" is required before proceeding.`);
                            setTimeout(() => {
                                input.scrollIntoView({ behavior: 'smooth', block: 'center' });
                                input.focus();
                                input.reportValidity();
                            }, 50);
                            return;
                        }
                    }

                    // Special Check: Notes & Diagnosis pane must have at least one diagnosis before leaving it for a later section
                    const targetPaneSelector = tabButton.getAttribute('data-bs-target');
                    if (previousPane.id === 'notes-pane' && targetPaneSelector !== '#vitals-pane' && targetPaneSelector !== '#history-pane') {
                        const diagnosesContainer = document.getElementById('diagnosesContainer');
                        const count = diagnosesContainer ? diagnosesContainer.querySelectorAll('.diagnosis-row').length : 0;
                        if (count === 0) {
                            e.preventDefault();
                            alert('Please select at least one symptom or add a custom diagnosis before continuing.');
                            return;
                        }
                    }
                });
            });



            // GUI card mappings
            const guiMappings = [
                { id: "hair_loss", terms: ["hair loss", "alopecia", "shedding"] },
                { id: "excessive_hair", terms: ["excessive hair growth", "hirsutism"] },
                { id: "unwanted_hair", terms: ["unwanted hair"] },
                { id: "wrinkle", terms: ["wrinkle", "wrinkles", "rhytids", "aging skin"] },
                { id: "botox", terms: ["botox", "botulinum"] },
                { id: "filler", terms: ["filler", "fillers", "dermal filler"] },
                { id: "consultation", terms: ["consultation"] },
                { id: "derma_pen", terms: ["derma pen", "dermapen", "microneedling"] },
                { id: "skin_booster", terms: ["skin booster", "skinbooster"] },
                { id: "prp", terms: ["prp", "platelet rich plasma"] },
                { id: "mesotherapy", terms: ["mesotherapy"] },
                { id: "weight_loss", terms: ["weight loss", "obesity", "slimming"] },
                { id: "pigmentation", terms: ["pigmentation", "hyperpigmentation", "melasma", "dark spots"] }
            ];

            let apiDebounceTimer = null;

            function performApiLookup(query) {
                if (!query) return;
                fetch(`https://clinicaltables.nlm.nih.gov/api/icd10cm/v3/search?sf=code,name&terms=${encodeURIComponent(query)}`)
                    .then(res => res.json())
                    .then(data => {
                        if (data && data[3] && data[3].length > 0) {
                            const code = data[3][0][0];
                            const desc = data[3][0][1];
                            
                            // Check if this matches a GUI card's ICD code
                            const matchingCard = document.querySelector(`.symptom-card[data-icd="${code}"]`);
                            if (matchingCard) {
                                if (!matchingCard.classList.contains('active')) {
                                    matchingCard.click();
                                }
                            } else {
                                // Create custom diagnosis row
                                createCustomDiagnosisRow(code, desc);
                            }
                        }
                    })
                    .catch(err => console.error('ICD-10 API Error:', err));
            }

            function runLocalGuiMatching() {
                const narrativeInput = document.getElementById('narrative_diagnosis');
                if (!narrativeInput) return;
                const narrativeText = narrativeInput.value.trim();
                const cleanText = narrativeText.toLowerCase();

                let matchedId = null;
                for (let i = 0; i < guiMappings.length; i++) {
                    const mapping = guiMappings[i];
                    if (mapping.terms.some(term => cleanText.includes(term))) {
                        matchedId = mapping.id;
                        break; // single diagnosis restriction
                    }
                }

                window.isProgrammaticClick = true;
                if (matchedId) {
                    const card = document.querySelector(`.symptom-card[data-symptom-id="${matchedId}"]`);
                    if (card && !card.classList.contains('active')) {
                        card.click();
                    }
                } else {
                    const activeCard = document.querySelector('.symptom-card.active');
                    if (activeCard) {
                        activeCard.click(); // toggle off
                    }
                }
                window.isProgrammaticClick = false;
            }

            // Sync Narrative Diagnosis to Step 4 visual display
            const narrativeDisplay = document.getElementById('narrativeDiagnosisDisplay');

            function syncNarrative() {
                if (narrativeInput && narrativeDisplay) {
                    const val = narrativeInput.value.trim();
                    narrativeDisplay.textContent = val || 'No narrative diagnosis entered yet. Go back to Notes to write one.';
                }
            }

            if (narrativeInput) {
                narrativeInput.addEventListener('input', function() {
                    syncNarrative();
                    // Run local GUI matching instantly
                    runLocalGuiMatching();

                    // Run API lookup debounced
                    if (apiDebounceTimer) clearTimeout(apiDebounceTimer);
                    apiDebounceTimer = setTimeout(() => {
                        const val = narrativeInput.value.trim();
                        if (val.length > 2) {
                            performApiLookup(val);
                        }
                    }, 600);
                });
                syncNarrative();
            }

            const notesDiseaseTabBtn = document.getElementById('notes-disease-tab');
            if (notesDiseaseTabBtn) {
                notesDiseaseTabBtn.addEventListener('shown.bs.tab', function () {
                    syncNarrative();
                    runLocalGuiMatching();
                });
            }

            // --- Sub-Tab Helper ---
            window.goToSubTab = function(tabId) {
                const tabEl = document.getElementById(tabId);
                if (tabEl) {
                    let tab = bootstrap.Tab.getInstance(tabEl);
                    if (!tab) {
                        tab = new bootstrap.Tab(tabEl);
                    }
                    tab.show();
                }
            };

            // --- Multi-Tab Wizard Logic ---
            window.goToTab = function(nextTabName, currentTabId) {
                // Click corresponding Drawer Pill Button
                const tabButton = document.getElementById('drawer-' + nextTabName + '-tab');
                if (tabButton) {
                    tabButton.click();
                }
            };

            // Recursive tab activation helper for nested Bootstrap tabs
            window.activateTabForElement = function(element) {
                let pane = element.closest('.tab-pane');
                const path = [];
                while (pane) {
                    const paneId = pane.id;
                    const trigger = document.querySelector(`[data-bs-target="#${paneId}"]`) || 
                                    document.querySelector(`[data-bs-target="${paneId}"]`) || 
                                    document.getElementById(pane.getAttribute('aria-labelledby'));
                    if (trigger) {
                        path.unshift(trigger);
                    }
                    pane = trigger ? trigger.closest('.tab-pane') : null;
                }
                path.forEach(trigger => {
                    trigger.click();
                });
            };

            // Main validation function with automatic tab redirection and highlight/focus
            window.validateConsultationForm = function() {
                const form = document.getElementById('consultationForm');
                if (!form) return true;

                const inputs = form.querySelectorAll('input[required], textarea[required], select[required]');
                for (const input of inputs) {
                    if (!input.checkValidity()) {
                        let fieldName = '';
                        const label = form.querySelector(`label[for="${input.id}"]`);
                        if (label) {
                            fieldName = label.textContent.trim();
                        } else if (input.placeholder) {
                            fieldName = input.placeholder.trim();
                        } else if (input.name) {
                            fieldName = input.name;
                        } else {
                            fieldName = 'Required field';
                        }
                        
                        fieldName = fieldName.replace(/[:*]/g, '').trim();
                        alert(`"${fieldName}" is required to finalize the consultation. Redirecting to the field...`);
                        
                        window.activateTabForElement(input);
                        
                        setTimeout(() => {
                            input.scrollIntoView({ behavior: 'smooth', block: 'center' });
                            input.focus();
                            input.reportValidity();
                        }, 250);
                        
                        return false;
                    }
                }

                // Check that we have at least one diagnosis
                const diagnosesContainer = document.getElementById('diagnosesContainer');
                const count = diagnosesContainer ? diagnosesContainer.querySelectorAll('.diagnosis-row').length : 0;
                if (count === 0) {
                    alert('Please select at least one symptom or add a custom diagnosis before finalizing.');
                    const diseaseTabBtn = document.getElementById('notes-disease-tab');
                    if (diseaseTabBtn) {
                        window.activateTabForElement(diseaseTabBtn);
                        setTimeout(() => {
                            diseaseTabBtn.focus();
                        }, 250);
                    }
                    return false;
                }

                return true;
            };

            // Practitioner / Specialty Referral Finalize logic
            window.validateAndFinalizeReferral = function() {
                const form = document.getElementById('consultationForm');
                if (!form) return;

                // 1. Validate Specialist selection first
                const refDocSelect = document.getElementById('diag_referral_doctor_id');
                const refDoc = refDocSelect.value;
                if (!refDoc) {
                    alert('Please select a referral practitioner / specialty.');
                    refDocSelect.focus();
                    return;
                }

                // 2. Validate Referral Date & Time
                const refDateInput = document.getElementById('diag_referral_date');
                const refTimeInput = document.getElementById('diag_referral_time');
                if (!refDateInput.value) {
                    alert('Please select a follow-up date.');
                    refDateInput.focus();
                    return;
                }
                if (!refTimeInput.value) {
                    alert('Please select a follow-up time.');
                    refTimeInput.focus();
                    return;
                }

                // Run the main consultation form validation (validates required fields + diagnosis list count)
                if (!window.validateConsultationForm()) {
                    return;
                }

                // 5. Populate Step 7 hidden follow-up fields
                const followupCheck = document.getElementById('followup_enabled');
                if (followupCheck) {
                    followupCheck.checked = true;
                    // Trigger the UI change handler
                    window.toggleFollowup(followupCheck);
                }

                const destDoc = document.getElementById('followup_doctor_id');
                const destDate = document.getElementById('followup_date');
                const destTime = document.getElementById('followup_time');

                if (destDoc) destDoc.value = refDoc;
                if (destDate) {
                    destDate.value = refDateInput.value;
                    if (destDate.value) destDate.type = 'date';
                    else destDate.type = 'text';
                }
                if (destTime) {
                    destTime.value = refTimeInput.value;
                    if (destTime.value) destTime.type = 'time';
                    else destTime.type = 'text';
                }

                // 6. Set submit type to finalize and submit form
                let submitTypeInput = document.getElementById('submit_type_hidden');
                if (!submitTypeInput) {
                    submitTypeInput = document.createElement('input');
                    submitTypeInput.type = 'hidden';
                    submitTypeInput.id = 'submit_type_hidden';
                    submitTypeInput.name = 'submit_type';
                    form.appendChild(submitTypeInput);
                }
                submitTypeInput.value = 'finalize';
                
                // Submit the form
                form.submit();
            };
            
            // --- Redesigned Vitals GUI Synchronizers & Evaluators ---
            const painSlider = document.getElementById('pain_scale');

            // Blood Pressure
            function updateBloodPressure() {
                const sysInput = document.getElementById('bp_systolic');
                const diaInput = document.getElementById('bp_diastolic');
                if (!sysInput || !diaInput) return;
                
                const sys = parseInt(sysInput.value);
                const dia = parseInt(diaInput.value);
                
                document.getElementById('bp_sys_display').textContent = sys + ' mmHg';
                document.getElementById('bp_dia_display').textContent = dia + ' mmHg';
                document.getElementById('bp_val_display').textContent = sys + '/' + dia;
                document.getElementById('blood_pressure').value = sys + '/' + dia;
                
                let status = 'Normal';
                let badgeClass = 'bg-emerald-100 text-emerald-800';
                if (sys < 90 || dia < 60) {
                    status = 'Hypotension';
                    badgeClass = 'bg-blue-100 text-blue-800';
                } else if (sys >= 180 || dia >= 120) {
                    status = 'Hypertensive Crisis';
                    badgeClass = 'bg-red-200 text-red-900 border border-red-500 animate-pulse';
                } else if (sys >= 140 || dia >= 90) {
                    status = 'Hypertension Stage 2';
                    badgeClass = 'bg-red-100 text-red-800';
                } else if ((sys >= 130 && sys <= 139) || (dia >= 80 && dia <= 89)) {
                    status = 'Hypertension Stage 1';
                    badgeClass = 'bg-orange-100 text-orange-800';
                } else if (sys >= 120 && sys <= 129 && dia < 80) {
                    status = 'Elevated';
                    badgeClass = 'bg-yellow-100 text-yellow-800';
                }
                
                const badge = document.getElementById('bp_status_badge');
                if (badge) {
                    badge.className = 'px-2 py-0.5 rounded text-[10px] font-bold ' + badgeClass;
                    badge.textContent = status;
                }
            }

            // Temperature
            function updateTemperature() {
                const tempInputEl = document.getElementById('temperature');
                if (!tempInputEl) return;
                const temp = parseFloat(tempInputEl.value);
                document.getElementById('temp_val_display').textContent = temp.toFixed(1);
                
                let status = 'Normal';
                let badgeClass = 'bg-emerald-100 text-emerald-800';
                if (temp < 35.5) {
                    status = 'Hypothermia';
                    badgeClass = 'bg-blue-100 text-blue-800';
                } else if (temp > 38.5) {
                    status = 'High Fever';
                    badgeClass = 'bg-red-100 text-red-800 animate-pulse';
                } else if (temp > 37.5) {
                    status = 'Low Fever';
                    badgeClass = 'bg-orange-100 text-orange-800';
                }
                
                const badge = document.getElementById('temp_status_badge');
                if (badge) {
                    badge.className = 'px-2 py-0.5 rounded text-[10px] font-bold ' + badgeClass;
                    badge.textContent = status;
                }
            }

            // Heart Rate
            function updateHeartRate() {
                const hrInput = document.getElementById('heart_rate');
                if (!hrInput) return;
                const hr = parseInt(hrInput.value);
                document.getElementById('hr_val_display').textContent = hr;
                
                let status = 'Normal';
                let badgeClass = 'bg-emerald-100 text-emerald-800';
                if (hr < 60) {
                    status = 'Bradycardia';
                    badgeClass = 'bg-orange-100 text-orange-800';
                } else if (hr > 100) {
                    status = 'Tachycardia';
                    badgeClass = 'bg-red-100 text-red-800';
                }
                
                const badge = document.getElementById('hr_status_badge');
                if (badge) {
                    badge.className = 'px-2 py-0.5 rounded text-[10px] font-bold ' + badgeClass;
                    badge.textContent = status;
                }
                
                const heartIcon = document.getElementById('beatingHeartIcon');
                if (heartIcon) {
                    const duration = 60 / hr;
                    heartIcon.style.animationDuration = duration + 's';
                }
            }

            // Weight
            function updateWeight() {
                const wInput = document.getElementById('weight');
                if (!wInput) return;
                const w = parseFloat(wInput.value);
                document.getElementById('weight_val_display').textContent = w.toFixed(1);
                updateBMI();
            }

            // Height
            function updateHeight() {
                const hInput = document.getElementById('height');
                if (!hInput) return;
                const h = parseFloat(hInput.value);
                document.getElementById('height_val_display').textContent = h.toFixed(1);
                updateBMI();
            }

            // Respiratory Rate
            function updateRespiratoryRate() {
                const respInput = document.getElementById('respiratory_rate');
                if (!respInput) return;
                const resp = parseInt(respInput.value);
                document.getElementById('respiratory_rate_val_display').textContent = resp;
                
                let status = 'Normal';
                let badgeClass = 'bg-emerald-100 text-emerald-800';
                if (resp < 12) {
                    status = 'Bradypnea';
                    badgeClass = 'bg-orange-100 text-orange-800';
                } else if (resp > 20) {
                    status = 'Tachypnea';
                    badgeClass = 'bg-red-100 text-red-800';
                }
                
                const badge = document.getElementById('resp_status_badge');
                if (badge) {
                    badge.className = 'px-2 py-0.5 rounded text-[10px] font-bold ' + badgeClass;
                    badge.textContent = status;
                }
            }

            // BMI Calculation
            function updateBMI() {
                const wInput = document.getElementById('weight');
                const hInput = document.getElementById('height');
                if (!wInput || !hInput) return;
                const w = parseFloat(wInput.value);
                const h = parseFloat(hInput.value);
                
                if (w > 0 && h > 0) {
                    const bmi = w / ((h / 100) ** 2);
                    document.getElementById('bmi_val_display').textContent = bmi.toFixed(1);
                    
                    let status = 'Normal';
                    let badgeClass = 'bg-emerald-100 text-emerald-800';
                    if (bmi < 18.5) {
                        status = 'Underweight';
                        badgeClass = 'bg-blue-100 text-blue-800';
                    } else if (bmi >= 30.0) {
                        status = 'Obese';
                        badgeClass = 'bg-red-100 text-red-800 animate-pulse';
                    } else if (bmi >= 25.0) {
                        status = 'Overweight';
                        badgeClass = 'bg-orange-100 text-orange-800';
                    }
                    
                    const badge = document.getElementById('bmi_status_badge');
                    if (badge) {
                        badge.className = 'px-2 py-0.5 rounded text-[10px] font-bold ' + badgeClass;
                        badge.textContent = status;
                    }
                } else {
                    document.getElementById('bmi_val_display').textContent = '--';
                }
            }

            // Oxygen Saturation
            function updateOxygenSaturation() {
                const o2Input = document.getElementById('oxygen_saturation');
                if (!o2Input) return;
                const o2 = parseInt(o2Input.value);
                document.getElementById('spo2_val_display').textContent = o2;
                
                let status = 'Normal';
                let badgeClass = 'bg-emerald-100 text-emerald-800';
                if (o2 < 90) {
                    status = 'Severe Hypoxia';
                    badgeClass = 'bg-red-100 text-red-800 animate-pulse';
                } else if (o2 <= 94) {
                    status = 'Mild Hypoxia';
                    badgeClass = 'bg-orange-100 text-orange-800';
                }
                
                const badge = document.getElementById('spo2_status_badge');
                if (badge) {
                    badge.className = 'px-2 py-0.5 rounded text-[10px] font-bold ' + badgeClass;
                    badge.textContent = status;
                }
            }

            // Pain Scale
            function updatePainScale(val) {
                document.getElementById('pain_scale').value = val;
                document.getElementById('pain_val_display').textContent = val;
                
                document.querySelectorAll('.pain-circle').forEach(circle => {
                    if (parseInt(circle.getAttribute('data-val')) === parseInt(val)) {
                        circle.classList.add('selected');
                    } else {
                        circle.classList.remove('selected');
                    }
                });
                
                let status = 'No Pain';
                let badgeClass = 'bg-slate-100 text-slate-800';
                if (val >= 9) {
                    status = 'Unbearable';
                    badgeClass = 'bg-red-200 text-red-900 border border-red-400 animate-pulse';
                } else if (val >= 7) {
                    status = 'Severe Pain';
                    badgeClass = 'bg-red-100 text-red-800';
                } else if (val >= 5) {
                    status = 'Moderate Pain';
                    badgeClass = 'bg-orange-100 text-orange-800';
                } else if (val >= 3) {
                    status = 'Uncomfortable';
                    badgeClass = 'bg-yellow-100 text-yellow-800';
                } else if (val >= 1) {
                    status = 'Mild Pain';
                    badgeClass = 'bg-emerald-100 text-emerald-800';
                }
                
                const badge = document.getElementById('pain_status_badge');
                if (badge) {
                    badge.className = 'px-2 py-0.5 rounded text-[10px] font-bold ' + badgeClass;
                    badge.textContent = status;
                }
            }

            // Bind listeners for range inputs
            document.getElementById('bp_systolic').addEventListener('input', () => { updateBloodPressure(); checkVitals(); });
            document.getElementById('bp_diastolic').addEventListener('input', () => { updateBloodPressure(); checkVitals(); });
            document.getElementById('temperature').addEventListener('input', () => { updateTemperature(); checkVitals(); });
            document.getElementById('heart_rate').addEventListener('input', () => { updateHeartRate(); checkVitals(); });
            document.getElementById('weight').addEventListener('input', () => { updateWeight(); checkVitals(); });
            document.getElementById('oxygen_saturation').addEventListener('input', () => { updateOxygenSaturation(); checkVitals(); });
            document.getElementById('height').addEventListener('input', () => { updateHeight(); checkVitals(); });
            document.getElementById('respiratory_rate').addEventListener('input', () => { updateRespiratoryRate(); checkVitals(); });

            // Bind click listeners for pain scale circles
            document.querySelectorAll('.pain-circle').forEach(circle => {
                circle.addEventListener('click', function() {
                    const val = this.getAttribute('data-val');
                    updatePainScale(val);
                    checkVitals();
                });
            });

            // Bind click listeners for presets
            document.querySelectorAll('.bp-preset').forEach(btn => {
                btn.addEventListener('click', () => {
                    const parts = btn.getAttribute('data-val').split('/');
                    document.getElementById('bp_systolic').value = parts[0];
                    document.getElementById('bp_diastolic').value = parts[1];
                    updateBloodPressure();
                    checkVitals();
                });
            });
            document.querySelectorAll('.temp-preset').forEach(btn => {
                btn.addEventListener('click', () => {
                    document.getElementById('temperature').value = btn.getAttribute('data-val');
                    updateTemperature();
                    checkVitals();
                });
            });
            document.querySelectorAll('.hr-preset').forEach(btn => {
                btn.addEventListener('click', () => {
                    document.getElementById('heart_rate').value = btn.getAttribute('data-val');
                    updateHeartRate();
                    checkVitals();
                });
            });
            document.querySelectorAll('.weight-preset').forEach(btn => {
                btn.addEventListener('click', () => {
                    document.getElementById('weight').value = btn.getAttribute('data-val');
                    updateWeight();
                    checkVitals();
                });
            });
            document.querySelectorAll('.height-preset').forEach(btn => {
                btn.addEventListener('click', () => {
                    document.getElementById('height').value = btn.getAttribute('data-val');
                    updateHeight();
                    checkVitals();
                });
            });
            document.querySelectorAll('.spo2-preset').forEach(btn => {
                btn.addEventListener('click', () => {
                    document.getElementById('oxygen_saturation').value = btn.getAttribute('data-val');
                    updateOxygenSaturation();
                    checkVitals();
                });
            });
            document.querySelectorAll('.resp-preset').forEach(btn => {
                btn.addEventListener('click', () => {
                    document.getElementById('respiratory_rate').value = btn.getAttribute('data-val');
                    updateRespiratoryRate();
                    checkVitals();
                });
            });

            // Bind increment/decrement buttons
            document.querySelectorAll('.increment-btn, .decrement-btn').forEach(btn => {
                btn.addEventListener('click', () => {
                    const targetId = btn.getAttribute('data-target');
                    const step = parseFloat(btn.getAttribute('data-step') || 1);
                    const input = document.getElementById(targetId);
                    if (!input) return;
                    
                    const isIncrement = btn.classList.contains('increment-btn');
                    let currentVal = parseFloat(input.value) || 0;
                    let newVal = isIncrement ? (currentVal + step) : (currentVal - step);
                    
                    // Clamp values based on min/max attributes
                    const minVal = parseFloat(input.min);
                    const maxVal = parseFloat(input.max);
                    if (!isNaN(minVal) && newVal < minVal) newVal = minVal;
                    if (!isNaN(maxVal) && newVal > maxVal) newVal = maxVal;
                    
                    input.value = newVal;
                    
                    if (targetId === 'bp_systolic' || targetId === 'bp_diastolic') {
                        updateBloodPressure();
                    } else if (targetId === 'temperature') {
                        updateTemperature();
                    } else if (targetId === 'heart_rate') {
                        updateHeartRate();
                    } else if (targetId === 'weight') {
                        updateWeight();
                    } else if (targetId === 'oxygen_saturation') {
                        updateOxygenSaturation();
                    } else if (targetId === 'height') {
                        updateHeight();
                    } else if (targetId === 'respiratory_rate') {
                        updateRespiratoryRate();
                    }
                    checkVitals();
                });
            });



            // Initialize all displays
            updateBloodPressure();
            updateTemperature();
            updateHeartRate();
            updateWeight();
            updateOxygenSaturation();
            updateHeight();
            updateRespiratoryRate();
            updateBMI();
            updatePainScale(document.getElementById('pain_scale').value);


            // --- GUI Symptom Cards Selector & ICD Mapping ---
            const diagnosesContainer = document.getElementById('diagnosesContainer');
            const symptomCards = document.querySelectorAll('.symptom-card');

            // Map symptom ID to its corresponding diagnosis row element (if added)
            const activeSymptomRows = {};

            // Function to generate pre-filled diagnosis rows
            function createPreFilledDiagnosisRow(symptomId, icd, desc) {
                // Clear any other active rows for single diagnosis restriction
                diagnosesContainer.innerHTML = '';
                document.querySelectorAll('.symptom-card.active').forEach(c => {
                    c.classList.remove('active');
                });
                for (let k in activeSymptomRows) {
                    delete activeSymptomRows[k];
                }

                const newRow = document.createElement('div');
                newRow.className = 'grid grid-cols-1 md:grid-cols-12 gap-3 mb-3 items-end diagnosis-row border border-hms-border p-3.5 rounded-xl bg-gray-50';
                newRow.setAttribute('data-symptom-ref', symptomId);
                newRow.innerHTML = `
                    <div class="md:col-span-3">
                        <label class="block text-xxs font-bold text-hms-mid mb-1">ICD-10 Code</label>
                        <input type="text" class="w-full border border-hms-border rounded-lg p-2.5 text-xs outline-none bg-white font-mono text-hms-accent font-semibold" name="icd_code[]" value="${icd}" readonly required>
                    </div>
                    <div class="md:col-span-8">
                        <label class="block text-xxs font-bold text-hms-mid mb-1">Description / Diagnostic Details</label>
                        <input type="text" class="w-full border border-hms-border rounded-lg p-2.5 text-xs outline-none bg-white font-medium text-hms-dark" name="icd_description[]" value="${desc}" readonly required>
                    </div>
                    <div class="md:col-span-1">
                        <button type="button" class="w-full border border-red-200 text-red-500 hover:bg-red-500 hover:text-white rounded-lg py-2.5 text-xs font-semibold remove-diagnosis-btn transition duration-150" data-symptom-trigger="${symptomId}" title="Remove Diagnosis">×</button>
                    </div>
                `;
                diagnosesContainer.appendChild(newRow);
                activeSymptomRows[symptomId] = newRow;
            }

            symptomCards.forEach(card => {
                card.addEventListener('click', function() {
                    const symptomId = card.getAttribute('data-symptom-id');
                    const icd = card.getAttribute('data-icd');
                    const desc = card.getAttribute('data-desc');
                    const isProgrammatic = window.isProgrammaticClick || false;
                    const narrativeInput = document.getElementById('narrative_diagnosis');

                    if (card.classList.contains('active')) {
                        // Deselect
                        card.classList.remove('active');
                        if (activeSymptomRows[symptomId]) {
                            activeSymptomRows[symptomId].remove();
                            delete activeSymptomRows[symptomId];
                        }

                        // Remove auto-prescribed medications
                        if (symptomId === 'hair_loss' || symptomId === 'botox') {
                            document.querySelectorAll(`.prescription-row[data-symptom-ref="${symptomId}"]`).forEach(row => row.remove());
                        }

                        // Clear narrative diagnosis preset if matches
                        if (!isProgrammatic && narrativeInput) {
                            const val = narrativeInput.value.trim();
                            if (symptomId === 'botox' && val.includes("facial wrinkles/dynamic lines")) {
                                narrativeInput.value = '';
                                if (typeof syncNarrative === 'function') syncNarrative();
                            } else if (symptomId === 'hair_loss' && val.includes("alopecia/hair loss")) {
                                narrativeInput.value = '';
                                if (typeof syncNarrative === 'function') syncNarrative();
                            }
                        }
                    } else {
                        // Select
                        card.classList.add('active');
                        card.classList.remove('alert-pulse');
                        createPreFilledDiagnosisRow(symptomId, icd, desc);

                        // Set narrative diagnosis preset if matches and not programmatic
                        if (!isProgrammatic && narrativeInput) {
                            if (symptomId === 'botox') {
                                narrativeInput.value = "Patient presented for aesthetic enhancement. Diagnosed with facial wrinkles/dynamic lines. Scheduled for Botox injection treatment.";
                            } else if (symptomId === 'hair_loss') {
                                narrativeInput.value = "Patient presented with complaints of hair thinning and hair fall. Diagnosed with alopecia/hair loss. Recommended hair loss treatment regimen.";
                            }
                            if (typeof syncNarrative === 'function') syncNarrative();
                        }

                        // Append auto-prescriptions
                        if (symptomId === 'botox') {
                            addPrescriptionRow("Botulinum Toxin Type A (Botox) 100 units", "As directed for wrinkles", "1 session", "Post-injection care: Do not massage areas. Keep upright for 4 hours.", "botox");
                        } else if (symptomId === 'hair_loss') {
                            addPrescriptionRow("Minoxidil 5% topical solution", "Apply 1ml twice daily to dry scalp", "90 days", "Massage gently. Wash hands after application.", "hair_loss");
                            addPrescriptionRow("Biotin 5mg tablets", "1 tablet daily", "90 days", "Take with water.", "hair_loss");
                        }
                    }
                });
            });


            // --- Live Vitals, Pain and Allergy Monitor ---
            const tempInput = document.getElementById('temperature');
            const allergyInput = document.getElementById('allergy_notes');
            
            const allergyBanner = document.getElementById('allergyWarningBanner');
            const allergyText = document.getElementById('allergyWarningText');
            const feverBanner = document.getElementById('feverSuggestionBanner');
            const painBanner = document.getElementById('painSuggestionBanner');
            const feverCard = document.querySelector('.symptom-card[data-symptom-id="fever"]');

            function checkVitals() {
                // 1. Temperature Check
                const temp = parseFloat(tempInput.value);
                if (!isNaN(temp) && temp > 37.5) {
                    feverBanner.classList.remove('hidden');
                    if (feverCard && !feverCard.classList.contains('active')) {
                        feverCard.classList.add('alert-pulse');
                    }
                } else {
                    feverBanner.classList.add('hidden');
                    if (feverCard) {
                        feverCard.classList.remove('alert-pulse');
                    }
                }

                // 2. Pain Scale Check
                const pain = parseInt(painSlider.value);
                if (!isNaN(pain) && pain >= 6) {
                    painBanner.classList.remove('hidden');
                } else {
                    painBanner.classList.add('hidden');
                }

                // 3. Allergy Check
                const allergyNotes = allergyInput.value.trim();
                if (allergyNotes.length > 0) {
                    allergyText.textContent = allergyNotes;
                    allergyBanner.classList.remove('hidden');
                } else {
                    allergyBanner.classList.add('hidden');
                }
            }

            // Bind listeners
            tempInput.addEventListener('input', checkVitals);
            document.getElementById('bp_systolic').addEventListener('input', checkVitals);
            document.getElementById('bp_diastolic').addEventListener('input', checkVitals);
            document.getElementById('pain_scale').addEventListener('input', checkVitals);
            allergyInput.addEventListener('input', checkVitals);

            // Run initial checks
            checkVitals();


            // --- Dynamic Manual Diagnosis Rows ---
            const addDiagnosisBtn = document.getElementById('addDiagnosisBtn');

            function createCustomDiagnosisRow(codeVal = '', descVal = '') {
                // Clear any other active rows for single diagnosis restriction
                diagnosesContainer.innerHTML = '';
                document.querySelectorAll('.symptom-card.active').forEach(c => {
                    c.classList.remove('active');
                });
                for (let k in activeSymptomRows) {
                    delete activeSymptomRows[k];
                }

                const newRow = document.createElement('div');
                newRow.className = 'grid grid-cols-1 md:grid-cols-12 gap-3 mb-3 items-end diagnosis-row border border-hms-border p-3.5 rounded-xl bg-gray-50';
                newRow.innerHTML = `
                    <div class="md:col-span-3">
                        <label class="block text-xxs font-bold text-hms-mid mb-1">ICD-10 Code</label>
                        <input type="text" class="w-full border border-hms-border rounded-lg p-2.5 text-xs outline-none bg-white font-mono text-hms-accent font-semibold" name="icd_code[]" placeholder="e.g., I10" value="${codeVal}" required>
                    </div>
                    <div class="md:col-span-8">
                        <label class="block text-xxs font-bold text-hms-mid mb-1">Description / Diagnostic Details</label>
                        <input type="text" class="w-full border border-hms-border rounded-lg p-2.5 text-xs outline-none bg-white font-medium text-hms-dark" name="icd_description[]" placeholder="e.g., Essential (primary) hypertension" value="${descVal}" required>
                    </div>
                    <div class="md:col-span-1">
                        <button type="button" class="w-full border border-red-200 text-red-500 hover:bg-red-500 hover:text-white rounded-lg py-2.5 text-xs font-semibold remove-diagnosis-btn transition duration-150" title="Delete Diagnosis">×</button>
                    </div>
                `;
                diagnosesContainer.appendChild(newRow);

                // Highlight/activate matching GUI symptom card if exists
                const matchingCard = document.querySelector(`.symptom-card[data-icd="${codeVal}"]`);
                if (matchingCard) {
                    if (!matchingCard.classList.contains('active')) {
                        matchingCard.classList.add('active');
                        matchingCard.classList.remove('alert-pulse');
                    }
                    newRow.setAttribute('data-symptom-ref', matchingCard.getAttribute('data-symptom-id'));
                    activeSymptomRows[matchingCard.getAttribute('data-symptom-id')] = newRow;
                }
            }

            addDiagnosisBtn.addEventListener('click', function() {
                createCustomDiagnosisRow();
            });


            // --- Structured Nursing Medication Rows ---
            const nursingMedContainer = document.getElementById('nursingMedContainer');
            const addNursingMedBtn = document.getElementById('addNursingMedBtn');

            window.createNursingMedRow = function(name = '', dose = '', route = '', time = '', notes = '') {
                const row = document.createElement('div');
                row.className = 'nursing-med-row border border-hms-border p-3.5 rounded-xl bg-white mb-3';
                row.innerHTML = `
                    <div class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end mb-2">
                        <div class="md:col-span-3">
                            <label class="block text-xxs font-bold text-hms-mid mb-1">Medicine Name</label>
                            <input type="text" class="w-full border border-hms-border rounded-lg p-2.5 text-xs outline-none focus:border-hms-accent nrs-med-name" name="nrs_med_name[]" placeholder="e.g., Metronidazole 500mg" value="${name}">
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-xxs font-bold text-hms-mid mb-1">Dose</label>
                            <input type="text" class="w-full border border-hms-border rounded-lg p-2.5 text-xs outline-none focus:border-hms-accent nrs-med-dose" name="nrs_med_dose[]" placeholder="e.g., 500mg" value="${dose}">
                        </div>
                        <div class="md:col-span-3">
                            <label class="block text-xxs font-bold text-hms-mid mb-1">Route</label>
                            <select class="w-full border border-hms-border rounded-lg p-2.5 text-xs outline-none focus:border-hms-accent nrs-med-route bg-white" name="nrs_med_route[]">
                                <option value="Oral" ${route==='Oral'?'selected':''}>Oral (PO)</option>
                                <option value="Intravenous" ${route==='Intravenous'?'selected':''}>Intravenous (IV)</option>
                                <option value="Intramuscular" ${route==='Intramuscular'?'selected':''}>Intramuscular (IM)</option>
                                <option value="Subcutaneous" ${route==='Subcutaneous'?'selected':''}>Subcutaneous (SC)</option>
                                <option value="Topical" ${route==='Topical'?'selected':''}>Topical</option>
                                <option value="Inhalation" ${route==='Inhalation'?'selected':''}>Inhalation</option>
                                <option value="Rectal" ${route==='Rectal'?'selected':''}>Rectal (PR)</option>
                                <option value="Other" ${route==='Other'?'selected':''}>Other</option>
                            </select>
                        </div>
                        <div class="md:col-span-3">
                            <label class="block text-xxs font-bold text-hms-mid mb-1">Time Administered</label>
                            <input type="time" class="w-full border border-hms-border rounded-lg p-2.5 text-xs outline-none focus:border-hms-accent nrs-med-time" name="nrs_med_time[]" value="${time}">
                        </div>
                        <div class="md:col-span-1">
                            <button type="button" class="w-full border border-red-200 text-red-500 hover:bg-red-500 hover:text-white rounded-lg py-2.5 text-xs font-semibold remove-nrs-med-btn transition duration-150">&times;</button>
                        </div>
                    </div>
                    <div>
                        <label class="block text-xxs font-bold text-hms-muted mb-1">Nursing Notes for this Medication</label>
                        <textarea class="w-full border border-hms-border rounded-lg p-2 text-xs outline-none focus:border-hms-accent nrs-med-notes" name="nrs_med_notes[]" rows="1" placeholder="e.g., Infused over 30 minutes, no adverse reactions observed...">${notes}</textarea>
                    </div>
                `;
                row.querySelector('.remove-nrs-med-btn').onclick = () => row.remove();
                nursingMedContainer.appendChild(row);
            }

            if (addNursingMedBtn) {
                addNursingMedBtn.onclick = () => window.createNursingMedRow();
            }

            // --- Repeatable Laser Parameter Rows ---
            const laserParamsTableBody = document.getElementById('laserParamsTableBody');
            const addLaserParamRowBtn = document.getElementById('addLaserParamRowBtn');

            window.addLaserParamRow = function(area = '', laser = '', fluence = '', spot = '', pulse = '', notes = '') {
                const tr = document.createElement('tr');
                tr.className = 'border-b border-hms-border laser-param-row';
                tr.innerHTML = `
                    <td class="py-2 px-2">
                        <input type="text" class="w-full border border-hms-border rounded-lg p-1.5 text-xs outline-none focus:border-hms-accent nrs-param-area" name="nrs_param_area[]" placeholder="e.g. Underarms" value="${area}" required>
                    </td>
                    <td class="py-2 px-2">
                        <input type="text" class="w-full border border-hms-border rounded-lg p-1.5 text-xs outline-none focus:border-hms-accent nrs-param-laser" name="nrs_param_laser[]" placeholder="alex 755nm" value="${laser}">
                    </td>
                    <td class="py-2 px-2">
                        <input type="text" class="w-full border border-hms-border rounded-lg p-1.5 text-xs outline-none focus:border-hms-accent nrs-param-fluence" name="nrs_param_fluence[]" placeholder="12j/cm2" value="${fluence}">
                    </td>
                    <td class="py-2 px-2">
                        <input type="text" class="w-full border border-hms-border rounded-lg p-1.5 text-xs outline-none focus:border-hms-accent nrs-param-spot" name="nrs_param_spot[]" placeholder="18mm" value="${spot}">
                    </td>
                    <td class="py-2 px-2">
                        <input type="text" class="w-full border border-hms-border rounded-lg p-1.5 text-xs outline-none focus:border-hms-accent nrs-param-pulse" name="nrs_param_pulse[]" placeholder="3ms" value="${pulse}">
                    </td>
                    <td class="py-2 px-2">
                        <input type="text" class="w-full border border-hms-border rounded-lg p-1.5 text-xs outline-none focus:border-hms-accent nrs-param-notes" name="nrs_param_notes[]" placeholder="Status or notes" value="${notes}">
                    </td>
                    <td class="py-2 px-2 text-center">
                        <button type="button" class="text-red-500 hover:text-red-700 text-lg font-bold remove-param-row-btn">&times;</button>
                    </td>
                `;
                tr.querySelector('.remove-param-row-btn').onclick = () => tr.remove();
                laserParamsTableBody.appendChild(tr);
            };

            if (addLaserParamRowBtn) {
                addLaserParamRowBtn.onclick = () => window.addLaserParamRow();
            }

            // Quick add pills click handlers
            document.querySelectorAll('.quick-add-param-pill').forEach(pill => {
                pill.onclick = function() {
                    const area = this.getAttribute('data-area') || '';
                    const laser = this.getAttribute('data-laser') || '';
                    const fluence = this.getAttribute('data-fluence') || '';
                    const spot = this.getAttribute('data-spot') || '';
                    const pulse = this.getAttribute('data-pulse') || '';
                    const notes = this.getAttribute('data-notes') || '';
                    window.addLaserParamRow(area, laser, fluence, spot, pulse, notes);
                };
            });

            // Skin Assessment presets
            document.querySelectorAll('.prep-skin-preset').forEach(btn => {
                btn.onclick = function() {
                    const val = this.getAttribute('data-val') || '';
                    const input = document.getElementById('nrs_prep_skin_type');
                    if (input) {
                        input.value = val;
                    }
                };
            });

            // Skin Prep Notes presets
            document.querySelectorAll('.prep-note-preset').forEach(btn => {
                btn.onclick = function() {
                    const val = this.getAttribute('data-val') || '';
                    const input = document.getElementById('nrs_prep_notes');
                    if (input) {
                        input.value = val;
                    }
                };
            });

            // Pre-Treatment checklist helper [Select Standard Prep]
            const btnSelectStandardPrep = document.getElementById('btnSelectStandardPrep');
            if (btnSelectStandardPrep) {
                btnSelectStandardPrep.onclick = function() {
                    const chks = [
                        'prep_assessment_done',
                        'prep_procedure_explained',
                        'prep_consent',
                        'prep_goggles_provided',
                        'prep_markings_shaving'
                    ];
                    chks.forEach(id => {
                        const el = document.getElementById(id);
                        if (el) el.checked = true;
                    });
                };
            }

            // Post-Procedure checklist helper [Select Standard Observations]
            const btnSelectStandardObs = document.getElementById('btnSelectStandardObs');
            if (btnSelectStandardObs) {
                btnSelectStandardObs.onclick = function() {
                    const chks = [
                        'obs_procedure_done',
                        'obs_erythema_edema',
                        'obs_no_complaints',
                        'obs_fucicort_applied'
                    ];
                    chks.forEach(id => {
                        const el = document.getElementById(id);
                        if (el) el.checked = true;
                    });
                };
            }

            // Advisory checklist helpers: [Select All] & [Clear All]
            const btnSelectAllAdvisory = document.getElementById('btnSelectAllAdvisory');
            const btnClearAllAdvisory = document.getElementById('btnClearAllAdvisory');
            const advisoryCheckboxIds = [
                'adv_fall_prevention',
                'adv_medication_schedule',
                'adv_diet_restrictions',
                'adv_activity_limits',
                'adv_wound_care',
                'adv_red_flags',
                'adv_hydration',
                'adv_followup_reminder',
                'adv_emergency_contact',
                'adv_no_self_medicate'
            ];

            if (btnSelectAllAdvisory) {
                btnSelectAllAdvisory.onclick = function() {
                    advisoryCheckboxIds.forEach(id => {
                        const el = document.getElementById(id);
                        if (el) el.checked = true;
                    });
                };
            }

            if (btnClearAllAdvisory) {
                btnClearAllAdvisory.onclick = function() {
                    advisoryCheckboxIds.forEach(id => {
                        const el = document.getElementById(id);
                        if (el) el.checked = false;
                    });
                };
            }


            // --- Dynamic Medicine Rows & Formulary ---
            const prescriptionsContainer = document.getElementById('prescriptionsContainer');
            const addMedicineBtn = document.getElementById('addMedicineBtn');

            function addPrescriptionRow(medName = '', dosage = '', duration = '', instructions = '', symptomRef = '') {
                const newRow = document.createElement('div');
                newRow.className = 'prescription-row border border-hms-border p-4 rounded-xl mb-4 bg-hms-bg';
                if (symptomRef) {
                    newRow.setAttribute('data-symptom-ref', symptomRef);
                }
                newRow.innerHTML = `
                    <div class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end mb-3">
                        <div class="md:col-span-4">
                            <label class="block text-xxs font-bold text-hms-mid mb-1">Medicine Name</label>
                            <input type="text" class="w-full border border-hms-border rounded-lg p-2.5 text-sm outline-none focus:border-hms-accent" name="medicine_name[]" placeholder="e.g., Amoxicillin 500mg" value="${medName}" required>
                        </div>
                        <div class="md:col-span-4">
                            <label class="block text-xxs font-bold text-hms-mid mb-1">Dosage / Frequency</label>
                            <input type="text" class="w-full border border-hms-border rounded-lg p-2.5 text-sm outline-none focus:border-hms-accent" name="medicine_dosage[]" placeholder="e.g., 1 tablet three times daily" value="${dosage}" required>
                        </div>
                        <div class="md:col-span-3">
                            <label class="block text-xxs font-bold text-hms-mid mb-1">Duration</label>
                            <input type="text" class="w-full border border-hms-border rounded-lg p-2.5 text-sm outline-none focus:border-hms-accent" name="medicine_duration[]" placeholder="e.g., 7 days" value="${duration}" required>
                        </div>
                        <div class="md:col-span-1">
                            <button type="button" class="w-full border border-red-200 text-red-500 hover:bg-red-500 hover:text-white rounded-lg py-2.5 text-sm font-semibold remove-medicine-btn transition duration-150" title="Delete Prescription">×</button>
                        </div>
                    </div>
                    <div>
                        <label class="block text-xxs font-bold text-hms-muted mb-1">Special Instructions</label>
                        <textarea class="w-full border border-hms-border rounded-lg p-2 text-xs outline-none focus:border-hms-accent" name="medicine_instructions[]" rows="1" placeholder="e.g., Take after meals, avoid alcohol...">${instructions}</textarea>
                    </div>
                `;
                prescriptionsContainer.appendChild(newRow);
            }

            addMedicineBtn.addEventListener('click', function() {
                addPrescriptionRow();
            });

            document.querySelectorAll('.med-pill').forEach(pill => {
                const originalText = pill.innerText;
                const originalClass = pill.className;
                
                pill.addEventListener('click', function() {
                    const medName = this.getAttribute('data-med');
                    const dosage = this.getAttribute('data-dosage');
                    const duration = this.getAttribute('data-duration');
                    const inst = this.getAttribute('data-inst');
                    addPrescriptionRow(medName, dosage, duration, inst);

                    // Add checkmark visual feedback
                    pill.innerText = originalText + " Added ✓";
                    pill.className = "med-pill px-3 py-1.5 bg-green-150 border border-green-300 rounded-full text-xs font-semibold text-green-700 transition duration-150";
                    pill.disabled = true;

                    setTimeout(() => {
                        pill.innerText = originalText;
                        pill.className = originalClass;
                        pill.disabled = false;
                    }, 1500);
                });
            });

            // Common Case Prescription Bundles Data and Handlers
            const caseBundles = {
                flu: [
                    { name: 'Paracetamol 500mg', dosage: '1 tablet every 6 hours', duration: '3 days', inst: 'Take after meals for fever.' },
                    { name: 'Multivitamin', dosage: '1 tablet once daily', duration: '10 days', inst: 'Take in the morning.' },
                    { name: 'Vitamin C 500mg', dosage: '1 tablet twice daily', duration: '7 days', inst: 'Take with water.' }
                ],
                cold: [
                    { name: 'Chlorpheniramine 4mg', dosage: '1 tablet at night', duration: '5 days', inst: 'May cause drowsiness.' },
                    { name: 'Paracetamol 500mg', dosage: '1 tablet three times daily as needed', duration: '5 days', inst: 'For body aches/fever.' },
                    { name: 'Lozenges', dosage: '1 lozenge dissolved in mouth every 4 hours', duration: '3 days', inst: 'For sore throat.' }
                ],
                gastro: [
                    { name: 'Oral Rehydration Salts (ORS)', dosage: '1 sachet dissolved in 1L water after each loose stool', duration: '3 days', inst: 'Sip slowly.' },
                    { name: 'Loperamide 2mg', dosage: '1 capsule after first loose stool, then 1 after each stool (max 4/day)', duration: '2 days', inst: 'Discontinue if constipation occurs.' },
                    { name: 'Metoclopramide 10mg', dosage: '1 tablet three times daily', duration: '3 days', inst: 'Take 30 mins before food for nausea.' }
                ],
                htn: [
                    { name: 'Amlodipine 5mg', dosage: '1 tablet once daily', duration: '30 days', inst: 'Take in the morning.' },
                    { name: 'Losartan 50mg', dosage: '1 tablet once daily', duration: '30 days', inst: 'Monitor blood pressure regularly.' }
                ],
                diabetes: [
                    { name: 'Metformin 500mg', dosage: '1 tablet twice daily with meals', duration: '30 days', inst: 'Take with breakfast and dinner.' },
                    { name: 'Gliclazide 80mg', dosage: '1 tablet once daily before breakfast', duration: '30 days', inst: 'Monitor for hypoglycemia.' }
                ]
            };

            document.querySelectorAll('.med-bundle-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const bundleKey = this.getAttribute('data-bundle');
                    const meds = caseBundles[bundleKey];
                    if (meds) {
                        meds.forEach(m => {
                            addPrescriptionRow(m.name, m.dosage, m.duration, m.inst);
                        });
                    }
                });
            });


            // --- Real-time Diagnosis Search ---
            const searchInput = document.getElementById('diagSearchInput');
            searchInput.addEventListener('input', function() {
                const query = searchInput.value.toLowerCase().trim();
                const cards = document.querySelectorAll('.symptom-card');
                cards.forEach(card => {
                    const name = card.querySelector('.font-serif').textContent.toLowerCase();
                    if (name.includes(query)) {
                        card.closest('div').style.display = 'block';
                    } else {
                        card.closest('div').style.display = 'none';
                    }
                });
            });


            // --- Pre-populate Draft States ---
            const existingDiags = <?php echo json_encode(array_column($existing_diagnoses, 'icd_code')); ?>;
            const diagDetails = <?php echo json_encode($existing_diagnoses); ?>;

            existingDiags.forEach(icd => {
                const item = diagDetails.find(d => d.icd_code === icd);
                let card = null;
                if (item) {
                    const cleanDesc = item.description.replace(/[:*;]/g, '').trim().toLowerCase();
                    const cards = document.querySelectorAll(`.symptom-card[data-icd="${icd}"]`);
                    cards.forEach(c => {
                        const cardDesc = c.getAttribute('data-desc').replace(/[:*;]/g, '').trim().toLowerCase();
                        if (cardDesc === cleanDesc) {
                            card = c;
                        }
                    });
                    if (!card && cards.length > 0) {
                        card = cards[0];
                    }
                }
                
                if (card) {
                    card.click();
                } else if (item) {
                    createCustomDiagnosisRow(item.icd_code, item.description);
                }
            });


            // --- Event Delegation for Removing Dynamically Appended Rows ---
            document.addEventListener('click', function(e) {
                // Delete diagnosis row
                if (e.target && e.target.classList.contains('remove-diagnosis-btn')) {
                    const row = e.target.closest('.diagnosis-row');
                    if (row) {
                        const symptomRef = row.getAttribute('data-symptom-ref');
                        if (symptomRef) {
                            const card = document.querySelector(`.symptom-card[data-symptom-id="${symptomRef}"]`);
                            if (card) {
                                card.classList.remove('active');
                            }
                            delete activeSymptomRows[symptomRef];
                        }
                        row.remove();
                    }
                }
                
                // Delete medicine row
                if (e.target && e.target.classList.contains('remove-medicine-btn')) {
                    const row = e.target.closest('.prescription-row');
                    if (row) {
                        row.remove();
                    }
                }
            });

            // --- Interactive Vitals Increments & Presets ---
            document.querySelectorAll('.increment-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const targetId = this.getAttribute('data-target');
                    const step = parseFloat(this.getAttribute('data-step') || '1');
                    const input = document.getElementById(targetId);
                    if (input) {
                        const val = parseFloat(input.value || '0');
                        const max = parseFloat(input.max || '999');
                        const newVal = Math.min(max, val + step);
                        input.value = step % 1 === 0 ? newVal : newVal.toFixed(1);
                        
                        // Fire corresponding synchronizer functions
                        if (targetId === 'temperature') updateTemperature();
                        else if (targetId === 'heart_rate') updateHeartRate();
                        else if (targetId === 'weight') updateWeight();
                        else if (targetId === 'oxygen_saturation') updateOxygenSaturation();
                        else if (targetId === 'height') updateHeight();
                        else if (targetId === 'respiratory_rate') updateRespiratoryRate();
                        
                        checkVitals();
                    }
                });
            });

            document.querySelectorAll('.decrement-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const targetId = this.getAttribute('data-target');
                    const step = parseFloat(this.getAttribute('data-step') || '1');
                    const input = document.getElementById(targetId);
                    if (input) {
                        const val = parseFloat(input.value || '0');
                        const min = parseFloat(input.min || '0');
                        const newVal = Math.max(min, val - step);
                        input.value = step % 1 === 0 ? newVal : newVal.toFixed(1);
                        
                        // Fire corresponding synchronizer functions
                        if (targetId === 'temperature') updateTemperature();
                        else if (targetId === 'heart_rate') updateHeartRate();
                        else if (targetId === 'weight') updateWeight();
                        else if (targetId === 'oxygen_saturation') updateOxygenSaturation();
                        else if (targetId === 'height') updateHeight();
                        else if (targetId === 'respiratory_rate') updateRespiratoryRate();
                        
                        checkVitals();
                    }
                });
            });

            // BP presets special handler
            document.querySelectorAll('.bp-preset').forEach(btn => {
                btn.addEventListener('click', function() {
                    const val = this.getAttribute('data-val');
                    const parts = val.split('/');
                    if (parts.length === 2) {
                        document.getElementById('bp_systolic').value = parts[0];
                        document.getElementById('bp_diastolic').value = parts[1];
                        updateBloodPressure();
                        checkVitals();
                    }
                });
            });

            const bindSliderPreset = (btnClass, inputId, updateFn) => {
                document.querySelectorAll(btnClass).forEach(btn => {
                    btn.addEventListener('click', function() {
                        const val = this.getAttribute('data-val');
                        const input = document.getElementById(inputId);
                        if (input) {
                            input.value = val;
                            updateFn();
                            checkVitals();
                        }
                    });
                });
            };

            bindSliderPreset('.temp-preset', 'temperature', updateTemperature);
            bindSliderPreset('.hr-preset', 'heart_rate', updateHeartRate);
            bindSliderPreset('.weight-preset', 'weight', updateWeight);
            bindSliderPreset('.spo2-preset', 'oxygen_saturation', updateOxygenSaturation);
            bindSliderPreset('.height-preset', 'height', updateHeight);
            bindSliderPreset('.resp-preset', 'respiratory_rate', updateRespiratoryRate);

        });
    </script>

    <!-- Extension Prompt Modal -->
    <div id="extensionModal" class="fixed inset-0 z-50 hidden overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
        <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
            <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" aria-hidden="true"></div>
            <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>
            <div class="inline-block align-bottom bg-white rounded-2xl text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full border border-hms-border">
                <div class="bg-white px-6 pt-6 pb-4 sm:p-6 sm:pb-4">
                    <div class="sm:flex sm:items-start">
                        <div class="mx-auto flex-shrink-0 flex items-center justify-center h-12 w-12 rounded-full bg-blue-50 text-hms-accent sm:mx-0 sm:h-10 sm:w-10">
                            <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </div>
                        <div class="mt-3 text-center sm:mt-0 sm:ml-4 sm:text-left">
                            <h3 class="font-serif text-lg font-bold text-hms-dark" id="modal-title">Consultation Time Running Out</h3>
                            <div class="mt-2">
                                <p class="text-xs text-hms-mid">Your current 30-minute consultation slot is about to end. Would you like to extend this session by another 30 minutes? This will automatically reserve the next consecutive slot for this patient.</p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="bg-gray-50 px-6 py-4 flex flex-row-reverse gap-2 rounded-b-2xl">
                    <button type="button" id="confirmExtensionBtn" class="bg-hms-accent hover:bg-hms-accentDim text-white rounded-full px-5 py-2 text-xs font-semibold shadow-sm transition duration-150">Yes, Extend Session</button>
                    <button type="button" id="cancelExtensionBtn" class="border border-hms-border text-hms-mid hover:bg-gray-200 rounded-full px-5 py-2 text-xs font-semibold transition duration-150">No, Keep Current</button>
                </div>
            </div>
        </div>
    </div>
<?php include_once __DIR__ . '/../includes/footer.php'; ?>

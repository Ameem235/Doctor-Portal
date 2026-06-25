<?php
/**
 * View Consultation Details and Lab Results Page
 * 
 * Renders the finalized clinical case sheet for a completed consultation.
 * Displays patient details, vitals, medical history, clinical notes, diagnoses, 
 * prescriptions, lab results, and previous medical history timeline.
 * Includes a print handler that isolates the prescription Rx ticket.
 * 
 * Emoji-Free Details View with MedCore styling.
 */

// Start session
session_start();

// Verify doctor authentication
if (!isset($_SESSION['doctor_id'])) {
    header("Location: login.php");
    exit();
}

// Retrieve appointment ID
$appointment_id = filter_input(INPUT_GET, 'appointment_id', FILTER_VALIDATE_INT);
if (!$appointment_id) {
    $_SESSION['error_msg'] = "Invalid appointment ID.";
    header("Location: dashboard.php");
    exit();
}

// Include database connection
require_once __DIR__ . '/../config/db.php';

try {
    // 1. Fetch consultation, appointment, patient, and doctor information
    $stmt = $pdo->prepare("
        SELECT c.*, a.appointment_date, a.appointment_time, a.status AS appointment_status, a.doctor_id,
               p.patient_id, p.name AS patient_name, p.dob, p.gender,
               d.name AS doctor_name, d.email AS doctor_email
        FROM consultations c
        INNER JOIN appointments a ON c.appointment_id = a.appointment_id
        INNER JOIN patients p ON a.patient_id = p.patient_id
        INNER JOIN doctors d ON a.doctor_id = d.doctor_id
        WHERE a.appointment_id = ?
    ");
    $stmt->execute([$appointment_id]);
    $consultation = $stmt->fetch();

    if (!$consultation) {
        $_SESSION['error_msg'] = "Consultation record not found.";
        header("Location: dashboard.php");
        exit();
    }

    // Verify ownership security
    if ($consultation['doctor_id'] != $_SESSION['doctor_id']) {
        $_SESSION['error_msg'] = "Access denied: You are not assigned to this appointment.";
        header("Location: dashboard.php");
        exit();
    }

    $consultation_id = $consultation['consultation_id'];

    // 2. Fetch Diagnoses
    $stmtDiag = $pdo->prepare("SELECT * FROM consultation_diagnoses WHERE consultation_id = ? ORDER BY id ASC");
    $stmtDiag->execute([$consultation_id]);
    $diagnoses = $stmtDiag->fetchAll();

    // 3. Fetch Prescriptions
    $stmtPres = $pdo->prepare("SELECT * FROM consultation_prescriptions WHERE consultation_id = ? ORDER BY id ASC");
    $stmtPres->execute([$consultation_id]);
    $prescriptions = $prescriptions = $stmtPres->fetchAll();

    // 4. Fetch Lab Tests & Results
    $stmtTest = $pdo->prepare("SELECT * FROM consultation_tests WHERE consultation_id = ? ORDER BY id ASC");
    $stmtTest->execute([$consultation_id]);
    $lab_tests = $stmtTest->fetchAll();

    // 5. Fetch Patient's Medical Timeline (previous completed consultations)
    $patient_id = $consultation['patient_id'];
    $past_visits = [];
    
    $stmtPast = $pdo->prepare("
        SELECT c.consultation_id, c.blood_pressure, c.temperature, c.pain_scale, c.narrative_diagnosis, c.chief_complaint, a.appointment_date, a.appointment_time
        FROM consultations c
        INNER JOIN appointments a ON c.appointment_id = a.appointment_id
        WHERE a.patient_id = ? AND a.appointment_id != ? AND c.status = 'Finalized'
        ORDER BY a.appointment_date DESC, a.appointment_time DESC
    ");
    $stmtPast->execute([$patient_id, $appointment_id]);
    $past_visits = $stmtPast->fetchAll();

    for ($i = 0; $i < count($past_visits); $i++) {
        $v_id = $past_visits[$i]['consultation_id'];
        
        $stmtD = $pdo->prepare("SELECT * FROM consultation_diagnoses WHERE consultation_id = ?");
        $stmtD->execute([$v_id]);
        $past_visits[$i]['diagnoses'] = $stmtD->fetchAll();

        $stmtP = $pdo->prepare("SELECT * FROM consultation_prescriptions WHERE consultation_id = ?");
        $stmtP->execute([$v_id]);
        $past_visits[$i]['prescriptions'] = $stmtP->fetchAll();

        $stmtT = $pdo->prepare("SELECT * FROM consultation_tests WHERE consultation_id = ?");
        $stmtT->execute([$v_id]);
        $past_visits[$i]['tests'] = $stmtT->fetchAll();
    }

    // Helper function to calculate patient age
    function calculateAge($dob) {
        $birthDate = new DateTime($dob);
        $todayDate = new DateTime();
        $diff = $todayDate->diff($birthDate);
        return $diff->y;
    }
    $patient_age = calculateAge($consultation['dob']);

    // Decode Review of Systems & Clinical Examination JSON data
    $ros_data = [];
    if (!empty($consultation['ros_data'])) {
        $ros_data = json_decode($consultation['ros_data'], true);
    }
    $exam_data = [];
    if (!empty($consultation['exam_data'])) {
        $exam_data = json_decode($consultation['exam_data'], true);
    }

} catch (PDOException $e) {
    $_SESSION['error_msg'] = "Database error: " . $e->getMessage();
    header("Location: dashboard.php");
    exit();
}
?>
<?php
$pageTitle = "Case Sheet Details";
include_once __DIR__ . '/../includes/header.php';
?>
<!-- Styles for Print Page -->
<style>
    .section-title {
        color: #4F7CAC;
        font-family: 'Lora', Georgia, serif;
        font-weight: 700;
        border-bottom: 2px solid #E5EAF0;
        padding-bottom: 6px;
        margin-bottom: 16px;
    }
    .vital-badge {
        background-color: #FFFFFF;
        border: 1px solid #E5EAF0;
        border-radius: 10px;
        padding: 12px;
        text-align: center;
        transition: all 0.2s ease;
    }
    .vital-badge:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.02);
    }
    .vital-value {
        font-size: 1.25rem;
        font-weight: 700;
        color: #1F2937;
    }
    .vital-label {
        font-size: 0.75rem;
        color: #6B7280;
        font-weight: 600;
        margin-top: 2px;
        text-transform: uppercase;
        letter-spacing: 0.02em;
    }
    #caseSheetPrintArea {
        display: none;
    }
    @media print {
        body {
            background-color: #FFFFFF !important;
            color: #000000 !important;
            font-family: 'Inter', sans-serif !important;
        }
        body.print-rx * {
            visibility: hidden;
        }
        body.print-rx #prescriptionPrintArea, body.print-rx #prescriptionPrintArea * {
            visibility: visible;
        }
        body.print-rx #prescriptionPrintArea {
            position: absolute !important;
            left: 0 !important;
            top: 0 !important;
            width: 100% !important;
            display: block !important;
            border: none !important;
            box-shadow: none !important;
        }
        body.print-rx #caseSheetPrintArea, body.print-rx #caseSheetPrintArea * {
            visibility: hidden !important;
            display: none !important;
        }

        body.print-casesheet * {
            visibility: hidden;
        }
        body.print-casesheet #caseSheetPrintArea, body.print-casesheet #caseSheetPrintArea * {
            visibility: visible;
        }
        body.print-casesheet #caseSheetPrintArea {
            position: absolute !important;
            left: 0 !important;
            top: 0 !important;
            width: 100% !important;
            display: block !important;
            border: none !important;
            box-shadow: none !important;
        }
        body.print-casesheet #prescriptionPrintArea, body.print-casesheet #prescriptionPrintArea * {
            visibility: hidden !important;
            display: none !important;
        }
        .no-print {
            display: none !important;
        }
    }
</style>
</head>
<body class="bg-hms-bg min-h-screen">
<?php
$backToDashboard = true;
$backSelectedId = $appointment_id;
$printRx = true;
$navbarNoPrint = true;
include_once __DIR__ . '/../includes/navbar.php';
?>

    <!-- Main Container -->
    <main class="container pb-12">
        <div class="max-w-5xl mx-auto">
            
            <!-- Patient summary details header -->
            <div class="bg-white border border-hms-border rounded-xl p-5 mb-6 shadow-sm no-print">
                <div class="bg-hms-panel border-l-4 border-hms-accent rounded p-4">
                    <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
                        <div>
                            <h1 class="font-serif text-2xl font-bold text-hms-dark mb-1"><?php echo htmlspecialchars($consultation['patient_name']); ?></h1>
                            <div class="text-hms-mid text-xs font-medium">
                                <span class="bg-hms-accent text-white px-2.5 py-0.5 rounded-full text-xxs mr-2 font-bold"><?php echo htmlspecialchars($consultation['gender']); ?></span>
                                <span class="mr-4"><strong>Age:</strong> <?php echo $patient_age; ?> Years (DOB: <?php echo date('d-M-Y', strtotime($consultation['dob'])); ?>)</span>
                                <span class="mr-4"><strong>Patient ID:</strong> #<?php echo htmlspecialchars($consultation['patient_id']); ?></span>
                                <span class="block md:inline-block mt-1 md:mt-0"><strong>Allotted:</strong> <?php echo date('M d, Y', strtotime($consultation['appointment_date'])) . ' at ' . date('h:i A', strtotime($consultation['appointment_time'])); ?></span>
                            </div>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="bg-white text-green-700 border border-green-200 px-3.5 py-1.5 rounded-full text-xs font-semibold shadow-sm">Consultation Completed</span>
                            <button onclick="printCaseSheet()" class="border-0 bg-emerald-600 hover:bg-emerald-700 text-white rounded-full px-5 py-1.5 text-xs font-semibold tracking-wide shadow-sm transition duration-200">Print Case Sheet</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Case Sheet Layout -->
            <div class="bg-white border border-hms-border rounded-xl p-6 mb-6 shadow-sm no-print">
                
                <!-- 1. Vitals & Allergy Section -->
                <h3 class="section-title">1. Patient Vitals & Allergies</h3>
                
                <?php if (!empty($consultation['allergy_notes'])): ?>
                    <div class="mb-5 p-4 bg-red-50 border-l-4 border-red-500 rounded text-red-700 text-sm font-medium">
                        <span class="font-bold">Warning:</span> Documented Allergy History: <span class="font-semibold"><?php echo htmlspecialchars($consultation['allergy_notes']); ?></span>
                    </div>
                <?php endif; ?>

                <?php
                $w = floatval($consultation['weight'] ?? 0);
                $h = floatval($consultation['height'] ?? 0);
                $bmi_val = '--';
                if ($w > 0 && $h > 0) {
                    $bmi_val = number_format($w / (($h / 100) ** 2), 1);
                }
                ?>
                <div class="grid grid-cols-3 md:grid-cols-9 gap-3 mb-8">
                    <div class="vital-badge shadow-sm">
                        <div class="vital-value"><?php echo htmlspecialchars($consultation['blood_pressure'] ?? 'N/A'); ?></div>
                        <div class="vital-label">BP</div>
                    </div>
                    <div class="vital-badge shadow-sm">
                        <div class="vital-value"><?php echo htmlspecialchars($consultation['temperature'] ?? 'N/A'); ?> °C</div>
                        <div class="vital-label">Temp</div>
                    </div>
                    <div class="vital-badge shadow-sm">
                        <div class="vital-value"><?php echo htmlspecialchars($consultation['heart_rate'] ?? 'N/A'); ?> bpm</div>
                        <div class="vital-label">Heart Rate</div>
                    </div>
                    <div class="vital-badge shadow-sm">
                        <div class="vital-value"><?php echo htmlspecialchars($consultation['respiratory_rate'] ?? 'N/A'); ?> rpm</div>
                        <div class="vital-label">Respiratory</div>
                    </div>
                    <div class="vital-badge shadow-sm">
                        <div class="vital-value"><?php echo htmlspecialchars($consultation['oxygen_saturation'] ?? 'N/A'); ?> %</div>
                        <div class="vital-label">SpO2 %</div>
                    </div>
                    <div class="vital-badge shadow-sm">
                        <div class="vital-value"><?php echo htmlspecialchars($consultation['height'] ?? 'N/A'); ?> cm</div>
                        <div class="vital-label">Height</div>
                    </div>
                    <div class="vital-badge shadow-sm">
                        <div class="vital-value"><?php echo htmlspecialchars($consultation['weight'] ?? 'N/A'); ?> kg</div>
                        <div class="vital-label">Weight</div>
                    </div>
                    <div class="vital-badge shadow-sm">
                        <div class="vital-value"><?php echo htmlspecialchars($bmi_val); ?></div>
                        <div class="vital-label">BMI</div>
                    </div>
                    <div class="vital-badge shadow-sm">
                        <div class="vital-value"><?php echo htmlspecialchars($consultation['pain_scale'] ?? 'N/A'); ?> / 10</div>
                        <div class="vital-label">Pain Scale</div>
                    </div>
                </div>

                <!-- 2. Medical History Section -->
                <h3 class="section-title">2. Medical History</h3>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8 text-sm">
                    <div class="p-4 bg-gray-50 border border-hms-border rounded-xl">
                        <div class="text-hms-muted text-xxs font-bold tracking-wider mb-1">REFERRED BY</div>
                        <div class="text-hms-dark font-medium"><?php echo htmlspecialchars($consultation['referred_by'] ?? 'Self-referral'); ?></div>
                    </div>
                    <div class="p-4 bg-gray-50 border border-hms-border rounded-xl md:col-span-2">
                        <div class="text-hms-muted text-xxs font-bold tracking-wider mb-1">MEDICAL HISTORY</div>
                        <div class="text-hms-dark font-medium whitespace-pre-wrap"><?php echo htmlspecialchars($consultation['medical_history'] ?? 'No significant past medical history.'); ?></div>
                    </div>
                    <div class="p-4 bg-gray-50 border border-hms-border rounded-xl">
                        <div class="text-hms-muted text-xxs font-bold tracking-wider mb-1">SURGICAL HISTORY</div>
                        <div class="text-hms-dark font-medium whitespace-pre-wrap"><?php echo htmlspecialchars($consultation['surgical_history'] ?? 'None recorded.'); ?></div>
                    </div>
                    <div class="p-4 bg-gray-50 border border-hms-border rounded-xl">
                        <div class="text-hms-muted text-xxs font-bold tracking-wider mb-1">FAMILY HISTORY</div>
                        <div class="text-hms-dark font-medium whitespace-pre-wrap"><?php echo htmlspecialchars($consultation['family_history'] ?? 'None recorded.'); ?></div>
                    </div>
                    <div class="p-4 bg-gray-50 border border-hms-border rounded-xl">
                        <div class="text-hms-muted text-xxs font-bold tracking-wider mb-1">SOCIAL HISTORY</div>
                        <div class="text-hms-dark font-medium whitespace-pre-wrap"><?php echo htmlspecialchars($consultation['social_history'] ?? 'None recorded.'); ?></div>
                    </div>
                </div>

                <!-- 3. Chief Complaints / History of Present Illness Section -->
                <h3 class="section-title">3. Chief Complaints / History of Present Illness</h3>
                <div class="bg-white border border-hms-border rounded-xl shadow-sm overflow-hidden mb-8">
                    <table class="w-full text-left border-collapse">
                        <tbody>
                            <tr class="border-b border-hms-border text-sm">
                                <td class="py-3 px-4 bg-gray-50 font-bold text-hms-mid text-xs uppercase tracking-wider" style="width: 250px;">Chief Complaint</td>
                                <td class="py-3 px-4 text-hms-dark font-medium whitespace-pre-wrap"><?php echo htmlspecialchars($consultation['chief_complaint'] ?? 'None'); ?></td>
                            </tr>
                            <tr class="border-b border-hms-border text-sm">
                                <td class="py-3 px-4 bg-gray-50 font-bold text-hms-mid text-xs uppercase tracking-wider">Pain Scale</td>
                                <td class="py-3 px-4 text-hms-dark font-medium"><?php echo htmlspecialchars($consultation['pain_scale_type'] ?? 'None'); ?></td>
                            </tr>
                            <tr class="border-b border-hms-border text-sm">
                                <td class="py-3 px-4 bg-gray-50 font-bold text-hms-mid text-xs uppercase tracking-wider">Location</td>
                                <td class="py-3 px-4 text-hms-dark font-medium"><?php echo htmlspecialchars($consultation['hpi_location'] ?? 'None'); ?></td>
                            </tr>
                            <tr class="border-b border-hms-border text-sm">
                                <td class="py-3 px-4 bg-gray-50 font-bold text-hms-mid text-xs uppercase tracking-wider">Quality</td>
                                <td class="py-3 px-4 text-hms-dark font-medium"><?php echo htmlspecialchars($consultation['hpi_quality'] ?? 'None'); ?></td>
                            </tr>
                            <tr class="border-b border-hms-border text-sm">
                                <td class="py-3 px-4 bg-gray-50 font-bold text-hms-mid text-xs uppercase tracking-wider">Duration</td>
                                <td class="py-3 px-4 text-hms-dark font-medium"><?php echo htmlspecialchars($consultation['hpi_duration'] ?? 'None'); ?></td>
                            </tr>
                            <tr class="border-b border-hms-border text-sm">
                                <td class="py-3 px-4 bg-gray-50 font-bold text-hms-mid text-xs uppercase tracking-wider">Timing</td>
                                <td class="py-3 px-4 text-hms-dark font-medium"><?php echo htmlspecialchars($consultation['hpi_timing'] ?? 'None'); ?></td>
                            </tr>
                            <tr class="border-b border-hms-border text-sm">
                                <td class="py-3 px-4 bg-gray-50 font-bold text-hms-mid text-xs uppercase tracking-wider">Context</td>
                                <td class="py-3 px-4 text-hms-dark font-medium"><?php echo htmlspecialchars($consultation['hpi_context'] ?? 'None'); ?></td>
                            </tr>
                            <tr class="text-sm">
                                <td class="py-3 px-4 bg-gray-50 font-bold text-hms-mid text-xs uppercase tracking-wider">Modifying Factor</td>
                                <td class="py-3 px-4 text-hms-dark font-medium"><?php echo htmlspecialchars($consultation['hpi_modifying_factor'] ?? 'None'); ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-8 text-sm">
                    <div class="p-4 bg-gray-50 border border-hms-border rounded-xl">
                        <div class="text-hms-muted text-xxs font-bold tracking-wider mb-1">PHYSICAL EXAMINATION</div>
                        <div class="text-hms-dark font-medium whitespace-pre-wrap"><?php echo htmlspecialchars($consultation['physical_examination'] ?? 'Not recorded'); ?></div>
                    </div>
                    <div class="p-4 bg-gray-50 border border-hms-border rounded-xl">
                        <div class="text-hms-muted text-xxs font-bold tracking-wider mb-1">NARRATIVE DIAGNOSIS</div>
                        <div class="text-hms-dark font-medium whitespace-pre-wrap"><?php echo htmlspecialchars($consultation['narrative_diagnosis'] ?? 'None recorded.'); ?></div>
                    </div>
                </div>

                <!-- 3.1. Review of Systems -->
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
                ?>
                <h3 class="section-title">3.1. Review of Systems</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-8 text-sm">
                    <?php foreach ($ros_systems as $key => $label): ?>
                        <div class="p-3 bg-gray-50 border border-hms-border rounded-xl flex justify-between items-center">
                            <span class="font-bold text-hms-mid text-xs uppercase tracking-wider"><?php echo $label; ?></span>
                            <span class="text-hms-dark font-medium"><?php echo htmlspecialchars($ros_data[$key] ?? 'No Complaints'); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- 3.2. Clinical Examination -->
                <h3 class="section-title">3.2. Clinical Examination</h3>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8 text-sm">
                    <div class="p-4 bg-gray-50 border border-hms-border rounded-xl">
                        <div class="text-hms-muted text-xxs font-bold tracking-wider mb-1">GENERAL</div>
                        <div class="text-hms-dark font-medium"><?php echo htmlspecialchars($exam_data['general'] ?? 'Normal'); ?></div>
                    </div>
                    <div class="p-4 bg-gray-50 border border-hms-border rounded-xl">
                        <div class="text-hms-muted text-xxs font-bold tracking-wider mb-1">SKIN</div>
                        <div class="text-hms-dark font-medium"><?php echo htmlspecialchars($exam_data['skin'] ?? 'Normal'); ?></div>
                    </div>
                    <div class="p-4 bg-gray-50 border border-hms-border rounded-xl">
                        <div class="text-hms-muted text-xxs font-bold tracking-wider mb-1">EXAMINATION NOTES</div>
                        <div class="text-hms-dark font-medium whitespace-pre-wrap"><?php echo !empty($exam_data['notes']) ? htmlspecialchars($exam_data['notes']) : 'No examination notes recorded.'; ?></div>
                    </div>
                </div>

                <!-- Nurse Notes Section -->
                <h3 class="section-title">3.5. Nurse Notes</h3>
                <?php 
                $nursing_plan = json_decode($consultation['nursing_plan'] ?? '{}', true);
                ?>
                <div class="bg-white border border-hms-border rounded-xl shadow-sm p-5 mb-8 text-sm">
                    <div class="flex flex-col md:flex-row gap-6">
                        <div class="flex-1 text-hms-dark font-medium whitespace-pre-wrap leading-relaxed"><?php 
                            echo !empty($consultation['nurse_notes']) ? htmlspecialchars($consultation['nurse_notes']) : 'No nurse notes recorded.'; 
                        ?></div>
                        <div class="md:w-64 flex-shrink-0 flex flex-col justify-end items-center md:items-end border-t md:border-t-0 md:border-l border-hms-border pt-4 md:pt-0 md:pl-6 text-center md:text-right">
                            <span class="text-hms-muted text-xxs font-bold uppercase tracking-wider block mb-2">Signed By (Nurse)</span>
                            <div class="text-hms-accent font-serif font-bold text-lg"><?php 
                                $nurse_name = '';
                                if (!empty($nursing_plan['advisory_by'])) {
                                    $nurse_name = $nursing_plan['advisory_by'];
                                } elseif (!empty($nursing_plan['prep_nurse'])) {
                                    $nurse_name = $nursing_plan['prep_nurse'];
                                }
                                echo $nurse_name !== '' ? htmlspecialchars($nurse_name) : 'Not signed';
                            ?></div>
                        </div>
                    </div>
                </div>

                <!-- 4. Diagnosis Section -->
                <h3 class="section-title">4. Diagnoses (ICD Standard)</h3>
                <div class="overflow-x-auto mb-8">
                    <?php if (empty($diagnoses)): ?>
                        <p class="text-hms-muted text-sm italic">No diagnoses recorded.</p>
                    <?php else: ?>
                        <table class="w-full text-left border-collapse">
                            <thead>
                                <tr class="bg-gray-50 text-hms-mid text-xs font-semibold uppercase tracking-wide border-b border-hms-border">
                                    <th class="py-2.5 px-4" style="width: 150px;">ICD-10 Code</th>
                                    <th class="py-2.5 px-4">Diagnostic Details</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($diagnoses as $diag): ?>
                                    <tr class="border-b border-hms-border text-sm">
                                        <td class="py-3 px-4">
                                            <span class="bg-hms-panel text-hms-accent px-3 py-1 rounded font-mono font-bold text-xs border border-blue-200"><?php echo htmlspecialchars($diag['icd_code']); ?></span>
                                        </td>
                                        <td class="py-3 px-4 text-hms-dark font-medium">
                                            <?php echo htmlspecialchars($diag['description']); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <!-- 5. Medications Prescription Section -->
                <h3 class="section-title">5. Prescribed Medications</h3>
                <div class="mb-8">
                    <?php if (empty($prescriptions)): ?>
                        <p class="text-hms-muted text-sm italic">No medications recommended during this consultation.</p>
                    <?php else: ?>
                        <div class="space-y-3.5 bg-gray-50 border border-hms-border rounded-xl p-4 shadow-sm">
                            <?php foreach ($prescriptions as $index => $pres): ?>
                                <div class="<?php echo $index > 0 ? 'border-t border-hms-border/60 pt-3.5' : ''; ?> text-sm">
                                    <div class="font-serif font-bold text-hms-dark text-base mb-1">
                                        <?php echo htmlspecialchars($pres['medicine_name']); ?>
                                    </div>
                                    <div class="text-xs text-hms-mid leading-relaxed">
                                        <span class="font-semibold text-hms-dark">Dosage:</span> <?php echo htmlspecialchars($pres['dosage']); ?> &bull; 
                                        <span class="font-semibold text-hms-dark">Duration:</span> <?php echo htmlspecialchars($pres['duration']); ?> &bull; 
                                        <span class="font-semibold text-hms-dark">Instructions:</span> <?php echo htmlspecialchars($pres['instructions'] ?? 'No special instructions'); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- 6. Lab Tests & Results Section -->
                <h3 class="section-title">6. Lab Tests & Results</h3>
                <div class="mb-8">
                    <?php if (empty($lab_tests)): ?>
                        <p class="text-hms-muted text-sm italic">No diagnostic laboratory tests were ordered during this appointment.</p>
                    <?php else: ?>
                        <div class="grid grid-cols-1 gap-4">
                            <?php foreach ($lab_tests as $test): ?>
                                <div class="border border-hms-border rounded-xl shadow-sm bg-white overflow-hidden">
                                    <div class="bg-gray-50 border-b border-hms-border flex justify-between items-center py-2.5 px-4 text-sm">
                                        <div>
                                            <strong class="text-hms-dark font-serif"><?php echo htmlspecialchars($test['test_name']); ?></strong>
                                            <span class="bg-white text-hms-mid border border-hms-border text-xxs px-2.5 py-0.5 rounded-full font-bold ml-2"><?php echo htmlspecialchars($test['category']); ?></span>
                                            <?php 
                                            $priority = $test['priority'] ?? 'Routine';
                                            $badgeClass = 'bg-gray-100 text-gray-700';
                                            if ($priority === 'STAT') {
                                                $badgeClass = 'bg-red-100 text-red-700 border border-red-200';
                                            } elseif ($priority === 'Urgent') {
                                                $badgeClass = 'bg-orange-100 text-orange-700 border border-orange-200';
                                            }
                                            ?>
                                            <span class="text-xxs px-2.5 py-0.5 rounded-full font-bold ml-1.5 <?php echo $badgeClass; ?>"><?php echo htmlspecialchars($priority); ?></span>
                                        </div>
                                        <span class="bg-green-100 text-green-700 text-xs px-3 py-1 rounded-full font-semibold">Completed</span>
                                    </div>
                                    <div class="p-4">
                                        <div class="test-result-box shadow-sm"><?php echo htmlspecialchars($test['result_summary']); ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- 7. Medical Timeline Section -->
                <h3 class="section-title">7. Medical Timeline (Previous Visits)</h3>
                <div class="mb-4">
                    <?php if (empty($past_visits)): ?>
                        <p class="text-hms-muted text-xs italic">No previous finalized consultations recorded for this patient.</p>
                    <?php else: ?>
                        <div class="timeline-container mt-2">
                            <?php foreach ($past_visits as $visit): ?>
                                <div class="timeline-item pb-4">
                                    <div class="font-serif font-bold text-hms-accent text-sm mb-1">
                                        <?php echo date('M d, Y', strtotime($visit['appointment_date'])) . ' at ' . date('h:i A', strtotime($visit['appointment_time'])); ?>
                                    </div>
                                    <div class="bg-gray-50 border border-hms-border p-4 rounded-xl text-xs text-hms-dark space-y-1.5">
                                        <div><strong>Chief Complaint:</strong> <?php echo htmlspecialchars($visit['chief_complaint'] ?? 'Not recorded'); ?></div>
                                        <div><strong>Vitals Check:</strong> BP: <?php echo htmlspecialchars($visit['blood_pressure'] ?? 'N/A'); ?> | Temp: <?php echo htmlspecialchars($visit['temperature'] ?? 'N/A'); ?> °C | Pain Scale: <?php echo htmlspecialchars($visit['pain_scale'] ?? 'N/A'); ?>/10</div>
                                        
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
                                                <strong>Medications:</strong>
                                                <ul class="list-disc pl-4 space-y-0.5 mt-0.5">
                                                    <?php foreach ($visit['prescriptions'] as $vp): ?>
                                                        <li><?php echo htmlspecialchars($vp['medicine_name']); ?> (<?php echo htmlspecialchars($vp['dosage']); ?>, <?php echo htmlspecialchars($vp['duration']); ?>)</li>
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

            </div>

            <!-- 8. Prescription Rx Ticket Preview Card -->
            <div class="bg-white border border-hms-border rounded-xl p-6 mb-6 shadow-sm">
                <div class="flex justify-between items-center mb-6 border-b border-hms-border pb-2">
                    <h3 class="font-serif font-bold text-hms-dark text-lg mb-0">Prescription Rx Ticket Preview</h3>
                    <div>
                        <button onclick="downloadRxXML()" class="border border-hms-accent text-hms-accent hover:bg-hms-accent hover:text-white rounded-full px-5 py-1.5 text-xs font-semibold tracking-wide transition duration-200 mr-2">Export Rx XML</button>
                        <button onclick="printRxTicket()" class="border-0 bg-hms-accent hover:bg-hms-accentDim text-white rounded-full px-5 py-1.5 text-xs font-semibold tracking-wide shadow-sm transition duration-200">Print Rx Ticket</button>
                        <button onclick="printCaseSheet()" class="border-0 bg-emerald-600 hover:bg-emerald-700 text-white rounded-full px-5 py-1.5 text-xs font-semibold tracking-wide shadow-sm transition duration-200 ml-2">Print Case Sheet</button>
                    </div>
                </div>
                
                <!-- Printable Card Outer Wrapper - LESS GRAPHICS AS POSSIBLE (Monochrome / Thermal Layout) -->
                <div id="prescriptionPrintArea" class="p-6 border border-black bg-white text-black mx-auto w-full font-mono text-xs" style="max-width: 650px; border-radius: 0;">
                    <!-- Header -->
                    <div class="flex justify-between items-center border-b border-black pb-4 mb-4">
                        <div>
                            <h4 class="font-bold text-base mb-0 uppercase">MedCore Clinic</h4>
                            <p class="text-[10px] mb-0">Clinical Prescription Ticket</p>
                        </div>
                        <div class="text-right text-xs">
                            <div><strong>Doctor:</strong> <?php echo htmlspecialchars($consultation['doctor_name']); ?></div>
                            <div><strong>Email:</strong> <?php echo htmlspecialchars($consultation['doctor_email']); ?></div>
                            <div><strong>Date:</strong> <?php echo date('d-M-Y', strtotime($consultation['appointment_date'])); ?></div>
                        </div>
                    </div>
                    
                    <!-- Patient Details -->
                    <div class="border border-black p-4 mb-4 text-xs">
                        <div class="flex justify-between">
                            <div><strong>Patient Name:</strong> <?php echo htmlspecialchars($consultation['patient_name']); ?></div>
                            <div><strong>Patient ID:</strong> #<?php echo htmlspecialchars($consultation['patient_id']); ?></div>
                        </div>
                        <div class="flex justify-between mt-1">
                            <div><strong>Age/Gender:</strong> <?php echo $patient_age; ?> Years / <?php echo htmlspecialchars($consultation['gender']); ?></div>
                            <div><strong>Appointment ID:</strong> #<?php echo htmlspecialchars($appointment_id); ?></div>
                        </div>
                    </div>
                    
                    <!-- Allergy Alerts -->
                    <?php if (!empty($consultation['allergy_notes'])): ?>
                        <div class="border border-black p-3 mb-4 text-xs font-bold">
                            ALLERGY ALERT: <?php echo htmlspecialchars($consultation['allergy_notes']); ?>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Vitals Brief Summary -->
                    <div class="grid grid-cols-4 gap-2 mb-6 border-b border-black pb-4 text-center text-xs">
                        <div>
                            <div class="text-[9px] uppercase mb-0.5">BP</div>
                            <div class="font-bold"><?php echo htmlspecialchars($consultation['blood_pressure'] ?? 'N/A'); ?></div>
                        </div>
                        <div class="border-l border-black">
                            <div class="text-[9px] uppercase mb-0.5">HR</div>
                            <div class="font-bold"><?php echo htmlspecialchars($consultation['heart_rate'] ?? 'N/A'); ?> bpm</div>
                        </div>
                        <div class="border-l border-black">
                            <div class="text-[9px] uppercase mb-0.5">TEMP</div>
                            <div class="font-bold"><?php echo htmlspecialchars($consultation['temperature'] ?? 'N/A'); ?> °C</div>
                        </div>
                        <div class="border-l border-black">
                            <div class="text-[9px] uppercase mb-0.5">WEIGHT</div>
                            <div class="font-bold"><?php echo htmlspecialchars($consultation['weight'] ?? 'N/A'); ?> kg</div>
                        </div>
                    </div>

                    <!-- Rx Symbol Header -->
                    <div class="text-lg font-bold mb-3">Rx</div>
                    
                    <!-- Medications List -->
                    <div class="mb-6 text-xs space-y-3">
                        <?php if (empty($prescriptions)): ?>
                            <div class="italic">No medications prescribed.</div>
                        <?php else: ?>
                            <?php foreach ($prescriptions as $pres): ?>
                                <div class="border-b border-dashed border-black pb-2">
                                    <div class="font-bold text-sm mb-0.5"><?php echo htmlspecialchars($pres['medicine_name']); ?></div>
                                    <div><strong>Dosage:</strong> <?php echo htmlspecialchars($pres['dosage']); ?></div>
                                    <div><strong>Duration:</strong> <?php echo htmlspecialchars($pres['duration']); ?></div>
                                    <?php if (!empty($pres['instructions'])): ?>
                                        <div class="italic text-[10px] mt-0.5">Instructions: <?php echo htmlspecialchars($pres['instructions']); ?></div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <!-- Diagnoses Summary -->
                    <?php if (!empty($diagnoses)): ?>
                        <div class="mb-6 text-xs border-t border-black pt-3">
                            <span class="font-bold">Diagnoses (ICD-10):</span>
                            <span class="ml-1">
                                <?php 
                                $diagList = [];
                                foreach ($diagnoses as $d) {
                                    $diagList[] = htmlspecialchars($d['icd_code'] . ' - ' . $d['description']);
                                }
                                echo implode(', ', $diagList);
                                ?>
                            </span>
                        </div>
                    <?php endif; ?>

                    <!-- Footer / Signature Line -->
                    <div class="grid grid-cols-2 pt-6 mt-6 border-t border-black items-end text-xs">
                        <div>
                            <div>MedCore Clinic Electronic Record</div>
                            <div>For validation, contact MedCore support.</div>
                        </div>
                        <div class="text-right">
                            <div class="border-b border-black mx-auto w-36 mb-1"></div>
                            <div class="text-[9px] uppercase tracking-wide">Physician Signature</div>
                            <div class="font-bold text-sm mt-0.5"><?php echo htmlspecialchars($consultation['doctor_name']); ?></div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </main>

    <!-- Detailed Case Sheet Print Area -->
    <div id="caseSheetPrintArea" class="bg-white" style="font-family: 'Inter', sans-serif; color: #1F2937; max-width: 800px; margin: 0 auto; padding: 0;">
        
        <!-- PAGE 1 -->
        <div class="print-page" style="padding: 15px; box-sizing: border-box; page-break-after: always; display: flex; flex-direction: column;">
            
            <!-- Branding Header -->
            <div style="display: flex; align-items: center; justify-content: space-between; border-bottom: 1.5px solid #000000; padding-bottom: 8px; margin-bottom: 8px;">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <!-- Logo Box -->
                    <div style="background-color: #4F7CAC; width: 65px; height: 65px; display: flex; flex-direction: column; justify-content: center; align-items: center; padding: 4px; border-radius: 6px; flex-shrink: 0; box-sizing: border-box; color: white;">
                        <div style="font-family: 'Lora', 'Georgia', serif; font-size: 10px; font-weight: bold; text-align: center; line-height: 1.1; letter-spacing: 0.05em;">MedCore</div>
                        <div style="font-family: 'Inter', sans-serif; font-size: 4px; text-transform: uppercase; letter-spacing: 0.1em; border-top: 0.5px solid rgba(255,255,255,0.4); padding-top: 2px; margin-top: 2px;">Medical Center</div>
                    </div>
                    <!-- Company info -->
                    <div style="font-family: 'Inter', sans-serif; font-size: 10px; color: #1F2937; line-height: 1.3;">
                        <div style="font-weight: bold; font-size: 11px; color: #000000; line-height: 1.2;">MedCore Medical Center</div>
                        <div>Madinat Khalifa, 6, Madinat Khalifa</div>
                        <div>Abu Dhabi, UAE</div>
                        <div style="font-weight: bold; margin-top: 1px;">Phone: 80076852</div>
                    </div>
                </div>
                <div style="text-align: right; font-family: 'Inter', sans-serif; font-size: 10px; color: #4B5563; line-height: 1.3;">
                    <div style="font-weight: bold; font-size: 11px; color: #000000;">CLINICAL CASE SHEET</div>
                    <div>Date: <?php echo date('d-M-Y', strtotime($consultation['appointment_date'])); ?></div>
                </div>
            </div>

            <!-- Centered Header -->
            <div style="text-align: center; font-weight: bold; font-size: 13px; margin: 8px 0; letter-spacing: 0.08em; text-transform: uppercase; border-bottom: 1.5px solid #000000; padding-bottom: 4px;">
                MEDICAL REPORT
            </div>

            <!-- PATIENT DETAILS Table -->
            <div style="font-weight: bold; background-color: #EFEFEF; padding: 4px 6px; font-size: 10px; border: 1px solid #D1D5DB; border-bottom: none; text-transform: uppercase; letter-spacing: 0.05em; color: #1F2937;">
                PATIENT DETAILS
            </div>
            <table style="width: 100%; border-collapse: collapse; border: 1px solid #D1D5DB; font-size: 10px; margin-bottom: 10px;">
                <tr style="border-bottom: 1px solid #D1D5DB;">
                    <td style="width: 18%; font-weight: bold; background-color: #F9FAFB; padding: 5px; border-right: 1px solid #D1D5DB; color: #4B5563;">Patient's Name</td>
                    <td style="width: 42%; padding: 5px; border-right: 1px solid #D1D5DB; font-weight: bold; color: #1F2937;" colspan="3"><?php echo htmlspecialchars($consultation['patient_name']); ?></td>
                    <td style="width: 18%; font-weight: bold; background-color: #F9FAFB; padding: 5px; border-right: 1px solid #D1D5DB; color: #4B5563;">File Opened By :</td>
                    <td style="width: 22%; padding: 5px; color: #1F2937;">System Admin</td>
                </tr>
                <tr style="border-bottom: 1px solid #D1D5DB;">
                    <td style="font-weight: bold; background-color: #F9FAFB; padding: 5px; border-right: 1px solid #D1D5DB; color: #4B5563;">Reg. No</td>
                    <td style="padding: 5px; border-right: 1px solid #D1D5DB; color: #1F2937;">CBCR<?php echo 30000 + $consultation['patient_id']; ?></td>
                    <td style="font-weight: bold; background-color: #F9FAFB; padding: 5px; border-right: 1px solid #D1D5DB; color: #4B5563;">Visit Date</td>
                    <td style="padding: 5px; border-right: 1px solid #D1D5DB; color: #1F2937;" colspan="3"><?php echo date('l M d, Y', strtotime($consultation['appointment_date'])); ?></td>
                </tr>
                <tr style="border-bottom: 1px solid #D1D5DB;">
                    <td style="font-weight: bold; background-color: #F9FAFB; padding: 5px; border-right: 1px solid #D1D5DB; color: #4B5563;">Age / Sex</td>
                    <td style="padding: 5px; border-right: 1px solid #D1D5DB; color: #1F2937;"><?php echo $patient_age; ?> / <?php echo htmlspecialchars($consultation['gender']); ?></td>
                    <td style="font-weight: bold; background-color: #F9FAFB; padding: 5px; border-right: 1px solid #D1D5DB; color: #4B5563;">Date of Birth</td>
                    <td style="padding: 5px; border-right: 1px solid #D1D5DB; color: #1F2937;" colspan="3"><?php echo date('l M d, Y', strtotime($consultation['dob'])); ?></td>
                </tr>
                <tr style="border-bottom: 1px solid #D1D5DB;">
                    <td style="font-weight: bold; background-color: #F9FAFB; padding: 5px; border-right: 1px solid #D1D5DB; color: #4B5563;">Contact No</td>
                    <td style="padding: 5px; border-right: 1px solid #D1D5DB; color: #1F2937;">6651615</td>
                    <td style="font-weight: bold; background-color: #F9FAFB; padding: 5px; border-right: 1px solid #D1D5DB; color: #4B5563;">Email Id</td>
                    <td style="padding: 5px; border-right: 1px solid #D1D5DB; color: #1F2937;" colspan="3"><?php echo strtolower(str_replace(' ', '', $consultation['patient_name'])) . '@gmail.com'; ?></td>
                </tr>
                <tr>
                    <td style="font-weight: bold; background-color: #F9FAFB; padding: 5px; border-right: 1px solid #D1D5DB; color: #4B5563;">Location</td>
                    <td style="padding: 5px; border-right: 1px solid #D1D5DB; color: #1F2937;">ABU DHABI</td>
                    <td style="font-weight: bold; background-color: #F9FAFB; padding: 5px; border-right: 1px solid #D1D5DB; color: #4B5563;">Nationality</td>
                    <td style="padding: 5px; color: #1F2937;" colspan="3">United Arab Emirates</td>
                </tr>
            </table>

            <!-- CONSULTATION DETAILS Table -->
            <div style="font-weight: bold; background-color: #EFEFEF; padding: 4px 6px; font-size: 10px; border: 1px solid #D1D5DB; border-bottom: none; text-transform: uppercase; letter-spacing: 0.05em; color: #1F2937;">
                CONSULTATION DETAILS
            </div>
            <table style="width: 100%; border-collapse: collapse; border: 1px solid #D1D5DB; font-size: 10px; margin-bottom: 10px;">
                <tr>
                    <td style="width: 18%; font-weight: bold; background-color: #F9FAFB; padding: 5px; border-right: 1px solid #D1D5DB; color: #4B5563;">Doctor</td>
                    <td style="width: 42%; padding: 5px; border-right: 1px solid #D1D5DB; font-weight: bold; color: #1F2937;"><?php echo htmlspecialchars($consultation['doctor_name']); ?></td>
                    <td style="width: 18%; font-weight: bold; background-color: #F9FAFB; padding: 5px; border-right: 1px solid #D1D5DB; color: #4B5563;">Department</td>
                    <td style="width: 22%; padding: 5px; color: #1F2937;">General Retouch</td>
                </tr>
            </table>

            <!-- CHIEF COMPLAINTS / HISTORY OF PRESENT ILLNESS Table -->
            <div style="font-weight: bold; background-color: #EFEFEF; padding: 4px 6px; font-size: 10px; border: 1px solid #D1D5DB; border-bottom: none; text-transform: uppercase; letter-spacing: 0.05em; color: #1F2937;">
                CHIEF COMPLAINTS / HISTORY OF PRESENT ILLNESS
            </div>
            <table style="width: 100%; border-collapse: collapse; border: 1px solid #D1D5DB; font-size: 10px; margin-bottom: 10px;">
                <tr style="border-bottom: 1px solid #D1D5DB;">
                    <td style="width: 18%; font-weight: bold; background-color: #F9FAFB; padding: 5px; border-right: 1px solid #D1D5DB; color: #4B5563;">Chief Complaint</td>
                    <td style="width: 82%; padding: 5px; color: #1F2937;"><?php echo nl2br(htmlspecialchars($consultation['chief_complaint'] ?? 'None')); ?></td>
                </tr>
                <tr style="border-bottom: 1px solid #D1D5DB;">
                    <td style="font-weight: bold; background-color: #F9FAFB; padding: 5px; border-right: 1px solid #D1D5DB; color: #4B5563;">Pain Scale</td>
                    <td style="padding: 5px; color: #1F2937;"><?php echo htmlspecialchars($consultation['pain_scale_type'] ?? 'None'); ?></td>
                </tr>
                <tr style="border-bottom: 1px solid #D1D5DB;">
                    <td style="font-weight: bold; background-color: #F9FAFB; padding: 5px; border-right: 1px solid #D1D5DB; color: #4B5563;">Location</td>
                    <td style="padding: 5px; color: #1F2937;"><?php echo htmlspecialchars($consultation['hpi_location'] ?? 'None'); ?></td>
                </tr>
                <tr style="border-bottom: 1px solid #D1D5DB;">
                    <td style="font-weight: bold; background-color: #F9FAFB; padding: 5px; border-right: 1px solid #D1D5DB; color: #4B5563;">Quality</td>
                    <td style="padding: 5px; color: #1F2937;"><?php echo htmlspecialchars($consultation['hpi_quality'] ?? 'None'); ?></td>
                </tr>
                <tr style="border-bottom: 1px solid #D1D5DB;">
                    <td style="font-weight: bold; background-color: #F9FAFB; padding: 5px; border-right: 1px solid #D1D5DB; color: #4B5563;">Duration</td>
                    <td style="padding: 5px; color: #1F2937;"><?php echo htmlspecialchars($consultation['hpi_duration'] ?? 'None'); ?></td>
                </tr>
                <tr style="border-bottom: 1px solid #D1D5DB;">
                    <td style="font-weight: bold; background-color: #F9FAFB; padding: 5px; border-right: 1px solid #D1D5DB; color: #4B5563;">Timing</td>
                    <td style="padding: 5px; color: #1F2937;"><?php echo htmlspecialchars($consultation['hpi_timing'] ?? 'None'); ?></td>
                </tr>
                <tr style="border-bottom: 1px solid #D1D5DB;">
                    <td style="font-weight: bold; background-color: #F9FAFB; padding: 5px; border-right: 1px solid #D1D5DB; color: #4B5563;">Context</td>
                    <td style="padding: 5px; color: #1F2937;"><?php echo htmlspecialchars($consultation['hpi_context'] ?? 'None'); ?></td>
                </tr>
                <tr>
                    <td style="font-weight: bold; background-color: #F9FAFB; padding: 5px; border-right: 1px solid #D1D5DB; color: #4B5563;">Modifying Factor</td>
                    <td style="padding: 5px; color: #1F2937;"><?php echo htmlspecialchars($consultation['hpi_modifying_factor'] ?? 'None'); ?></td>
                </tr>
            </table>

            <!-- VITAL SIGNS Table -->
            <div style="font-weight: bold; background-color: #EFEFEF; padding: 4px 6px; font-size: 10px; border: 1px solid #D1D5DB; border-bottom: none; text-transform: uppercase; letter-spacing: 0.05em; color: #1F2937;">
                VITAL SIGNS
            </div>
            <table style="width: 100%; border-collapse: collapse; border: 1px solid #D1D5DB; font-size: 10px; margin-bottom: 10px;">
                <tr style="background-color: #F9FAFB; border-bottom: 1px solid #D1D5DB;">
                    <td colspan="6" style="font-weight: bold; padding: 5px; color: #4B5563; font-size: 9px;">Entered By-<?php echo htmlspecialchars($consultation['doctor_name']); ?></td>
                </tr>
                <?php
                $bp_parts = explode('/', $consultation['blood_pressure'] ?? '120/80');
                $sys = $bp_parts[0] ?? '120';
                $dia = $bp_parts[1] ?? '80';
                ?>
                <tr style="border-bottom: 1px solid #D1D5DB;">
                    <td style="width: 18%; font-weight: bold; background-color: #F9FAFB; padding: 5px; border-right: 1px solid #D1D5DB; color: #4B5563;">Temperature</td>
                    <td style="width: 15%; padding: 5px; border-right: 1px solid #D1D5DB; color: #1F2937;"><?php echo htmlspecialchars($consultation['temperature'] ?? '36.8'); ?> DegC</td>
                    <td style="width: 18%; font-weight: bold; background-color: #F9FAFB; padding: 5px; border-right: 1px solid #D1D5DB; color: #4B5563;">B.P (Systolic)</td>
                    <td style="width: 15%; padding: 5px; border-right: 1px solid #D1D5DB; color: #1F2937;"><?php echo htmlspecialchars($sys); ?> mmHg</td>
                    <td style="width: 18%; font-weight: bold; background-color: #F9FAFB; padding: 5px; border-right: 1px solid #D1D5DB; color: #4B5563;">B.P (Diastolic)</td>
                    <td style="width: 16%; padding: 5px; color: #1F2937;"><?php echo htmlspecialchars($dia); ?> mmHg</td>
                </tr>
                <tr style="border-bottom: 1px solid #D1D5DB;">
                    <td style="font-weight: bold; background-color: #F9FAFB; padding: 5px; border-right: 1px solid #D1D5DB; color: #4B5563;">Pulse</td>
                    <td style="padding: 5px; border-right: 1px solid #D1D5DB; color: #1F2937;"><?php echo htmlspecialchars($consultation['heart_rate'] ?? '72'); ?> bpm</td>
                    <td style="font-weight: bold; background-color: #F9FAFB; padding: 5px; border-right: 1px solid #D1D5DB; color: #4B5563;">Respiratory</td>
                    <td style="padding: 5px; border-right: 1px solid #D1D5DB; color: #1F2937;"><?php echo htmlspecialchars($consultation['respiratory_rate'] ?? '18'); ?> bpm</td>
                    <td style="font-weight: bold; background-color: #F9FAFB; padding: 5px; border-right: 1px solid #D1D5DB; color: #4B5563;">O2 Saturation</td>
                    <td style="padding: 5px; color: #1F2937;"><?php echo htmlspecialchars($consultation['oxygen_saturation'] ?? '98'); ?> %</td>
                </tr>
                <tr>
                    <td style="font-weight: bold; background-color: #F9FAFB; padding: 5px; border-right: 1px solid #D1D5DB; color: #4B5563;">Height</td>
                    <td style="padding: 5px; border-right: 1px solid #D1D5DB; color: #1F2937;"><?php echo htmlspecialchars($consultation['height'] ?? '160'); ?> cm</td>
                    <td style="font-weight: bold; background-color: #F9FAFB; padding: 5px; border-right: 1px solid #D1D5DB; color: #4B5563;">Weight</td>
                    <td style="padding: 5px; border-right: 1px solid #D1D5DB; color: #1F2937;"><?php echo htmlspecialchars($consultation['weight'] ?? '70'); ?> kg</td>
                    <td style="font-weight: bold; background-color: #F9FAFB; padding: 5px; border-right: 1px solid #D1D5DB; color: #4B5563;">BMI</td>
                    <td style="padding: 5px; color: #1F2937; font-weight: bold;"><?php echo htmlspecialchars($bmi_val); ?> kg/m2</td>
                </tr>
            </table>

            <!-- REVIEW OF SYSTEMS Table -->
            <div style="font-weight: bold; background-color: #EFEFEF; padding: 4px 6px; font-size: 10px; border: 1px solid #D1D5DB; border-bottom: none; text-transform: uppercase; letter-spacing: 0.05em; color: #1F2937;">
                REVIEW OF SYSTEMS
            </div>
            <table style="width: 100%; border-collapse: collapse; border: 1px solid #D1D5DB; font-size: 10px; margin-bottom: 10px;">
                <tbody>
                    <?php foreach ($ros_systems as $key => $label): ?>
                        <tr style="border-bottom: 1px solid #D1D5DB;">
                            <td style="width: 30%; padding: 5px; border-right: 1px solid #D1D5DB; font-weight: bold; color: #4B5563; background-color: #F9FAFB;"><?php echo $label; ?></td>
                            <td style="width: 70%; padding: 5px; color: #1F2937;"><?php echo htmlspecialchars($ros_data[$key] ?? 'No Complaints'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <!-- CLINICAL EXAMINATION Table -->
            <div style="font-weight: bold; background-color: #EFEFEF; padding: 4px 6px; font-size: 10px; border: 1px solid #D1D5DB; border-bottom: none; text-transform: uppercase; letter-spacing: 0.05em; color: #1F2937;">
                CLINICAL EXAMINATION
            </div>
            <table style="width: 100%; border-collapse: collapse; border: 1px solid #D1D5DB; font-size: 10px; margin-bottom: 10px;">
                <tr style="border-bottom: 1px solid #D1D5DB;">
                    <td style="width: 30%; padding: 5px; border-right: 1px solid #D1D5DB; font-weight: bold; color: #4B5563; background-color: #F9FAFB;">General</td>
                    <td style="width: 70%; padding: 5px; color: #1F2937;"><?php echo htmlspecialchars($exam_data['general'] ?? 'Normal'); ?></td>
                </tr>
                <tr style="border-bottom: 1px solid #D1D5DB;">
                    <td style="padding: 5px; border-right: 1px solid #D1D5DB; font-weight: bold; color: #4B5563; background-color: #F9FAFB;">Skin</td>
                    <td style="padding: 5px; color: #1F2937;"><?php echo htmlspecialchars($exam_data['skin'] ?? 'Normal'); ?></td>
                </tr>
                <tr style="background-color: #EFEFEF; border-bottom: 1px solid #D1D5DB;">
                    <td colspan="2" style="font-weight: bold; padding: 4px 6px; font-size: 10px; color: #1F2937;">EXAMINATION NOTES</td>
                </tr>
                <tr>
                    <td colspan="2" style="padding: 6px; color: #1F2937; line-height: 1.4;">
                        <?php echo !empty($exam_data['notes']) ? nl2br(htmlspecialchars($exam_data['notes'])) : 'None recorded'; ?>
                    </td>
                </tr>
            </table>

            <!-- Page 1 Footer -->
            <div style="margin-top: auto; border-top: 1.5px solid #000000; padding-top: 6px; font-size: 8px; color: #4B5563; font-family: sans-serif;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2px; font-weight: bold;">
                    <div>Printed Date: <?php echo date('d-m-Y H:i'); ?></div>
                    <div>Page 1 of 2</div>
                </div>
                <div style="border-top: 0.5px solid #E5E7EB; padding-top: 2px; display: flex; justify-content: space-between; line-height: 1.2;">
                    <div>Madinat Khalifa, 6, Madinat Khalifa - Abu Dhabi - T: 80076852<br>Please note that all payments made are non-refundable</div>
                    <div style="text-align: right;">Email: info@medcore.ae , www.medcore.ae</div>
                </div>
            </div>

        </div>

        <!-- PAGE 2 -->
        <div class="print-page" style="padding: 15px; box-sizing: border-box; display: flex; flex-direction: column;">
            
            <!-- Branding Header (Same as Page 1) -->
            <div style="display: flex; align-items: center; justify-content: space-between; border-bottom: 1.5px solid #000000; padding-bottom: 8px; margin-bottom: 8px;">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <!-- Logo Box -->
                    <div style="background-color: #4F7CAC; width: 65px; height: 65px; display: flex; flex-direction: column; justify-content: center; align-items: center; padding: 4px; border-radius: 6px; flex-shrink: 0; box-sizing: border-box; color: white;">
                        <div style="font-family: 'Lora', 'Georgia', serif; font-size: 10px; font-weight: bold; text-align: center; line-height: 1.1; letter-spacing: 0.05em;">MedCore</div>
                        <div style="font-family: 'Inter', sans-serif; font-size: 4px; text-transform: uppercase; letter-spacing: 0.1em; border-top: 0.5px solid rgba(255,255,255,0.4); padding-top: 2px; margin-top: 2px;">Medical Center</div>
                    </div>
                    <!-- Company info -->
                    <div style="font-family: 'Inter', sans-serif; font-size: 10px; color: #1F2937; line-height: 1.3;">
                        <div style="font-weight: bold; font-size: 11px; color: #000000; line-height: 1.2;">MedCore Medical Center</div>
                        <div>Madinat Khalifa, 6, Madinat Khalifa</div>
                        <div>Abu Dhabi, UAE</div>
                        <div style="font-weight: bold; margin-top: 1px;">Phone: 80076852</div>
                    </div>
                </div>
                <div style="text-align: right; font-family: 'Inter', sans-serif; font-size: 10px; color: #4B5563; line-height: 1.3;">
                    <div style="font-weight: bold; font-size: 11px; color: #000000;">CLINICAL CASE SHEET</div>
                    <div>Date: <?php echo date('d-M-Y', strtotime($consultation['appointment_date'])); ?></div>
                </div>
            </div>

            <!-- Centered Header -->
            <div style="text-align: center; font-weight: bold; font-size: 13px; margin: 8px 0; letter-spacing: 0.08em; text-transform: uppercase; border-bottom: 1.5px solid #000000; padding-bottom: 4px;">
                TREATMENT PLAN &amp; CLINICAL DIAGNOSIS
            </div>

            <!-- NURSE NOTES Section -->
            <div style="font-weight: bold; background-color: #EFEFEF; padding: 4px 6px; font-size: 10px; border: 1px solid #D1D5DB; border-bottom: none; text-transform: uppercase; letter-spacing: 0.05em; color: #1F2937;">
                NURSE NOTES
            </div>
            <table style="width: 100%; border-collapse: collapse; border: 1px solid #D1D5DB; font-size: 10px; margin-bottom: 10px;">
                <tr>
                    <td style="width: 70%; padding: 8px; vertical-align: top; color: #1F2937; line-height: 1.5; white-space: pre-wrap; border-right: 1px solid #D1D5DB;"><?php 
                        echo !empty($consultation['nurse_notes']) ? htmlspecialchars($consultation['nurse_notes']) : 'No nurse care plan or observations recorded.'; 
                    ?></td>
                    <td style="width: 30%; padding: 8px; vertical-align: bottom; text-align: center; color: #1F2937; font-weight: bold; font-family: 'Inter', sans-serif;">
                        <?php 
                        $nurse_name = '';
                        if (!empty($nursing_plan['advisory_by'])) {
                            $nurse_name = $nursing_plan['advisory_by'];
                        } elseif (!empty($nursing_plan['prep_nurse'])) {
                            $nurse_name = $nursing_plan['prep_nurse'];
                        }
                        if ($nurse_name !== '') {
                            echo htmlspecialchars($nurse_name);
                        } else {
                            echo '<span style="color: #9CA3AF; font-style: italic;">Not signed</span>';
                        }
                        ?>
                    </td>
                </tr>
            </table>

            <!-- NARRATIVE DIAGNOSIS Table -->
            <div style="font-weight: bold; background-color: #EFEFEF; padding: 4px 6px; font-size: 10px; border: 1px solid #D1D5DB; border-bottom: none; text-transform: uppercase; letter-spacing: 0.05em; color: #1F2937; margin-top: 5px;">
                NARRATIVE DIAGNOSIS
            </div>
            <table style="width: 100%; border-collapse: collapse; border: 1px solid #D1D5DB; font-size: 10px; margin-bottom: 10px;">
                <tr>
                    <td style="padding: 6px; color: #1F2937; line-height: 1.4;">
                        <?php echo !empty($consultation['narrative_diagnosis']) ? nl2br(htmlspecialchars($consultation['narrative_diagnosis'])) : 'None recorded'; ?>
                    </td>
                </tr>
            </table>

            <!-- FINAL DIAGNOSIS Table -->
            <div style="font-weight: bold; background-color: #EFEFEF; padding: 4px 6px; font-size: 10px; border: 1px solid #D1D5DB; border-bottom: none; text-transform: uppercase; letter-spacing: 0.05em; color: #1F2937; margin-top: 5px;">
                FINAL DIAGNOSIS
            </div>
            <table style="width: 100%; border-collapse: collapse; border: 1px solid #D1D5DB; font-size: 10px; margin-bottom: 10px;">
                <tbody>
                    <?php if (empty($diagnoses)): ?>
                        <tr>
                            <td style="padding: 6px; color: #6B7280; font-style: italic;">No final diagnosis recorded.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($diagnoses as $index => $diag): ?>
                            <tr style="border-bottom: 1px solid #D1D5DB;">
                                <td style="padding: 6px; color: #1F2937; font-weight: 500;">
                                    <?php 
                                    $prefix = ($index === 0) ? '[PRIMARY DIAGNOSIS]' : '[SECONDARY DIAGNOSIS]';
                                    echo htmlspecialchars($diag['icd_code'] . ' - ' . $diag['description'] . ' ' . $prefix); 
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <!-- PAST MEDICAL & SURGICAL HISTORY Table -->
            <?php if (!empty($consultation['medical_history']) || !empty($consultation['surgical_history']) || !empty($consultation['family_history']) || !empty($consultation['social_history'])): ?>
                <div style="font-weight: bold; background-color: #EFEFEF; padding: 4px 6px; font-size: 10px; border: 1px solid #D1D5DB; border-bottom: none; text-transform: uppercase; letter-spacing: 0.05em; color: #1F2937; margin-top: 5px;">
                    PAST MEDICAL &amp; SURGICAL HISTORY
                </div>
                <table style="width: 100%; border-collapse: collapse; border: 1px solid #D1D5DB; font-size: 10px; margin-bottom: 10px;">
                    <tbody>
                        <?php if (!empty($consultation['referred_by'])): ?>
                        <tr style="border-bottom: 1px solid #D1D5DB;">
                            <td style="width: 30%; padding: 5px; border-right: 1px solid #D1D5DB; font-weight: bold; color: #4B5563; background-color: #F9FAFB;">Referred By</td>
                            <td style="width: 70%; padding: 5px; color: #1F2937;"><?php echo htmlspecialchars($consultation['referred_by']); ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if (!empty($consultation['medical_history'])): ?>
                        <tr style="border-bottom: 1px solid #D1D5DB;">
                            <td style="width: 30%; padding: 5px; border-right: 1px solid #D1D5DB; font-weight: bold; color: #4B5563; background-color: #F9FAFB;">Medical History</td>
                            <td style="width: 70%; padding: 5px; color: #1F2937;"><?php echo nl2br(htmlspecialchars($consultation['medical_history'])); ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if (!empty($consultation['surgical_history'])): ?>
                        <tr style="border-bottom: 1px solid #D1D5DB;">
                            <td style="padding: 5px; border-right: 1px solid #D1D5DB; font-weight: bold; color: #4B5563; background-color: #F9FAFB;">Surgical History</td>
                            <td style="padding: 5px; color: #1F2937;"><?php echo nl2br(htmlspecialchars($consultation['surgical_history'])); ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if (!empty($consultation['family_history'])): ?>
                        <tr style="border-bottom: 1px solid #D1D5DB;">
                            <td style="padding: 5px; border-right: 1px solid #D1D5DB; font-weight: bold; color: #4B5563; background-color: #F9FAFB;">Family History</td>
                            <td style="padding: 5px; color: #1F2937;"><?php echo nl2br(htmlspecialchars($consultation['family_history'])); ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if (!empty($consultation['social_history'])): ?>
                        <tr>
                            <td style="padding: 5px; border-right: 1px solid #D1D5DB; font-weight: bold; color: #4B5563; background-color: #F9FAFB;">Social History</td>
                            <td style="padding: 5px; color: #1F2937;"><?php echo nl2br(htmlspecialchars($consultation['social_history'])); ?></td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <!-- MEDICATIONS List -->
            <?php if (!empty($prescriptions)): ?>
                <div style="font-weight: bold; background-color: #EFEFEF; padding: 4px 6px; font-size: 10px; border: 1px solid #D1D5DB; border-bottom: none; text-transform: uppercase; letter-spacing: 0.05em; color: #1F2937; margin-top: 5px;">
                    PRESCRIBED MEDICATIONS (Rx)
                </div>
                <div style="border: 1px solid #D1D5DB; font-size: 10px; padding: 10px; margin-bottom: 10px; line-height: 1.5; color: #1F2937; background-color: #FFFFFF;">
                    <?php foreach ($prescriptions as $index => $pres): ?>
                        <div style="<?php echo $index > 0 ? 'border-top: 1px dashed #D1D5DB; padding-top: 6px; margin-top: 6px;' : ''; ?>">
                            <div style="font-weight: bold; font-size: 11px;"><?php echo htmlspecialchars($pres['medicine_name']); ?></div>
                            <div style="margin-top: 2px;">
                                <strong>Dosage / Frequency:</strong> <?php echo htmlspecialchars($pres['dosage']); ?> &bull;
                                <strong>Duration:</strong> <?php echo htmlspecialchars($pres['duration']); ?> &bull;
                                <strong>Instructions:</strong> <?php echo htmlspecialchars($pres['instructions'] ?? 'No special instructions'); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- LAB TESTS Table -->
            <?php if (!empty($lab_tests)): ?>
                <div style="font-weight: bold; background-color: #EFEFEF; padding: 4px 6px; font-size: 10px; border: 1px solid #D1D5DB; border-bottom: none; text-transform: uppercase; letter-spacing: 0.05em; color: #1F2937; margin-top: 5px;">
                    DIAGNOSTIC LAB TESTS
                </div>
                <table style="width: 100%; border-collapse: collapse; border: 1px solid #D1D5DB; font-size: 10px; margin-bottom: 10px;">
                    <thead>
                        <tr style="background-color: #F9FAFB; border-bottom: 1px solid #D1D5DB; text-align: left;">
                            <th style="padding: 5px; border-right: 1px solid #D1D5DB; width: 35%; color: #4B5563; font-weight: bold;">Test Name</th>
                            <th style="padding: 5px; border-right: 1px solid #D1D5DB; width: 15%; color: #4B5563; font-weight: bold;">Priority</th>
                            <th style="padding: 5px; color: #4B5563; font-weight: bold;">Results / Summary</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($lab_tests as $test): ?>
                            <tr style="border-bottom: 1px solid #D1D5DB; vertical-align: top;">
                                <td style="padding: 5px; border-right: 1px solid #D1D5DB; font-weight: bold; color: #1F2937;"><?php echo htmlspecialchars($test['test_name']); ?></td>
                                <td style="padding: 5px; border-right: 1px solid #D1D5DB; color: #4B5563;"><?php echo htmlspecialchars($test['priority'] ?? 'Routine'); ?></td>
                                <td style="padding: 5px; font-size: 9px; color: #4B5563; white-space: pre-wrap;"><?php echo htmlspecialchars($test['result_summary']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <!-- Signature/Stamp Block -->
            <div style="margin-top: 20px; border-top: 1px solid #E5E7EB; padding-top: 12px; display: flex; justify-content: space-between; align-items: flex-end;">
                <div>
                    <!-- Circular Doctor Stamp -->
                    <div style="width: 70px; height: 70px; border: 2px dashed #1E40AF; border-radius: 50%; display: flex; flex-direction: column; justify-content: center; align-items: center; opacity: 0.8; transform: rotate(-8deg); margin-bottom: 4px; box-sizing: border-box;">
                        <div style="font-size: 7px; font-weight: bold; color: #1E40AF; font-family: monospace; text-transform: uppercase; line-height: 1;">MedCore</div>
                        <div style="font-size: 6px; color: #1E40AF; font-family: sans-serif; text-align: center; font-weight: bold; line-height: 1; margin: 1px 0;">CLINIC<br>APPROVED</div>
                        <div style="font-size: 5px; color: #1E40AF; font-family: monospace;"><?php echo date('d-m-Y'); ?></div>
                    </div>
                </div>
                <div style="text-align: right; font-family: 'Inter', sans-serif; font-size: 10px; color: #1F2937;">
                    <div style="font-weight: bold; font-size: 11px; margin-bottom: 1px;">Dr. <?php echo htmlspecialchars($consultation['doctor_name']); ?></div>
                    <div style="color: #6B7280; font-size: 8px; text-transform: uppercase; letter-spacing: 0.05em;">Authorized Medical Signature</div>
                </div>
            </div>

            <!-- Page 2 Footer -->
            <div style="margin-top: auto; border-top: 1.5px solid #000000; padding-top: 6px; font-size: 8px; color: #4B5563; font-family: sans-serif;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2px; font-weight: bold;">
                    <div>Printed Date: <?php echo date('d-m-Y H:i'); ?></div>
                    <div>Page 2 of 2</div>
                </div>
                <div style="border-top: 0.5px solid #E5E7EB; padding-top: 2px; display: flex; justify-content: space-between; line-height: 1.2;">
                    <div>Madinat Khalifa, 6, Madinat Khalifa - Abu Dhabi - T: 80076852<br>Please note that all payments made are non-refundable</div>
                    <div style="text-align: right;">Email: info@medcore.ae , www.medcore.ae</div>
                </div>
            </div>

        </div>

    </div>

    <script>
        function startPrint(elementId) {
            // Remove any existing print container
            const existing = document.getElementById('printContainer');
            if (existing) existing.remove();

            // Create new print container
            const printContainer = document.createElement('div');
            printContainer.id = 'printContainer';
            
            // Clone the content of target element
            const target = document.getElementById(elementId);
            if (!target) return;
            printContainer.innerHTML = target.innerHTML;
            
            // Append to body
            document.body.appendChild(printContainer);
            
            // Trigger print
            document.body.classList.add('printing-active');
            window.print();
        }

        function printRxTicket() {
            startPrint('prescriptionPrintArea');
        }

        function printCaseSheet() {
            startPrint('caseSheetPrintArea');
        }

        window.addEventListener('afterprint', () => {
            document.body.classList.remove('printing-active');
            const pc = document.getElementById('printContainer');
            if (pc) pc.remove();
        });

        // Function to export Rx ticket data as an XML file
        function downloadRxXML() {
            function escapeXml(unsafe) {
                if (unsafe === null || unsafe === undefined) return '';
                return String(unsafe).replace(/[<>&'"]/g, function (c) {
                    switch (c) {
                        case '<': return '&lt;';
                        case '>': return '&gt;';
                        case '&': return '&amp;';
                        case '\'': return '&apos;';
                        case '"': return '&quot;';
                        default: return c;
                    }
                });
            }

            const patientId = "<?php echo htmlspecialchars($consultation['patient_id']); ?>";
            const patientName = "<?php echo htmlspecialchars($consultation['patient_name']); ?>";
            const age = "<?php echo $patient_age; ?>";
            const gender = "<?php echo htmlspecialchars($consultation['gender']); ?>";
            const doctorName = "<?php echo htmlspecialchars($consultation['doctor_name']); ?>";
            const doctorEmail = "<?php echo htmlspecialchars($consultation['doctor_email']); ?>";
            const date = "<?php echo date('Y-m-d', strtotime($consultation['appointment_date'])); ?>";
            const apptId = "<?php echo htmlspecialchars($appointment_id); ?>";
            const bp = "<?php echo htmlspecialchars($consultation['blood_pressure'] ?? 'N/A'); ?>";
            const hr = "<?php echo htmlspecialchars($consultation['heart_rate'] ?? 'N/A'); ?>";
            const temp = "<?php echo htmlspecialchars($consultation['temperature'] ?? 'N/A'); ?>";
            const weight = "<?php echo htmlspecialchars($consultation['weight'] ?? 'N/A'); ?>";
            const height = "<?php echo htmlspecialchars($consultation['height'] ?? 'N/A'); ?>";
            const respRate = "<?php echo htmlspecialchars($consultation['respiratory_rate'] ?? 'N/A'); ?>";
            const bmi = "<?php echo htmlspecialchars($bmi_val); ?>";
            const painScale = "<?php echo htmlspecialchars($consultation['pain_scale'] ?? 'N/A'); ?>";
            const allergy = "<?php echo htmlspecialchars($consultation['allergy_notes'] ?? ''); ?>";
            const rosData = <?php echo json_encode($ros_data); ?>;
            const examData = <?php echo json_encode($exam_data); ?>;
            
            let xml = '<?php echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>"; ?>\n';
            xml += '<prescription>\n';
            xml += '  <clinic>MedCore Clinic</clinic>\n';
            xml += `  <appointment_id>${apptId}</appointment_id>\n`;
            xml += `  <date>${date}</date>\n`;
            
            xml += '  <doctor>\n';
            xml += `    <name>${doctorName}</name>\n`;
            xml += `    <email>${doctorEmail}</email>\n`;
            xml += '  </doctor>\n';
            
            xml += '  <patient>\n';
            xml += `    <id>${patientId}</id>\n`;
            xml += `    <name>${patientName}</name>\n`;
            xml += `    <age>${age}</age>\n`;
            xml += `    <gender>${gender}</gender>\n`;
            xml += '  </patient>\n';
            
            xml += '  <vitals>\n';
            xml += `    <blood_pressure>${bp}</blood_pressure>\n`;
            xml += `    <heart_rate>${hr}</heart_rate>\n`;
            xml += `    <temperature>${temp}</temperature>\n`;
            xml += `    <weight>${weight}</weight>\n`;
            xml += `    <height>${height}</height>\n`;
            xml += `    <respiratory_rate>${respRate}</respiratory_rate>\n`;
            xml += `    <bmi>${bmi}</bmi>\n`;
            xml += `    <pain_scale>${painScale}</pain_scale>\n`;
            xml += '  </vitals>\n';

            xml += '  <review_of_systems>\n';
            const rosSystems = [
                'integumentary', 'constitutional', 'eyes', 'enmt', 'cardiovascular',
                'respiratory', 'gastrointestinal', 'genitourinary', 'musculoskeletal',
                'neurological', 'psychiatric', 'endocrine', 'hem_lymph', 'allergic_immuno'
            ];
            rosSystems.forEach(system => {
                const val = rosData[system] || 'No Complaints';
                xml += `    <${system}>${escapeXml(val)}</${system}>\n`;
            });
            xml += '  </review_of_systems>\n';

            xml += '  <clinical_examination>\n';
            xml += `    <general>${escapeXml(examData.general || 'Normal')}</general>\n`;
            xml += `    <skin>${escapeXml(examData.skin || 'Normal')}</skin>\n`;
            xml += `    <notes>${escapeXml(examData.notes || 'No examination notes recorded.')}</notes>\n`;
            xml += '  </clinical_examination>\n';
            
            if (allergy) {
                xml += `  <allergies>${allergy}</allergies>\n`;
            }
            
            xml += '  <diagnoses>\n';
            <?php foreach ($diagnoses as $d): ?>
            xml += '    <diagnosis>\n';
            xml += `      <code><?php echo htmlspecialchars($d['icd_code']); ?></code>\n`;
            xml += `      <description><?php echo htmlspecialchars($d['description']); ?></description>\n`;
            xml += '    </diagnosis>\n';
            <?php endforeach; ?>
            xml += '  </diagnoses>\n';
            
            xml += '  <medications>\n';
            <?php foreach ($prescriptions as $pres): ?>
            xml += '    <medication>\n';
            xml += `      <name><?php echo htmlspecialchars($pres['medicine_name']); ?></name>\n`;
            xml += `      <dosage><?php echo htmlspecialchars($pres['dosage']); ?></dosage>\n`;
            xml += `      <duration><?php echo htmlspecialchars($pres['duration']); ?></duration>\n`;
            xml += `      <instructions><?php echo htmlspecialchars($pres['instructions'] ?? ''); ?></instructions>\n`;
            xml += '    </medication>\n';
            <?php endforeach; ?>
            xml += '  </medications>\n';
            
            xml += '</prescription>';

            // Trigger download of XML file
            const blob = new Blob([xml], { type: 'application/xml' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `rx_ticket_${apptId}.xml`;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        }
    </script>

        </div>
    </main>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>

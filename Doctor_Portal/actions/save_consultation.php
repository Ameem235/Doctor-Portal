<?php
/**
 * Consultation Save Handler (Action)
 * 
 * Processes the clinical case sheet submission using database transactions.
 * Redirects back to the public dashboard or consultation forms.
 */

// Start session
session_start();

// Redirect if doctor is not authenticated
if (!isset($_SESSION['doctor_id'])) {
    header("Location: ../public/login.php");
    exit();
}

// Redirect if form was not submitted via POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['error_msg'] = "Invalid access method.";
    header("Location: ../public/dashboard.php");
    exit();
}

// Include database connection
require_once __DIR__ . '/../config/db.php';

$doctor_id = $_SESSION['doctor_id'];

// Helper function to allow filter_input mock in CLI tests
if (!function_exists('get_post_val')) {
    function get_post_val($key, $filter, $default = null) {
        $val = filter_input(INPUT_POST, $key, $filter);
        if ($val === null || $val === false) {
            if (isset($_POST[$key])) {
                $filtered = filter_var($_POST[$key], $filter);
                return $filtered !== false ? $filtered : $default;
            }
            return $default;
        }
        return $val;
    }
}

// Retrieve and validate appointment ID
$appointment_id = get_post_val('appointment_id', FILTER_VALIDATE_INT);
if (!$appointment_id) {
    $_SESSION['error_msg'] = "Invalid appointment context.";
    header("Location: ../public/dashboard.php");
    exit();
}

// Determine if doctor clicked Save Draft or Finalize
$submit_type = $_POST['submit_type'] ?? 'finalize';

try {
    // 1. Verify that the appointment exists, is assigned to this doctor, and is not Scheduled
    $stmt = $pdo->prepare("SELECT doctor_id, status, appointment_date FROM appointments WHERE appointment_id = ?");
    $stmt->execute([$appointment_id]);
    $appointment = $stmt->fetch();

    if (!$appointment) {
        throw new Exception("Appointment record not found.");
    }
    if ($appointment['doctor_id'] != $doctor_id) {
        throw new Exception("Unauthorized transaction: You are not the doctor assigned to this appointment.");
    }
    if ($appointment['status'] === 'Scheduled') {
        throw new Exception("The appointment must be accepted before recording a consultation.");
    }
    if ($appointment['status'] === 'Completed') {
        throw new Exception("Consultation has already been completed for this appointment.");
    }

    // 2. Retrieve Form Inputs
    $blood_pressure = trim($_POST['blood_pressure'] ?? '');
    $temperature = $_POST['temperature'] !== '' ? get_post_val('temperature', FILTER_VALIDATE_FLOAT) : null;
    $heart_rate = $_POST['heart_rate'] !== '' ? get_post_val('heart_rate', FILTER_VALIDATE_INT) : null;
    $weight = $_POST['weight'] !== '' ? get_post_val('weight', FILTER_VALIDATE_FLOAT) : null;
    $oxygen_saturation = $_POST['oxygen_saturation'] !== '' ? get_post_val('oxygen_saturation', FILTER_VALIDATE_INT) : null;
    $pain_scale = get_post_val('pain_scale', FILTER_VALIDATE_INT);
    $height = $_POST['height'] !== '' ? get_post_val('height', FILTER_VALIDATE_FLOAT) : null;
    $respiratory_rate = $_POST['respiratory_rate'] !== '' ? get_post_val('respiratory_rate', FILTER_VALIDATE_INT) : null;

    // Review of Systems (ROS) - 14 Systems
    $ros_fields = [
        'integumentary', 'constitutional', 'eyes', 'enmt', 'cardiovascular',
        'respiratory', 'gastrointestinal', 'genitourinary', 'musculoskeletal',
        'neurological', 'psychiatric', 'endocrine', 'hem_lymph', 'allergic_immuno'
    ];
    $ros_array = [];
    foreach ($ros_fields as $field) {
        $ros_array[$field] = trim($_POST['ros_' . $field] ?? 'No Complaints');
    }
    $ros_data = json_encode($ros_array);

    // Clinical Examination (General, Skin, Notes)
    $exam_array = [
        'general' => trim($_POST['exam_general'] ?? 'Normal'),
        'skin' => trim($_POST['exam_skin'] ?? 'Normal'),
        'notes' => trim($_POST['exam_notes'] ?? '')
    ];
    $exam_data = json_encode($exam_array);
    
    $allergy_notes = trim($_POST['allergy_notes'] ?? '');
    $referred_by = trim($_POST['referred_by'] ?? '');
    $medical_history = trim($_POST['medical_history'] ?? '');
    $family_history = trim($_POST['family_history'] ?? '');
    $social_history = trim($_POST['social_history'] ?? '');
    $surgical_history = trim($_POST['surgical_history'] ?? '');
    
    $chief_complaint = trim($_POST['chief_complaint'] ?? '');
    $pain_scale_type = trim($_POST['pain_scale_type'] ?? '');
    $hpi_location = trim($_POST['hpi_location'] ?? '');
    $hpi_quality = trim($_POST['hpi_quality'] ?? '');
    $hpi_duration = trim($_POST['hpi_duration'] ?? '');
    $hpi_timing = trim($_POST['hpi_timing'] ?? '');
    $hpi_context = trim($_POST['hpi_context'] ?? '');
    $hpi_modifying_factor = trim($_POST['hpi_modifying_factor'] ?? '');
    $physical_examination = trim($_POST['physical_examination'] ?? '');
    $narrative_diagnosis = trim($_POST['narrative_diagnosis'] ?? '');
    // Structured Clinical Treatment & Nursing Planning fields
    $nrs_target_procedure = trim($_POST['nrs_target_procedure'] ?? '');
    $nrs_exam_date = trim($_POST['nrs_exam_date'] ?? '');
    $nrs_exam_doctor = trim($_POST['nrs_exam_doctor'] ?? '');
    $nrs_exam_findings = trim($_POST['nrs_exam_findings'] ?? '');
    $nrs_exam_orders = trim($_POST['nrs_exam_orders'] ?? '');
    $nrs_exam_vitals_note = trim($_POST['nrs_exam_vitals_note'] ?? '');

    $prep_assessment_done = isset($_POST['prep_assessment_done']) ? true : false;
    $nrs_prep_skin_type = trim($_POST['nrs_prep_skin_type'] ?? '');
    $prep_procedure_explained = isset($_POST['prep_procedure_explained']) ? true : false;
    $prep_consent = isset($_POST['prep_consent']) ? true : false;
    $prep_goggles_provided = isset($_POST['prep_goggles_provided']) ? true : false;
    $prep_markings_shaving = isset($_POST['prep_markings_shaving']) ? true : false;
    $prep_id_verified = isset($_POST['prep_id_verified']) ? true : false;
    $prep_allergies_checked = isset($_POST['prep_allergies_checked']) ? true : false;
    $prep_fasting = isset($_POST['prep_fasting']) ? true : false;
    $prep_iv_access = isset($_POST['prep_iv_access']) ? true : false;
    $prep_positioning = isset($_POST['prep_positioning']) ? true : false;
    $prep_monitoring = isset($_POST['prep_monitoring']) ? true : false;
    $prep_emergency_kit = isset($_POST['prep_emergency_kit']) ? true : false;
    $prep_baseline_vitals = isset($_POST['prep_baseline_vitals']) ? true : false;
    $prep_labwork = isset($_POST['prep_labwork']) ? true : false;
    $nrs_prep_notes = trim($_POST['nrs_prep_notes'] ?? '');
    $nrs_prep_nurse = trim($_POST['nrs_prep_nurse'] ?? '');
    $nrs_prep_time = trim($_POST['nrs_prep_time'] ?? '');

    // Medications administered
    $nrs_med_names = $_POST['nrs_med_name'] ?? [];
    $nrs_med_doses = $_POST['nrs_med_dose'] ?? [];
    $nrs_med_routes = $_POST['nrs_med_route'] ?? [];
    $nrs_med_times = $_POST['nrs_med_time'] ?? [];
    $nrs_med_notes = $_POST['nrs_med_notes'] ?? [];
    $medications_given = [];
    for ($i = 0; $i < count($nrs_med_names); $i++) {
        $m_name = trim($nrs_med_names[$i] ?? '');
        if ($m_name === '') continue;
        $medications_given[] = [
            'name' => $m_name,
            'dose' => trim($nrs_med_doses[$i] ?? ''),
            'route' => trim($nrs_med_routes[$i] ?? 'Oral'),
            'time' => trim($nrs_med_times[$i] ?? ''),
            'notes' => trim($nrs_med_notes[$i] ?? '')
        ];
    }

    // Laser procedure parameters
    $nrs_param_areas = $_POST['nrs_param_area'] ?? [];
    $nrs_param_lasers = $_POST['nrs_param_laser'] ?? [];
    $nrs_param_fluences = $_POST['nrs_param_fluence'] ?? [];
    $nrs_param_spots = $_POST['nrs_param_spot'] ?? [];
    $nrs_param_pulses = $_POST['nrs_param_pulse'] ?? [];
    $nrs_param_notes = $_POST['nrs_param_notes'] ?? [];
    $procedure_parameters = [];
    for ($i = 0; $i < count($nrs_param_areas); $i++) {
        $p_area = trim($nrs_param_areas[$i] ?? '');
        if ($p_area === '') continue;
        $procedure_parameters[] = [
            'area' => $p_area,
            'laser' => trim($nrs_param_lasers[$i] ?? ''),
            'fluence' => trim($nrs_param_fluences[$i] ?? ''),
            'spot' => trim($nrs_param_spots[$i] ?? ''),
            'pulse' => trim($nrs_param_pulses[$i] ?? ''),
            'notes' => trim($nrs_param_notes[$i] ?? '')
        ];
    }

    $obs_procedure_done = isset($_POST['obs_procedure_done']) ? true : false;
    $obs_erythema_edema = isset($_POST['obs_erythema_edema']) ? true : false;
    $obs_no_complaints = isset($_POST['obs_no_complaints']) ? true : false;
    $obs_fucicort_applied = isset($_POST['obs_fucicort_applied']) ? true : false;
    $obs_fucidin_applied = isset($_POST['obs_fucidin_applied']) ? true : false;
    $obs_cold_compress = isset($_POST['obs_cold_compress']) ? true : false;

    $nrs_changes_performed = trim($_POST['nrs_changes_performed'] ?? '');
    $nrs_changes_response = trim($_POST['nrs_changes_response'] ?? '');
    $nrs_changes_location = trim($_POST['nrs_changes_location'] ?? '');
    $nrs_changes_time = trim($_POST['nrs_changes_time'] ?? '');
    $nrs_tolerance = trim($_POST['nrs_tolerance'] ?? '');
    $tolerance_val = $nrs_tolerance !== '' ? $nrs_tolerance : 'Not Tolerated';
    $nrs_tolerance_notes = trim($_POST['nrs_tolerance_notes'] ?? '');
    $nrs_post_vitals = trim($_POST['nrs_post_vitals'] ?? '');

    $adv_fall_prevention = isset($_POST['adv_fall_prevention']) ? true : false;
    $adv_medication_schedule = isset($_POST['adv_medication_schedule']) ? true : false;
    $adv_diet_restrictions = isset($_POST['adv_diet_restrictions']) ? true : false;
    $adv_activity_limits = isset($_POST['adv_activity_limits']) ? true : false;
    $adv_wound_care = isset($_POST['adv_wound_care']) ? true : false;
    $adv_red_flags = isset($_POST['adv_red_flags']) ? true : false;
    $adv_hydration = isset($_POST['adv_hydration']) ? true : false;
    $adv_followup_reminder = isset($_POST['adv_followup_reminder']) ? true : false;
    $adv_emergency_contact = isset($_POST['adv_emergency_contact']) ? true : false;
    $adv_no_self_medicate = isset($_POST['adv_no_self_medicate']) ? true : false;
    $nrs_advisory_notes = trim($_POST['nrs_advisory_notes'] ?? '');
    $nrs_advisory_by = trim($_POST['nrs_advisory_by'] ?? '');
    $nrs_advisory_time = trim($_POST['nrs_advisory_time'] ?? '');

    $nursing_plan_array = [
        'target_procedure' => $nrs_target_procedure,
        'exam_date' => $nrs_exam_date,
        'exam_doctor' => $nrs_exam_doctor,
        'exam_findings' => $nrs_exam_findings,
        'exam_orders' => $nrs_exam_orders,
        'exam_vitals_note' => $nrs_exam_vitals_note,
        'prep_assessment_done' => $prep_assessment_done,
        'prep_skin_type' => $nrs_prep_skin_type,
        'prep_checklist' => [
            'consent' => $prep_consent,
            'id_verified' => $prep_id_verified,
            'allergies_checked' => $prep_allergies_checked,
            'fasting' => $prep_fasting,
            'iv_access' => $prep_iv_access,
            'positioning' => $prep_positioning,
            'monitoring' => $prep_monitoring,
            'emergency_kit' => $prep_emergency_kit,
            'baseline_vitals' => $prep_baseline_vitals,
            'labwork' => $prep_labwork,
            'procedure_explained' => $prep_procedure_explained,
            'goggles_provided' => $prep_goggles_provided,
            'markings_shaving' => $prep_markings_shaving
        ],
        'prep_notes' => $nrs_prep_notes,
        'prep_nurse' => $nrs_prep_nurse,
        'prep_time' => $nrs_prep_time,
        'medications_given' => $medications_given,
        'procedure_parameters' => $procedure_parameters,
        'post_procedure_checklist' => [
            'procedure_done' => $obs_procedure_done,
            'erythema_edema' => $obs_erythema_edema,
            'no_complaints' => $obs_no_complaints,
            'fucicort_applied' => $obs_fucicort_applied,
            'fucidin_applied' => $obs_fucidin_applied,
            'cold_compress' => $obs_cold_compress
        ],
        'changes_performed' => $nrs_changes_performed,
        'changes_response' => $nrs_changes_response,
        'changes_location' => $nrs_changes_location,
        'changes_time' => $nrs_changes_time,
        'tolerance' => $tolerance_val,
        'tolerance_notes' => $nrs_tolerance_notes,
        'post_vitals' => $nrs_post_vitals,
        'advisory_checklist' => [
            'fall_prevention' => $adv_fall_prevention,
            'medication_schedule' => $adv_medication_schedule,
            'diet_restrictions' => $adv_diet_restrictions,
            'activity_limits' => $adv_activity_limits,
            'wound_care' => $adv_wound_care,
            'red_flags' => $adv_red_flags,
            'hydration' => $adv_hydration,
            'followup_reminder' => $adv_followup_reminder,
            'emergency_contact' => $adv_emergency_contact,
            'no_self_medicate' => $adv_no_self_medicate
        ],
        'advisory_notes' => $nrs_advisory_notes,
        'advisory_by' => $nrs_advisory_by,
        'advisory_time' => $nrs_advisory_time
    ];

    $nursing_plan = json_encode($nursing_plan_array);

    // Auto-construct legacy columns for backward compatibility (Consolidate into comprehensive nurse_notes)
    $nurse_notes_parts = [];
    
    // 1. Doctor Examination Records / Seen by Doctor
    $seen_doctor = 'No';
    if ($nrs_exam_doctor !== '') {
        $exam_time_str = $nrs_exam_date !== '' ? date('M d, Y h:i A', strtotime($nrs_exam_date)) : '';
        $seen_doctor = 'Yes - by ' . $nrs_exam_doctor . ($exam_time_str ? ' at ' . $exam_time_str : '');
    }
    
    // Special procedure applied
    $special_procedure = 'None';
    if ($nrs_changes_performed !== '') {
        $special_procedure = $nrs_changes_performed;
    } elseif ($obs_procedure_done) {
        $special_procedure = 'Laser hair reduction';
    } elseif (!empty($procedure_parameters)) {
        $special_procedure = 'Laser treatment';
    }

    // Location of the special feature applied
    $location_applied = 'None';
    if ($nrs_changes_location !== '') {
        $location_applied = $nrs_changes_location;
    } elseif (!empty($procedure_parameters)) {
        $areas_list = [];
        foreach ($procedure_parameters as $p) {
            $areas_list[] = $p['area'];
        }
        $location_applied = implode(', ', array_unique($areas_list));
    }

    // Session Tolerance (already defined as $tolerance_val above)

    $summary_block = [];
    $summary_block[] = "Seen and examined by doctor: " . $seen_doctor;
    $summary_block[] = "which special procedure applied: " . $special_procedure;
    $summary_block[] = "Location of the special feature applied: " . $location_applied;
    $summary_block[] = "Session tolerance: " . $tolerance_val;
    $nurse_notes_parts[] = implode("\n", $summary_block);

    // Doctor details block
    $doc_exam_block = [];
    if ($nrs_exam_findings !== '') {
        $doc_exam_block[] = "Doctor Examination Findings: " . $nrs_exam_findings;
    }
    if ($nrs_exam_orders !== '') {
        $doc_exam_block[] = "Doctor's Orders / Instructions: " . $nrs_exam_orders;
    }
    if ($nrs_exam_vitals_note !== '') {
        $doc_exam_block[] = "Doctor's Vitals Observation: " . $nrs_exam_vitals_note;
    }
    if (!empty($doc_exam_block)) {
        $nurse_notes_parts[] = implode("\n", $doc_exam_block);
    }
    
    // 2. Preparation for Patient & Skin Assessment
    $prep_block = [];
    $prep_block[] = "--- Patient Preparation & Skin Assessment ---";
    if ($prep_assessment_done) {
        $prep_block[] = "Skin Assessment done";
    }
    if ($nrs_prep_skin_type !== '') {
        $prep_block[] = "Skin Type & Details: " . $nrs_prep_skin_type;
    }
    
    $prep_checklist_labels = [
        'consent' => 'Consent signed and secured',
        'id_verified' => 'Patient identity verified',
        'allergies_checked' => 'Allergies checked and verified',
        'fasting' => 'Fasting status confirmed',
        'iv_access' => 'IV access secured',
        'positioning' => 'Patient positioning optimized',
        'monitoring' => 'Monitoring equipment connected',
        'emergency_kit' => 'Emergency kit verified',
        'baseline_vitals' => 'Baseline vitals recorded',
        'labwork' => 'Labwork reviewed'
    ];
    $checked_prep = [];
    foreach ($prep_checklist_labels as $key => $label) {
        if (!empty($_POST['prep_' . $key])) {
            $checked_prep[] = "- " . $label;
        }
    }
    if ($prep_procedure_explained) {
        $checked_prep[] = "- Explained the procedure and possible outcome of the treatment";
    }
    if ($prep_goggles_provided) {
        $checked_prep[] = "- Protective eye goggles provided";
    }
    if ($prep_markings_shaving) {
        $checked_prep[] = "- Markings and shaving done";
    }
    
    if (!empty($checked_prep)) {
        $prep_block[] = implode("\n", $checked_prep);
    }
    if ($nrs_prep_notes !== '') {
        $prep_block[] = "Prep Notes: " . $nrs_prep_notes;
    }
    if ($nrs_prep_nurse !== '' || $nrs_prep_time !== '') {
        $prep_block[] = "Prepared by: " . $nrs_prep_nurse . ($nrs_prep_time !== '' ? " at " . $nrs_prep_time : "");
    }
    $nurse_notes_parts[] = implode("\n", $prep_block);
    
    // 3. Treatment (Medication, Parameters, Observations, Vitals)
    $treatment_block = [];
    $treatment_block[] = "--- Clinical Treatment & Parameters ---";
    
    if (!empty($medications_given)) {
        $med_list_parts = [];
        foreach ($medications_given as $mg) {
            $med_desc = "- " . $mg['name'] . ($mg['dose'] !== '' ? " " . $mg['dose'] : "") . ($mg['route'] !== '' ? " via " . $mg['route'] : "") . ($mg['time'] !== '' ? " at " . $mg['time'] : "");
            if ($mg['notes'] !== '') {
                $med_desc .= " (" . $mg['notes'] . ")";
            }
            $med_list_parts[] = $med_desc;
        }
        $treatment_block[] = "Medications Administered:\n" . implode("\n", $med_list_parts);
    }
    
    if (!empty($procedure_parameters)) {
        $param_lines = [];
        foreach ($procedure_parameters as $p) {
            $param_line = "- " . $p['area'];
            $details = [];
            if ($p['laser'] !== '') $details[] = $p['laser'];
            if ($p['fluence'] !== '') $details[] = $p['fluence'];
            if ($p['spot'] !== '') $details[] = $p['spot'];
            if ($p['pulse'] !== '') $details[] = $p['pulse'];
            if (!empty($details)) {
                $param_line .= " : " . implode(" : ", $details);
            }
            if ($p['notes'] !== '') {
                $param_line .= " (" . $p['notes'] . ")";
            }
            $param_lines[] = $param_line;
        }
        $treatment_block[] = "Parameters used:\n" . implode("\n", $param_lines);
    }

    $obs_block = [];
    if ($obs_procedure_done) $obs_block[] = "- Laser hair reduction done.";
    if ($obs_erythema_edema) $obs_block[] = "- Mild erythema and perifollicular edema noted.";
    if ($obs_no_complaints) $obs_block[] = "- No complaints of pain or burn sensation.";
    if ($obs_fucicort_applied) $obs_block[] = "- Fucicort cream applied.";
    if ($obs_fucidin_applied) $obs_block[] = "- Fucidin cream applied.";
    if ($obs_cold_compress) $obs_block[] = "- Cold compress applied post-treatment.";
    
    if (!empty($obs_block)) {
        $treatment_block[] = "Post-Procedure Observations:\n" . implode("\n", $obs_block);
    }
    
    if ($nrs_changes_performed !== '') {
        $treatment_block[] = "Special Procedure Applied Notes: " . $nrs_changes_performed;
    }
    if ($nrs_changes_location !== '') {
        $treatment_block[] = "Location of the special feature applied: " . $nrs_changes_location;
    }
    if ($nrs_changes_response !== '') {
        $treatment_block[] = "Patient Response: " . $nrs_changes_response;
    }
    if ($nrs_changes_time !== '') {
        $treatment_block[] = "Procedure Execution Time: " . $nrs_changes_time;
    }
    
    if ($nrs_tolerance_notes !== '') {
        $treatment_block[] = "Session Tolerance Notes: " . $nrs_tolerance_notes;
    }
    if ($nrs_post_vitals !== '') {
        $treatment_block[] = "Post-Treatment Vitals: " . $nrs_post_vitals;
    }
    $nurse_notes_parts[] = implode("\n", $treatment_block);
    
    // 4. Advisory Measures
    $advisory_block = [];
    $advisory_block[] = "--- Post Home Care Instructions (Advisory) ---";
    
    $advisory_checklist_labels = [
        'fall_prevention' => 'Fall prevention precautions instructed',
        'medication_schedule' => 'Medication schedule explained',
        'diet_restrictions' => 'Dietary restrictions explained',
        'activity_limits' => 'Activity limitations explained',
        'wound_care' => 'Wound care instructions provided',
        'red_flags' => 'Red flag symptoms explained',
        'hydration' => 'Hydration instructions given',
        'followup_reminder' => 'Follow-up appointments reminder given',
        'emergency_contact' => 'Emergency contact numbers provided',
        'no_self_medicate' => 'Advised not to self-medicate'
    ];
    $checked_adv = [];
    foreach ($advisory_checklist_labels as $key => $label) {
        if (!empty($_POST['adv_' . $key])) {
            $checked_adv[] = "- " . $label;
        }
    }
    if (!empty($checked_adv)) {
        $advisory_block[] = implode("\n", $checked_adv);
    }
    if ($nrs_advisory_notes !== '') {
        $advisory_block[] = "Discharge / Advisory Notes: " . $nrs_advisory_notes;
    }
    if ($nrs_advisory_by !== '' || $nrs_advisory_time !== '') {
        $advisory_block[] = "Advised by: " . $nrs_advisory_by . ($nrs_advisory_time !== '' ? " at " . $nrs_advisory_time : "");
    }
    $nurse_notes_parts[] = implode("\n", $advisory_block);
    
    $nurse_notes = implode("\n\n", $nurse_notes_parts);
    $treatment_notes = $nrs_changes_performed !== '' ? $nrs_changes_performed : $special_procedure;

    $followup_enabled = !empty($_POST['followup_enabled']);
    $followup_date = $followup_enabled ? trim($_POST['followup_date'] ?? '') : null;
    $followup_time = $followup_enabled ? trim($_POST['followup_time'] ?? '') : null;
    $followup_doctor_id = $followup_enabled ? get_post_val('followup_doctor_id', FILTER_VALIDATE_INT) : null;
    if (empty($followup_date)) { $followup_date = null; }
    if (empty($followup_time)) { $followup_time = null; }
    if (empty($followup_doctor_id)) { $followup_doctor_id = null; }
    if ($followup_enabled && !$followup_doctor_id) {
        $followup_doctor_id = $doctor_id;
    }

    // Strict Validations ONLY when Finalizing
    if ($submit_type === 'finalize') {
        if (empty($blood_pressure)) {
            throw new Exception("Blood pressure reading is required to finalize.");
        }
        if ($temperature === false || $temperature === null || $temperature < 30.0 || $temperature > 45.0) {
            throw new Exception("Please input a valid body temperature (between 30.0 and 45.0 °C) to finalize.");
        }
        if ($heart_rate === false || $heart_rate === null || $heart_rate < 30 || $heart_rate > 250) {
            throw new Exception("Please input a valid heart rate (between 30 and 250 bpm) to finalize.");
        }
        if ($weight === false || $weight === null || $weight < 1.0 || $weight > 400.0) {
            throw new Exception("Please input a valid weight (between 1.0 and 400.0 kg) to finalize.");
        }
        if ($oxygen_saturation === false || $oxygen_saturation === null || $oxygen_saturation < 50 || $oxygen_saturation > 100) {
            throw new Exception("Please input a valid oxygen saturation (between 50% and 100% SpO2) to finalize.");
        }
        if ($pain_scale === false || $pain_scale === null || $pain_scale < 0 || $pain_scale > 10) {
            throw new Exception("Pain scale level must be selected to finalize.");
        }
        if ($height === false || $height === null || $height < 30.0 || $height > 300.0) {
            throw new Exception("Please input a valid height (between 30.0 and 300.0 cm) to finalize.");
        }
        if ($respiratory_rate === false || $respiratory_rate === null || $respiratory_rate < 5 || $respiratory_rate > 100) {
            throw new Exception("Please input a valid respiratory rate (between 5 and 100 breaths/min) to finalize.");
        }
        if (empty($chief_complaint)) {
            throw new Exception("Chief complaint note is required to finalize.");
        }
        if (empty($narrative_diagnosis)) {
            throw new Exception("Narrative diagnosis is required to finalize.");
        }
        if (empty($hpi_location)) {
            throw new Exception("HPI Location is required to finalize.");
        }
        if (empty($hpi_duration)) {
            throw new Exception("HPI Duration is required to finalize.");
        }

        // Validate that we have at least one diagnosis
        $icd_codes = $_POST['icd_code'] ?? [];
        if (empty($icd_codes) || empty(trim($icd_codes[0]))) {
            throw new Exception("At least one diagnosis is required to finalize.");
        }
    }

    // Retrieve child table arrays
    $icd_codes = $_POST['icd_code'] ?? [];
    $icd_descriptions = $_POST['icd_description'] ?? [];

    $medicine_names = $_POST['medicine_name'] ?? [];
    $medicine_dosages = $_POST['medicine_dosage'] ?? [];
    $medicine_durations = $_POST['medicine_duration'] ?? [];
    $medicine_instructions = $_POST['medicine_instructions'] ?? [];

    $lab_tests = $_POST['lab_tests'] ?? [];
    $test_priorities = $_POST['test_priority'] ?? [];
    $test_categories = $_POST['test_category'] ?? [];

    // 3. Begin Database Transaction
    $pdo->beginTransaction();

    // Check if a consultation record already exists for this appointment
    $stmtCheck = $pdo->prepare("SELECT consultation_id FROM consultations WHERE appointment_id = ?");
    $stmtCheck->execute([$appointment_id]);
    $consultation_id = $stmtCheck->fetchColumn();

    $old_followup_date = null;
    $old_followup_time = null;
    $old_followup_doctor_id = null;
    if ($consultation_id) {
        $stmtOldFollowup = $pdo->prepare("SELECT followup_date, followup_time, followup_doctor_id FROM consultations WHERE consultation_id = ?");
        $stmtOldFollowup->execute([$consultation_id]);
        $oldFollowup = $stmtOldFollowup->fetch();
        if ($oldFollowup) {
            $old_followup_date = $oldFollowup['followup_date'];
            $old_followup_time = $oldFollowup['followup_time'];
            $old_followup_doctor_id = $oldFollowup['followup_doctor_id'];
        }
    }

    if ($consultation_id) {
        // Update existing consultation row
        $updateConsultationQuery = "
            UPDATE consultations
            SET blood_pressure = ?, temperature = ?, heart_rate = ?, weight = ?, oxygen_saturation = ?, pain_scale = ?,
                allergy_notes = ?, referred_by = ?, medical_history = ?, family_history = ?, social_history = ?, surgical_history = ?,
                chief_complaint = ?, pain_scale_type = ?, hpi_location = ?, hpi_quality = ?, hpi_duration = ?, hpi_timing = ?, hpi_context = ?, hpi_modifying_factor = ?,
                physical_examination = ?, narrative_diagnosis = ?, nurse_notes = ?, treatment_notes = ?, 
                followup_date = ?, followup_time = ?, followup_doctor_id = ?, status = ?,
                height = ?, respiratory_rate = ?, ros_data = ?, exam_data = ?, nursing_plan = ?
            WHERE consultation_id = ?
        ";
        $stmtUpdate = $pdo->prepare($updateConsultationQuery);
        $stmtUpdate->execute([
            empty($blood_pressure) ? null : $blood_pressure,
            $temperature,
            $heart_rate,
            $weight,
            $oxygen_saturation,
            $pain_scale,
            empty($allergy_notes) ? null : $allergy_notes,
            empty($referred_by) ? null : $referred_by,
            empty($medical_history) ? null : $medical_history,
            empty($family_history) ? null : $family_history,
            empty($social_history) ? null : $social_history,
            empty($surgical_history) ? null : $surgical_history,
            empty($chief_complaint) ? null : $chief_complaint,
            empty($pain_scale_type) ? null : $pain_scale_type,
            empty($hpi_location) ? null : $hpi_location,
            empty($hpi_quality) ? null : $hpi_quality,
            empty($hpi_duration) ? null : $hpi_duration,
            empty($hpi_timing) ? null : $hpi_timing,
            empty($hpi_context) ? null : $hpi_context,
            empty($hpi_modifying_factor) ? null : $hpi_modifying_factor,
            empty($physical_examination) ? null : $physical_examination,
            empty($narrative_diagnosis) ? null : $narrative_diagnosis,
            empty($nurse_notes) ? null : $nurse_notes,
            empty($treatment_notes) ? null : $treatment_notes,
            $followup_date,
            $followup_time,
            $followup_doctor_id,
            $submit_type === 'draft' ? 'Draft' : 'Finalized',
            $height,
            $respiratory_rate,
            $ros_data,
            $exam_data,
            $nursing_plan,
            $consultation_id
        ]);
        // Clean up previous child table associations to rewrite fresh values
        $pdo->prepare("DELETE FROM consultation_diagnoses WHERE consultation_id = ?")->execute([$consultation_id]);
        $pdo->prepare("DELETE FROM consultation_prescriptions WHERE consultation_id = ?")->execute([$consultation_id]);
        $pdo->prepare("DELETE FROM consultation_tests WHERE consultation_id = ?")->execute([$consultation_id]);

    } else {
        // Insert new consultation row
        $insertConsultationQuery = "
            INSERT INTO consultations (appointment_id, blood_pressure, temperature, heart_rate, weight, oxygen_saturation, pain_scale,
                                       allergy_notes, referred_by, medical_history, family_history, social_history, surgical_history,
                                       chief_complaint, pain_scale_type, hpi_location, hpi_quality, hpi_duration, hpi_timing, hpi_context, hpi_modifying_factor,
                                       physical_examination, narrative_diagnosis, nurse_notes, treatment_notes, 
                                       followup_date, followup_time, followup_doctor_id, status,
                                       height, respiratory_rate, ros_data, exam_data, nursing_plan)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ";
        $stmtInsert = $pdo->prepare($insertConsultationQuery);
        $stmtInsert->execute([
            $appointment_id,
            empty($blood_pressure) ? null : $blood_pressure,
            $temperature,
            $heart_rate,
            $weight,
            $oxygen_saturation,
            $pain_scale,
            empty($allergy_notes) ? null : $allergy_notes,
            empty($referred_by) ? null : $referred_by,
            empty($medical_history) ? null : $medical_history,
            empty($family_history) ? null : $family_history,
            empty($social_history) ? null : $social_history,
            empty($surgical_history) ? null : $surgical_history,
            empty($chief_complaint) ? null : $chief_complaint,
            empty($pain_scale_type) ? null : $pain_scale_type,
            empty($hpi_location) ? null : $hpi_location,
            empty($hpi_quality) ? null : $hpi_quality,
            empty($hpi_duration) ? null : $hpi_duration,
            empty($hpi_timing) ? null : $hpi_timing,
            empty($hpi_context) ? null : $hpi_context,
            empty($hpi_modifying_factor) ? null : $hpi_modifying_factor,
            empty($physical_examination) ? null : $physical_examination,
            empty($narrative_diagnosis) ? null : $narrative_diagnosis,
            empty($nurse_notes) ? null : $nurse_notes,
            empty($treatment_notes) ? null : $treatment_notes,
            $followup_date,
            $followup_time,
            $followup_doctor_id,
            $submit_type === 'draft' ? 'Draft' : 'Finalized',
            $height,
            $respiratory_rate,
            $ros_data,
            $exam_data,
            $nursing_plan
        ]);

        $consultation_id = $pdo->lastInsertId();
    }

    // Step A: Insert diagnoses
    $insertDiagnosisQuery = "
        INSERT INTO consultation_diagnoses (consultation_id, icd_code, description)
        VALUES (?, ?, ?)
    ";
    $stmtInsertDiagnosis = $pdo->prepare($insertDiagnosisQuery);

    if (count($icd_codes) > 0) {
        $code = trim($icd_codes[0] ?? '');
        $desc = trim($icd_descriptions[0] ?? '');
        
        if (!empty($code) || !empty($desc)) {
            if (empty($code) || empty($desc)) {
                throw new Exception("All diagnosis entries must have both an ICD-10 Code and Description.");
            }
            $stmtInsertDiagnosis->execute([$consultation_id, $code, $desc]);
        }
    }

    // Step B: Insert prescriptions
    $insertPrescriptionQuery = "
        INSERT INTO consultation_prescriptions (consultation_id, medicine_name, dosage, duration, instructions)
        VALUES (?, ?, ?, ?, ?)
    ";
    $stmtInsertPrescription = $pdo->prepare($insertPrescriptionQuery);

    for ($i = 0; $i < count($medicine_names); $i++) {
        $medName = trim($medicine_names[$i] ?? '');
        $medDosage = trim($medicine_dosages[$i] ?? '');
        $medDuration = trim($medicine_durations[$i] ?? '');
        $medInstructions = trim($medicine_instructions[$i] ?? '');

        if (empty($medName) && empty($medDosage) && empty($medDuration) && empty($medInstructions)) {
            continue;
        }
        if (empty($medName) || empty($medDosage) || empty($medDuration)) {
            throw new Exception("All prescription entries must have medicine name, dosage, and duration filled.");
        }

        $stmtInsertPrescription->execute([
            $consultation_id,
            $medName,
            $medDosage,
            $medDuration,
            empty($medInstructions) ? null : $medInstructions
        ]);
    }

    // Step C: Insert lab tests
    if (!empty($lab_tests)) {
        $insertTestQuery = "
            INSERT INTO consultation_tests (consultation_id, test_name, category, priority, status, result_summary)
            VALUES (?, ?, ?, ?, ?, ?)
        ";
        $stmtInsertTest = $pdo->prepare($insertTestQuery);

        foreach ($lab_tests as $testName) {
            $testNameClean = trim($testName);
            if (empty($testNameClean)) {
                continue;
            }

            $priority = $test_priorities[$testNameClean] ?? 'Routine';
            $category = $test_categories[$testNameClean] ?? 'General';
            
            if ($submit_type === 'finalize') {
                $status = 'Completed';
                if ($testNameClean === 'Complete Blood Count (CBC)') {
                    $resultSummary = "White Blood Cells (WBC): 5.8 x10^3/uL (Normal: 4.5-11.0)\nRed Blood Cells (RBC): 4.65 x10^6/uL (Normal: 4.50-5.90)\nHemoglobin: 14.2 g/dL (Normal: 13.5-17.5)\nHematocrit: 42.5% (Normal: 41.0-50.0)\nPlatelets: 235 x10^3/uL (Normal: 150-450)";
                } elseif ($testNameClean === 'Lipid Profile') {
                    $resultSummary = "Total Cholesterol: 192 mg/dL (Normal: < 200)\nTriglycerides: 138 mg/dL (Normal: < 150)\nHDL Cholesterol: 48 mg/dL (Normal: > 40)\nLDL Cholesterol: 116 mg/dL (Normal: < 100, Optimal: < 70)\nCholesterol/HDL Ratio: 4.0 (Normal: < 5.0)";
                } elseif ($testNameClean === 'Chest X-Ray') {
                    $resultSummary = "PA and Lateral views of the chest are normal.\nLungs: Clear, no active infiltrates, consolidation, or effusion.\nHeart: Normal cardiomediastinal contour and heart size.\nBones/Soft Tissue: Intact thoracic skeleton, normal soft tissues.\nImpression: No active cardiopulmonary disease.";
                } elseif ($testNameClean === 'Urinalysis') {
                    $resultSummary = "Appearance: Yellow, Clear (Normal)\nSpecific Gravity: 1.018 (Normal: 1.005-1.030)\npH: 6.2 (Normal: 5.0-8.0)\nProtein: Negative (Normal)\nGlucose: Negative (Normal)\nKetones: Negative (Normal)\nBilirubin: Negative (Normal)\nMicroscopic: No WBCs, RBCs, or bacteria detected.";
                } else {
                    $resultSummary = "Laboratory diagnostics completed. Parameter values are within reference ranges.";
                }
            } else {
                $status = 'Ordered';
                $resultSummary = 'Results pending - draft state.';
            }

            $stmtInsertTest->execute([$consultation_id, $testNameClean, $category, $priority, $status, $resultSummary]);
        }
    }

    // Step D: Update corresponding appointment status and consecutive slot reservation
    if ($submit_type === 'finalize') {
        $updateAppointmentQuery = "
            UPDATE appointments
            SET status = 'Completed'
            WHERE appointment_id = ?
        ";
        $stmtUpdateAppointment = $pdo->prepare($updateAppointmentQuery);
        $stmtUpdateAppointment->execute([$appointment_id]);

        // Consecutive slot reservation logic (Requirement 10)
        $session_duration = get_post_val('session_duration', FILTER_VALIDATE_INT) ?: 0;
        $slots_needed = ceil($session_duration / 1800); // 1800s = 30 minutes
        if ($slots_needed > 1) {
            // Retrieve appointment date, start time, and doctor ID
            $stmtApptDetails = $pdo->prepare("SELECT doctor_id, appointment_date, appointment_time, patient_id FROM appointments WHERE appointment_id = ?");
            $stmtApptDetails->execute([$appointment_id]);
            $apptDetails = $stmtApptDetails->fetch();
            if ($apptDetails) {
                $doctor_id = $apptDetails['doctor_id'];
                $appt_date = $apptDetails['appointment_date'];
                $start_time = $apptDetails['appointment_time'];
                $patient_id = $apptDetails['patient_id'];

                // Recursive function to push conflicting appointments
                if (!function_exists('moveAppointmentConflict')) {
                    function moveAppointmentConflict($pdo, $doctor_id, $date, $time_str) {
                        $stmtConflict = $pdo->prepare("
                            SELECT appointment_id 
                            FROM appointments 
                            WHERE doctor_id = ? AND appointment_date = ? AND appointment_time = ? 
                              AND status IN ('Scheduled', 'Accepted')
                        ");
                        $stmtConflict->execute([$doctor_id, $date, $time_str]);
                        $conflict = $stmtConflict->fetch();
                        
                        if ($conflict) {
                            $conflicting_id = $conflict['appointment_id'];
                            $next_time_str = date('H:i:s', strtotime($time_str . ' +30 minutes'));
                            
                            // Cascading recursion
                            moveAppointmentConflict($pdo, $doctor_id, $date, $next_time_str);
                            
                            // Move conflicting appointment to the resolved time
                            $stmtUpdateConf = $pdo->prepare("UPDATE appointments SET appointment_time = ? WHERE appointment_id = ?");
                            $stmtUpdateConf->execute([$next_time_str, $conflicting_id]);
                            
                            // Sync follow-up time if it was in the consultations table
                            $stmtConsult = $pdo->prepare("SELECT consultation_id FROM consultations WHERE appointment_id = ?");
                            $stmtConsult->execute([$conflicting_id]);
                            $c_id = $stmtConsult->fetchColumn();
                            if ($c_id) {
                                $stmtUpdateConsult = $pdo->prepare("UPDATE consultations SET followup_time = ? WHERE consultation_id = ?");
                                $stmtUpdateConsult->execute([$next_time_str, $c_id]);
                            }
                        }
                    }
                }

                // Reserve next slots
                $current_time = strtotime($start_time);
                for ($s = 1; $s < $slots_needed; $s++) {
                    $current_time = strtotime('+30 minutes', $current_time);
                    $slot_time_str = date('H:i:s', $current_time);

                    // Check if Completed placeholder already exists for this patient, doctor, date, and time
                    $stmtCheckPlaceholder = $pdo->prepare("
                        SELECT COUNT(*) FROM appointments 
                        WHERE patient_id = ? AND doctor_id = ? AND appointment_date = ? AND appointment_time = ? AND status = 'Completed'
                    ");
                    $stmtCheckPlaceholder->execute([$patient_id, $doctor_id, $appt_date, $slot_time_str]);
                    if ($stmtCheckPlaceholder->fetchColumn() == 0) {
                        // Move any conflicting appointment recursively
                        moveAppointmentConflict($pdo, $doctor_id, $appt_date, $slot_time_str);

                        // Insert consecutive reserved placeholder slot for this user
                        $stmtReserve = $pdo->prepare("
                            INSERT INTO appointments (patient_id, doctor_id, appointment_date, appointment_time, status)
                            VALUES (?, ?, ?, ?, 'Completed')
                        ");
                        $stmtReserve->execute([$patient_id, $doctor_id, $appt_date, $slot_time_str]);
                    }
                }
            }
        }
    } else {
        $updateAppointmentQuery = "
            UPDATE appointments
            SET status = 'Accepted'
            WHERE appointment_id = ?
        ";
        $stmtUpdateAppointment = $pdo->prepare($updateAppointmentQuery);
        $stmtUpdateAppointment->execute([$appointment_id]);
    }

    // Step E: Schedule, reschedule, or cancel follow-up appointment (Draft and Finalize)
    $followup_booked = false;
    
    // Fetch patient_id from the original appointment
    $stmtPatient = $pdo->prepare("SELECT patient_id FROM appointments WHERE appointment_id = ?");
    $stmtPatient->execute([$appointment_id]);
    $orig_patient_id = $stmtPatient->fetchColumn();

    if ($orig_patient_id) {
        $eff_old_doctor_id = $old_followup_doctor_id ? $old_followup_doctor_id : $doctor_id;

        if ($followup_date && $followup_time) {
            if ($old_followup_date && $old_followup_time) {
                // If it already existed and has changed (date, time, or doctor), update/reschedule
                if ($old_followup_date !== $followup_date || $old_followup_time !== $followup_time || $eff_old_doctor_id !== $followup_doctor_id) {
                    // Check if an appointment already exists at the new date/time with the new doctor
                    $stmtCheckAppt = $pdo->prepare(
                        "SELECT COUNT(*) FROM appointments 
                         WHERE patient_id = ? AND doctor_id = ? AND appointment_date = ? AND appointment_time = ? AND status = 'Scheduled'"
                    );
                    $stmtCheckAppt->execute([$orig_patient_id, $followup_doctor_id, $followup_date, $followup_time]);
                    $new_exists = $stmtCheckAppt->fetchColumn() > 0;

                    if ($new_exists) {
                        // Delete the old one
                        $stmtDeleteOld = $pdo->prepare(
                            "DELETE FROM appointments 
                             WHERE patient_id = ? AND doctor_id = ? AND appointment_date = ? AND appointment_time = ? AND status = 'Scheduled'"
                        );
                        $stmtDeleteOld->execute([$orig_patient_id, $eff_old_doctor_id, $old_followup_date, $old_followup_time]);
                    } else {
                        // Try updating the old one to the new doctor and date/time
                        $stmtUpdateAppt = $pdo->prepare(
                            "UPDATE appointments 
                             SET doctor_id = ?, appointment_date = ?, appointment_time = ? 
                             WHERE patient_id = ? AND doctor_id = ? AND appointment_date = ? AND appointment_time = ? AND status = 'Scheduled'"
                        );
                        $stmtUpdateAppt->execute([$followup_doctor_id, $followup_date, $followup_time, $orig_patient_id, $eff_old_doctor_id, $old_followup_date, $old_followup_time]);
                        
                        if ($stmtUpdateAppt->rowCount() === 0) {
                            $stmtFollowup = $pdo->prepare(
                                "INSERT INTO appointments (patient_id, doctor_id, appointment_date, appointment_time, status)
                                 VALUES (?, ?, ?, ?, 'Scheduled')"
                            );
                            $stmtFollowup->execute([$orig_patient_id, $followup_doctor_id, $followup_date, $followup_time]);
                        }
                    }
                    $followup_booked = true;
                } else {
                    // Unchanged, make sure it exists
                    $stmtCheckAppt = $pdo->prepare(
                        "SELECT COUNT(*) FROM appointments 
                         WHERE patient_id = ? AND doctor_id = ? AND appointment_date = ? AND appointment_time = ? AND status = 'Scheduled'"
                    );
                    $stmtCheckAppt->execute([$orig_patient_id, $followup_doctor_id, $followup_date, $followup_time]);
                    $exists = $stmtCheckAppt->fetchColumn() > 0;
                    if (!$exists) {
                        $stmtFollowup = $pdo->prepare(
                            "INSERT INTO appointments (patient_id, doctor_id, appointment_date, appointment_time, status)
                             VALUES (?, ?, ?, ?, 'Scheduled')"
                        );
                        $stmtFollowup->execute([$orig_patient_id, $followup_doctor_id, $followup_date, $followup_time]);
                    }
                    $followup_booked = true;
                }
            } else {
                // No old follow-up existed. Insert if it doesn't exist
                $stmtCheckAppt = $pdo->prepare(
                    "SELECT COUNT(*) FROM appointments 
                     WHERE patient_id = ? AND doctor_id = ? AND appointment_date = ? AND appointment_time = ? AND status = 'Scheduled'"
                );
                $stmtCheckAppt->execute([$orig_patient_id, $followup_doctor_id, $followup_date, $followup_time]);
                $exists = $stmtCheckAppt->fetchColumn() > 0;

                if (!$exists) {
                    $stmtFollowup = $pdo->prepare(
                        "INSERT INTO appointments (patient_id, doctor_id, appointment_date, appointment_time, status)
                         VALUES (?, ?, ?, ?, 'Scheduled')"
                    );
                    $stmtFollowup->execute([$orig_patient_id, $followup_doctor_id, $followup_date, $followup_time]);
                }
                $followup_booked = true;
            }
        } else {
            // Delete old follow-up
            if ($old_followup_date && $old_followup_time) {
                $stmtDeleteAppt = $pdo->prepare(
                    "DELETE FROM appointments 
                     WHERE patient_id = ? AND doctor_id = ? AND appointment_date = ? AND appointment_time = ? AND status = 'Scheduled'"
                );
                $stmtDeleteAppt->execute([$orig_patient_id, $eff_old_doctor_id, $old_followup_date, $old_followup_time]);
            }
        }
    }

    // Commit transaction
    $pdo->commit();

    if ($submit_type === 'draft') {
        $_SESSION['success_msg'] = "Consultation draft saved successfully.";
    } else {
        $msg = "You have finalized this case.";
        if ($followup_booked) {
            $msg .= " A follow-up appointment has been automatically scheduled for " . date('M d, Y', strtotime($followup_date)) . " at " . date('h:i A', strtotime($followup_time)) . ".";
        }
        $_SESSION['success_msg'] = $msg;
    }
    
    $appt_date = $appointment['appointment_date'] ?? '';
    if (!empty($appt_date)) {
        header("Location: ../public/dashboard.php?date=" . urlencode($appt_date));
    } else {
        header("Location: ../public/dashboard.php");
    }
    exit();

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $_SESSION['error_msg'] = "Transaction Failed: " . $e->getMessage();
    
    if (isset($appointment_id) && $appointment_id > 0) {
        header("Location: ../public/consultation.php?appointment_id=" . $appointment_id);
    } else {
        header("Location: ../public/dashboard.php");
    }
    exit();
}
?>

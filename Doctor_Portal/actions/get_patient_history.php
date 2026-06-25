<?php
/**
 * Patient Visit History API Endpoint (JSON)
 * 
 * Fetches all past visits, chief complaints, diagnoses, prescriptions,
 * vitals, and tests for a selected patient associated with the active doctor.
 */

// Start session
session_start();

// Include database connection
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

// Verify doctor authentication
if (!isset($_SESSION['doctor_id'])) {
    echo json_encode(['error' => 'Unauthorized access. Please log in.']);
    exit();
}

$doctor_id = $_SESSION['doctor_id'];

// Retrieve and validate Patient ID
$patient_id = filter_input(INPUT_GET, 'patient_id', FILTER_VALIDATE_INT) ?: (filter_var($_GET['patient_id'] ?? null, FILTER_VALIDATE_INT) ?: null);
if (!$patient_id) {
    echo json_encode(['error' => 'Invalid Patient ID.']);
    exit();
}

try {
    // 1. Fetch patient basic details
    $stmtPatient = $pdo->prepare("SELECT patient_id, name, dob, gender FROM patients WHERE patient_id = ?");
    $stmtPatient->execute([$patient_id]);
    $patient = $stmtPatient->fetch();

    if (!$patient) {
        echo json_encode(['error' => 'Patient record not found.']);
        exit();
    }

    // 2. Fetch all appointments and consultations for this patient and doctor
    $stmtHistory = $pdo->prepare("
        SELECT a.appointment_id, a.appointment_date, a.appointment_time, a.status AS appt_status,
               c.consultation_id, c.chief_complaint, c.status AS consult_status, c.narrative_diagnosis,
               c.blood_pressure, c.temperature, c.heart_rate, c.weight, c.oxygen_saturation, c.pain_scale, c.height, c.respiratory_rate
        FROM appointments a
        LEFT JOIN consultations c ON a.appointment_id = c.appointment_id
        WHERE a.patient_id = ? AND a.doctor_id = ?
        ORDER BY a.appointment_date DESC, a.appointment_time DESC
    ");
    $stmtHistory->execute([$patient_id, $doctor_id]);
    $visits = $stmtHistory->fetchAll();

    $historyData = [];
    foreach ($visits as $visit) {
        $diagnoses = [];
        $prescriptions = [];
        $tests = [];

        if (!empty($visit['consultation_id'])) {
            $c_id = $visit['consultation_id'];

            // Fetch diagnoses
            $stmtDiag = $pdo->prepare("SELECT icd_code, description FROM consultation_diagnoses WHERE consultation_id = ?");
            $stmtDiag->execute([$c_id]);
            $diagnoses = $stmtDiag->fetchAll();

            // Fetch prescriptions
            $stmtPres = $pdo->prepare("SELECT medicine_name, dosage, duration, instructions FROM consultation_prescriptions WHERE consultation_id = ?");
            $stmtPres->execute([$c_id]);
            $prescriptions = $stmtPres->fetchAll();

            // Fetch lab tests ordered
            $stmtTest = $pdo->prepare("SELECT test_name, category, priority, status, result_summary FROM consultation_tests WHERE consultation_id = ?");
            $stmtTest->execute([$c_id]);
            $tests = $stmtTest->fetchAll();
        }

        $historyData[] = [
            'appointment_id' => $visit['appointment_id'],
            'appointment_date' => $visit['appointment_date'],
            'appointment_time' => $visit['appointment_time'],
            'appt_status' => $visit['appt_status'],
            'consultation_id' => $visit['consultation_id'],
            'consult_status' => $visit['consult_status'] ?? 'None',
            'chief_complaint' => $visit['chief_complaint'] ?? 'N/A',
            'narrative_diagnosis' => $visit['narrative_diagnosis'] ?? 'N/A',
            'vitals' => [
                'blood_pressure' => $visit['blood_pressure'] ?? 'N/A',
                'temperature' => $visit['temperature'] !== null ? $visit['temperature'] . ' °C' : 'N/A',
                'heart_rate' => $visit['heart_rate'] !== null ? $visit['heart_rate'] . ' bpm' : 'N/A',
                'weight' => $visit['weight'] !== null ? $visit['weight'] . ' kg' : 'N/A',
                'height' => $visit['height'] !== null ? $visit['height'] . ' cm' : 'N/A',
                'oxygen_saturation' => $visit['oxygen_saturation'] !== null ? $visit['oxygen_saturation'] . '%' : 'N/A',
                'respiratory_rate' => $visit['respiratory_rate'] !== null ? $visit['respiratory_rate'] . ' /min' : 'N/A',
                'pain_scale' => $visit['pain_scale'] !== null ? $visit['pain_scale'] . '/10' : 'N/A',
            ],
            'diagnoses' => $diagnoses,
            'prescriptions' => $prescriptions,
            'tests' => $tests
        ];
    }

    echo json_encode([
        'patient' => [
            'patient_id' => $patient['patient_id'],
            'name' => $patient['name'],
            'dob' => $patient['dob'],
            'gender' => $patient['gender']
        ],
        'history' => $historyData
    ]);

} catch (PDOException $e) {
    echo json_encode(['error' => 'Database Query Error: ' . $e->getMessage()]);
}
?>

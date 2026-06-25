<?php
/**
 * Real-Time Auto-Extension Endpoint
 * 
 * Invoked by AJAX from the consultation screen when session timer overruns slot boundaries.
 * Automatically allocates the next slot and shifts conflicts recursively.
 */

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['doctor_id'])) {
    echo json_encode(['error' => 'Unauthenticated']);
    exit();
}

require_once __DIR__ . '/../config/db.php';

$doctor_id = $_SESSION['doctor_id'];

// Get inputs (supporting standard POST and JSON POST)
$raw_input = file_get_contents('php://input');
$json_data = json_decode($raw_input, true);

$appointment_id = isset($json_data['appointment_id']) ? intval($json_data['appointment_id']) : (isset($_POST['appointment_id']) ? intval($_POST['appointment_id']) : 0);
$session_duration = isset($json_data['session_duration']) ? intval($json_data['session_duration']) : (isset($_POST['session_duration']) ? intval($_POST['session_duration']) : 0);

if (!$appointment_id || !$session_duration) {
    echo json_encode(['error' => 'Invalid parameters']);
    exit();
}

try {
    // 1. Verify appointment exists and belongs to this doctor
    $stmt = $pdo->prepare("SELECT doctor_id, patient_id, appointment_date, appointment_time, status FROM appointments WHERE appointment_id = ?");
    $stmt->execute([$appointment_id]);
    $appointment = $stmt->fetch();

    if (!$appointment) {
        throw new Exception("Appointment not found");
    }
    if ($appointment['doctor_id'] != $doctor_id) {
        throw new Exception("Unauthorized access to this appointment");
    }
    if ($appointment['status'] === 'Scheduled') {
        throw new Exception("Appointment must be accepted first");
    }

    $patient_id = $appointment['patient_id'];
    $appt_date = $appointment['appointment_date'];
    $start_time = $appointment['appointment_time'];

    // 2. Calculate slots needed
    $slots_needed = ceil($session_duration / 1800); // 30-minute slots

    if ($slots_needed <= 1) {
        echo json_encode(['success' => true, 'message' => 'No extra slots needed', 'slots_reserved' => 1]);
        exit();
    }

    $pdo->beginTransaction();

    // Helper functions for slot reservation
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

    // Reserve slots
    $current_time = strtotime($start_time);
    $reserved_times = [];
    for ($s = 1; $s < $slots_needed; $s++) {
        $current_time = strtotime('+30 minutes', $current_time);
        $slot_time_str = date('H:i:s', $current_time);

        // Check if Completed placeholder already exists
        $stmtCheck = $pdo->prepare("
            SELECT COUNT(*) FROM appointments 
            WHERE patient_id = ? AND doctor_id = ? AND appointment_date = ? AND appointment_time = ? AND status = 'Completed'
        ");
        $stmtCheck->execute([$patient_id, $doctor_id, $appt_date, $slot_time_str]);
        if ($stmtCheck->fetchColumn() == 0) {
            // Move conflicts
            moveAppointmentConflict($pdo, $doctor_id, $appt_date, $slot_time_str);

            // Insert placeholder
            $stmtReserve = $pdo->prepare("
                INSERT INTO appointments (patient_id, doctor_id, appointment_date, appointment_time, status)
                VALUES (?, ?, ?, ?, 'Completed')
            ");
            $stmtReserve->execute([$patient_id, $doctor_id, $appt_date, $slot_time_str]);
        }
        $reserved_times[] = $slot_time_str;
    }

    $pdo->commit();
    echo json_encode([
        'success' => true, 
        'message' => 'Successfully extended appointment slots and resolved conflicts',
        'slots_reserved' => $slots_needed,
        'reserved_times' => $reserved_times
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['error' => $e->getMessage()]);
}

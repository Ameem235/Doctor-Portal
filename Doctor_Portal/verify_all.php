<?php
/**
 * Comprehensive EHR Workflow Verification Script
 */

require_once __DIR__ . '/config/db.php';

try {
    echo "1. Resetting database to clean state...\n";
    include __DIR__ . '/public/setup.php';
    echo "Database reset complete.\n\n";

    $appt_id = 1;

    // Set appointment to Accepted
    echo "2. Setting appointment 1 to Accepted...\n";
    $stmt = $pdo->prepare("UPDATE appointments SET status = 'Accepted' WHERE appointment_id = ?");
    $stmt->execute([$appt_id]);

    // 3. Simulate saving a consultation draft
    echo "3. Simulating consultation Save Draft...\n";
    $pdo->beginTransaction();

    $ros_data = json_encode([
        'integumentary' => 'No Complaints',
        'constitutional' => 'No Complaints',
        'eyes' => 'No Complaints',
        'enmt' => 'No Complaints',
        'cardiovascular' => 'No Complaints',
        'respiratory' => 'Mild congestion',
        'gastrointestinal' => 'No Complaints',
        'genitourinary' => 'No Complaints',
        'musculoskeletal' => 'No Complaints',
        'neurological' => 'Headache',
        'psychiatric' => 'No Complaints',
        'endocrine' => 'No Complaints',
        'hem_lymph' => 'No Complaints',
        'allergic_immuno' => 'No Complaints'
    ]);

    $exam_data = json_encode([
        'general' => 'Normal',
        'skin' => 'Normal',
        'notes' => 'General physical exam normal except mild headache.'
    ]);

    $nursing_plan_json = json_encode([
        'exam_date' => '2026-06-23T10:00',
        'exam_doctor' => 'Dr. Elizabeth Blackwell',
        'exam_findings' => 'Findings notes.',
        'exam_orders' => 'Order notes.',
        'exam_vitals_note' => 'Vitals note.',
        'prep_checklist' => [
            'consent' => true,
            'id_verified' => true,
            'allergies_checked' => true,
            'fasting' => false,
            'iv_access' => true,
            'positioning' => true,
            'monitoring' => true,
            'emergency_kit' => true,
            'baseline_vitals' => true,
            'labwork' => true
        ],
        'prep_notes' => 'Patient prepared.',
        'prep_nurse' => 'Nurse Fatima',
        'prep_time' => '09:45',
        'medications_given' => [
            [
                'name' => 'Paracetamol',
                'dose' => '500mg',
                'route' => 'Oral',
                'time' => '09:50',
                'notes' => 'Given for pain'
            ]
        ],
        'changes_performed' => 'No changes.',
        'changes_response' => 'N/A',
        'changes_time' => '09:55',
        'tolerance' => 'Tolerated Well',
        'tolerance_notes' => 'Tolerated session fine.',
        'post_vitals' => 'Stable.',
        'advisory_checklist' => [
            'fall_prevention' => true,
            'medication_schedule' => true,
            'diet_restrictions' => false,
            'activity_limits' => false,
            'wound_care' => false,
            'red_flags' => true,
            'hydration' => true,
            'followup_reminder' => true,
            'emergency_contact' => true,
            'no_self_medicate' => true
        ],
        'advisory_notes' => 'Advisory note.',
        'advisory_by' => 'Nurse Sana',
        'advisory_time' => '10:05'
    ]);

    // Insert draft consultation (Testing pain scale of 0)
    $insertConsultationQuery = "
        INSERT INTO consultations (appointment_id, blood_pressure, temperature, heart_rate, weight, oxygen_saturation, pain_scale,
                                   allergy_notes, referred_by, medical_history, family_history, social_history, surgical_history,
                                   chief_complaint, pain_scale_type, hpi_location, hpi_quality, hpi_duration, hpi_timing, hpi_context, hpi_modifying_factor,
                                   physical_examination, narrative_diagnosis, nurse_notes, treatment_notes, 
                                   followup_date, followup_time, followup_doctor_id, status, height, respiratory_rate, ros_data, exam_data, nursing_plan)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ";
    $stmt = $pdo->prepare($insertConsultationQuery);
    $stmt->execute([
        $appt_id, '120/80', 36.8, 72, 70.5, 98, 0, 'NKDA', 'Dr. Adams', 'Mild hypertension', 'Father has hypertension', 'Non-smoker', 'None',
        'Dull headache for 2 days', 'Wong-Baker', 'Head, frontal region', 'Dull, throbbing', '2 days', 'Continuous', 'Work stress', 'Rest improves',
        'HEENT normal, neck supple', 'Rest and hydration advised.', 'Check BP every 4 hours.', 'Administer IV fluids.', '2026-06-28', '10:00:00', 1, 'Draft', 175.50, 18, $ros_data, $exam_data, $nursing_plan_json
    ]);
    $consultation_id = $pdo->lastInsertId();

    // Insert draft diagnosis
    $stmt = $pdo->prepare("INSERT INTO consultation_diagnoses (consultation_id, icd_code, description) VALUES (?, 'R51', 'Headache, unspecified')");
    $stmt->execute([$consultation_id]);

    // Insert draft prescription
    $stmt = $pdo->prepare("INSERT INTO consultation_prescriptions (consultation_id, medicine_name, dosage, duration, instructions) VALUES (?, 'Ibuprofen 400mg', '1 tablet every 6 hours as needed', '3 days', 'Take with food')");
    $stmt->execute([$consultation_id]);

    // Insert draft lab test (Ordered status, results pending)
    $stmt = $pdo->prepare("INSERT INTO consultation_tests (consultation_id, test_name, category, priority, status, result_summary) VALUES (?, 'Complete Blood Count (CBC)', 'Hematology', 'STAT', 'Ordered', 'Results pending - draft state.')");
    $stmt->execute([$consultation_id]);

    $pdo->commit();
    echo "Draft saved successfully. Consultation ID: $consultation_id\n";

    // Verify Draft Status in DB
    $stmt = $pdo->prepare("SELECT status, nurse_notes, treatment_notes, followup_date, followup_time, followup_doctor_id, height, respiratory_rate, ros_data, exam_data, pain_scale, pain_scale_type, hpi_location, hpi_quality, hpi_duration, hpi_timing, hpi_context, hpi_modifying_factor, nursing_plan FROM consultations WHERE consultation_id = ?");
    $stmt->execute([$consultation_id]);
    $row = $stmt->fetch();
    if ($row['status'] !== 'Draft') {
        throw new Exception("Expected consultation status to be 'Draft', got '{$row['status']}'");
    }
    if (empty($row['nursing_plan'])) {
        throw new Exception("Expected nursing_plan to be populated in draft, got empty.");
    }
    if ($row['nurse_notes'] !== 'Check BP every 4 hours.' || $row['treatment_notes'] !== 'Administer IV fluids.') {
        throw new Exception("Nurse notes or treatment notes were not saved correctly in draft!");
    }
    if ($row['followup_date'] !== '2026-06-28' || $row['followup_time'] !== '10:00:00' || $row['followup_doctor_id'] != 1) {
        throw new Exception("Expected followup date/time/doctor to be 2026-06-28 10:00:00 with doctor 1 in draft, got '{$row['followup_date']} {$row['followup_time']} doctor:{$row['followup_doctor_id']}'");
    }
    if (floatval($row['height'] ?? 0) !== 175.50 || intval($row['respiratory_rate'] ?? 0) !== 18 || intval($row['pain_scale']) !== 0) {
        throw new Exception("Vitals (height, respiratory_rate, pain_scale) were not saved correctly in draft! Got: Height: {$row['height']}, Resp: {$row['respiratory_rate']}, Pain Scale: {$row['pain_scale']}");
    }
    if (empty($row['ros_data']) || empty($row['exam_data'])) {
        throw new Exception("ROS or Clinical Exam data was empty in draft!");
    }
    if ($row['pain_scale_type'] !== 'Wong-Baker' || $row['hpi_location'] !== 'Head, frontal region' || $row['hpi_quality'] !== 'Dull, throbbing' || $row['hpi_duration'] !== '2 days' || $row['hpi_timing'] !== 'Continuous' || $row['hpi_context'] !== 'Work stress' || $row['hpi_modifying_factor'] !== 'Rest improves') {
        throw new Exception("HPI fields were not saved correctly in draft! Got: pain_scale_type={$row['pain_scale_type']}, location={$row['hpi_location']}, quality={$row['hpi_quality']}, duration={$row['hpi_duration']}, timing={$row['hpi_timing']}, context={$row['hpi_context']}, modifying={$row['hpi_modifying_factor']}");
    }
    echo "Verification passed: Consultation status is 'Draft', notes/follow-up/doctor/HPI are saved correctly.\n\n";

    // 4. Simulate resuming and finalizing the draft
    echo "4. Simulating resuming and Finalizing the consultation...\n";
    $pdo->beginTransaction();

    // Clean up draft children (as save_consultation.php does)
    $pdo->prepare("DELETE FROM consultation_diagnoses WHERE consultation_id = ?")->execute([$consultation_id]);
    $pdo->prepare("DELETE FROM consultation_prescriptions WHERE consultation_id = ?")->execute([$consultation_id]);
    $pdo->prepare("DELETE FROM consultation_tests WHERE consultation_id = ?")->execute([$consultation_id]);

    // Update consultation to Finalized
    $updateConsultationQuery = "
        UPDATE consultations
        SET blood_pressure = ?, temperature = ?, heart_rate = ?, weight = ?, oxygen_saturation = ?, pain_scale = ?,
            allergy_notes = ?, referred_by = ?, medical_history = ?, family_history = ?, social_history = ?, surgical_history = ?,
            chief_complaint = ?, pain_scale_type = ?, hpi_location = ?, hpi_quality = ?, hpi_duration = ?, hpi_timing = ?, hpi_context = ?, hpi_modifying_factor = ?,
            physical_examination = ?, narrative_diagnosis = ?, nurse_notes = ?, treatment_notes = ?, 
            followup_date = ?, followup_time = ?, followup_doctor_id = ?, status = 'Finalized',
            height = ?, respiratory_rate = ?, ros_data = ?, exam_data = ?, nursing_plan = ?
        WHERE consultation_id = ?
    ";
    $stmt = $pdo->prepare($updateConsultationQuery);
    $stmt->execute([
        '120/80', 36.8, 72, 70.5, 98, 2, 'NKDA', 'Dr. Adams', 'Mild hypertension', 'Father has hypertension', 'Non-smoker', 'None',
        'Dull headache for 2 days', 'FLACC', 'Head, bilateral', 'Dull, pressure-like', '2 days', 'Intermittent', 'Work stress, screen time', 'Ibuprofen helps',
        'HEENT normal, neck supple', 'Rest and hydration advised.', 'Check BP every 2 hours.', 'Administer IV fluids and Paracetamol.', '2026-06-28', '10:00:00', 1,
        175.50, 18, $ros_data, $exam_data, $nursing_plan_json, $consultation_id
    ]);

    // Rewrite diagnoses
    $stmt = $pdo->prepare("INSERT INTO consultation_diagnoses (consultation_id, icd_code, description) VALUES (?, 'R51', 'Headache, unspecified')");
    $stmt->execute([$consultation_id]);

    // Rewrite prescriptions
    $stmt = $pdo->prepare("INSERT INTO consultation_prescriptions (consultation_id, medicine_name, dosage, duration, instructions) VALUES (?, 'Ibuprofen 400mg', '1 tablet every 6 hours as needed', '3 days', 'Take with food')");
    $stmt->execute([$consultation_id]);

    // Rewrite lab tests (Finalized status, results completed)
    $stmt = $pdo->prepare("INSERT INTO consultation_tests (consultation_id, test_name, category, priority, status, result_summary) VALUES (?, 'Complete Blood Count (CBC)', 'Hematology', 'STAT', 'Completed', 'White Blood Cells (WBC): 5.8 x10^3/uL\nHemoglobin: 14.2 g/dL')");
    $stmt->execute([$consultation_id]);

    // Update appointment status to Completed
    $stmt = $pdo->prepare("UPDATE appointments SET status = 'Completed' WHERE appointment_id = ?");
    $stmt->execute([$appt_id]);

    $pdo->commit();
    echo "Consultation finalized successfully.\n";

    // Verify Finalized status in DB
    $stmt = $pdo->prepare("SELECT status, nurse_notes, treatment_notes, followup_date, followup_time, followup_doctor_id, height, respiratory_rate, ros_data, exam_data, pain_scale, pain_scale_type, hpi_location, hpi_quality, hpi_duration, hpi_timing, hpi_context, hpi_modifying_factor, nursing_plan FROM consultations WHERE consultation_id = ?");
    $stmt->execute([$consultation_id]);
    $row = $stmt->fetch();
    if ($row['status'] !== 'Finalized') {
        throw new Exception("Expected consultation status to be 'Finalized', got '{$row['status']}'");
    }
    if ($row['nurse_notes'] !== 'Check BP every 2 hours.' || $row['treatment_notes'] !== 'Administer IV fluids and Paracetamol.') {
        throw new Exception("Nurse notes or treatment notes were not updated correctly in finalized state!");
    }
    if ($row['followup_date'] !== '2026-06-28' || $row['followup_time'] !== '10:00:00' || $row['followup_doctor_id'] != 1) {
        throw new Exception("Expected followup date/time/doctor to be 2026-06-28 10:00:00 with doctor 1 in finalized state, got '{$row['followup_date']} {$row['followup_time']} doctor:{$row['followup_doctor_id']}'");
    }
    if (floatval($row['height'] ?? 0) !== 175.50 || intval($row['respiratory_rate'] ?? 0) !== 18 || intval($row['pain_scale']) !== 2) {
        throw new Exception("Vitals (height, respiratory_rate, pain_scale) were not updated correctly in finalized state! Got: Height: {$row['height']}, Resp: {$row['respiratory_rate']}, Pain Scale: {$row['pain_scale']}");
    }
    if ($row['pain_scale_type'] !== 'FLACC' || $row['hpi_location'] !== 'Head, bilateral' || $row['hpi_quality'] !== 'Dull, pressure-like' || $row['hpi_duration'] !== '2 days' || $row['hpi_timing'] !== 'Intermittent' || $row['hpi_context'] !== 'Work stress, screen time' || $row['hpi_modifying_factor'] !== 'Ibuprofen helps') {
        throw new Exception("HPI fields were not updated correctly in finalized state!");
    }

    $stmt = $pdo->prepare("SELECT status FROM appointments WHERE appointment_id = ?");
    $stmt->execute([$appt_id]);
    $a_status = $stmt->fetchColumn();
    if ($a_status !== 'Completed') {
        throw new Exception("Expected appointment status to be 'Completed', got '$a_status'");
    }

    // Verify lab test completion and values
    $stmt = $pdo->prepare("SELECT * FROM consultation_tests WHERE consultation_id = ?");
    $stmt->execute([$consultation_id]);
    $test_record = $stmt->fetch();
    if ($test_record['priority'] !== 'STAT' || $test_record['category'] !== 'Hematology' || $test_record['status'] !== 'Completed') {
        throw new Exception("Lab test columns priority/category/status were not saved correctly!");
    }

    if (empty($row['nursing_plan'])) {
        throw new Exception("Expected nursing_plan to be updated in finalized state, got empty.");
    }
    echo "Verification passed: Consultation is 'Finalized' and Appointment is 'Completed'.\n";
    echo "Verification passed: Lab test priority/category are correct and status is 'Completed'.\n";
    echo "Verification passed: Nurse notes and treatment notes are updated correctly in database.\n";
    echo "Verification passed: Follow-up date/time are saved correctly in finalized state.\n";
    echo "Verification passed: HPI fields updated correctly in finalized state.\n";
    echo "Verification passed: Structured Nursing Plan JSON updated correctly in finalized state.\n\n";

    // 5. Simulating and verifying follow-up synchronization logic
    echo "5. Simulating and verifying follow-up synchronization logic...\n";
    $test_doctor_id = 1;
    $test_appt_id = 2; // Let's use appointment 2
    
    // Fetch patient_id for appointment 2
    $stmtPatient = $pdo->prepare("SELECT patient_id FROM appointments WHERE appointment_id = ?");
    $stmtPatient->execute([$test_appt_id]);
    $test_patient_id = $stmtPatient->fetchColumn();
    if (!$test_patient_id) {
        throw new Exception("Test patient not found for appointment $test_appt_id");
    }

    // Helper to simulate the exact sync logic in save_consultation.php
    $syncFollowup = function($pdo, $appointment_id, $doctor_id, $patient_id, $followup_date, $followup_time, $consultation_id, $old_followup_date, $old_followup_time) {
        $followup_booked = false;
        if ($followup_date && $followup_time) {
            if ($old_followup_date && $old_followup_time) {
                if ($old_followup_date !== $followup_date || $old_followup_time !== $followup_time) {
                    $stmtCheckAppt = $pdo->prepare(
                        "SELECT COUNT(*) FROM appointments 
                         WHERE patient_id = ? AND doctor_id = ? AND appointment_date = ? AND appointment_time = ? AND status = 'Scheduled'"
                    );
                    $stmtCheckAppt->execute([$patient_id, $doctor_id, $followup_date, $followup_time]);
                    $new_exists = $stmtCheckAppt->fetchColumn() > 0;

                    if ($new_exists) {
                        $stmtDeleteOld = $pdo->prepare(
                            "DELETE FROM appointments 
                             WHERE patient_id = ? AND doctor_id = ? AND appointment_date = ? AND appointment_time = ? AND status = 'Scheduled'"
                        );
                        $stmtDeleteOld->execute([$patient_id, $doctor_id, $old_followup_date, $old_followup_time]);
                    } else {
                        $stmtUpdateAppt = $pdo->prepare(
                            "UPDATE appointments 
                             SET appointment_date = ?, appointment_time = ? 
                             WHERE patient_id = ? AND doctor_id = ? AND appointment_date = ? AND appointment_time = ? AND status = 'Scheduled'"
                        );
                        $stmtUpdateAppt->execute([$followup_date, $followup_time, $patient_id, $doctor_id, $old_followup_date, $old_followup_time]);
                        
                        if ($stmtUpdateAppt->rowCount() === 0) {
                            $stmtFollowup = $pdo->prepare(
                                "INSERT INTO appointments (patient_id, doctor_id, appointment_date, appointment_time, status)
                                 VALUES (?, ?, ?, ?, 'Scheduled')"
                            );
                            $stmtFollowup->execute([$patient_id, $doctor_id, $followup_date, $followup_time]);
                        }
                    }
                    $followup_booked = true;
                } else {
                    $stmtCheckAppt = $pdo->prepare(
                        "SELECT COUNT(*) FROM appointments 
                         WHERE patient_id = ? AND doctor_id = ? AND appointment_date = ? AND appointment_time = ? AND status = 'Scheduled'"
                    );
                    $stmtCheckAppt->execute([$patient_id, $doctor_id, $followup_date, $followup_time]);
                    $exists = $stmtCheckAppt->fetchColumn() > 0;
                    if (!$exists) {
                        $stmtFollowup = $pdo->prepare(
                            "INSERT INTO appointments (patient_id, doctor_id, appointment_date, appointment_time, status)
                             VALUES (?, ?, ?, ?, 'Scheduled')"
                        );
                        $stmtFollowup->execute([$patient_id, $doctor_id, $followup_date, $followup_time]);
                    }
                    $followup_booked = true;
                }
            } else {
                $stmtCheckAppt = $pdo->prepare(
                    "SELECT COUNT(*) FROM appointments 
                     WHERE patient_id = ? AND doctor_id = ? AND appointment_date = ? AND appointment_time = ? AND status = 'Scheduled'"
                );
                $stmtCheckAppt->execute([$patient_id, $doctor_id, $followup_date, $followup_time]);
                $exists = $stmtCheckAppt->fetchColumn() > 0;

                if (!$exists) {
                    $stmtFollowup = $pdo->prepare(
                        "INSERT INTO appointments (patient_id, doctor_id, appointment_date, appointment_time, status)
                         VALUES (?, ?, ?, ?, 'Scheduled')"
                    );
                    $stmtFollowup->execute([$patient_id, $doctor_id, $followup_date, $followup_time]);
                }
                $followup_booked = true;
            }
        } else {
            if ($old_followup_date && $old_followup_time) {
                $stmtDeleteAppt = $pdo->prepare(
                    "DELETE FROM appointments 
                     WHERE patient_id = ? AND doctor_id = ? AND appointment_date = ? AND appointment_time = ? AND status = 'Scheduled'"
                );
                $stmtDeleteAppt->execute([$patient_id, $doctor_id, $old_followup_date, $old_followup_time]);
            }
        }
        return $followup_booked;
    };

    // Test Case A: Initial Followup Creation
    $followup_date = '2026-07-01';
    $followup_time = '14:00:00';
    $old_followup_date = null;
    $old_followup_time = null;
    
    $syncFollowup($pdo, $test_appt_id, $test_doctor_id, $test_patient_id, $followup_date, $followup_time, null, $old_followup_date, $old_followup_time);

    // Verify appointment was created
    $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE patient_id = ? AND doctor_id = ? AND appointment_date = ? AND appointment_time = ? AND status = 'Scheduled'");
    $stmtCheck->execute([$test_patient_id, $test_doctor_id, $followup_date, $followup_time]);
    if ($stmtCheck->fetchColumn() != 1) {
        throw new Exception("Test Case A Failed: Expected exactly 1 scheduled follow-up appointment.");
    }
    echo "Verification passed: Initial follow-up appointment successfully created.\n";

    // Test Case B: Prevent Duplicate booking
    $syncFollowup($pdo, $test_appt_id, $test_doctor_id, $test_patient_id, $followup_date, $followup_time, null, $followup_date, $followup_time);
    $stmtCheck->execute([$test_patient_id, $test_doctor_id, $followup_date, $followup_time]);
    if ($stmtCheck->fetchColumn() != 1) {
        throw new Exception("Test Case B Failed: Duplicate follow-up appointment was created.");
    }
    echo "Verification passed: Duplicate follow-up appointment prevented.\n";

    // Test Case C: Reschedule (Update Date/Time)
    $new_followup_date = '2026-07-02';
    $new_followup_time = '15:30:00';
    $syncFollowup($pdo, $test_appt_id, $test_doctor_id, $test_patient_id, $new_followup_date, $new_followup_time, null, $followup_date, $followup_time);

    // Verify old one is deleted/updated and new one exists
    $stmtCheckOld = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE patient_id = ? AND doctor_id = ? AND appointment_date = ? AND appointment_time = ? AND status = 'Scheduled'");
    $stmtCheckOld->execute([$test_patient_id, $test_doctor_id, $followup_date, $followup_time]);
    if ($stmtCheckOld->fetchColumn() != 0) {
        throw new Exception("Test Case C Failed: Old follow-up appointment still exists after reschedule.");
    }
    $stmtCheckNew = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE patient_id = ? AND doctor_id = ? AND appointment_date = ? AND appointment_time = ? AND status = 'Scheduled'");
    $stmtCheckNew->execute([$test_patient_id, $test_doctor_id, $new_followup_date, $new_followup_time]);
    if ($stmtCheckNew->fetchColumn() != 1) {
        throw new Exception("Test Case C Failed: Rescheduled follow-up appointment was not found.");
    }
    echo "Verification passed: Follow-up appointment successfully rescheduled.\n";

    // Test Case D: Delete on Uncheck (followup date/time set to null)
    $syncFollowup($pdo, $test_appt_id, $test_doctor_id, $test_patient_id, null, null, null, $new_followup_date, $new_followup_time);
    $stmtCheckNew->execute([$test_patient_id, $test_doctor_id, $new_followup_date, $new_followup_time]);
    if ($stmtCheckNew->fetchColumn() != 0) {
        throw new Exception("Test Case D Failed: Follow-up appointment was not deleted when unchecked.");
    }
    echo "Verification passed: Follow-up appointment successfully deleted on uncheck.\n\n";

    // Insert a dummy completed appointment and finalized consultation for patient history verification
    $stmtPastAppt = $pdo->prepare("INSERT INTO `appointments` (`patient_id`, `doctor_id`, `appointment_date`, `appointment_time`, `status`) VALUES (?, ?, ?, ?, 'Completed')");
    $stmtPastAppt->execute([$test_patient_id, $test_doctor_id, '2026-06-15', '09:30:00']);
    $pastApptId = $pdo->lastInsertId();

    $stmtPastConsult = $pdo->prepare("INSERT INTO `consultations` (`appointment_id`, `blood_pressure`, `temperature`, `heart_rate`, `weight`, `oxygen_saturation`, `pain_scale`, `chief_complaint`, `narrative_diagnosis`, `status`, `height`, `respiratory_rate`) VALUES (?, '118/78', 36.7, 70, 70.2, 99, 0, 'Follow up on Acne', 'Mild improvement. Patient tolerating Adapalene well.', 'Finalized', 175.0, 16)");
    $stmtPastConsult->execute([$pastApptId]);
    $pastConsultId = $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO `consultation_diagnoses` (`consultation_id`, `icd_code`, `description`) VALUES (?, 'L70.0', 'Acne vulgaris')")->execute([$pastConsultId]);
    $pdo->prepare("INSERT INTO `consultation_prescriptions` (`consultation_id`, `medicine_name`, `dosage`, `duration`, `instructions`) VALUES (?, 'Adapalene 0.1% Gel', 'Apply thin layer daily at bedtime', '30 days', 'Continue as directed.')")->execute([$pastConsultId]);

    // 6. Verifying custom patient history JSON endpoint
    echo "6. Verifying custom patient history JSON endpoint...\n";
    $test_history_script = __DIR__ . '/test_history_tmp.php';
    file_put_contents($test_history_script, '<?php
        error_reporting(0);
        ini_set("display_errors", "0");
        session_start();
        $_SESSION["doctor_id"] = 1;
        $_GET["patient_id"] = ' . $test_patient_id . ';
        include "actions/get_patient_history.php";
    ');
    $json_output = shell_exec("php " . escapeshellarg($test_history_script) . " 2>&1");
    if (file_exists($test_history_script)) {
        unlink($test_history_script);
    }

    $history_response = json_decode($json_output, true);
    if (!$history_response) {
        throw new Exception("Patient history API did not return valid JSON. Output: " . $json_output);
    }
    if (isset($history_response['error'])) {
        throw new Exception("Patient history API returned error: " . $history_response['error']);
    }
    if (!isset($history_response['patient']) || !isset($history_response['history'])) {
        throw new Exception("Patient history API response missing 'patient' or 'history' key.");
    }
    if ($history_response['patient']['patient_id'] != $test_patient_id) {
        throw new Exception("Patient history API returned patient_id {$history_response['patient']['patient_id']}, expected {$test_patient_id}");
    }
    if (empty($history_response['history'])) {
        throw new Exception("Patient history API history list is empty.");
    }
    echo "Verification passed: Patient history API returned valid structured JSON and correct patient records.\n\n";

    // 7. Verifying cascading conflict resolution and consecutive slot reservation
    echo "7. Verifying cascading conflict resolution and consecutive slot reservation...\n";
    $today_date = date('Y-m-d');
    $pdo->prepare("UPDATE appointments SET appointment_date = ?, appointment_time = ?, status = 'Accepted' WHERE appointment_id = 4")->execute([$today_date, '10:30:00']);
    $pdo->prepare("UPDATE appointments SET appointment_date = ?, appointment_time = ?, status = 'Scheduled' WHERE appointment_id = 5")->execute([$today_date, '11:00:00']);
    $pdo->prepare("UPDATE appointments SET appointment_date = ?, appointment_time = ?, status = 'Scheduled' WHERE appointment_id = 6")->execute([$today_date, '11:30:00']);

    // Run save_consultation.php in a subprocess to isolate its exit() behavior
    $test_save_script = __DIR__ . '/test_save_tmp.php';
    file_put_contents($test_save_script, '<?php
        error_reporting(0);
        ini_set("display_errors", "0");
        session_start();
        register_shutdown_function(function() {
            if (isset($_SESSION["error_msg"])) {
                echo "SESSION_ERROR_MSG: " . $_SESSION["error_msg"] . "\n";
            }
        });
        $_SERVER["REQUEST_METHOD"] = "POST";
        $_SESSION["doctor_id"] = 1;
        $_POST = [
            "appointment_id" => 4,
            "submit_type" => "finalize",
            "nrs_target_procedure" => "IPL / Laser Hair Removal",
            "session_duration" => 3600, // 60 minutes -> 2 slots: 10:30 and 11:00
            "blood_pressure" => "120/80",
            "temperature" => 36.8,
            "heart_rate" => 72,
            "weight" => 70.5,
            "oxygen_saturation" => 98,
            "pain_scale" => 0,
            "height" => 175.50,
            "respiratory_rate" => 18,
            "chief_complaint" => "Checkup",
            "narrative_diagnosis" => "Patient is doing well.",
            "hpi_location" => "General",
            "hpi_duration" => "1 day",
            "icd_code" => ["Z00.00"],
            "icd_description" => ["General medical examination"]
        ];
        include "actions/save_consultation.php";
    ');

    $output = shell_exec("php " . escapeshellarg($test_save_script) . " 2>&1");
    if (file_exists($test_save_script)) {
        unlink($test_save_script);
    }

    // Verify appointment 4 status is Completed
    $stmt = $pdo->prepare("SELECT status FROM appointments WHERE appointment_id = 4");
    $stmt->execute();
    if ($stmt->fetchColumn() !== 'Completed') {
        throw new Exception("Expected appointment 4 to be Completed. Subprocess Output: " . $output);
    }

    // Verify target_procedure was saved in the database
    $stmt = $pdo->prepare("SELECT nursing_plan FROM consultations WHERE appointment_id = 4");
    $stmt->execute();
    $saved_nursing_plan_json = $stmt->fetchColumn();
    $saved_nursing_plan = json_decode($saved_nursing_plan_json, true);
    if (($saved_nursing_plan['target_procedure'] ?? '') !== 'IPL / Laser Hair Removal') {
        throw new Exception("Expected target_procedure to be saved as 'IPL / Laser Hair Removal', got: " . ($saved_nursing_plan['target_procedure'] ?? 'null') . ". Subprocess Output: " . $output);
    }

    // Verify a new completed placeholder appointment exists at 11:00:00
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE doctor_id = 1 AND appointment_date = ? AND appointment_time = ? AND status = 'Completed'");
    $stmt->execute([$today_date, '11:00:00']);
    if ($stmt->fetchColumn() < 1) {
        throw new Exception("Consecutive slot for 11:00:00 was not reserved.");
    }

    // Verify appointment 5 (Aisha Khan) was shifted to 11:30:00
    $stmt = $pdo->prepare("SELECT appointment_time FROM appointments WHERE appointment_id = 5");
    $stmt->execute();
    $appt5_time = $stmt->fetchColumn();
    if (strtotime($appt5_time) !== strtotime('11:30:00')) {
        throw new Exception("Expected appointment 5 to be shifted to 11:30:00, got " . $appt5_time);
    }

    // Verify appointment 6 (Sana Mir) was shifted to 12:00:00
    $stmt = $pdo->prepare("SELECT appointment_time FROM appointments WHERE appointment_id = 6");
    $stmt->execute();
    $appt6_time = $stmt->fetchColumn();
    if (strtotime($appt6_time) !== strtotime('12:00:00')) {
        throw new Exception("Expected appointment 6 to be shifted to 12:00:00, got " . $appt6_time);
    }

    echo "Verification passed: Consecutive slot reserved and conflicts shifted recursively.\n";
    echo "Verification passed: target_procedure saved successfully inside the nursing_plan JSON column.\n\n";

    // 8. Verifying real-time auto-extension and duplicate slot reservation prevention
    echo "8. Verifying real-time auto-extension and duplicate slot reservation prevention...\n";
    $today_date = date('Y-m-d');
    
    // Reset database to ensure clean slate for this test
    $pdo->prepare("UPDATE appointments SET status = 'Accepted', appointment_date = ?, appointment_time = ? WHERE appointment_id = 4")->execute([$today_date, '10:30:00']);
    $pdo->prepare("UPDATE appointments SET status = 'Scheduled', appointment_date = ?, appointment_time = ? WHERE appointment_id = 5")->execute([$today_date, '11:00:00']);
    $pdo->prepare("UPDATE appointments SET status = 'Scheduled', appointment_date = ?, appointment_time = ? WHERE appointment_id = 6")->execute([$today_date, '11:30:00']);
    
    // Delete any Completed slots created from previous tests to avoid interference
    $pdo->prepare("DELETE FROM appointments WHERE status = 'Completed' AND appointment_id > 10")->execute();

    // Run auto_extend_appointment.php in a subprocess
    $test_extend_script = __DIR__ . '/test_extend_tmp.php';
    file_put_contents($test_extend_script, '<?php
        error_reporting(0);
        ini_set("display_errors", "0");
        session_start();
        $_SESSION["doctor_id"] = 1;
        $_POST = [
            "appointment_id" => 4,
            "session_duration" => 3600 // 60 minutes -> requires 11:00 slot
        ];
        include "actions/auto_extend_appointment.php";
    ');

    $extend_output = shell_exec("php " . escapeshellarg($test_extend_script) . " 2>&1");
    if (file_exists($test_extend_script)) {
        unlink($test_extend_script);
    }

    $extend_res = json_decode($extend_output, true);
    if (!$extend_res || !isset($extend_res['success']) || !$extend_res['success']) {
        throw new Exception("Real-time auto-extension AJAX request failed. Output: " . $extend_output);
    }

    // Verify a Completed placeholder appointment was created at 11:00:00
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE doctor_id = 1 AND appointment_date = ? AND appointment_time = ? AND status = 'Completed'");
    $stmt->execute([$today_date, '11:00:00']);
    if ($stmt->fetchColumn() < 1) {
        throw new Exception("Real-time auto-extension did not reserve consecutive slot for 11:00:00.");
    }

    // Verify appointment 5 (Aisha Khan) was shifted to 11:30:00
    $stmt = $pdo->prepare("SELECT appointment_time FROM appointments WHERE appointment_id = 5");
    $stmt->execute();
    $appt5_time = $stmt->fetchColumn();
    if (strtotime($appt5_time) !== strtotime('11:30:00')) {
        throw new Exception("Auto-extension failed to shift conflicting appointment 5 to 11:30:00, got " . $appt5_time);
    }

    // Now run save_consultation.php to finalize, which should not create duplicate slots
    $test_save_dup_script = __DIR__ . '/test_save_dup_tmp.php';
    file_put_contents($test_save_dup_script, '<?php
        error_reporting(0);
        ini_set("display_errors", "0");
        session_start();
        $_SERVER["REQUEST_METHOD"] = "POST";
        $_SESSION["doctor_id"] = 1;
        $_POST = [
            "appointment_id" => 4,
            "submit_type" => "finalize",
            "session_duration" => 3600, // Same 60 minutes
            "blood_pressure" => "120/80",
            "temperature" => 36.8,
            "heart_rate" => 72,
            "weight" => 70.5,
            "oxygen_saturation" => 98,
            "pain_scale" => 0,
            "height" => 175.50,
            "respiratory_rate" => 18,
            "chief_complaint" => "Checkup",
            "narrative_diagnosis" => "Patient is doing well.",
            "hpi_location" => "General",
            "hpi_duration" => "1 day",
            "icd_code" => ["Z00.00"],
            "icd_description" => ["General medical examination"]
        ];
        include "actions/save_consultation.php";
    ');

    $save_dup_output = shell_exec("php " . escapeshellarg($test_save_dup_script) . " 2>&1");
    if (file_exists($test_save_dup_script)) {
        unlink($test_save_dup_script);
    }

    // Verify appointment 4 is Completed
    $stmt = $pdo->prepare("SELECT status FROM appointments WHERE appointment_id = 4");
    $stmt->execute();
    if ($stmt->fetchColumn() !== 'Completed') {
        throw new Exception("Finalization failed after auto-extension. Output: " . $save_dup_output);
    }

    // Verify there is still only 1 completed placeholder appointment at 11:00:00 (no duplicate slot reserved)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE doctor_id = 1 AND appointment_date = ? AND appointment_time = ? AND status = 'Completed'");
    $stmt->execute([$today_date, '11:00:00']);
    $placeholder_count = $stmt->fetchColumn();
    if ($placeholder_count != 1) {
        throw new Exception("Duplicate reservation slot created at 11:00:00. Count: " . $placeholder_count);
    }

    echo "Verification passed: Real-time auto-extension and duplicate prevention checked successfully.\n\n";

    echo "ALL WORKFLOW DB CHECKS PASSED SUCCESSFULLY!\n";

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "TEST FAILED: " . $e->getMessage() . "\n";
}

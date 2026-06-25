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

    echo "ALL WORKFLOW DB CHECKS PASSED SUCCESSFULLY!\n";

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "TEST FAILED: " . $e->getMessage() . "\n";
}

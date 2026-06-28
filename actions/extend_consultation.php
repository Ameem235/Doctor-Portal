<?php
/**
 * Extend Consultation Endpoint
 * ----------------------------------------------------------------------------
 * Invoked by AJAX from the consultation screen when a doctor presses the
 * "Extend" button next to the session timer (e.g. a large treatment that needs
 * more chair time).
 *
 * What it does:
 *   1. Adds the requested minutes to THIS appointment's `duration` (portal DB).
 *   2. Computes the new end time and pushes ONLY the next appointment(s) that
 *      now overlap the extended window. The push cascades: each pushed patient
 *      may in turn collide with the one after it. The moment an upcoming
 *      appointment already starts at/after the running boundary, the cascade
 *      stops — that patient (and everyone after) is left untouched, exactly as
 *      requested.
 *   3. Mirrors every change into the reception scheduler database
 *      (`medcore_hms`) so the front-desk board reflects the new times:
 *        - the extended appointment's `duration`
 *        - each pushed appointment's `start_hour` / `start_minute`
 *      Reception rows are matched by appt_uid == portal `ext_ref`.
 *
 * Returns JSON describing the new end time and what was shifted.
 */

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['doctor_id'])) {
    echo json_encode(['ok' => false, 'error' => 'Unauthenticated']);
    exit();
}

require_once __DIR__ . '/../config/db.php';

$doctor_id = $_SESSION['doctor_id'];

// Accept both JSON and form POST.
$raw  = file_get_contents('php://input');
$json = json_decode($raw, true);
if (!is_array($json)) $json = [];

$appointment_id = (int)($json['appointment_id'] ?? $_POST['appointment_id'] ?? 0);
$extend_minutes = (int)($json['extend_minutes'] ?? $_POST['extend_minutes'] ?? 0);

if ($appointment_id <= 0 || $extend_minutes <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Invalid parameters']);
    exit();
}
// Clamp to a sane range so a typo can't wipe the day's board.
if ($extend_minutes > 480) $extend_minutes = 480;

/** Connect to the reception scheduler DB (best effort; same MySQL server). */
function reception_db()
{
    static $pdo = null;
    if ($pdo !== null) return $pdo ?: null;
    try {
        $pdo = new PDO(
            'mysql:host=127.0.0.1;dbname=medcore_hms;charset=utf8mb4',
            'root',
            '',
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
    } catch (PDOException $e) {
        $pdo = false; // mark as unavailable so we don't retry every call
    }
    return $pdo ?: null;
}

try {
    // 1. Load and authorize the appointment being extended.
    $stmt = $pdo->prepare("
        SELECT appointment_id, doctor_id, patient_id, appointment_date, appointment_time, duration, status, ext_ref
        FROM appointments
        WHERE appointment_id = ?
    ");
    $stmt->execute([$appointment_id]);
    $appt = $stmt->fetch();

    if (!$appt) {
        throw new Exception("Appointment not found.");
    }
    if ($appt['doctor_id'] != $doctor_id) {
        throw new Exception("You are not assigned to this appointment.");
    }
    if ($appt['status'] === 'Scheduled') {
        throw new Exception("Accept the appointment before extending it.");
    }
    if ($appt['status'] === 'Completed') {
        throw new Exception("This consultation is already completed.");
    }

    $date          = $appt['appointment_date'];
    $start_ts      = strtotime($date . ' ' . $appt['appointment_time']);
    $old_duration  = (int)($appt['duration'] ?: 30);
    $new_duration  = $old_duration + $extend_minutes;
    $new_end_ts    = $start_ts + $new_duration * 60;

    $pdo->beginTransaction();

    // 2. Grow this appointment's duration on the portal side.
    $pdo->prepare("UPDATE appointments SET duration = ? WHERE appointment_id = ?")
        ->execute([$new_duration, $appointment_id]);

    // 3. Cascade-push only the upcoming appointments that overlap the new window.
    $stmtNext = $pdo->prepare("
        SELECT appointment_id, appointment_time, duration, ext_ref
        FROM appointments
        WHERE doctor_id = ?
          AND appointment_date = ?
          AND appointment_time > ?
          AND status IN ('Scheduled', 'Accepted')
        ORDER BY appointment_time ASC
    ");
    $stmtNext->execute([$doctor_id, $date, $appt['appointment_time']]);
    $upcoming = $stmtNext->fetchAll();

    $updatePortalTime = $pdo->prepare("UPDATE appointments SET appointment_time = ? WHERE appointment_id = ?");

    $reception_pushes = []; // [ext_ref => 'H:i:s'] for upcoming pushes
    $shifted = [];          // for the JSON response
    $boundary = $new_end_ts;

    foreach ($upcoming as $next) {
        $next_start_ts = strtotime($date . ' ' . $next['appointment_time']);

        // No overlap → this patient already starts after the extension.
        // Per the requirement, stop here: no further appointments change.
        if ($next_start_ts >= $boundary) {
            break;
        }

        // Overlap → move this patient to the running boundary.
        $new_start_str = date('H:i:s', $boundary);
        $updatePortalTime->execute([$new_start_str, $next['appointment_id']]);

        if (!empty($next['ext_ref'])) {
            $reception_pushes[$next['ext_ref']] = $new_start_str;
        }
        $shifted[] = [
            'appointment_id' => (int)$next['appointment_id'],
            'from'           => substr($next['appointment_time'], 0, 5),
            'to'             => substr($new_start_str, 0, 5),
        ];

        // Advance the boundary by this patient's own duration.
        $boundary = $boundary + (int)($next['duration'] ?: 30) * 60;
    }

    $pdo->commit();

    // 4. Mirror everything into the reception scheduler (best effort).
    $reception_synced = false;
    $rdb = reception_db();
    if ($rdb) {
        try {
            // 4a. Extended appointment's new duration.
            if (!empty($appt['ext_ref'])) {
                $rdb->prepare("UPDATE appointments SET duration = ? WHERE appt_uid = ?")
                    ->execute([$new_duration, $appt['ext_ref']]);
            }
            // 4b. Pushed appointments' new start times.
            $rUpd = $rdb->prepare("UPDATE appointments SET start_hour = ?, start_minute = ? WHERE appt_uid = ?");
            foreach ($reception_pushes as $uid => $time_str) {
                $h = (int)date('G', strtotime($time_str));
                $m = (int)date('i', strtotime($time_str));
                $rUpd->execute([$h, $m, $uid]);
            }
            $reception_synced = true;
        } catch (PDOException $e) {
            // Leave reception_synced false; portal change already stands.
            $reception_synced = false;
        }
    }

    echo json_encode([
        'ok'               => true,
        'message'          => 'Consultation extended by ' . $extend_minutes . ' minutes.',
        'appointment_id'   => $appointment_id,
        'extended_minutes' => $extend_minutes,
        'new_duration'     => $new_duration,
        'new_end_time'     => date('H:i', $new_end_ts),
        'shifted'          => $shifted,
        'reception_synced' => $reception_synced,
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}

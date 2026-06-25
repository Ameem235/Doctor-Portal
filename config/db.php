<?php
/**
 * Database Connection Configuration
 * 
 * Establishes a secure PDO connection to the 'medical_center' database.
 */

// Connection parameters
$host = 'localhost';
$db   = 'medical_center';
$user = 'root';
$pass = ''; // Default password is empty for local XAMPP installations
$charset = 'utf8mb4';

// Data Source Name
$dsn = "mysql:host=$host;dbname=$db;charset=$charset";

// PDO options for security and error handling
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    // Instantiate PDO connection
    $pdo = new PDO($dsn, $user, $pass, $options);

    // Run schema migrations for clinical extensions
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM consultations LIKE 'height'");
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE consultations ADD COLUMN height DECIMAL(5,2) NULL");
        }
        $stmt = $pdo->query("SHOW COLUMNS FROM consultations LIKE 'respiratory_rate'");
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE consultations ADD COLUMN respiratory_rate INT NULL");
        }
        $stmt = $pdo->query("SHOW COLUMNS FROM consultations LIKE 'ros_data'");
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE consultations ADD COLUMN ros_data TEXT NULL");
        }
        $stmt = $pdo->query("SHOW COLUMNS FROM consultations LIKE 'exam_data'");
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE consultations ADD COLUMN exam_data TEXT NULL");
        }
    } catch (PDOException $ex) {
        // Suppress migration errors if table/db does not exist yet (e.g. during initial setup)
    }

    /* ------------------------------------------------------------------
     * MedCore (reception) integration migrations.
     *
     * The reception app (medcore_db) sends consultation requests into THIS
     * database via send_to_doctor.php. These idempotent migrations add the
     * linking columns that bridge carries, and seed the reception doctors
     * as portal login accounts so a sent consultation reaches the right
     * doctor's dashboard. Safe to run on every connection.
     * ---------------------------------------------------------------- */
    try {
        // patients: carry the reception Medical Record Number + phone so a
        // patient sent from reception is matched (deduped) instead of cloned.
        $stmt = $pdo->query("SHOW COLUMNS FROM patients LIKE 'mrn'");
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE patients ADD COLUMN mrn VARCHAR(40) NULL");
            $pdo->exec("ALTER TABLE patients ADD UNIQUE KEY uq_patient_mrn (mrn)");
        }
        $stmt = $pdo->query("SHOW COLUMNS FROM patients LIKE 'phone'");
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE patients ADD COLUMN phone VARCHAR(40) NULL");
        }
        // national_id: reception identity field used for deduplication across systems.
        $stmt = $pdo->query("SHOW COLUMNS FROM patients LIKE 'national_id'");
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE patients ADD COLUMN national_id VARCHAR(60) NULL");
        }
        // email: carried from reception patient record.
        $stmt = $pdo->query("SHOW COLUMNS FROM patients LIKE 'email'");
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE patients ADD COLUMN email VARCHAR(150) NULL");
        }

        // appointments: tag the source, carry the reception appointment id
        // (ext_ref, used to dedupe re-sends) and the reason for visit.
        $stmt = $pdo->query("SHOW COLUMNS FROM appointments LIKE 'source'");
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE appointments ADD COLUMN source VARCHAR(20) NOT NULL DEFAULT 'portal'");
        }
        $stmt = $pdo->query("SHOW COLUMNS FROM appointments LIKE 'ext_ref'");
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE appointments ADD COLUMN ext_ref VARCHAR(64) NULL");
            $pdo->exec("ALTER TABLE appointments ADD UNIQUE KEY uq_appt_ext_ref (ext_ref)");
        }
        $stmt = $pdo->query("SHOW COLUMNS FROM appointments LIKE 'chief_complaint'");
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE appointments ADD COLUMN chief_complaint TEXT NULL");
        }
        // duration (minutes): carried from the reception scheduler slot length.
        $stmt = $pdo->query("SHOW COLUMNS FROM appointments LIKE 'duration'");
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE appointments ADD COLUMN duration INT NOT NULL DEFAULT 30");
        }

        // consultations: nursing_plan stores the full structured nursing JSON blob.
        $stmt = $pdo->query("SHOW COLUMNS FROM consultations LIKE 'nursing_plan'");
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE consultations ADD COLUMN nursing_plan TEXT NULL");
        }
        // consultations: draft/finalized workflow status.
        $stmt = $pdo->query("SHOW COLUMNS FROM consultations LIKE 'status'");
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE consultations ADD COLUMN status ENUM('Draft','Finalized') NOT NULL DEFAULT 'Finalized'");
        }
        // consultations: audit timestamps.
        $stmt = $pdo->query("SHOW COLUMNS FROM consultations LIKE 'created_at'");
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE consultations ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP");
            $pdo->exec("ALTER TABLE consultations ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
        }

        // Seed the reception (medcore) doctors as portal login accounts.
        // Idempotent: only inserts an account whose email is not present.
        $receptionDoctors = [
            ['Dr. Mohammed (General Practice)', 'mohammed@medcore.local'],
            ['Dr. Fatima (Dental Surgery)',     'fatima@medcore.local'],
            ['Dr. Roger (Dermatology)',         'roger@medcore.local'],
            ['Dr. Sarah (Pediatrics)',          'sarah@medcore.local'],
            ['Dr. Ali (Orthopedics)',           'ali@medcore.local'],
        ];
        $seedHash = password_hash('password123', PASSWORD_DEFAULT);
        $findDoc = $pdo->prepare("SELECT COUNT(*) FROM doctors WHERE email = ?");
        $addDoc  = $pdo->prepare("INSERT INTO doctors (name, email, password_hash) VALUES (?, ?, ?)");
        foreach ($receptionDoctors as $rd) {
            $findDoc->execute([$rd[1]]);
            if ($findDoc->fetchColumn() == 0) {
                $addDoc->execute([$rd[0], $rd[1], $seedHash]);
            }
        }
    } catch (PDOException $ex) {
        // Suppress migration errors if tables/db do not exist yet (initial setup).
    }
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}
?>

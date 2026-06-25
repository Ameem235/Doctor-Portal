<?php
/**
 * Database Setup and Seeding Script
 * 
 * This file creates the 'medical_center' database and all required tables:
 * doctors, patients, appointments, consultations, consultation_diagnoses,
 * and consultation_prescriptions. It also seeds sample records including a test doctor
 * (doctor@medical.com / password123) and today's appointments to verify the dashboard.
 */

// Database connection parameters for XAMPP (default credentials)
$host = 'localhost';
$username = 'root';
$password = '';

try {
    // 1. Establish connection to MySQL without choosing a database
    $pdo = new PDO("mysql:host=$host;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 2. Create the Database
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `medical_center` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `medical_center`");

    // 3. Drop existing tables if they exist to force clean schema creation
    $pdo->exec("DROP TABLE IF EXISTS `consultation_prescriptions`");
    $pdo->exec("DROP TABLE IF EXISTS `consultation_diagnoses`");
    $pdo->exec("DROP TABLE IF EXISTS `consultation_tests`");
    $pdo->exec("DROP TABLE IF EXISTS `consultations`");
    $pdo->exec("DROP TABLE IF EXISTS `appointments`");
    $pdo->exec("DROP TABLE IF EXISTS `patients`");
    $pdo->exec("DROP TABLE IF EXISTS `doctors`");

    // 4. Create Tables
    
    // doctors table
    $pdo->exec("CREATE TABLE IF NOT EXISTS `doctors` (
        `doctor_id` INT AUTO_INCREMENT PRIMARY KEY,
        `name` VARCHAR(255) NOT NULL,
        `email` VARCHAR(255) UNIQUE NOT NULL,
        `password_hash` VARCHAR(255) NOT NULL
    ) ENGINE=InnoDB;");

    // patients table
    // `mrn` / `phone` carry reception (MedCore) identity so consultations sent
    // from the reception side match an existing patient instead of cloning one.
    $pdo->exec("CREATE TABLE IF NOT EXISTS `patients` (
        `patient_id` INT AUTO_INCREMENT PRIMARY KEY,
        `name` VARCHAR(255) NOT NULL,
        `dob` DATE NOT NULL,
        `gender` ENUM('Male', 'Female', 'Other') NOT NULL,
        `mrn` VARCHAR(40) NULL,
        `phone` VARCHAR(40) NULL,
        UNIQUE KEY `uq_patient_mrn` (`mrn`)
    ) ENGINE=InnoDB;");

    // appointments table
    // `source` distinguishes portal-created vs reception-sent rows; `ext_ref`
    // is the reception appointment id (dedupes re-sends); `chief_complaint`
    // carries the reason for visit captured at reception.
    $pdo->exec("CREATE TABLE IF NOT EXISTS `appointments` (
        `appointment_id` INT AUTO_INCREMENT PRIMARY KEY,
        `patient_id` INT NOT NULL,
        `doctor_id` INT NOT NULL,
        `appointment_date` DATE NOT NULL,
        `appointment_time` TIME NOT NULL,
        `status` ENUM('Scheduled', 'Accepted', 'Completed') DEFAULT 'Scheduled',
        `source` VARCHAR(20) NOT NULL DEFAULT 'portal',
        `ext_ref` VARCHAR(64) NULL,
        `chief_complaint` TEXT NULL,
        UNIQUE KEY `uq_appt_ext_ref` (`ext_ref`),
        FOREIGN KEY (`patient_id`) REFERENCES `patients`(`patient_id`) ON DELETE CASCADE,
        FOREIGN KEY (`doctor_id`) REFERENCES `doctors`(`doctor_id`) ON DELETE CASCADE
    ) ENGINE=InnoDB;");

    // consultations table
    $pdo->exec("CREATE TABLE IF NOT EXISTS `consultations` (
        `consultation_id` INT AUTO_INCREMENT PRIMARY KEY,
        `appointment_id` INT UNIQUE NOT NULL,
        `blood_pressure` VARCHAR(20) NULL,
        `temperature` DECIMAL(4,1) NULL,
        `heart_rate` INT NULL,
        `weight` DECIMAL(5,2) NULL,
        `oxygen_saturation` INT NULL,
        `pain_scale` INT NULL,
        `allergy_notes` TEXT NULL,
        `referred_by` VARCHAR(255) NULL,
        `medical_history` TEXT NULL,
        `family_history` TEXT NULL,
        `social_history` TEXT NULL,
        `surgical_history` TEXT NULL,
        `chief_complaint` TEXT NULL,
        `pain_scale_type` VARCHAR(50) NULL,
        `hpi_location` VARCHAR(255) NULL,
        `hpi_quality` VARCHAR(255) NULL,
        `hpi_duration` VARCHAR(255) NULL,
        `hpi_timing` VARCHAR(255) NULL,
        `hpi_context` VARCHAR(255) NULL,
        `hpi_modifying_factor` VARCHAR(255) NULL,
        `physical_examination` TEXT NULL,
        `narrative_diagnosis` TEXT NULL,
        `nurse_notes` TEXT NULL,
        `treatment_notes` TEXT NULL,
        `followup_date` DATE NULL,
        `followup_time` TIME NULL,
        `followup_doctor_id` INT NULL,
        `height` DECIMAL(5,2) NULL,
        `respiratory_rate` INT NULL,
        `ros_data` TEXT NULL,
        `exam_data` TEXT NULL,
        `nursing_plan` TEXT NULL,
        `status` ENUM('Draft', 'Finalized') DEFAULT 'Finalized',
        FOREIGN KEY (`appointment_id`) REFERENCES `appointments`(`appointment_id`) ON DELETE CASCADE,
        FOREIGN KEY (`followup_doctor_id`) REFERENCES `doctors`(`doctor_id`) ON DELETE SET NULL
    ) ENGINE=InnoDB;");

    // consultation_diagnoses table
    $pdo->exec("CREATE TABLE IF NOT EXISTS `consultation_diagnoses` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `consultation_id` INT NOT NULL,
        `icd_code` VARCHAR(20) NOT NULL,
        `description` TEXT NOT NULL,
        FOREIGN KEY (`consultation_id`) REFERENCES `consultations`(`consultation_id`) ON DELETE CASCADE
    ) ENGINE=InnoDB;");

    // consultation_prescriptions table
    $pdo->exec("CREATE TABLE IF NOT EXISTS `consultation_prescriptions` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `consultation_id` INT NOT NULL,
        `medicine_name` VARCHAR(255) NOT NULL,
        `dosage` VARCHAR(100) NOT NULL,
        `duration` VARCHAR(100) NOT NULL,
        `instructions` TEXT NULL,
        FOREIGN KEY (`consultation_id`) REFERENCES `consultations`(`consultation_id`) ON DELETE CASCADE
    ) ENGINE=InnoDB;");

    // consultation_tests table
    $pdo->exec("CREATE TABLE IF NOT EXISTS `consultation_tests` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `consultation_id` INT NOT NULL,
        `test_name` VARCHAR(255) NOT NULL,
        `category` VARCHAR(100) NOT NULL,
        `priority` ENUM('Routine', 'Urgent', 'STAT') DEFAULT 'Routine',
        `status` ENUM('Ordered', 'Completed') DEFAULT 'Completed',
        `result_summary` TEXT NOT NULL,
        FOREIGN KEY (`consultation_id`) REFERENCES `consultations`(`consultation_id`) ON DELETE CASCADE
    ) ENGINE=InnoDB;");

    // 5. Seed Seed Data
    
    // Seed Doctors
    $hashedPass = password_hash('password123', PASSWORD_DEFAULT);
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM `doctors` WHERE `email` = ?");
    $stmt->execute(['doctor@medical.com']);
    if ($stmt->fetchColumn() == 0) {
        $insertDoctor = $pdo->prepare("INSERT INTO `doctors` (`name`, `email`, `password_hash`) VALUES (?, ?, ?)");
        $insertDoctor->execute(['Dr. Elizabeth Blackwell', 'doctor@medical.com', $hashedPass]);
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM `doctors` WHERE `email` = ?");
    $stmt->execute(['gregory@house.com']);
    if ($stmt->fetchColumn() == 0) {
        $insertDoctor = $pdo->prepare("INSERT INTO `doctors` (`name`, `email`, `password_hash`) VALUES (?, ?, ?)");
        $insertDoctor->execute(['Dr. Gregory House (Infectious Diseases)', 'gregory@house.com', $hashedPass]);
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM `doctors` WHERE `email` = ?");
    $stmt->execute(['valentine@mott.com']);
    if ($stmt->fetchColumn() == 0) {
        $insertDoctor = $pdo->prepare("INSERT INTO `doctors` (`name`, `email`, `password_hash`) VALUES (?, ?, ?)");
        $insertDoctor->execute(['Dr. Valentine Mott (Cardiology)', 'valentine@mott.com', $hashedPass]);
    }

    // Seed the reception (MedCore) doctors as portal login accounts so a
    // consultation routed to any reception doctor reaches a real login.
    // All use the demo password 'password123'.
    $receptionDoctors = [
        ['Dr. Mohammed (General Practice)', 'mohammed@medcore.local'],
        ['Dr. Fatima (Dental Surgery)',     'fatima@medcore.local'],
        ['Dr. Roger (Dermatology)',         'roger@medcore.local'],
        ['Dr. Sarah (Pediatrics)',          'sarah@medcore.local'],
        ['Dr. Ali (Orthopedics)',           'ali@medcore.local'],
    ];
    foreach ($receptionDoctors as $rd) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM `doctors` WHERE `email` = ?");
        $stmt->execute([$rd[1]]);
        if ($stmt->fetchColumn() == 0) {
            $insertDoctor = $pdo->prepare("INSERT INTO `doctors` (`name`, `email`, `password_hash`) VALUES (?, ?, ?)");
            $insertDoctor->execute([$rd[0], $rd[1], $hashedPass]);
        }
    }

    // Get doctor_id of seeded doctor
    $stmt = $pdo->prepare("SELECT `doctor_id` FROM `doctors` WHERE `email` = ?");
    $stmt->execute(['doctor@medical.com']);
    $doctorId = $stmt->fetchColumn();

    // Seed Patients and Appointments
    $seedData = [
        [
            'name' => 'Muhammad Ali',
            'dob' => '1988-03-12',
            'gender' => 'Male',
            'appt_date' => date('Y-m-d'),
            'appt_time' => '09:00:00'
        ],
        [
            'name' => 'Fatima Bibi',
            'dob' => '1995-07-22',
            'gender' => 'Female',
            'appt_date' => date('Y-m-d'),
            'appt_time' => '10:30:00'
        ],
        [
            'name' => 'Aisha Khan',
            'dob' => '1991-11-05',
            'gender' => 'Female',
            'appt_date' => date('Y-m-d'),
            'appt_time' => '11:45:00'
        ],
        [
            'name' => 'Sana Mir',
            'dob' => '1992-12-05',
            'gender' => 'Female',
            'appt_date' => date('Y-m-d'),
            'appt_time' => '13:00:00'
        ],
        [
            'name' => 'Kamran Akmal',
            'dob' => '1985-06-15',
            'gender' => 'Male',
            'appt_date' => date('Y-m-d'),
            'appt_time' => '15:00:00'
        ],
        [
            'name' => 'Yasmin Rashid',
            'dob' => '1960-04-10',
            'gender' => 'Female',
            'appt_date' => date('Y-m-d', strtotime('+1 day')),
            'appt_time' => '10:00:00'
        ],
        [
            'name' => 'Imran Khan',
            'dob' => '1972-10-05',
            'gender' => 'Male',
            'appt_date' => date('Y-m-d', strtotime('+1 day')),
            'appt_time' => '11:30:00'
        ],
        [
            'name' => 'Bilal Ahmed',
            'dob' => '1982-01-30',
            'gender' => 'Male',
            'appt_date' => date('Y-m-d', strtotime('+1 day')),
            'appt_time' => '14:15:00'
        ],
        [
            'name' => 'Zainab Yousaf',
            'dob' => '2000-09-18',
            'gender' => 'Female',
            'appt_date' => date('Y-m-d', strtotime('+1 day')),
            'appt_time' => '15:30:00'
        ],
        [
            'name' => 'Tariq Mahmood',
            'dob' => '1975-05-14',
            'gender' => 'Male',
            'appt_date' => date('Y-m-d', strtotime('+1 day')),
            'appt_time' => '16:45:00'
        ]
    ];

    foreach ($seedData as $data) {
        $insertPatient = $pdo->prepare("INSERT INTO `patients` (`name`, `dob`, `gender`) VALUES (?, ?, ?)");
        $insertPatient->execute([$data['name'], $data['dob'], $data['gender']]);
        $patientId = $pdo->lastInsertId();

        $insertAppt = $pdo->prepare("INSERT INTO `appointments` (`patient_id`, `doctor_id`, `appointment_date`, `appointment_time`, `status`) VALUES (?, ?, ?, ?, 'Scheduled')");
        $insertAppt->execute([$patientId, $doctorId, $data['appt_date'], $data['appt_time']]);
    }

    $setupSuccess = true;
} catch (PDOException $e) {
    $setupSuccess = false;
    $errorMessage = $e->getMessage();
}
?>
<?php
$pageTitle = "Database Setup";
include_once __DIR__ . '/../includes/header.php';
?>
</head>
<body class="bg-hms-bg min-h-screen flex items-center justify-center p-4 font-sans text-hms-dark">
    <div class="w-full max-w-md my-8">
        <div class="bg-white border border-hms-border rounded-2xl p-6 shadow-sm">
            <div class="text-center mb-6">
                <h2 class="font-serif text-2xl font-bold text-hms-dark mb-1">Database Setup</h2>
                <p class="text-hms-mid text-sm font-medium">Medical Centre Management System</p>
            </div>

            <?php if ($setupSuccess): ?>
                <div class="mb-5 p-4 bg-emerald-50 border border-emerald-200 rounded-xl text-emerald-800 text-sm font-medium flex items-start gap-3" role="alert">
                    <svg class="flex-shrink-0 w-5 h-5 text-emerald-600 mt-0.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    <div>
                        Database and tables initialized successfully!
                    </div>
                </div>

                <div class="bg-hms-panel border border-hms-border rounded-xl p-5 mb-5 text-sm">
                    <h5 class="font-serif font-bold text-hms-dark mb-3">Seeded Demo Doctor Account</h5>
                    <div class="space-y-1.5 font-medium text-hms-mid">
                        <p><strong>Email:</strong> <code class="bg-white px-2 py-0.5 rounded border border-hms-border text-hms-accent font-mono text-xs">doctor@medical.com</code></p>
                        <p><strong>Password:</strong> <code class="bg-white px-2 py-0.5 rounded border border-hms-border text-hms-accent font-mono text-xs">password123</code></p>
                    </div>
                </div>

                <div class="bg-blue-50 border border-blue-200 text-blue-850 p-4 rounded-xl text-xs font-medium mb-6">
                    Ten patient records and ten corresponding appointments with distinct dates and times have been generated.
                </div>

                <div>
                    <a href="login.php" class="w-full flex justify-center items-center bg-hms-accent hover:bg-hms-accentDim text-white rounded-full py-3 text-sm font-semibold tracking-wide shadow-sm transition duration-200">Proceed to Login</a>
                </div>
            <?php else: ?>
                <div class="p-5 bg-red-50 border border-red-200 rounded-xl text-red-800 text-sm font-medium mb-6" role="alert">
                    <h4 class="font-serif font-bold text-lg mb-2">Setup Failed!</h4>
                    <p class="mb-3 text-red-700">An error occurred while setting up the database. Please ensure your XAMPP/MySQL server is running and configured correctly.</p>
                    <hr class="border-red-200 my-3">
                    <p class="font-mono text-xs bg-white p-3 rounded border border-red-200 overflow-x-auto text-red-600"><strong>Error Details:</strong> <?php echo htmlspecialchars($errorMessage); ?></p>
                </div>
                
                <div>
                    <a href="setup.php" class="w-full flex justify-center items-center bg-hms-mid hover:bg-hms-dark text-white rounded-full py-3 text-sm font-semibold tracking-wide shadow-sm transition duration-200">Retry Setup</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php include_once __DIR__ . '/../includes/footer.php'; ?>


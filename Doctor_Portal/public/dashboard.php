<?php
/**
 * Doctor Dashboard / Scheduler Page
 * 
 * Implements a premium scheduling interface with a left mini-calendar,
 * waiting list status, welcome dashboard, and chronological Day Scheduler.
 */

// Start session
session_start();

// Verify doctor authentication
if (!isset($_SESSION['doctor_id'])) {
    header("Location: login.php");
    exit();
}

// Include database connection
require_once __DIR__ . '/../config/db.php';

$doctor_id = $_SESSION['doctor_id'];
$doctor_name = $_SESSION['doctor_name'];
$today = date('Y-m-d');

// Determine calendar settings
$calendar_month = isset($_GET['month']) ? intval($_GET['month']) : intval(date('m'));
$calendar_year = isset($_GET['year']) ? intval($_GET['year']) : intval(date('Y'));

if ($calendar_month < 1 || $calendar_month > 12) { $calendar_month = intval(date('m')); }
if ($calendar_year < 1970 || $calendar_year > 2100) { $calendar_year = intval(date('Y')); }

// Check if a date is selected from the calendar
$selected_date_str = isset($_GET['date']) ? trim($_GET['date']) : null;
if ($selected_date_str) {
    // Validate date format
    $temp_time = strtotime($selected_date_str);
    if (!$temp_time) {
        $selected_date_str = null;
    } else {
        $selected_date_str = date('Y-m-d', $temp_time);
        // Automatically sync calendar view to the selected date's month/year on first request
        if (!isset($_GET['month']) && !isset($_GET['year'])) {
            $calendar_month = intval(date('m', $temp_time));
            $calendar_year = intval(date('Y', $temp_time));
        }
    }
}

// Handle POST actions (Accept, Reschedule, Delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];
        $appt_id = filter_input(INPUT_POST, 'appointment_id', FILTER_VALIDATE_INT);
        
        if ($appt_id) {
            try {
                // Verify ownership first
                $chk = $pdo->prepare("SELECT doctor_id, status FROM appointments WHERE appointment_id = ?");
                $chk->execute([$appt_id]);
                $appt_data = $chk->fetch();
                
                if ($appt_data && $appt_data['doctor_id'] == $doctor_id) {
                    if ($action === 'accept') {
                        if ($appt_data['status'] === 'Scheduled') {
                            $update_stmt = $pdo->prepare("UPDATE appointments SET status = 'Accepted' WHERE appointment_id = ?");
                            $update_stmt->execute([$appt_id]);
                            $_SESSION['success_msg'] = "Appointment #$appt_id accepted successfully. You can now start the consultation.";
                        } else {
                            $_SESSION['error_msg'] = "Only Scheduled appointments can be accepted.";
                        }
                    } elseif ($action === 'reschedule') {
                        $new_date = trim($_POST['new_date'] ?? '');
                        if (!empty($new_date)) {
                            // Find the next available 15-minute slot for this doctor on $new_date
                            $stmtTaken = $pdo->prepare("SELECT appointment_time FROM appointments WHERE doctor_id = ? AND appointment_date = ?");
                            $stmtTaken->execute([$doctor_id, $new_date]);
                            $taken_times = $stmtTaken->fetchAll(PDO::FETCH_COLUMN);
                            
                            $taken_formatted = array_map(function($time_str) {
                                return date('H:i:s', strtotime($time_str));
                            }, $taken_times);
                            
                            $new_time = null;
                            $start = strtotime('09:00:00');
                            $end = strtotime('18:00:00');
                            for ($t = $start; $t <= $end; $t += 15 * 60) {
                                $candidate = date('H:i:s', $t);
                                if (!in_array($candidate, $taken_formatted)) {
                                    $new_time = $candidate;
                                    break;
                                }
                            }
                            
                            if ($new_time) {
                                // Update date/time; reset Accepted back to Scheduled so doctor re-accepts
                                $new_status = ($appt_data['status'] === 'Accepted') ? 'Scheduled' : $appt_data['status'];
                                
                                // Fetch old follow-up values if rescheduling a follow-up
                                $stmtConsult = $pdo->prepare("SELECT consultation_id, followup_date, followup_time FROM consultations WHERE appointment_id = ?");
                                $stmtConsult->execute([$appt_id]);
                                $consult = $stmtConsult->fetch();
                                
                                if ($consult) {
                                    // If it is associated with a consultation follow-up, sync the consultations table too
                                    $stmtUpdateConsult = $pdo->prepare("UPDATE consultations SET followup_date = ?, followup_time = ? WHERE consultation_id = ?");
                                    $stmtUpdateConsult->execute([$new_date, $new_time, $consult['consultation_id']]);
                                }

                                $rs = $pdo->prepare("UPDATE appointments SET appointment_date = ?, appointment_time = ?, status = ? WHERE appointment_id = ?");
                                $rs->execute([$new_date, $new_time, $new_status, $appt_id]);
                                
                                $date_label = date('M d, Y', strtotime($new_date));
                                $time_label = date('h:i A', strtotime($new_time));
                                $_SESSION['success_msg'] = "Appointment #$appt_id rescheduled to $date_label at $time_label.";
                            } else {
                                $_SESSION['error_msg'] = "No available 15-minute slots on the selected day.";
                            }
                        } else {
                            $_SESSION['error_msg'] = "Please provide a new date to reschedule.";
                        }
                    } elseif ($action === 'delete') {
                        // Delete appointment (cascading deletes consultations/diagnoses/prescriptions automatically)
                        $delete_stmt = $pdo->prepare("DELETE FROM appointments WHERE appointment_id = ?");
                        $delete_stmt->execute([$appt_id]);
                        $_SESSION['success_msg'] = "Appointment #$appt_id successfully deleted.";
                    }
                } else {
                    $_SESSION['error_msg'] = "Unauthorized operation or appointment not found.";
                }
            } catch (PDOException $e) {
                $_SESSION['error_msg'] = "Operation failed: " . $e->getMessage();
            }
        }
    }
    
    // Redirect back to preserve date selection
    $redirect_url = "dashboard.php";
    $params = [];
    if ($selected_date_str) { $params[] = "date=" . urlencode($selected_date_str); }
    if (isset($_GET['month'])) { $params[] = "month=" . intval($_GET['month']); }
    if (isset($_GET['year'])) { $params[] = "year=" . intval($_GET['year']); }
    if (!empty($params)) {
        $redirect_url .= "?" . implode("&", $params);
    }
    header("Location: " . $redirect_url);
    exit();
}

// Retrieve flash message if set, then clear it
$success_msg = $_SESSION['success_msg'] ?? '';
$error_msg = $_SESSION['error_msg'] ?? '';
unset($_SESSION['success_msg'], $_SESSION['error_msg']);

// Generate calendar variables
$num_days = cal_days_in_month(CAL_GREGORIAN, $calendar_month, $calendar_year);
$first_day_timestamp = mktime(0, 0, 0, $calendar_month, 1, $calendar_year);
$first_day_of_week = date('w', $first_day_timestamp);

$days_grid = [];
for ($i = 0; $i < $first_day_of_week; $i++) {
    $days_grid[] = null;
}
for ($day = 1; $day <= $num_days; $day++) {
    $days_grid[] = $day;
}
while (count($days_grid) % 7 !== 0) {
    $days_grid[] = null;
}

$month_names = [
    1 => "Jan", 2 => "Feb", 3 => "Mar", 4 => "Apr", 5 => "May", 6 => "Jun",
    7 => "Jul", 8 => "Aug", 9 => "Sep", 10 => "Oct", 11 => "Nov", 12 => "Dec"
];
$month_name_full = [
    1 => "January", 2 => "February", 3 => "March", 4 => "April", 5 => "May", 6 => "June",
    7 => "July", 8 => "August", 9 => "September", 10 => "October", 11 => "November", 12 => "December"
];

// Fetch active doctor's appointment days in this month to show indicators
$appt_days = [];
try {
    $stmtDays = $pdo->prepare("
        SELECT DISTINCT appointment_date 
        FROM appointments 
        WHERE doctor_id = ? AND MONTH(appointment_date) = ? AND YEAR(appointment_date) = ?
    ");
    $stmtDays->execute([$doctor_id, $calendar_month, $calendar_year]);
    $appt_days = $stmtDays->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    // Fail silently
}

// Fetch appointments for the selected date
$appointments = [];
$timeline_slots = [];
if ($selected_date_str) {
    try {
        $query = "
            SELECT a.appointment_id, a.appointment_date, a.appointment_time, a.status,
                   p.patient_id, p.name AS patient_name, p.dob, p.gender,
                   c.status AS consultation_status
            FROM appointments a
            INNER JOIN patients p ON a.patient_id = p.patient_id
            LEFT JOIN consultations c ON a.appointment_id = c.appointment_id
            WHERE a.doctor_id = ? AND a.appointment_date = ?
            ORDER BY a.appointment_time ASC
        ";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$doctor_id, $selected_date_str]);
        $appointments = $stmt->fetchAll();

        // Establish standard 15-minute timeline slots from 09:00 to 18:00, merged with actual appointment times
        $timeline_times = [];
        $start_t = strtotime('09:00:00');
        $end_t = strtotime('18:00:00');
        for ($t = $start_t; $t <= $end_t; $t += 15 * 60) {
            $timeline_times[] = date('H:i:s', $t);
        }
        foreach ($appointments as $appt) {
            $t = $appt['appointment_time'];
            if (!in_array($t, $timeline_times)) {
                $timeline_times[] = $t;
            }
        }
        sort($timeline_times);

        foreach ($timeline_times as $t) {
            $timeline_slots[$t] = [];
        }
        foreach ($appointments as $appt) {
            $timeline_slots[$appt['appointment_time']][] = $appt;
        }
    } catch (PDOException $e) {
        $error_msg = "Failed to load appointments: " . $e->getMessage();
    }
}

// Fetch today's clinical metrics for display
try {
    $stmtCompleted = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE doctor_id = ? AND appointment_date = ? AND status = 'Completed'");
    $stmtCompleted->execute([$doctor_id, $today]);
    $completed_today_count = $stmtCompleted->fetchColumn();

    $stmtPending = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE doctor_id = ? AND appointment_date = ? AND status IN ('Scheduled', 'Accepted')");
    $stmtPending->execute([$doctor_id, $today]);
    $pending_today_count = $stmtPending->fetchColumn();

    // Query last 5 recent activities for this doctor
    $activity_query = "
        (SELECT 'Completed Consultation' as activity_type, p.name as patient_name, a.appointment_date, a.appointment_time, c.status as c_status
         FROM consultations c
         INNER JOIN appointments a ON c.appointment_id = a.appointment_id
         INNER JOIN patients p ON a.patient_id = p.patient_id
         WHERE a.doctor_id = ?)
        UNION
        (SELECT 'Accepted Appointment' as activity_type, p.name as patient_name, a.appointment_date, a.appointment_time, 'Finalized' as c_status
         FROM appointments a
         INNER JOIN patients p ON a.patient_id = p.patient_id
         WHERE a.doctor_id = ? AND a.status = 'Accepted' AND a.appointment_id NOT IN (SELECT appointment_id FROM consultations))
        ORDER BY appointment_date DESC, appointment_time DESC LIMIT 5
    ";
    $stmtAct = $pdo->prepare($activity_query);
    $stmtAct->execute([$doctor_id, $doctor_id]);
    $activities = $stmtAct->fetchAll();
} catch (PDOException $e) {
    $completed_today_count = 0;
    $pending_today_count = 0;
    $activities = [];
}

// Helper to calculate patient age
function calculateAge($dob) {
    $birthDate = new DateTime($dob);
    $todayDate = new DateTime();
    $diff = $todayDate->diff($birthDate);
    return $diff->y;
}

$pageTitle = "Scheduler Workspace";
include_once __DIR__ . '/../includes/header.php';
?>
<style>
    /* Compact Layout Styling */
    html, body { height: 100%; overflow: hidden; }
    body { display: flex; flex-direction: column; }
    main#scheduler-container { flex: 1; display: flex; overflow: hidden; }

    /* Stripe Pattern CSS Class */
    .bg-striped-purple {
        background-image: repeating-linear-gradient(45deg, #fdf4ff, #fdf4ff 10px, #fae8ff 10px, #fae8ff 20px);
        background-color: #fdf4ff;
        border-color: #e9d5ff;
    }
    .bg-striped-green {
        background-image: repeating-linear-gradient(45deg, #f0fdf4, #f0fdf4 10px, #dcfce7 10px, #dcfce7 20px);
        background-color: #f0fdf4;
        border-color: #bbf7d0;
    }
    .bg-solid-blue {
        background-color: #eff6ff;
        border-color: #bfdbfe;
    }

    /* Popover Arrow Pointer styling */
    .popover-arrow {
        transform: translateY(-100%);
        margin-top: -10px;
    }
    .popover-arrow::after {
        content: "";
        position: absolute;
        top: 100%;
        left: 24px;
        border-width: 8px;
        border-style: solid;
        border-color: white transparent transparent transparent;
    }
    .popover-arrow::before {
        content: "";
        position: absolute;
        top: 100%;
        left: 23px;
        border-width: 9px;
        border-style: solid;
        border-color: #E5EAF0 transparent transparent transparent;
    }
</style>
</head>
<body class="bg-hms-bg font-sans text-hms-dark">

    <!-- Top Navigation Bar Replica from Screenshot -->
    <header class="h-[60px] bg-white border-b border-hms-border flex items-center justify-between px-6 flex-shrink-0">
        <!-- Brand & Breadcrumb -->
        <div class="flex items-center gap-6">
            <a href="dashboard.php" class="flex items-center gap-2.5 no-underline">
                <svg width="24" height="24" viewBox="0 0 30 30" fill="none">
                    <rect width="30" height="30" rx="7" fill="#4F7CAC" opacity="0.95"/>
                    <rect x="12" y="5"  width="6" height="20" rx="1.5" fill="white"/>
                    <rect x="5"  y="12" width="20" height="6"  rx="1.5" fill="white"/>
                </svg>
                <span class="font-serif text-xl text-hms-dark tracking-wide font-semibold">MedCore</span>
            </a>
            <div class="hidden md:flex items-center gap-1.5 text-[10px] font-bold text-hms-muted tracking-wider uppercase">
                <a href="dashboard.php" class="hover:text-hms-accent no-underline">Home</a>
                <span>&gt;</span>
                <span class="text-hms-mid">Front Desk</span>
                <span>&gt;</span>
                <span class="text-hms-accent">Scheduler</span>
            </div>
        </div>

        <!-- Actions -->
        <div class="flex items-center gap-4">

            <!-- Language and User Settings -->
            <div class="flex items-center gap-3">
                <!-- Profile Avatar -->
                <div class="flex items-center gap-2">
                    <div class="w-8 h-8 rounded-full bg-hms-panel flex items-center justify-center text-xs font-bold text-hms-accent border border-hms-border">
                        <?php 
                        $doc_words = explode(" ", $doctor_name); 
                        echo strtoupper(substr($doc_words[0], 0, 1) . (isset($doc_words[1]) ? substr($doc_words[1], 0, 1) : '')); 
                        ?>
                    </div>
                    <span class="text-xs font-semibold text-hms-dark hidden sm:inline"><?php echo htmlspecialchars($doctor_name); ?></span>
                </div>

                <a href="logout.php" class="no-underline border border-hms-accent text-hms-accent hover:bg-hms-accent hover:text-white rounded-full px-4 py-1 text-xxs font-semibold tracking-wide transition duration-150">Sign Out</a>
            </div>
        </div>
    </header>

    <!-- Main Workspace container -->
    <main id="scheduler-container" class="relative">
        <!-- Centered Patient Details Modal -->
        <div id="global-click-modal" class="fixed inset-0 bg-black/50 z-50 hidden flex items-center justify-center p-4">
            <div class="bg-white rounded-2xl shadow-2xl border border-hms-border w-full max-w-md overflow-hidden relative flex flex-col animate-fade-in" onclick="event.stopPropagation()">
                <!-- Close button -->
                <button type="button" onclick="closePatientModal()" class="absolute top-4 right-4 text-hms-muted hover:text-hms-dark text-xl font-bold transition focus:outline-none z-10">&times;</button>
                
                <!-- Header -->
                <div class="bg-hms-panel p-5 border-b border-hms-border">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <div id="modal-patient-name" class="font-serif font-bold text-base text-hms-dark leading-tight">Patient Name</div>
                            <div id="modal-pin" class="text-xxs text-hms-muted mt-1 font-semibold">PIN: 103115000</div>
                        </div>
                        <span id="modal-status-badge" class="text-xxs font-bold px-2.5 py-0.5 rounded-full uppercase tracking-wider">Scheduled</span>
                    </div>
                </div>
                
                <!-- Content Body -->
                <div class="p-5 space-y-4 text-xs font-medium">
                    <div class="grid grid-cols-2 gap-4 border-b border-hms-border pb-4">
                        <div>
                            <span class="text-hms-muted text-xxs font-bold uppercase tracking-wider block mb-1">DOB &amp; Gender</span>
                            <span id="modal-dob-gender" class="text-hms-dark font-semibold">01/01/2000, Male</span>
                        </div>
                        <div>
                            <span class="text-hms-muted text-xxs font-bold uppercase tracking-wider block mb-1">Mobile</span>
                            <span id="modal-mobile" class="text-hms-dark font-semibold">971-5470000</span>
                        </div>
                    </div>
                    
                    <div class="border-b border-hms-border pb-4">
                        <span class="text-hms-muted text-xxs font-bold uppercase tracking-wider block mb-1">Allotted Schedule</span>
                        <span id="modal-schedule" class="text-hms-dark font-semibold">Monday, June 22, 2026 at 09:00 AM</span>
                    </div>

                    <div class="border-b border-hms-border pb-4">
                        <span class="text-hms-muted text-xxs font-bold uppercase tracking-wider block mb-1">Assigned Items</span>
                        <span class="text-hms-mid text-[11px] font-normal leading-normal block">
                            Consultation GP, Free follow-up consultation of the same diagnosis within 7 days of initial consultation by a General Practitioner, Family Consultation Package
                        </span>
                    </div>
                    
                    <!-- Actions Section -->
                    <div>
                        <span class="text-hms-muted text-xxs font-bold uppercase tracking-wider block mb-2">Actions</span>
                        <div id="modal-actions-container" class="space-y-2">
                            <!-- Populated dynamically via JS -->
                        </div>
                        
                        <!-- Embedded Reschedule Panel inside Modal -->
                        <div id="modal-reschedule-panel" class="hidden bg-hms-bg p-3 rounded-lg border border-hms-border mt-3 animate-fade-in">
                            <form action="dashboard.php?date=<?php echo urlencode($selected_date_str); ?>" method="POST" class="flex flex-wrap items-end gap-3">
                                <input type="hidden" name="appointment_id" id="modal-resched-appt-id" value="">
                                <input type="hidden" name="action" value="reschedule">
                                <div class="flex-grow min-w-[120px]">
                                    <label class="block text-[9px] font-bold text-hms-mid mb-1">New Date</label>
                                    <input type="date" name="new_date" id="modal-resched-date-input" class="w-full border border-hms-border rounded-lg p-2 text-xs outline-none focus:border-hms-accent" required>
                                </div>
                                <div class="flex gap-2">
                                    <button type="submit" class="border-0 bg-hms-accentDim hover:bg-hms-accentDark text-white rounded-lg px-3 py-2 text-xxs font-bold uppercase tracking-wider shadow-sm transition duration-150">Confirm</button>
                                    <button type="button" onclick="document.getElementById('modal-reschedule-panel').classList.add('hidden')" class="border border-hms-border text-hms-mid hover:bg-hms-panel rounded-lg px-3 py-2 text-xxs font-bold uppercase tracking-wider transition duration-150">Cancel</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Left Sidebar: Mini Calendar and Waiting List -->
        <aside class="w-full md:w-[320px] bg-white border-r border-hms-border p-4 flex flex-col justify-between flex-shrink-0 overflow-y-auto">
            
            <!-- Calendar Widget Area -->
            <div class="space-y-4">
                <!-- Year Display and Navs -->
                <div class="flex justify-between items-center px-1">
                    <span class="text-[10px] font-bold text-hms-muted tracking-wider uppercase">Active Year: <?php echo $calendar_year; ?></span>
                    <div class="flex gap-2">
                        <a href="dashboard.php?year=<?php echo $calendar_year - 1; ?>&month=<?php echo $calendar_month; ?><?php echo $selected_date_str ? '&date='.$selected_date_str : ''; ?>" class="no-underline text-hms-muted hover:text-hms-accent text-xs font-bold">&laquo;</a>
                        <a href="dashboard.php?year=<?php echo $calendar_year + 1; ?>&month=<?php echo $calendar_month; ?><?php echo $selected_date_str ? '&date='.$selected_date_str : ''; ?>" class="no-underline text-hms-muted hover:text-hms-accent text-xs font-bold">&raquo;</a>
                    </div>
                </div>

                <!-- Custom Month selection / Grid Container -->
                <div class="flex gap-3">
                    
                    <!-- Month Selector Column (Matches Screenshot layout) -->
                    <div class="flex flex-col justify-between border-r border-hms-border pr-2.5 text-[10px] font-bold text-hms-muted uppercase w-10">
                        <?php
                        $display_months = [2, 3, 4, 5, 6, 7, 8, 9]; // Feb to Sep as shown in screenshot
                        foreach ($display_months as $m_num) {
                            $m_label = $month_names[$m_num];
                            $isActiveMonth = ($calendar_month === $m_num);
                            $month_url = "dashboard.php?month=" . $m_num . "&year=" . $calendar_year . ($selected_date_str ? "&date=" . urlencode($selected_date_str) : "");
                            echo '<a href="' . $month_url . '" class="no-underline py-0.5 hover:text-hms-accent ' . ($isActiveMonth ? 'text-hms-accent font-bold border-l-2 border-hms-accent pl-1.5' : 'text-hms-muted pl-2') . '">' . $m_label . '</a>';
                        }
                        ?>
                    </div>

                    <!-- Day Grid Column -->
                    <div class="flex-1">
                        <div class="flex justify-between items-center mb-2 px-1">
                            <span class="text-xxs uppercase tracking-wider text-hms-dark font-bold"><?php echo $month_name_full[$calendar_month] . ' ' . $calendar_year; ?></span>
                            <a href="dashboard.php?date=<?php echo $today; ?>" class="no-underline text-[9px] font-bold text-hms-accent hover:underline uppercase">Today</a>
                        </div>
                        
                        <!-- Week headers -->
                        <div class="grid grid-cols-7 gap-1 text-center text-[9px] font-bold text-hms-muted uppercase mb-1">
                            <div>Su</div><div>Mo</div><div>Tu</div><div>We</div><div>Th</div><div>Fr</div><div>Sa</div>
                        </div>

                        <!-- Days Grid -->
                        <div class="grid grid-cols-7 gap-1 text-center text-[11px]">
                            <?php foreach ($days_grid as $day): ?>
                                <?php if ($day === null): ?>
                                    <div class="py-1"></div>
                                <?php else: ?>
                                    <?php
                                        $day_str = str_pad($day, 2, '0', STR_PAD_LEFT);
                                        $month_str = str_pad($calendar_month, 2, '0', STR_PAD_LEFT);
                                        $grid_date = $calendar_year . '-' . $month_str . '-' . $day_str;
                                        $hasAppt = in_array($grid_date, $appt_days);
                                        $isSelected = ($selected_date_str === $grid_date);
                                        $isToday = ($today === $grid_date);
                                        
                                        $dayClass = "py-1.5 rounded flex flex-col items-center justify-center cursor-pointer hover:bg-hms-panel font-medium transition duration-100";
                                        if ($isSelected) {
                                            $dayClass .= " bg-hms-accent text-white hover:bg-hms-accentDark";
                                        } elseif ($isToday) {
                                            $dayClass .= " bg-blue-50 text-hms-accent border border-hms-accent/20 font-bold";
                                        } else {
                                            $dayClass .= " text-hms-dark";
                                        }
                                    ?>
                                    <a href="dashboard.php?date=<?php echo $grid_date; ?>&month=<?php echo $calendar_month; ?>&year=<?php echo $calendar_year; ?>" class="no-underline <?php echo $dayClass; ?>">
                                        <span><?php echo $day; ?></span>
                                        <?php if ($hasAppt): ?>
                                            <span class="w-1 h-1 rounded-full <?php echo $isSelected ? 'bg-white' : 'bg-hms-accent'; ?> mt-0.5"></span>
                                        <?php endif; ?>
                                    </a>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Waiting List Area (Matches Screenshot) -->
            <div class="border-t border-hms-border pt-4 mt-6">
                <h4 class="font-serif text-sm font-bold text-hms-dark mb-1">Waiting List</h4>
                <div class="text-hms-muted text-xxs font-medium mb-3 uppercase tracking-wider">No Data Found.</div>
                
                <div class="bg-hms-panel rounded-xl p-3 text-xxs border border-hms-border">
                    <span class="font-bold text-hms-dark block">Reseed Database</span>
                    <span class="text-hms-mid block mt-1 mb-2">Initialize or refresh mock clinical records to test the scheduler views.</span>
                    <a href="setup.php" class="inline-block bg-white text-hms-accent border border-hms-border hover:bg-hms-accent hover:text-white transition rounded-full px-3.5 py-1 font-semibold uppercase no-underline">Seed Data</a>
                </div>
            </div>
        </aside>

        <!-- Right Side: Main Schedule Board / Welcome Screen -->
        <section class="flex-1 bg-hms-bg flex flex-col overflow-hidden p-6">
            
            <!-- Toast notifications -->
            <?php if (!empty($success_msg)): ?>
                <div class="mb-4 p-3 bg-green-50 border-l-4 border-green-500 rounded text-green-700 text-xs font-semibold flex justify-between items-center shadow-sm flex-shrink-0" role="alert" id="toast-success">
                    <span><?php echo htmlspecialchars($success_msg); ?></span>
                    <button type="button" class="text-green-500 hover:text-green-700" onclick="this.parentElement.remove()">×</button>
                </div>
            <?php endif; ?>
            <?php if (!empty($error_msg)): ?>
                <div class="mb-4 p-3 bg-red-50 border-l-4 border-red-500 rounded text-red-700 text-xs font-semibold flex justify-between items-center shadow-sm flex-shrink-0" role="alert" id="toast-error">
                    <span><?php echo htmlspecialchars($error_msg); ?></span>
                    <button type="button" class="text-red-500 hover:text-red-700" onclick="this.parentElement.remove()">×</button>
                </div>
            <?php endif; ?>

            <?php if ($selected_date_str === null): ?>
                <!-- Welcome Screen State -->
                <div class="flex-1 flex flex-col items-center justify-center max-w-2xl mx-auto text-center space-y-6 overflow-y-auto">
                    <div class="p-6 bg-white border border-hms-border rounded-2xl shadow-sm space-y-4">
                        <div class="flex justify-center">
                            <div class="w-16 h-16 bg-hms-panel text-hms-accent rounded-full flex items-center justify-center shadow-inner">
                                <svg xmlns="http://www.w3.org/2000/svg" width="36" height="36" fill="currentColor" viewBox="0 0 16 16">
                                    <path d="M8 8a3 3 0 1 0 0-6 3 3 0 0 0 0 6zm2-3a2 2 0 1 1-4 0 2 2 0 0 1 4 0zm4 8c0 1-1 1-1 1H3s-1 0-1-1 1-4 6-4 6 3 6 4zm-1-.004c-.001-.246-.154-.986-.832-1.664C11.516 10.68 10.025 10 8 10c-2.026 0-3.516.68-4.168 1.332-.678.678-.83 1.418-.832 1.664h10z"/>
                                </svg>
                            </div>
                        </div>
                        <h2 class="font-serif text-2xl font-bold text-hms-dark leading-tight">Welcome, Physician <?php echo htmlspecialchars($doctor_name); ?>!</h2>
                        <p class="text-hms-mid text-sm max-w-md mx-auto">
                            MedCore Clinical Scheduler is ready. Select a specific date from the calendar on the left to view, manage, and record patient consultations.
                        </p>
                        <div>
                            <a href="dashboard.php?date=<?php echo $today; ?>" class="inline-block bg-hms-accent hover:bg-hms-accentDim text-white text-xs font-bold uppercase tracking-wider px-6 py-3 rounded-full shadow transition no-underline">View Today's Appointments</a>
                        </div>
                    </div>

                    <!-- Clinical Activity Summary Grid -->
                    <div class="grid grid-cols-2 gap-4 w-full">
                        <div class="bg-white border border-hms-border rounded-xl p-4 shadow-sm text-center">
                            <span class="text-hms-accent font-serif text-2xl font-bold block"><?php echo $completed_today_count; ?></span>
                            <span class="text-hms-muted text-[10px] font-bold uppercase tracking-wider block mt-1">Completed Today</span>
                        </div>
                        <div class="bg-white border border-hms-border rounded-xl p-4 shadow-sm text-center">
                            <span class="text-hms-accent font-serif text-2xl font-bold block"><?php echo $pending_today_count; ?></span>
                            <span class="text-hms-muted text-[10px] font-bold uppercase tracking-wider block mt-1">Pending Today</span>
                        </div>
                    </div>
                </div>

            <?php else: ?>
                <!-- Day View Scheduler State (Matches Screenshot layout) -->
                
                <!-- Scheduler Header -->
                <div class="bg-white border border-hms-border rounded-xl p-3 shadow-sm mb-4 flex flex-wrap items-center justify-between gap-4 flex-shrink-0">
                    <!-- Left: Navigation and Date -->
                    <div class="flex items-center gap-2">
                        <a href="dashboard.php?date=<?php echo $today; ?>" class="no-underline bg-hms-bg border border-hms-border text-hms-dark text-xs font-semibold px-3 py-1.5 rounded-lg hover:bg-hms-panel transition">TODAY</a>
                        
                        <div class="flex items-center border border-hms-border rounded-lg bg-hms-bg overflow-hidden">
                            <a href="dashboard.php?date=<?php echo date('Y-m-d', strtotime($selected_date_str . ' -1 day')); ?>" class="px-2.5 py-1.5 text-hms-dark hover:bg-hms-panel text-xs font-bold no-underline">&lt;</a>
                            <a href="dashboard.php?date=<?php echo date('Y-m-d', strtotime($selected_date_str . ' +1 day')); ?>" class="px-2.5 py-1.5 text-hms-dark hover:bg-hms-panel text-xs font-bold no-underline border-l border-hms-border">&gt;</a>
                        </div>

                        <span class="font-serif font-bold text-hms-dark text-sm ml-2">
                            <?php echo strtoupper(date('l, F j, Y', strtotime($selected_date_str))); ?>
                        </span>
                    </div>

                    <!-- Center & Right: View Dropdown, Block button, options -->
                    <div class="flex items-center gap-3">
                        <select class="border border-hms-border rounded-lg bg-hms-bg px-2.5 py-1.5 text-xs font-semibold text-hms-dark focus:outline-none">
                            <option selected>Day</option>
                            <option>Week</option>
                            <option>Month</option>
                        </select>

                        <button onclick="alert('Clinical Calendar Blocked: Select slots are now reserved.')" class="bg-hms-accent hover:bg-hms-accentDim text-white text-xs font-bold tracking-wider px-4 py-1.5 rounded-lg shadow-sm transition uppercase">Block Calendar</button>

                        <div class="flex items-center gap-1">
                            <button onclick="window.location.reload();" class="p-1.5 rounded-lg hover:bg-hms-panel text-hms-mid" title="Refresh">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                                    <path fill-rule="evenodd" d="M8 3a5 5 0 1 0 4.546 2.914.5.5 0 0 1 .908-.417A6 6 0 1 1 8 2v1z"/>
                                    <path d="M8 4.466V.534a.25.25 0 0 1 .41-.192l2.36 1.966c.12.1.12.284 0 .384L8.41 4.658A.25.25 0 0 1 8 4.466z"/>
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Enterprise Location Banner -->
                <div class="bg-[#f0f9ff] border-b border-sky-100 px-4 py-2 text-xxs font-bold text-sky-800 tracking-wider flex items-center justify-between flex-shrink-0">
                    <span>DEPARTMENT: ENTERPRISE CLINICS</span>
                    <span>WEEKDAY: <?php echo date('D, n/j', strtotime($selected_date_str)); ?></span>
                </div>

                <!-- Timeline Scheduler List -->
                <div class="flex-grow bg-white border border-hms-border rounded-xl p-4 shadow-sm overflow-y-auto">
                    <div class="space-y-4">
                        <?php foreach ($timeline_slots as $slot_time => $appts): ?>
                            <div class="flex items-start gap-4 py-3 border-b border-dashed border-hms-border last:border-0">
                                
                                <!-- Time Label (Left Column) -->
                                <div class="w-20 flex-shrink-0 text-hms-mid font-semibold text-xs pt-1 uppercase">
                                    <?php echo date('h:i A', strtotime($slot_time)); ?>
                                </div>

                                <!-- Cards Slot Area -->
                                <div class="flex-1 space-y-3">
                                    <?php if (empty($appts)): ?>
                                        <div class="text-hms-muted text-xxs italic pt-1">No schedules registered</div>
                                    <?php else: ?>
                                        <?php foreach ($appts as $appt): ?>
                                            <?php
                                                // Identify styling based on state
                                                if ($appt['status'] === 'Completed') {
                                                    $cardClass = 'bg-striped-green';
                                                    $statusLabel = 'You have finalized case';
                                                    $statusBadge = 'bg-green-150 text-green-700 border border-green-200';
                                                } elseif ($appt['status'] === 'Accepted') {
                                                    if ($appt['consultation_status'] === 'Draft') {
                                                        $cardClass = 'bg-striped-purple';
                                                        $statusLabel = 'Draft Saved';
                                                        $statusBadge = 'bg-purple-100 text-purple-700 border border-purple-200';
                                                    } else {
                                                        $cardClass = 'bg-solid-blue';
                                                        $statusLabel = 'Accepted';
                                                        $statusBadge = 'bg-blue-100 text-blue-700 border border-blue-200';
                                                    }
                                                } else {
                                                    $cardClass = 'bg-solid-blue';
                                                    $statusLabel = 'Scheduled';
                                                    $statusBadge = 'bg-yellow-100 text-yellow-700 border border-yellow-200';
                                                }

                                                $words = explode(" ", $appt['patient_name']);
                                                $initials = strtoupper((isset($words[0][0]) ? $words[0][0] : '') . (isset($words[1][0]) ? $words[1][0] : ''));
                                            ?>
                                            <div class="appointment-card group border rounded-xl p-4 transition duration-150 hover:shadow <?php echo $cardClass; ?> cursor-pointer"
                                                 onclick="openPatientModal(this)"
                                                 data-patient-name="<?php echo htmlspecialchars($appt['patient_name']); ?>"
                                                 data-pin="<?php echo 103115000 + $appt['patient_id']; ?>"
                                                 data-dob-gender="<?php echo date('d/m/Y', strtotime($appt['dob'])) . ', ' . $appt['gender']; ?>"
                                                 data-mobile="971-547<?php echo (400000 + $appt['patient_id'] * 71); ?>"
                                                 data-status="<?php echo $appt['status']; ?>"
                                                 data-consult-status="<?php echo $appt['consultation_status']; ?>"
                                                 data-appt-id="<?php echo $appt['appointment_id']; ?>"
                                                 data-appt-date="<?php echo htmlspecialchars($appt['appointment_date']); ?>"
                                                 data-appt-time="<?php echo date('h:i A', strtotime($appt['appointment_time'])); ?>">
                                                 
                                                 <!-- Card layout -->
                                                 <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
                                                     
                                                     <!-- Patient Details area (Avatar + Name) -->
                                                     <div class="flex items-center gap-3 min-w-[240px]">
                                                         <div class="w-9 h-9 rounded-full bg-hms-accent/10 border border-hms-accent/20 text-hms-accent font-bold flex items-center justify-center text-xs font-serif">
                                                             <?php echo htmlspecialchars($initials); ?>
                                                         </div>
                                                         <div>
                                                             <span class="font-serif font-bold text-hms-dark text-sm hover:text-hms-accent transition block leading-tight">
                                                                 <?php echo htmlspecialchars($appt['patient_name']); ?>
                                                             </span>
                                                             <span class="text-hms-muted text-[10px] block mt-0.5">
                                                                 PIN: <?php echo 103115000 + $appt['patient_id']; ?> &nbsp;&middot;&nbsp; DOB: <?php echo date('d/m/Y', strtotime($appt['dob'])); ?> &nbsp;&middot;&nbsp; <?php echo $appt['gender']; ?>
                                                             </span>
                                                         </div>
                                                     </div>
 
                                                     <!-- Consultation details text -->
                                                     <div class="flex-1 text-[11px] text-hms-mid max-w-lg">
                                                         <span class="font-bold text-hms-dark block mb-0.5">Assigned Items:</span>
                                                         Consultation GP, Free follow-up consultation within 7 days of initial consultation.
                                                     </div>
 
                                                     <!-- Actions and status badge -->
                                                     <div class="flex items-center gap-3 self-end lg:self-center">
                                                         <span class="text-xxs font-bold px-2.5 py-0.5 rounded-full uppercase tracking-wider <?php echo $statusBadge; ?>">
                                                             <?php echo $statusLabel; ?>
                                                         </span>
 
                                                         <span class="h-4 w-[1px] bg-hms-border"></span>
 
                                                         <div class="flex items-center gap-1.5" onclick="event.stopPropagation()">
                                                             <?php if ($appt['status'] === 'Scheduled'): ?>
                                                                 <!-- Accept button -->
                                                                 <form action="dashboard.php?date=<?php echo urlencode($selected_date_str); ?>" method="POST" class="inline">
                                                                     <input type="hidden" name="appointment_id" value="<?php echo $appt['appointment_id']; ?>">
                                                                     <input type="hidden" name="action" value="accept">
                                                                     <button type="submit" class="w-8 h-8 rounded-full bg-hms-accent hover:bg-hms-accentDim text-white flex items-center justify-center font-bold text-base shadow-sm transition border-0" title="Accept Appointment">&rarr;</button>
                                                                 </form>
                                                                 
                                                                 <!-- Reschedule button toggle -->
                                                                 <button type="button" onclick="document.getElementById('reschedule-card-<?php echo $appt['appointment_id']; ?>').classList.toggle('hidden')" class="w-8 h-8 rounded-full border border-hms-border bg-white text-hms-mid hover:bg-hms-panel flex items-center justify-center font-bold transition" title="Reschedule Appointment">
                                                                     <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" viewBox="0 0 16 16">
                                                                         <path d="M11 6.5a.5.5 0 0 1 .5-.5h1a.5.5 0 0 1 .5.5v1a.5.5 0 0 1-.5.5h-1a.5.5 0 0 1-.5-.5v-1z"/>
                                                                         <path d="M3.5 0a.5.5 0 0 1 .5.5V1h8V.5a.5.5 0 0 1 1 0V1h1a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2V3a2 2 0 0 1 2-2h1V.5a.5.5 0 0 1 .5-.5zM1 4v10a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V4H1z"/>
                                                                     </svg>
                                                                 </button>
                                                             
                                                             <?php elseif ($appt['status'] === 'Accepted'): ?>
                                                                 <!-- Start / Resume Consultation link -->
                                                                 <?php if ($appt['consultation_status'] === 'Draft'): ?>
                                                                     <a href="consultation.php?appointment_id=<?php echo urlencode($appt['appointment_id']); ?>" class="w-8 h-8 rounded-full bg-yellow-500 hover:bg-yellow-600 text-white flex items-center justify-center shadow-sm no-underline transition font-bold text-base" title="Resume Consultation">&rarr;</a>
                                                                 <?php else: ?>
                                                                     <a href="consultation.php?appointment_id=<?php echo urlencode($appt['appointment_id']); ?>" class="w-8 h-8 rounded-full bg-hms-accent hover:bg-hms-accentDim text-white flex items-center justify-center shadow-sm no-underline transition font-bold text-base" title="Start Consultation">&rarr;</a>
                                                                 <?php endif; ?>
                                                                 
                                                                 <button type="button" onclick="document.getElementById('reschedule-card-<?php echo $appt['appointment_id']; ?>').classList.toggle('hidden')" class="w-8 h-8 rounded-full border border-hms-border bg-white text-hms-mid hover:bg-hms-panel flex items-center justify-center font-bold transition" title="Reschedule Appointment">
                                                                     <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" viewBox="0 0 16 16">
                                                                         <path d="M11 6.5a.5.5 0 0 1 .5-.5h1a.5.5 0 0 1 .5.5v1a.5.5 0 0 1-.5.5h-1a.5.5 0 0 1-.5-.5v-1z"/>
                                                                         <path d="M3.5 0a.5.5 0 0 1 .5.5V1h8V.5a.5.5 0 0 1 1 0V1h1a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2V3a2 2 0 0 1 2-2h1V.5a.5.5 0 0 1 .5-.5zM1 4v10a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V4H1z"/>
                                                                     </svg>
                                                                 </button>
                                                             
                                                             <?php else: ?>
                                                                 <!-- Completed state: View link -->
                                                                 <a href="view_consultation.php?appointment_id=<?php echo urlencode($appt['appointment_id']); ?>" class="w-8 h-8 rounded-full border border-hms-accent text-hms-accent hover:bg-hms-accent hover:text-white flex items-center justify-center shadow-sm no-underline transition font-bold text-base" title="View Case Sheet">&rarr;</a>
                                                             <?php endif; ?>
 
                                                             <!-- Delete Icon Button -->
                                                             <form action="dashboard.php?date=<?php echo urlencode($selected_date_str); ?>" method="POST" class="inline" onsubmit="return confirm('Delete this appointment record?');">
                                                                 <input type="hidden" name="appointment_id" value="<?php echo $appt['appointment_id']; ?>">
                                                                 <input type="hidden" name="action" value="delete">
                                                                 <button type="submit" class="w-8 h-8 rounded-full bg-red-50 hover:bg-red-100 text-red-600 border border-red-200 flex items-center justify-center shadow-sm transition font-bold text-base" title="Delete Appointment">&minus;</button>
                                                             </form>
                                                         </div>
                                                     </div>
 
                                                 </div>
 
                                                 <!-- Expanding Reschedule Panel inside the card -->
                                                 <div id="reschedule-card-<?php echo $appt['appointment_id']; ?>" class="hidden border-t border-hms-border mt-3 pt-3" onclick="event.stopPropagation()">
                                                     <form action="dashboard.php?date=<?php echo urlencode($selected_date_str); ?>" method="POST" class="flex flex-wrap items-end gap-3 bg-hms-bg p-3 rounded-lg border border-hms-border">
                                                         <input type="hidden" name="appointment_id" value="<?php echo $appt['appointment_id']; ?>">
                                                         <input type="hidden" name="action" value="reschedule">
                                                         <div class="flex-1 min-w-[120px]">
                                                             <label class="block text-[9px] font-bold text-hms-mid mb-1">New Date</label>
                                                             <input type="date" name="new_date" class="w-full border border-hms-border rounded-lg p-2 text-xs outline-none focus:border-hms-accent" value="<?php echo htmlspecialchars($appt['appointment_date']); ?>" required>
                                                         </div>
                                                         <div class="flex gap-2">
                                                             <button type="submit" class="border-0 bg-hms-accentDim hover:bg-hms-accentDark text-white rounded-lg px-3 py-2 text-xxs font-bold uppercase tracking-wider shadow-sm transition duration-150">Confirm</button>
                                                             <button type="button" onclick="document.getElementById('reschedule-card-<?php echo $appt['appointment_id']; ?>').classList.add('hidden')" class="border border-hms-border text-hms-mid hover:bg-hms-panel rounded-lg px-3 py-2 text-xxs font-bold uppercase tracking-wider transition duration-150">Cancel</button>
                                                         </div>
                                                     </form>
                                                 </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>

                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

            <?php endif; ?>

        </section>

    </main>

<script>
    // Dismiss toast banners after 5 seconds
    setTimeout(function() {
        document.querySelectorAll('#toast-success, #toast-error').forEach(function(el) {
            el.style.transition = 'opacity 0.5s ease';
            el.style.opacity = '0';
            setTimeout(function() { el.remove(); }, 500);
        });
    }, 5000);

    function openPatientModal(card) {
        const name = card.dataset.patientName || '';
        const pin = card.dataset.pin || '';
        const dobGender = card.dataset.dobGender || '';
        const mobile = card.dataset.mobile || '';
        const status = card.dataset.status || '';
        const consultStatus = card.dataset.consultStatus || '';
        const apptId = card.dataset.apptId || '';
        const apptDate = card.dataset.apptDate || '';
        const apptTime = card.dataset.apptTime || '';

        // Set text fields
        document.getElementById('modal-patient-name').textContent = name;
        document.getElementById('modal-pin').textContent = 'PIN: ' + pin;
        document.getElementById('modal-dob-gender').textContent = dobGender;
        document.getElementById('modal-mobile').textContent = mobile;
        document.getElementById('modal-schedule').textContent = apptDate + ' at ' + apptTime;

        // Set status badge classes
        const badge = document.getElementById('modal-status-badge');
        badge.textContent = status === 'Completed' ? 'You have finalized case' : (consultStatus === 'Draft' ? 'Draft Saved' : status);
        badge.className = 'text-xxs font-bold px-2.5 py-0.5 rounded-full uppercase tracking-wider ';
        if (status === 'Completed') {
            badge.className += 'bg-green-150 text-green-700 border border-green-200';
        } else if (status === 'Accepted') {
            if (consultStatus === 'Draft') {
                badge.className += 'bg-purple-100 text-purple-700 border border-purple-200';
            } else {
                badge.className += 'bg-blue-100 text-blue-700 border border-blue-200';
            }
        } else {
            badge.className += 'bg-yellow-100 text-yellow-700 border border-yellow-200';
        }

        // Reschedule elements setup
        document.getElementById('modal-resched-appt-id').value = apptId;
        document.getElementById('modal-resched-date-input').value = apptDate;
        document.getElementById('modal-reschedule-panel').classList.add('hidden');

        // Populate actions container
        const actionsContainer = document.getElementById('modal-actions-container');
        actionsContainer.innerHTML = '';

        if (status === 'Scheduled') {
            // Accept form
            const acceptForm = document.createElement('form');
            acceptForm.action = 'dashboard.php?date=' + encodeURIComponent(apptDate);
            acceptForm.method = 'POST';
            acceptForm.className = 'w-full';
            acceptForm.innerHTML = `
                <input type="hidden" name="appointment_id" value="${apptId}">
                <input type="hidden" name="action" value="accept">
                <button type="submit" class="w-full bg-hms-accent hover:bg-hms-accentDim text-white rounded-lg py-2.5 text-xs font-bold uppercase tracking-wider shadow-sm transition border-0">Accept Appointment</button>
            `;
            actionsContainer.appendChild(acceptForm);

            // Reschedule button
            const reschedBtn = document.createElement('button');
            reschedBtn.type = 'button';
            reschedBtn.className = 'w-full bg-white hover:bg-hms-panel text-hms-accent border border-hms-accent rounded-lg py-2.5 text-xs font-bold uppercase tracking-wider transition';
            reschedBtn.textContent = 'Reschedule Appointment';
            reschedBtn.onclick = function() {
                document.getElementById('modal-reschedule-panel').classList.toggle('hidden');
            };
            actionsContainer.appendChild(reschedBtn);

            // Delete form
            const deleteForm = document.createElement('form');
            deleteForm.action = 'dashboard.php?date=' + encodeURIComponent(apptDate);
            deleteForm.method = 'POST';
            deleteForm.className = 'w-full';
            deleteForm.onsubmit = function() { return confirm('Delete this appointment record?'); };
            deleteForm.innerHTML = `
                <input type="hidden" name="appointment_id" value="${apptId}">
                <input type="hidden" name="action" value="delete">
                <button type="submit" class="w-full bg-red-50 hover:bg-red-100 text-red-600 border border-red-200 rounded-lg py-2.5 text-xs font-bold uppercase tracking-wider transition">Delete Appointment</button>
            `;
            actionsContainer.appendChild(deleteForm);

        } else if (status === 'Accepted') {
            // Start / Resume Consultation link
            const consultLink = document.createElement('a');
            consultLink.href = 'consultation.php?appointment_id=' + encodeURIComponent(apptId);
            consultLink.className = 'w-full text-center block no-underline text-white rounded-lg py-2.5 text-xs font-bold uppercase tracking-wider shadow-sm transition ';
            if (consultStatus === 'Draft') {
                consultLink.className += 'bg-yellow-500 hover:bg-yellow-600';
                consultLink.textContent = 'Resume Consultation';
            } else {
                consultLink.className += 'bg-hms-accent hover:bg-hms-accentDim';
                consultLink.textContent = 'Start Consultation';
            }
            actionsContainer.appendChild(consultLink);

            // Reschedule button
            const reschedBtn = document.createElement('button');
            reschedBtn.type = 'button';
            reschedBtn.className = 'w-full bg-white hover:bg-hms-panel text-hms-accent border border-hms-accent rounded-lg py-2.5 text-xs font-bold uppercase tracking-wider transition';
            reschedBtn.textContent = 'Reschedule Appointment';
            reschedBtn.onclick = function() {
                document.getElementById('modal-reschedule-panel').classList.toggle('hidden');
            };
            actionsContainer.appendChild(reschedBtn);

            // Delete form
            const deleteForm = document.createElement('form');
            deleteForm.action = 'dashboard.php?date=' + encodeURIComponent(apptDate);
            deleteForm.method = 'POST';
            deleteForm.className = 'w-full';
            deleteForm.onsubmit = function() { return confirm('Delete this appointment record?'); };
            deleteForm.innerHTML = `
                <input type="hidden" name="appointment_id" value="${apptId}">
                <input type="hidden" name="action" value="delete">
                <button type="submit" class="w-full bg-red-50 hover:bg-red-100 text-red-600 border border-red-200 rounded-lg py-2.5 text-xs font-bold uppercase tracking-wider transition">Delete Appointment</button>
            `;
            actionsContainer.appendChild(deleteForm);

        } else if (status === 'Completed') {
            // View Consultation link
            const viewLink = document.createElement('a');
            viewLink.href = 'view_consultation.php?appointment_id=' + encodeURIComponent(apptId);
            viewLink.className = 'w-full text-center block no-underline bg-white border border-hms-accent text-hms-accent hover:bg-hms-accent hover:text-white rounded-lg py-2.5 text-xs font-bold uppercase tracking-wider transition';
            viewLink.textContent = 'View Case Sheet & Report';
            actionsContainer.appendChild(viewLink);
        }

        // Show modal
        const modal = document.getElementById('global-click-modal');
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    }

    function closePatientModal() {
        const modal = document.getElementById('global-click-modal');
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }

    // Close modal when clicking outside the card (on the backdrop overlay)
    document.getElementById('global-click-modal').addEventListener('click', function(e) {
        if (e.target === this) {
            closePatientModal();
        }
    });
</script>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>

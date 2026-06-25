<?php
/**
 * Shared Navigation Bar Template
 * 
 * Renders MedCore styled navigation context based on page-specific variables.
 */
$showNavUser = isset($doctor_name);
$showBackToDashboard = isset($backToDashboard) && $backToDashboard;
$showPrintRx = isset($printRx) && $printRx;
?>
<nav class="navbar navbar-expand-lg navbar-light bg-white border-b border-hms-border py-2 mb-3 <?php echo isset($navbarNoPrint) && $navbarNoPrint ? 'no-print' : ''; ?>">
    <div class="container">
        <!-- MedCore Logo & Branding -->
        <a class="flex items-center gap-2.5 no-underline" href="dashboard.php">
            <svg width="24" height="24" viewBox="0 0 30 30" fill="none">
                <rect width="30" height="30" rx="7" fill="#4F7CAC" opacity="0.95"/>
                <rect x="12" y="5"  width="6" height="20" rx="1.5" fill="white"/>
                <rect x="5"  y="12" width="20" height="6"  rx="1.5" fill="white"/>
            </svg>
            <span class="font-serif text-xl text-hms-dark tracking-wide font-medium">MedCore</span>
        </a>
        
        <?php if ($showNavUser): ?>
            <button class="navbar-toggler border-0 focus:outline-none" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse justify-content-end" id="navbarNav">
                <div class="navbar-nav items-center gap-4 mt-3 mt-lg-0">
                    <span class="text-hms-mid font-medium text-sm">
                        Welcome, Physician <span class="text-hms-dark font-semibold font-serif"><?php echo htmlspecialchars($doctor_name); ?></span>
                    </span>
                    <a href="logout.php" class="no-underline border border-hms-accent text-hms-accent hover:bg-hms-accent hover:text-white rounded-full px-5 py-1.5 text-xs font-semibold tracking-wide transition duration-200" id="signout-button">Sign Out</a>
                </div>
            </div>
        <?php elseif ($showBackToDashboard || $showPrintRx): ?>
            <div class="navbar-nav items-center ms-auto flex flex-row gap-2">
                <?php if ($showBackToDashboard): ?>
                    <a href="dashboard.php<?php echo isset($backSelectedId) ? '?selected_id=' . urlencode($backSelectedId) : ''; ?>" class="no-underline border border-hms-accent text-hms-accent hover:bg-hms-accent hover:text-white rounded-full px-5 py-1.5 text-xs font-semibold tracking-wide transition duration-200">Back to Dashboard</a>
                <?php endif; ?>
                <?php if ($showPrintRx): ?>
                    <button onclick="window.print()" class="border-0 bg-hms-accent hover:bg-hms-accentDim text-white rounded-full px-5 py-1.5 text-xs font-semibold tracking-wide shadow-sm transition duration-200">Print Prescription Ticket</button>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</nav>

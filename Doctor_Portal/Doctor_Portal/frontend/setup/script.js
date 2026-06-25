document.addEventListener('DOMContentLoaded', () => {
    const resetBtn = document.getElementById('reset-btn');
    const statusDiv = document.getElementById('setup-status');

    resetBtn.addEventListener('click', () => {
        try {
            // Trigger database re-seed
            window.db.resetDatabase();
            
            // Show Success Notification
            statusDiv.innerHTML = `
                <svg class="flex-shrink-0 w-5 h-5 text-emerald-600 mt-0.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                <div>
                    Mock Database successfully reset and re-seeded!
                </div>
            `;
            statusDiv.className = 'mb-5 p-4 bg-emerald-50 border border-emerald-200 rounded-xl text-emerald-800 text-sm font-medium flex items-start gap-3';
        } catch (e) {
            statusDiv.innerHTML = `
                <svg class="flex-shrink-0 w-5 h-5 text-red-600 mt-0.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                </svg>
                <div>
                    Reset failed: ${e.message}
                </div>
            `;
            statusDiv.className = 'mb-5 p-4 bg-red-50 border border-red-200 rounded-xl text-red-800 text-sm font-medium flex items-start gap-3';
        }
    });
});

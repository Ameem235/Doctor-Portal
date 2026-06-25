// ═══════════════════════════════════════════════════════
// MedCore HMS — Scheduler Dashboard Script
// ═══════════════════════════════════════════════════════

// ── AUTH GUARD ──────────────────────────────────────────
const currentUser = window.db.getCurrentUser();
if (!currentUser) { window.location.href = '../login/index.html'; }

// ── STATE ───────────────────────────────────────────────
const MONTH_NAMES = ['January','February','March','April','May','June','July','August','September','October','November','December'];
const DAY_NAMES   = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
const DAY_SHORT   = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];

let selectedId      = null;         // selected appointment_id
let schedulerDate   = new Date();   // the date the scheduler is showing
let miniCalDate     = new Date();   // month the mini-calendar shows
let filterText      = '';           // search filter

// ── BOOT ────────────────────────────────────────────────
document.getElementById('nav-doctor-name').textContent = currentUser.name;
document.getElementById('col-doctor-name').textContent = currentUser.name || 'Enterprise';
document.getElementById('col-doctor-sub').textContent  = currentUser.role || '';

// Auto-select from URL param
const urlParams = new URLSearchParams(window.location.search);
const paramId = parseInt(urlParams.get('selected_id'));
if (!isNaN(paramId)) { selectedId = paramId; }

renderAll();

// ════════════════════════════════════════════════════════
// RENDER ALL
// ════════════════════════════════════════════════════════
function renderAll() {
    renderMiniCal();
    renderScheduler();
    renderWorkspace();
    renderRecentActivities();
}

// ════════════════════════════════════════════════════════
// MINI CALENDAR
// ════════════════════════════════════════════════════════
function renderMiniCal() {
    const year  = miniCalDate.getFullYear();
    const month = miniCalDate.getMonth();

    document.getElementById('mini-cal-title').textContent =
        `${MONTH_NAMES[month].toUpperCase()} ${year}`;

    const grid = document.getElementById('mini-cal-grid');
    grid.innerHTML = '';

    const firstDay = new Date(year, month, 1).getDay();
    const daysInMonth = new Date(year, month + 1, 0).getDate();
    const today = new Date();
    const todayStr = toDateStr(today);
    const schedStr = toDateStr(schedulerDate);

    // Get all appointment dates for this doctor to mark dots
    const allAppts = window.db.getAppointmentsWithPatientDetails(currentUser.doctor_id);
    const apptDates = new Set(allAppts.map(a => a.appointment_date));

    // Empty leading cells
    for (let i = 0; i < firstDay; i++) {
        const cell = document.createElement('div');
        cell.className = 'mc-day mc-empty';
        grid.appendChild(cell);
    }

    // Day cells
    for (let d = 1; d <= daysInMonth; d++) {
        const dateStr = `${year}-${String(month+1).padStart(2,'0')}-${String(d).padStart(2,'0')}`;
        const cell = document.createElement('div');
        cell.textContent = d;
        let cls = 'mc-day';
        if (dateStr === todayStr)   cls += ' mc-today';
        if (dateStr === schedStr && dateStr !== todayStr) cls += ' mc-selected';
        if (apptDates.has(dateStr)) cls += ' mc-has-appt';
        cell.className = cls;
        cell.onclick = () => jumpToDate(dateStr);
        grid.appendChild(cell);
    }
}

function miniCalPrev() {
    miniCalDate = new Date(miniCalDate.getFullYear(), miniCalDate.getMonth() - 1, 1);
    renderMiniCal();
}
function miniCalNext() {
    miniCalDate = new Date(miniCalDate.getFullYear(), miniCalDate.getMonth() + 1, 1);
    renderMiniCal();
}
function goToToday() {
    schedulerDate = new Date();
    miniCalDate   = new Date();
    filterText    = '';
    const inp = document.getElementById('appt-search-input');
    if (inp) inp.value = '';
    renderAll();
}
function schedulerPrev() {
    schedulerDate.setDate(schedulerDate.getDate() - 1);
    miniCalDate = new Date(schedulerDate);
    renderAll();
}
function schedulerNext() {
    schedulerDate.setDate(schedulerDate.getDate() + 1);
    miniCalDate = new Date(schedulerDate);
    renderAll();
}
function jumpToDate(dateStr) {
    schedulerDate = new Date(dateStr + 'T00:00:00');
    miniCalDate   = new Date(schedulerDate);
    renderAll();
}
function changeView(val) {
    if (val === 'week') { alert('Week view coming soon!'); document.getElementById('view-select').value = 'day'; }
}

// ════════════════════════════════════════════════════════
// SCHEDULER GRID
// ════════════════════════════════════════════════════════
function renderScheduler() {
    const dateStr = toDateStr(schedulerDate);

    // Update toolbar date label
    const dayName   = DAY_NAMES[schedulerDate.getDay()].toUpperCase();
    const monthName = MONTH_NAMES[schedulerDate.getMonth()].toUpperCase();
    document.getElementById('sched-date-text').textContent =
        `${dayName}, ${monthName} ${schedulerDate.getDate()}, ${schedulerDate.getFullYear()}`;

    // Get appointments for selected date
    const allAppts = window.db.getAppointmentsWithPatientDetails(currentUser.doctor_id);
    let appts = allAppts.filter(a => a.appointment_date === dateStr);

    // Apply search filter
    if (filterText.trim()) {
        const q = filterText.toLowerCase();
        appts = appts.filter(a => a.patient_name.toLowerCase().includes(q));
    }

    // Sort by time
    appts.sort((a, b) => a.appointment_time.localeCompare(b.appointment_time));

    // Metrics for this day
    const todayStr = toDateStr(new Date());
    const allToday = allAppts.filter(a => a.appointment_date === todayStr);
    const done    = allToday.filter(a => a.status === 'Completed').length;
    const pending = allToday.filter(a => a.status === 'Scheduled' || a.status === 'Accepted').length;
    const total   = allToday.length;

    document.getElementById('tb-total').textContent   = total;
    document.getElementById('tb-done').textContent    = done;
    document.getElementById('tb-pending').textContent = pending;
    document.getElementById('ss-done').textContent    = done;
    document.getElementById('ss-pending').textContent = pending;
    document.getElementById('ss-total').textContent   = total;

    // Build time-slot grid
    const container = document.getElementById('time-slots-container');
    const noMsg     = document.getElementById('no-appt-msg');
    container.innerHTML = '';

    if (appts.length === 0) {
        noMsg.classList.add('visible');
        return;
    }
    noMsg.classList.remove('visible');

    // Determine hour range to show (9 AM → 6 PM by default, expanded if appts outside)
    let minHour = 9, maxHour = 19;
    appts.forEach(a => {
        const h = parseInt(a.appointment_time.split(':')[0]);
        if (h < minHour) minHour = h;
        if (h >= maxHour) maxHour = h + 1;
    });

    // Group appts by slot key "HH:MM"
    const bySlot = {};
    appts.forEach(a => {
        const key = a.appointment_time.substring(0, 5);
        if (!bySlot[key]) bySlot[key] = [];
        bySlot[key].push(a);
    });

    // Render 15-min slots
    for (let h = minHour; h < maxHour; h++) {
        for (let m = 0; m < 60; m += 15) {
            if (h === 18 && m > 0 && maxHour <= 19) break; // Don't show slots past 18:00 unless expanded
            const slotKey = `${String(h).padStart(2,'0')}:${String(m).padStart(2,'0')}`;
            const isMajor = m === 0;

            const row = document.createElement('div');
            row.className = `ts-row${isMajor ? ' ts-row-major' : ''}`;

            // Time label (only on the hour)
            const label = document.createElement('div');
            label.className = 'time-gutter';
            if (isMajor) {
                const hr12 = h % 12 || 12;
                const ampm = h >= 12 ? 'PM' : 'AM';
                label.textContent = `${hr12}:00 ${ampm}`;
            }
            row.appendChild(label);

            // Content area
            const content = document.createElement('div');
            content.className = 'ts-content';

            if (bySlot[slotKey]) {
                bySlot[slotKey].forEach(appt => {
                    content.appendChild(buildApptCard(appt));
                });
            }

            row.appendChild(content);
            container.appendChild(row);
        }
    }
}

// Build a single appointment card
function buildApptCard(appt) {
    const names    = appt.patient_name.split(' ');
    const initials = ((names[0]?.[0] || '') + (names[names.length - 1]?.[0] || '')).toUpperCase();
    const age      = calculateAge(appt.dob);
    const timeStr  = formatApptTime(appt.appointment_time);
    const dobStr   = formatDOB(appt.dob);

    let statusClass = 'bg-solid-blue', statusLabel = 'Scheduled';
    if (appt.status === 'Accepted') {
        if (appt.consultation_status === 'Draft') { statusClass = 'bg-striped-purple'; statusLabel = 'Draft'; }
        else { statusClass = 'bg-solid-blue'; statusLabel = 'Accepted'; }
    } else if (appt.status === 'Completed') { statusClass = 'bg-striped-green'; statusLabel = 'Completed'; }

    const isSelected = selectedId === appt.appointment_id;

    const card = document.createElement('div');
    card.className = `appt-card ${statusClass}${isSelected ? ' ac-selected' : ''}`;
    card.id = `appt-card-${appt.appointment_id}`;
    card.onclick = () => selectAppointment(appt.appointment_id);

    // Build items text like the reference
    const items = `Consultation ${appt.status}${appt.consultation_status === 'Draft' ? ' · Draft in progress' : ''}`;

    card.innerHTML = `
        <div class="appt-avatar">${initials}</div>
        <div class="appt-info">
            <div class="appt-name">${appt.patient_name}</div>
            <div class="appt-meta">PIN: ${String(appt.patient_id).padStart(9,'0')} &nbsp;·&nbsp; DOB/Gender: ${dobStr.split(',')[0]}, ${appt.gender?.charAt(0)}</div>
            <div class="appt-meta" style="color:#4B5563;">Items : ${items}</div>
        </div>
        <div class="appt-actions" onclick="event.stopPropagation()">
            <button class="w-6 h-6 rounded-full bg-hms-accent hover:bg-hms-accentDim text-white flex items-center justify-center font-bold text-xs shadow-sm transition border-0" title="Open" onclick="selectAppointment(${appt.appointment_id})">&rarr;</button>
            <button class="w-6 h-6 rounded-full bg-red-50 hover:bg-red-100 text-red-600 border border-red-200 flex items-center justify-center font-bold text-xs shadow-sm transition" title="Delete" onclick="deleteAppointment(${appt.appointment_id})">&minus;</button>
        </div>
    `;
    return card;
}

// ════════════════════════════════════════════════════════
// APPOINTMENT SELECTION
// ════════════════════════════════════════════════════════
function selectAppointment(id) {
    selectedId = id;
    // Re-highlight cards
    document.querySelectorAll('.appt-card').forEach(c => c.classList.remove('ac-selected'));
    const card = document.getElementById(`appt-card-${id}`);
    if (card) card.classList.add('ac-selected');
    renderWorkspace();
}

// ════════════════════════════════════════════════════════
// WORKSPACE PANEL
// ════════════════════════════════════════════════════════
function renderWorkspace() {
    const wsPanel  = document.getElementById('workspace-panel');
    const wsActive = document.getElementById('ws-active');

    const allAppts = window.db.getAppointmentsWithPatientDetails(currentUser.doctor_id);
    const appt = allAppts.find(a => a.appointment_id === selectedId);

    if (!appt) {
        if (wsPanel) {
            wsPanel.classList.add('hidden');
            wsPanel.classList.remove('flex');
        }
        return;
    }

    if (wsPanel) {
        wsPanel.classList.remove('hidden');
        wsPanel.classList.add('flex');
    }

    document.getElementById('ws-patient-name').textContent = appt.patient_name;
    document.getElementById('ws-patient-id').textContent   = `Patient ID: #${appt.patient_id}`;
    document.getElementById('ws-gender').textContent       = appt.gender || '—';
    document.getElementById('ws-age').textContent          = `${calculateAge(appt.dob)} Yrs Old`;
    document.getElementById('ws-dob').textContent          = formatDOB(appt.dob);
    document.getElementById('ws-date').textContent         = formatFullDate(appt.appointment_date);
    document.getElementById('ws-time').textContent         = formatApptTime(appt.appointment_time);

    const statusSpan = document.getElementById('ws-status');
    statusSpan.className = '';
    let badgeClass = 'badge-scheduled', badgeText = 'Scheduled';
    if (appt.status === 'Accepted') {
        if (appt.consultation_status === 'Draft') { badgeClass = 'badge-draft'; badgeText = 'Draft'; }
        else { badgeClass = 'badge-accepted'; badgeText = 'Accepted'; }
    } else if (appt.status === 'Completed') { badgeClass = 'badge-completed'; badgeText = 'Completed'; }
    statusSpan.className = badgeClass;
    statusSpan.style.cssText = 'font-size:9px;font-weight:700;padding:3px 8px;border-radius:20px;white-space:nowrap;flex-shrink:0;margin-top:2px;';
    statusSpan.textContent = badgeText;

    const actions = document.getElementById('ws-actions-container');
    actions.innerHTML = '';

    const btn = (label, onclick, style) => {
        const b = document.createElement('button');
        b.textContent = label;
        b.setAttribute('style', `width:100%;border-radius:8px;padding:8px 12px;font-size:12px;font-weight:600;cursor:pointer;transition:all 0.15s;${style}`);
        b.onclick = onclick;
        return b;
    };
    const lnk = (label, href, style) => {
        const a = document.createElement('a');
        a.textContent = label;
        a.href = href;
        a.setAttribute('style', `display:block;text-align:center;text-decoration:none;width:100%;border-radius:8px;padding:8px 12px;font-size:12px;font-weight:600;transition:all 0.15s;${style}`);
        return a;
    };

    if (appt.status === 'Scheduled') {
        actions.appendChild(btn('Accept Appointment',
            () => acceptAppointment(appt.appointment_id),
            'background:#4F7CAC;color:white;border:none;'));
        actions.appendChild(btn('Reschedule', toggleReschedulePanel,
            'background:white;color:#4F7CAC;border:1px solid #4F7CAC;'));
        // Reschedule panel
        const rp = buildReschedulePanel(appt);
        actions.appendChild(rp);
        actions.appendChild(btn('Delete Appointment',
            () => deleteAppointment(appt.appointment_id, true),
            'background:white;color:#EF4444;border:1px solid #FCA5A5;'));

    } else if (appt.status === 'Accepted') {
        const isDraft  = appt.consultation_status === 'Draft';
        const btnLabel = isDraft ? 'Resume Consultation' : 'Start Consultation';
        const btnStyle = isDraft
            ? 'background:#F59E0B;color:white;border:none;'
            : 'background:#4F7CAC;color:white;border:none;';
        actions.appendChild(lnk(btnLabel, `../consultation/index.html?appointment_id=${appt.appointment_id}`, btnStyle));
        actions.appendChild(btn('Reschedule', toggleReschedulePanel,
            'background:white;color:#4F7CAC;border:1px solid #4F7CAC;'));
        actions.appendChild(buildReschedulePanel(appt));
        actions.appendChild(btn('Delete Appointment',
            () => deleteAppointment(appt.appointment_id, true),
            'background:white;color:#EF4444;border:1px solid #FCA5A5;'));

    } else if (appt.status === 'Completed') {
        const note = document.createElement('div');
        note.innerHTML = `<div style="background:#EFF6FF;border-left:3px solid #3B82F6;border-radius:6px;padding:10px 12px;margin-bottom:8px;">
            <div style="font-size:12px;font-weight:700;color:#1D4ED8;margin-bottom:2px;">Consultation Completed</div>
            <div style="font-size:10px;color:#3B82F6;line-height:1.5;">Vitals, diagnoses, prescriptions and lab orders recorded.</div>
        </div>`;
        actions.appendChild(note);
        actions.appendChild(lnk('View Case Sheet & Lab Results',
            `../view_consultation/index.html?appointment_id=${appt.appointment_id}`,
            'background:white;color:#4F7CAC;border:1px solid #4F7CAC;'));
    }

    renderRecentActivities();
}

function closeWorkspaceModal() {
    selectedId = null;
    document.querySelectorAll('.appt-card').forEach(c => c.classList.remove('ac-selected'));
    renderWorkspace();
}

function buildReschedulePanel(appt) {
    const div = document.createElement('div');
    div.id = 'reschedule-panel';
    div.className = 'reschedule-panel';
    div.style.cssText = 'background:#F7F9FC;border:1px solid #E5EAF0;border-radius:8px;padding:12px;';
    div.innerHTML = `
        <p style="font-size:10px;color:#6B7280;margin-bottom:10px;line-height:1.5;">Select a new date. Status resets to <strong>Scheduled</strong> if currently Accepted.</p>
        <div style="margin-bottom:8px;">
            <label style="font-size:9px;font-weight:700;color:#6B7280;display:block;margin-bottom:4px;">New Date</label>
            <input type="date" id="resched-date" value="${appt.appointment_date}" style="width:100%;border:1px solid #E5EAF0;border-radius:6px;padding:5px 8px;font-size:11px;outline:none;">
        </div>
        <button onclick="handleReschedule(event, ${appt.appointment_id})" style="width:100%;background:#3D6490;color:white;border:none;border-radius:6px;padding:7px;font-size:11px;font-weight:700;cursor:pointer;">Confirm Reschedule</button>
    `;
    return div;
}

// ════════════════════════════════════════════════════════
// RECENT ACTIVITIES
// ════════════════════════════════════════════════════════
function renderRecentActivities() {
    const container    = document.getElementById('activities-list');
    const noActivities = document.getElementById('no-activities');
    if (!container) return;
    container.innerHTML = '';

    const activities = window.db.getRecentActivities(currentUser.doctor_id);
    if (activities.length === 0) {
        noActivities.style.display = 'block';
        return;
    }
    noActivities.style.display = 'none';

    activities.forEach(act => {
        const label = act.activity_type === 'Completed Consultation'
            ? (act.c_status === 'Draft' ? 'Saved Draft' : 'Completed Consultation')
            : 'Accepted Appointment';
        const dt = formatActivityDateTime(act.appointment_date, act.appointment_time);
        const li = document.createElement('li');
        li.style.cssText = 'border-bottom:1px solid #F0F3F7;padding-bottom:8px;margin-bottom:8px;';
        li.innerHTML = `
            <span style="display:block;font-size:10px;font-weight:700;color:#4F7CAC;">${label}</span>
            <span style="display:block;font-size:11px;font-weight:600;color:#1F2937;margin-top:2px;">${act.patient_name}</span>
            <span style="display:block;font-size:10px;color:#9CA3AF;margin-top:1px;">${dt}</span>
        `;
        container.appendChild(li);
    });
}

// ════════════════════════════════════════════════════════
// APPOINTMENT ACTIONS
// ════════════════════════════════════════════════════════
function acceptAppointment(id) {
    window.db.updateAppointment(id, { status: 'Accepted' });
    showToast(`Appointment #${id} accepted. You can now start the consultation.`, 'success');
    renderAll();
}

function toggleReschedulePanel() {
    const panel = document.getElementById('reschedule-panel');
    if (panel) panel.classList.toggle('open');
}

function handleReschedule(event, id) {
    event.preventDefault();
    const newDate = document.getElementById('resched-date')?.value;
    if (!newDate) { showToast('Provide a date.', 'error'); return; }
    
    // Find next available 15-minute slot for this doctor on newDate
    const allAppts = window.db.getAppointmentsWithPatientDetails(currentUser.doctor_id) || [];
    const dateAppts = allAppts.filter(a => a.appointment_date === newDate && a.appointment_id !== id);
    const takenTimes = new Set(dateAppts.map(a => a.appointment_time));
    
    let newTime = null;
    let startHour = 9, endHour = 18;
    for (let h = startHour; h <= endHour; h++) {
        for (let m = 0; m < 60; m += 15) {
            if (h === 18 && m > 0) break; // Don't go beyond 18:00
            const timeStr = `${String(h).padStart(2,'0')}:${String(m).padStart(2,'0')}:00`;
            if (!takenTimes.has(timeStr)) {
                newTime = timeStr;
                break;
            }
        }
        if (newTime) break;
    }
    
    if (!newTime) {
        showToast('No available 15-minute slots on the selected day.', 'error');
        return;
    }

    const appt = window.db.getAppointmentById(id);
    const newStatus = appt.status === 'Accepted' ? 'Scheduled' : appt.status;
    window.db.updateAppointment(id, { appointment_date: newDate, appointment_time: newTime, status: newStatus });
    
    const consult = window.db.getConsultationByAppointmentId(id);
    if (consult) {
        window.db.saveConsultation(id, { followup_date: newDate, followup_time: newTime });
    }

    showToast(`Appointment #${id} rescheduled to ${formatApptDate(newDate)} at ${formatApptTime(newTime)}.`, 'success');
    renderAll();
}

function deleteAppointment(id, fromWorkspace = false) {
    if (confirm('Delete this appointment? This will permanently erase its consultation history.')) {
        window.db.deleteAppointment(id);
        showToast(`Appointment #${id} deleted.`, 'success');
        if (fromWorkspace || selectedId === id) { selectedId = null; }
        renderAll();
    }
}

function reseedDatabase() {
    window.db.resetDatabase();
    window.db.setCurrentUser(currentUser);
    showToast('Database reset and re-seeded.', 'success');
    renderAll();
}

// ════════════════════════════════════════════════════════
// SEARCH FILTER
// ════════════════════════════════════════════════════════
function filterAppointments(val) {
    filterText = val;
    renderScheduler();
}

// ════════════════════════════════════════════════════════
// TOAST NOTIFICATIONS
// ════════════════════════════════════════════════════════
function showToast(message, type = 'success') {
    const container = document.getElementById('alert-container');
    const toast     = document.createElement('div');
    const isSuccess = type === 'success';
    toast.style.cssText = `
        margin-bottom:8px;padding:12px 14px;border-radius:8px;font-size:12px;font-weight:600;
        display:flex;justify-content:space-between;align-items:center;
        box-shadow:0 4px 16px rgba(0,0,0,0.1);transition:opacity 0.3s;
        background:${isSuccess ? '#F0FDF4' : '#FEF2F2'};
        border-left:3px solid ${isSuccess ? '#22C55E' : '#EF4444'};
        color:${isSuccess ? '#15803D' : '#DC2626'};
    `;
    toast.innerHTML = `
        <span>${isSuccess ? '✓' : '⚠'} ${message}</span>
        <button onclick="this.parentElement.remove()" style="background:none;border:none;cursor:pointer;color:inherit;font-size:14px;padding:0 0 0 10px;">×</button>
    `;
    container.appendChild(toast);
    setTimeout(() => { toast.style.opacity = '0'; setTimeout(() => toast.remove(), 400); }, 5000);
}

// ════════════════════════════════════════════════════════
// DATE / TIME HELPERS
// ════════════════════════════════════════════════════════
function toDateStr(d) {
    return `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;
}
function calculateAge(dob) {
    const b = new Date(dob), t = new Date();
    let age = t.getFullYear() - b.getFullYear();
    const m = t.getMonth() - b.getMonth();
    if (m < 0 || (m === 0 && t.getDate() < b.getDate())) age--;
    return age;
}
function formatApptTime(timeStr) {
    const [h, m] = timeStr.split(':');
    const hour = parseInt(h);
    return `${String(hour % 12 || 12).padStart(2,'0')}:${m} ${hour >= 12 ? 'PM' : 'AM'}`;
}
function formatApptDate(dateStr) {
    const d = new Date(dateStr + 'T00:00:00');
    return d.toLocaleDateString('en-US', { month: 'short', day: '2-digit', year: 'numeric' });
}
function formatDOB(dateStr) {
    const d = new Date(dateStr + 'T00:00:00');
    return d.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
}
function formatFullDate(dateStr) {
    const d = new Date(dateStr + 'T00:00:00');
    return d.toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });
}
function formatActivityDateTime(dateStr, timeStr) {
    const d     = new Date(dateStr + 'T' + timeStr);
    const month = d.toLocaleDateString('en-US', { month: 'short' });
    return `${month} ${d.getDate()}, ${formatApptTime(timeStr)}`;
}

// ════════════════════════════════════════════════════════
// LOGOUT
// ════════════════════════════════════════════════════════
function handleLogout() {
    window.db.logout();
    window.location.href = '../login/index.html';
}

// ── GLOBAL HOOKS ────────────────────────────────────────
window.handleLogout         = handleLogout;
window.reseedDatabase       = reseedDatabase;
window.acceptAppointment    = acceptAppointment;
window.handleReschedule     = handleReschedule;
window.deleteAppointment    = deleteAppointment;
window.toggleReschedulePanel= toggleReschedulePanel;
window.filterAppointments   = filterAppointments;
window.goToToday            = goToToday;
window.schedulerPrev        = schedulerPrev;
window.schedulerNext        = schedulerNext;
window.miniCalPrev          = miniCalPrev;
window.miniCalNext          = miniCalNext;
window.changeView           = changeView;
window.selectAppointment    = selectAppointment;
window.closeWorkspaceModal  = closeWorkspaceModal;

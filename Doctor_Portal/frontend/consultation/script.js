// Auth check
const currentUser = window.db.getCurrentUser();
if (!currentUser) {
    window.location.href = '../login/index.html';
}

// Get appointment ID
const urlParams = new URLSearchParams(window.location.search);
const apptId = parseInt(urlParams.get('appointment_id'));

if (isNaN(apptId)) {
    window.location.href = '../dashboard/index.html';
}

const appt = window.db.getAppointmentById(apptId);
if (!appt || appt.doctor_id !== currentUser.doctor_id) {
    window.location.href = '../dashboard/index.html';
}

// Make sure appointment is Accepted to edit (Scheduled or Completed cannot edit)
if (appt.status === 'Scheduled') {
    alert('Please accept the appointment before starting the consultation.');
    window.location.href = `../dashboard/index.html?selected_id=${apptId}`;
} else if (appt.status === 'Completed') {
    window.location.href = `../view_consultation/index.html?appointment_id=${apptId}`;
}

// --- STATE MANAGEMENT ---
let sessionSeconds = 0;
const activeSymptomCards = new Map(); // maps symptomId -> diagnosis element reference
let lastProcessedNarrative = '';

// Date/Age Helper
function calculateAge(dob) {
    const birthDate = new Date(dob);
    const today = new Date();
    let age = today.getFullYear() - birthDate.getFullYear();
    const m = today.getMonth() - birthDate.getMonth();
    if (m < 0 || (m === 0 && today.getDate() < birthDate.getDate())) {
        age--;
    }
    return age;
}

function formatDOB(dateStr) {
    const d = new Date(dateStr + 'T00:00:00');
    return d.toLocaleDateString('en-US', { day: '2-digit', month: 'short', year: 'numeric' });
}

// --- INJECT PATIENT DATA ---
const patient = window.db.getPatientById(appt.patient_id);
document.getElementById('patient-name-title').textContent = patient.name;
document.getElementById('patient-gender-badge').textContent = patient.gender;
document.getElementById('patient-age-val').textContent = calculateAge(patient.dob);
document.getElementById('patient-dob-val').textContent = formatDOB(patient.dob);
document.getElementById('patient-id-val').textContent = patient.patient_id;
document.getElementById('appt-id-val').textContent = apptId;
document.getElementById('back-to-dashboard-btn').href = `../dashboard/index.html?selected_id=${apptId}`;

// --- POPULATE DOCTOR DROPDOWNS ---
const doctors = JSON.parse(localStorage.getItem('hms_doctors') || '[]');
const diagReferralSelect = document.getElementById('diag_referral_doctor_id');
const followupDoctorSelect = document.getElementById('followup_doctor_id');

if (diagReferralSelect) {
    diagReferralSelect.innerHTML = '<option value="" disabled selected>-- Select Specialty / Practitioner --</option>';
    doctors.forEach(doc => {
        if (doc.doctor_id !== currentUser.doctor_id) {
            const opt = document.createElement('option');
            opt.value = doc.doctor_id;
            opt.textContent = doc.name;
            diagReferralSelect.appendChild(opt);
        }
    });
}

if (followupDoctorSelect) {
    followupDoctorSelect.innerHTML = '';
    doctors.forEach(doc => {
        const opt = document.createElement('option');
        opt.value = doc.doctor_id;
        opt.textContent = doc.name;
        if (doc.doctor_id === currentUser.doctor_id) {
            opt.selected = true;
        }
        followupDoctorSelect.appendChild(opt);
    });
}

// --- TIMER ---
setInterval(() => {
    sessionSeconds++;
    const m = String(Math.floor(sessionSeconds / 60)).padStart(2, '0');
    const s = String(sessionSeconds % 60).padStart(2, '0');
    document.getElementById('sessionTimer').textContent = `${m}:${s}`;
}, 1000);

// --- DRAWER NAVIGATION CONTROLS ---
const drawer = document.getElementById('consultationDrawer');
const edgeTrigger = document.getElementById('drawerEdgeTrigger');
const backdrop = document.getElementById('drawerBackdrop');
let drawerHoverTimeout = null;

function openDrawer() {
    clearTimeout(drawerHoverTimeout);
    drawer.style.left = '0px';
    backdrop.style.display = 'block';
}

function closeDrawer() {
    drawer.style.left = '-300px';
    backdrop.style.display = 'none';
}

function toggleDrawer() {
    if (drawer.style.left === '0px') {
        closeDrawer();
    } else {
        openDrawer();
    }
}

edgeTrigger.addEventListener('mouseenter', openDrawer);

drawer.addEventListener('mouseleave', () => {
    if (!edgeTrigger.matches(':hover')) {
        drawerHoverTimeout = setTimeout(closeDrawer, 120);
    }
});

edgeTrigger.addEventListener('mouseleave', () => {
    if (!drawer.matches(':hover')) {
        drawerHoverTimeout = setTimeout(closeDrawer, 120);
    }
});

drawer.addEventListener('mouseenter', () => {
    clearTimeout(drawerHoverTimeout);
});

let apiDebounceTimer = null;

function performApiLookup(query) {
    if (!query) return;
    fetch(`https://clinicaltables.nlm.nih.gov/api/icd10cm/v3/search?sf=code,name&terms=${encodeURIComponent(query)}`)
        .then(res => res.json())
        .then(data => {
            if (data && data[3] && data[3].length > 0) {
                const code = data[3][0][0];
                const desc = data[3][0][1];
                
                // Check if this matches a GUI card's ICD code
                const matchingCard = document.querySelector(`.symptom-card[data-icd="${code}"]`);
                if (matchingCard) {
                    if (!matchingCard.classList.contains('active')) {
                        matchingCard.click();
                    }
                } else {
                    // Create custom diagnosis row
                    createDiagnosisRow(null, code, desc, false);
                }
            }
        })
        .catch(err => console.error('ICD-10 API Error:', err));
}

function applyNarrativeDiagnosisMapping() {
    const narrativeInputEl = document.getElementById('narrative_diagnosis');
    if (!narrativeInputEl) return;
    const narrativeText = (narrativeInputEl.value || '').trim();
    if (!narrativeText) return;

    const lowerText = narrativeText.toLowerCase();

    const diseaseKeywords = {
        hair_loss: [/hair\s+loss/i, /alopecia/i, /shedding/i],
        excessive_hair: [/excessive\s+hair/i, /hirsutism/i],
        unwanted_hair: [/unwanted\s+hair/i],
        wrinkle: [/wrinkle/i, /rhytid/i, /aging\s+skin/i],
        botox: [/botox/i, /botulinum/i],
        filler: [/filler/i, /dermal\s+filler/i],
        consultation: [/consultation/i],
        derma_pen: [/derma\s+pen/i, /dermapen/i, /microneedling/i],
        skin_booster: [/skin\s+booster/i, /skinbooster/i],
        prp: [/prp/i, /platelet\s+rich\s+plasma/i],
        mesotherapy: [/mesotherapy/i],
        weight_loss: [/weight\s+loss/i, /obesity/i, /slimming/i],
        pigmentation: [/pigmentation/i, /hyperpigmentation/i, /melasma/i, /dark\s+spot/i]
    };

    // Find all matches
    const matchedSymptomIds = [];
    for (const [symptomId, regexes] of Object.entries(diseaseKeywords)) {
        const matches = regexes.some(regex => regex.test(lowerText));
        if (matches) {
            matchedSymptomIds.push(symptomId);
        }
    }

    // Only apply mapping if there is at least one match found in the narrative diagnosis
    if (matchedSymptomIds.length > 0) {
        const topSymptomId = matchedSymptomIds[0];

        // Sync symptom cards
        const symptomCards = document.querySelectorAll('.symptom-card');
        symptomCards.forEach(card => {
            const symptomId = card.getAttribute('data-symptom-id');
            const isActive = card.classList.contains('active');
            const shouldBeActive = (symptomId === topSymptomId);

            if (shouldBeActive && !isActive) {
                card.click();
            } else if (!shouldBeActive && isActive) {
                card.click();
            }
        });
    } else {
        const activeCard = document.querySelector('.symptom-card.active');
        if (activeCard) {
            activeCard.click(); // toggle off
        }
    }
}

// Update title in header on tab switch
const drawerPills = document.querySelectorAll('#consultationDrawerTabs button[data-bs-toggle="pill"]');
drawerPills.forEach(pill => {
    pill.addEventListener('shown.bs.tab', (e) => {
        const targetText = e.target.querySelector('span').textContent;
        document.getElementById('currentSectionTitle').textContent = targetText;
    });
});

// Sync Narrative Diagnosis & run matching GUI/Auto-Lookup on notes-disease-tab shown or input
const notesDiseaseTabBtn = document.getElementById('notes-disease-tab');
if (notesDiseaseTabBtn) {
    notesDiseaseTabBtn.addEventListener('shown.bs.tab', () => {
        const currentNarrative = (document.getElementById('narrative_diagnosis').value || '').trim();
        if (currentNarrative !== lastProcessedNarrative) {
            applyNarrativeDiagnosisMapping();
            lastProcessedNarrative = currentNarrative;
        }
    });
}

const narrativeInput = document.getElementById('narrative_diagnosis');
if (narrativeInput) {
    narrativeInput.addEventListener('input', () => {
        applyNarrativeDiagnosisMapping();

        // Run API lookup debounced
        if (apiDebounceTimer) clearTimeout(apiDebounceTimer);
        apiDebounceTimer = setTimeout(() => {
            const val = narrativeInput.value.trim();
            if (val.length > 2) {
                performApiLookup(val);
            }
        }, 600);
    });
}

// --- Sub-Tab Helper ---
function goToSubTab(tabId) {
    const tabEl = document.getElementById(tabId);
    if (tabEl) {
        let tab = bootstrap.Tab.getInstance(tabEl);
        if (!tab) {
            tab = new bootstrap.Tab(tabEl);
        }
        tab.show();
    }
}

// Switch tabs inside wizard
function goToTab(nextTabName, currentTabId) {
    const tabButton = document.getElementById('drawer-' + nextTabName + '-tab');
    if (tabButton) {
        tabButton.click();
    }
}

// --- REDESIGNED VITALS GUI SYNCHRONIZERS & EVALUATORS ---
const painSlider = document.getElementById('pain_scale');

// Blood Pressure
function updateBloodPressure() {
    const sysInput = document.getElementById('bp_systolic');
    const diaInput = document.getElementById('bp_diastolic');
    if (!sysInput || !diaInput) return;
    
    const sys = parseInt(sysInput.value);
    const dia = parseInt(diaInput.value);
    
    document.getElementById('bp_sys_display').textContent = sys + ' mmHg';
    document.getElementById('bp_dia_display').textContent = dia + ' mmHg';
    document.getElementById('bp_val_display').textContent = sys + '/' + dia;
    document.getElementById('blood_pressure').value = sys + '/' + dia;
    
    let status = 'Normal';
    let badgeClass = 'bg-emerald-100 text-emerald-800';
    if (sys < 90 || dia < 60) {
        status = 'Hypotension';
        badgeClass = 'bg-blue-100 text-blue-800';
    } else if (sys >= 180 || dia >= 120) {
        status = 'Hypertensive Crisis';
        badgeClass = 'bg-red-200 text-red-900 border border-red-500 animate-pulse';
    } else if (sys >= 140 || dia >= 90) {
        status = 'Hypertension Stage 2';
        badgeClass = 'bg-red-100 text-red-800';
    } else if ((sys >= 130 && sys <= 139) || (dia >= 80 && dia <= 89)) {
        status = 'Hypertension Stage 1';
        badgeClass = 'bg-orange-100 text-orange-800';
    } else if (sys >= 120 && sys <= 129 && dia < 80) {
        status = 'Elevated';
        badgeClass = 'bg-yellow-100 text-yellow-800';
    }
    
    const badge = document.getElementById('bp_status_badge');
    if (badge) {
        badge.className = 'px-2 py-0.5 rounded text-[10px] font-bold ' + badgeClass;
        badge.textContent = status;
    }
}

// Temperature
function updateTemperature() {
    const tempInputEl = document.getElementById('temperature');
    if (!tempInputEl) return;
    const temp = parseFloat(tempInputEl.value);
    document.getElementById('temp_val_display').textContent = temp.toFixed(1);
    
    let status = 'Normal';
    let badgeClass = 'bg-emerald-100 text-emerald-800';
    if (temp < 35.5) {
        status = 'Hypothermia';
        badgeClass = 'bg-blue-100 text-blue-800';
    } else if (temp > 38.5) {
        status = 'High Fever';
        badgeClass = 'bg-red-100 text-red-800 animate-pulse';
    } else if (temp > 37.5) {
        status = 'Low Fever';
        badgeClass = 'bg-orange-100 text-orange-800';
    }
    
    const badge = document.getElementById('temp_status_badge');
    if (badge) {
        badge.className = 'px-2 py-0.5 rounded text-[10px] font-bold ' + badgeClass;
        badge.textContent = status;
    }
}

// Heart Rate
function updateHeartRate() {
    const hrInput = document.getElementById('heart_rate');
    if (!hrInput) return;
    const hr = parseInt(hrInput.value);
    document.getElementById('hr_val_display').textContent = hr;
    
    let status = 'Normal';
    let badgeClass = 'bg-emerald-100 text-emerald-800';
    if (hr < 60) {
        status = 'Bradycardia';
        badgeClass = 'bg-orange-100 text-orange-800';
    } else if (hr > 100) {
        status = 'Tachycardia';
        badgeClass = 'bg-red-100 text-red-800';
    }
    
    const badge = document.getElementById('hr_status_badge');
    if (badge) {
        badge.className = 'px-2 py-0.5 rounded text-[10px] font-bold ' + badgeClass;
        badge.textContent = status;
    }
    
    const heartIcon = document.getElementById('beatingHeartIcon');
    if (heartIcon) {
        const duration = 60 / hr;
        heartIcon.style.animationDuration = duration + 's';
    }
}

// Weight
function updateWeight() {
    const wInput = document.getElementById('weight');
    if (!wInput) return;
    const w = parseFloat(wInput.value);
    document.getElementById('weight_val_display').textContent = w.toFixed(1);
    updateBMI();
}

// Height
function updateHeight() {
    const hInput = document.getElementById('height');
    if (!hInput) return;
    const h = parseFloat(hInput.value);
    document.getElementById('height_val_display').textContent = h.toFixed(1);
    updateBMI();
}

// Respiratory Rate
function updateRespiratoryRate() {
    const respInput = document.getElementById('respiratory_rate');
    if (!respInput) return;
    const resp = parseInt(respInput.value);
    document.getElementById('respiratory_rate_val_display').textContent = resp;
    
    let status = 'Normal';
    let badgeClass = 'bg-emerald-100 text-emerald-800';
    if (resp < 12) {
        status = 'Bradypnea';
        badgeClass = 'bg-orange-100 text-orange-800';
    } else if (resp > 20) {
        status = 'Tachypnea';
        badgeClass = 'bg-red-100 text-red-800';
    }
    
    const badge = document.getElementById('resp_status_badge');
    if (badge) {
        badge.className = 'px-2 py-0.5 rounded text-[10px] font-bold ' + badgeClass;
        badge.textContent = status;
    }
}

// BMI Calculation
function updateBMI() {
    const wInput = document.getElementById('weight');
    const hInput = document.getElementById('height');
    if (!wInput || !hInput) return;
    const w = parseFloat(wInput.value);
    const h = parseFloat(hInput.value);
    
    if (w > 0 && h > 0) {
        const bmi = w / ((h / 100) ** 2);
        document.getElementById('bmi_val_display').textContent = bmi.toFixed(1);
        
        let status = 'Normal';
        let badgeClass = 'bg-emerald-100 text-emerald-800';
        if (bmi < 18.5) {
            status = 'Underweight';
            badgeClass = 'bg-blue-100 text-blue-800';
        } else if (bmi >= 30.0) {
            status = 'Obese';
            badgeClass = 'bg-red-100 text-red-800 animate-pulse';
        } else if (bmi >= 25.0) {
            status = 'Overweight';
            badgeClass = 'bg-orange-100 text-orange-800';
        }
        
        const badge = document.getElementById('bmi_status_badge');
        if (badge) {
            badge.className = 'px-2 py-0.5 rounded text-[10px] font-bold ' + badgeClass;
            badge.textContent = status;
        }
    } else {
        document.getElementById('bmi_val_display').textContent = '--';
    }
}

// Oxygen Saturation
function updateOxygenSaturation() {
    const o2Input = document.getElementById('oxygen_saturation');
    if (!o2Input) return;
    const o2 = parseInt(o2Input.value);
    document.getElementById('spo2_val_display').textContent = o2;
    
    let status = 'Normal';
    let badgeClass = 'bg-emerald-100 text-emerald-800';
    if (o2 < 90) {
        status = 'Severe Hypoxia';
        badgeClass = 'bg-red-100 text-red-800 animate-pulse';
    } else if (o2 <= 94) {
        status = 'Mild Hypoxia';
        badgeClass = 'bg-orange-100 text-orange-800';
    }
    
    const badge = document.getElementById('spo2_status_badge');
    if (badge) {
        badge.className = 'px-2 py-0.5 rounded text-[10px] font-bold ' + badgeClass;
        badge.textContent = status;
    }
}

// Pain Scale
function updatePainScale(val) {
    document.getElementById('pain_scale').value = val;
    document.getElementById('pain_val_display').textContent = val;
    
    document.querySelectorAll('.pain-circle').forEach(circle => {
        if (parseInt(circle.getAttribute('data-val')) === parseInt(val)) {
            circle.classList.add('selected');
        } else {
            circle.classList.remove('selected');
        }
    });
    
    let status = 'No Pain';
    let badgeClass = 'bg-slate-100 text-slate-800';
    if (val >= 9) {
        status = 'Unbearable';
        badgeClass = 'bg-red-200 text-red-900 border border-red-400 animate-pulse';
    } else if (val >= 7) {
        status = 'Severe Pain';
        badgeClass = 'bg-red-100 text-red-800';
    } else if (val >= 5) {
        status = 'Moderate Pain';
        badgeClass = 'bg-orange-100 text-orange-800';
    } else if (val >= 3) {
        status = 'Uncomfortable';
        badgeClass = 'bg-yellow-100 text-yellow-800';
    } else if (val >= 1) {
        status = 'Mild Pain';
        badgeClass = 'bg-emerald-100 text-emerald-800';
    }
    
    const badge = document.getElementById('pain_status_badge');
    if (badge) {
        badge.className = 'px-2 py-0.5 rounded text-[10px] font-bold ' + badgeClass;
        badge.textContent = status;
    }
}

// --- LIVE ALERTS ---
const tempInput = document.getElementById('temperature');
const allergyInput = document.getElementById('allergy_notes');

const feverBanner = document.getElementById('feverSuggestionBanner');
const painBanner = document.getElementById('painSuggestionBanner');
const allergyBanner = document.getElementById('allergyWarningBanner');
const allergyText = document.getElementById('allergyWarningText');
const feverCard = document.querySelector('.symptom-card[data-symptom-id="fever"]');

function checkVitalsAlerts() {
    // Fever
    const temp = parseFloat(tempInput.value);
    if (!isNaN(temp) && temp > 37.5) {
        feverBanner.classList.remove('hidden');
        if (feverCard && !feverCard.classList.contains('active')) {
            feverCard.classList.add('alert-pulse');
        }
    } else {
        feverBanner.classList.add('hidden');
        if (feverCard) feverCard.classList.remove('alert-pulse');
    }

    // Pain
    const pain = parseInt(painSlider.value);
    if (!isNaN(pain) && pain >= 6) {
        painBanner.classList.remove('hidden');
    } else {
        painBanner.classList.add('hidden');
    }

    // Allergies
    const allergies = allergyInput.value.trim();
    if (allergies.length > 0) {
        allergyText.textContent = allergies;
        allergyBanner.classList.remove('hidden');
    } else {
        allergyBanner.classList.add('hidden');
    }
}

// Bind range input event listeners
document.getElementById('bp_systolic').addEventListener('input', () => { updateBloodPressure(); checkVitalsAlerts(); });
document.getElementById('bp_diastolic').addEventListener('input', () => { updateBloodPressure(); checkVitalsAlerts(); });
document.getElementById('temperature').addEventListener('input', () => { updateTemperature(); checkVitalsAlerts(); });
document.getElementById('heart_rate').addEventListener('input', () => { updateHeartRate(); checkVitalsAlerts(); });
document.getElementById('weight').addEventListener('input', () => { updateWeight(); checkVitalsAlerts(); });
document.getElementById('oxygen_saturation').addEventListener('input', () => { updateOxygenSaturation(); checkVitalsAlerts(); });
document.getElementById('height').addEventListener('input', () => { updateHeight(); checkVitalsAlerts(); });
document.getElementById('respiratory_rate').addEventListener('input', () => { updateRespiratoryRate(); checkVitalsAlerts(); });
document.getElementById('pain_scale').addEventListener('input', (e) => { updatePainScale(e.target.value); checkVitalsAlerts(); });

// Bind click listeners for pain scale circles
document.querySelectorAll('.pain-circle').forEach(circle => {
    circle.addEventListener('click', function() {
        const val = this.getAttribute('data-val');
        updatePainScale(val);
        checkVitalsAlerts();
    });
});

// Bind click listeners for presets
document.querySelectorAll('.bp-preset').forEach(btn => {
    btn.addEventListener('click', () => {
        const parts = btn.getAttribute('data-val').split('/');
        document.getElementById('bp_systolic').value = parts[0];
        document.getElementById('bp_diastolic').value = parts[1];
        updateBloodPressure();
        checkVitalsAlerts();
    });
});
document.querySelectorAll('.temp-preset').forEach(btn => {
    btn.addEventListener('click', () => {
        document.getElementById('temperature').value = btn.getAttribute('data-val');
        updateTemperature();
        checkVitalsAlerts();
    });
});
document.querySelectorAll('.hr-preset').forEach(btn => {
    btn.addEventListener('click', () => {
        document.getElementById('heart_rate').value = btn.getAttribute('data-val');
        updateHeartRate();
        checkVitalsAlerts();
    });
});
document.querySelectorAll('.weight-preset').forEach(btn => {
    btn.addEventListener('click', () => {
        document.getElementById('weight').value = btn.getAttribute('data-val');
        updateWeight();
        checkVitalsAlerts();
    });
});
document.querySelectorAll('.height-preset').forEach(btn => {
    btn.addEventListener('click', () => {
        document.getElementById('height').value = btn.getAttribute('data-val');
        updateHeight();
        checkVitalsAlerts();
    });
});
document.querySelectorAll('.spo2-preset').forEach(btn => {
    btn.addEventListener('click', () => {
        document.getElementById('oxygen_saturation').value = btn.getAttribute('data-val');
        updateOxygenSaturation();
        checkVitalsAlerts();
    });
});
document.querySelectorAll('.resp-preset').forEach(btn => {
    btn.addEventListener('click', () => {
        document.getElementById('respiratory_rate').value = btn.getAttribute('data-val');
        updateRespiratoryRate();
        checkVitalsAlerts();
    });
});

// Bind increment/decrement buttons
document.querySelectorAll('.increment-btn, .decrement-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        const targetId = btn.getAttribute('data-target');
        const step = parseFloat(btn.getAttribute('data-step') || 1);
        const input = document.getElementById(targetId);
        if (!input) return;
        
        const isIncrement = btn.classList.contains('increment-btn');
        let currentVal = parseFloat(input.value) || 0;
        let newVal = isIncrement ? (currentVal + step) : (currentVal - step);
        
        // Clamp values based on min/max attributes
        const minVal = parseFloat(input.min);
        const maxVal = parseFloat(input.max);
        if (!isNaN(minVal) && newVal < minVal) newVal = minVal;
        if (!isNaN(maxVal) && newVal > maxVal) newVal = maxVal;
        
        input.value = newVal;
        
        if (targetId === 'bp_systolic' || targetId === 'bp_diastolic') {
            updateBloodPressure();
        } else if (targetId === 'temperature') {
            updateTemperature();
        } else if (targetId === 'heart_rate') {
            updateHeartRate();
        } else if (targetId === 'weight') {
            updateWeight();
        } else if (targetId === 'oxygen_saturation') {
            updateOxygenSaturation();
        } else if (targetId === 'height') {
            updateHeight();
        } else if (targetId === 'respiratory_rate') {
            updateRespiratoryRate();
        }
        checkVitalsAlerts();
    });
});


// Initial visual update
updateBloodPressure();
updateTemperature();
updateHeartRate();
updateWeight();
updateOxygenSaturation();
updateHeight();
updateRespiratoryRate();
updateBMI();
updatePainScale(document.getElementById('pain_scale').value);
checkVitalsAlerts();

// --- SYMPTOM CHECKS & DIAGNOSES ---
const diagSearch = document.getElementById('diagSearchInput');
const symptomCards = document.querySelectorAll('.symptom-card');
const diagnosesContainer = document.getElementById('diagnosesContainer');
const addDiagnosisBtn = document.getElementById('addDiagnosisBtn');

// Filter symptoms
diagSearch.addEventListener('input', (e) => {
    const val = e.target.value.toLowerCase();
    symptomCards.forEach(card => {
        const desc = card.getAttribute('data-desc').toLowerCase();
        const title = card.querySelector('div').textContent.toLowerCase();
        if (desc.includes(val) || title.includes(val)) {
            card.style.display = 'block';
        } else {
            card.style.display = 'none';
        }
    });
});

function createDiagnosisRow(symptomId, icd, desc, isReadOnly = true) {
    // Clear any other active rows for single diagnosis restriction
    diagnosesContainer.innerHTML = '';
    document.querySelectorAll('.symptom-card.active').forEach(c => {
        c.classList.remove('active');
    });
    activeSymptomCards.clear();

    const row = document.createElement('div');
    row.className = 'grid grid-cols-1 md:grid-cols-12 gap-3 mb-3 items-end diagnosis-row border border-hms-border p-3.5 rounded-xl bg-gray-50';
    if (symptomId) row.setAttribute('data-symptom-ref', symptomId);

    row.innerHTML = `
        <div class="md:col-span-3">
            <label class="block text-xxs font-bold text-hms-mid mb-1">ICD-10 Code</label>
            <input type="text" class="w-full border border-hms-border rounded-lg p-2.5 text-xs outline-none bg-white font-mono text-hms-accent font-semibold icd-code" value="${icd}" ${isReadOnly ? 'readonly' : ''} placeholder="e.g. I10" required>
        </div>
        <div class="md:col-span-8">
            <label class="block text-xxs font-bold text-hms-mid mb-1">Description / Diagnostic Details</label>
            <input type="text" class="w-full border border-hms-border rounded-lg p-2.5 text-xs outline-none bg-white font-medium text-hms-dark icd-description" value="${desc}" ${isReadOnly ? 'readonly' : ''} placeholder="e.g. Essential hypertension" required>
        </div>
        <div class="md:col-span-1">
            <button type="button" class="w-full border border-red-200 text-red-500 hover:bg-red-500 hover:text-white rounded-lg py-2.5 text-xs font-semibold remove-diagnosis-btn transition duration-150" title="Delete Diagnosis">×</button>
        </div>
    `;

    // Remove handler
    row.querySelector('.remove-diagnosis-btn').onclick = () => {
        row.remove();
        if (symptomId) {
            const card = document.querySelector(`.symptom-card[data-symptom-id="${symptomId}"]`);
            if (card) card.classList.remove('active');
            activeSymptomCards.delete(symptomId);
        }
    };

    diagnosesContainer.appendChild(row);
    if (symptomId) activeSymptomCards.set(symptomId, row);
}

// Symptom card select
symptomCards.forEach(card => {
    card.addEventListener('click', () => {
        const id = card.getAttribute('data-symptom-id');
        const icd = card.getAttribute('data-icd');
        const desc = card.getAttribute('data-desc');

        if (card.classList.contains('active')) {
            card.classList.remove('active');
            const row = activeSymptomCards.get(id);
            if (row) {
                row.remove();
                activeSymptomCards.delete(id);
            }
        } else {
            card.classList.add('active');
            card.classList.remove('alert-pulse');
            createDiagnosisRow(id, icd, desc, true);
        }
    });
});

addDiagnosisBtn.onclick = () => {
    createDiagnosisRow(null, '', '', false);
};

// --- PRESCRIPTION DRUGS ---
const prescriptionsContainer = document.getElementById('prescriptionsContainer');
const addMedicineBtn = document.getElementById('addMedicineBtn');

function createMedicineRow(name = '', dosage = '', duration = '', instructions = '') {
    const row = document.createElement('div');
    row.className = 'prescription-row border border-hms-border p-4 rounded-xl mb-4 bg-hms-bg';
    row.innerHTML = `
        <div class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end mb-3">
            <div class="md:col-span-4">
                <label class="block text-xxs font-bold text-hms-mid mb-1">Medicine Name</label>
                <input type="text" class="w-full border border-hms-border rounded-lg p-2.5 text-sm outline-none focus:border-hms-accent med-name" placeholder="e.g., Amoxicillin 500mg" value="${name}" required>
            </div>
            <div class="md:col-span-4">
                <label class="block text-xxs font-bold text-hms-mid mb-1">Dosage / Frequency</label>
                <input type="text" class="w-full border border-hms-border rounded-lg p-2.5 text-sm outline-none focus:border-hms-accent med-dosage" placeholder="e.g., 1 tablet three times daily" value="${dosage}" required>
            </div>
            <div class="md:col-span-3">
                <label class="block text-xxs font-bold text-hms-mid mb-1">Duration</label>
                <input type="text" class="w-full border border-hms-border rounded-lg p-2.5 text-sm outline-none focus:border-hms-accent med-duration" placeholder="e.g., 7 days" value="${duration}" required>
            </div>
            <div class="md:col-span-1">
                <button type="button" class="w-full border border-red-200 text-red-500 hover:bg-red-500 hover:text-white rounded-lg py-2.5 text-sm font-semibold remove-medication-btn transition duration-150">×</button>
            </div>
        </div>
        <div>
            <label class="block text-xxs font-bold text-hms-muted mb-1">Special Instructions</label>
            <textarea class="w-full border border-hms-border rounded-lg p-2 text-xs outline-none focus:border-hms-accent med-instructions" rows="1" placeholder="e.g., Take after meals, avoid alcohol...">${instructions}</textarea>
        </div>
    `;

    row.querySelector('.remove-medication-btn').onclick = () => row.remove();
    prescriptionsContainer.appendChild(row);
}

addMedicineBtn.onclick = () => {
    createMedicineRow();
};

document.querySelectorAll('.med-pill').forEach(pill => {
    pill.addEventListener('click', function() {
        const medName = this.getAttribute('data-med');
        const dosage = this.getAttribute('data-dosage');
        const duration = this.getAttribute('data-duration');
        const inst = this.getAttribute('data-inst');
        createMedicineRow(medName, dosage, duration, inst);
    });
});

// Common Case Prescription Bundles Data and Handlers
const caseBundles = {
    flu: [
        { name: 'Paracetamol 500mg', dosage: '1 tablet every 6 hours', duration: '3 days', inst: 'Take after meals for fever.' },
        { name: 'Multivitamin', dosage: '1 tablet once daily', duration: '10 days', inst: 'Take in the morning.' },
        { name: 'Vitamin C 500mg', dosage: '1 tablet twice daily', duration: '7 days', inst: 'Take with water.' }
    ],
    cold: [
        { name: 'Chlorpheniramine 4mg', dosage: '1 tablet at night', duration: '5 days', inst: 'May cause drowsiness.' },
        { name: 'Paracetamol 500mg', dosage: '1 tablet three times daily as needed', duration: '5 days', inst: 'For body aches/fever.' },
        { name: 'Lozenges', dosage: '1 lozenge dissolved in mouth every 4 hours', duration: '3 days', inst: 'For sore throat.' }
    ],
    gastro: [
        { name: 'Oral Rehydration Salts (ORS)', dosage: '1 sachet dissolved in 1L water after each loose stool', duration: '3 days', inst: 'Sip slowly.' },
        { name: 'Loperamide 2mg', dosage: '1 capsule after first loose stool, then 1 after each stool (max 4/day)', duration: '2 days', inst: 'Discontinue if constipation occurs.' },
        { name: 'Metoclopramide 10mg', dosage: '1 tablet three times daily', duration: '3 days', inst: 'Take 30 mins before food for nausea.' }
    ],
    htn: [
        { name: 'Amlodipine 5mg', dosage: '1 tablet once daily', duration: '30 days', inst: 'Take in the morning.' },
        { name: 'Losartan 50mg', dosage: '1 tablet once daily', duration: '30 days', inst: 'Monitor blood pressure regularly.' }
    ],
    diabetes: [
        { name: 'Metformin 500mg', dosage: '1 tablet twice daily with meals', duration: '30 days', inst: 'Take with breakfast and dinner.' },
        { name: 'Gliclazide 80mg', dosage: '1 tablet once daily before breakfast', duration: '30 days', inst: 'Monitor for hypoglycemia.' }
    ]
};

document.querySelectorAll('.med-bundle-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        const bundleKey = this.getAttribute('data-bundle');
        const meds = caseBundles[bundleKey];
        if (meds) {
            meds.forEach(m => {
                createMedicineRow(m.name, m.dosage, m.duration, m.inst);
            });
        }
    });
});


// --- NURSING MEDICATION ROWS (Section 3.1) ---
const nursingMedContainer = document.getElementById('nursingMedContainer');
const addNursingMedBtn = document.getElementById('addNursingMedBtn');

function createNursingMedRow(name = '', dose = '', route = '', time = '', notes = '') {
    const row = document.createElement('div');
    row.className = 'nursing-med-row border border-hms-border p-3.5 rounded-xl bg-white';
    row.innerHTML = `
        <div class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end mb-2">
            <div class="md:col-span-3">
                <label class="block text-xxs font-bold text-hms-mid mb-1">Medicine Name</label>
                <input type="text" class="w-full border border-hms-border rounded-lg p-2.5 text-xs outline-none focus:border-hms-accent nrs-med-name" placeholder="e.g., Metronidazole 500mg" value="${name}">
            </div>
            <div class="md:col-span-2">
                <label class="block text-xxs font-bold text-hms-mid mb-1">Dose</label>
                <input type="text" class="w-full border border-hms-border rounded-lg p-2.5 text-xs outline-none focus:border-hms-accent nrs-med-dose" placeholder="e.g., 500mg" value="${dose}">
            </div>
            <div class="md:col-span-3">
                <label class="block text-xxs font-bold text-hms-mid mb-1">Route</label>
                <select class="w-full border border-hms-border rounded-lg p-2.5 text-xs outline-none focus:border-hms-accent nrs-med-route bg-white">
                    <option value="Oral" ${route==='Oral'?'selected':''}>Oral (PO)</option>
                    <option value="Intravenous" ${route==='Intravenous'?'selected':''}>Intravenous (IV)</option>
                    <option value="Intramuscular" ${route==='Intramuscular'?'selected':''}>Intramuscular (IM)</option>
                    <option value="Subcutaneous" ${route==='Subcutaneous'?'selected':''}>Subcutaneous (SC)</option>
                    <option value="Topical" ${route==='Topical'?'selected':''}>Topical</option>
                    <option value="Inhalation" ${route==='Inhalation'?'selected':''}>Inhalation</option>
                    <option value="Rectal" ${route==='Rectal'?'selected':''}>Rectal (PR)</option>
                    <option value="Other" ${route==='Other'?'selected':''}>Other</option>
                </select>
            </div>
            <div class="md:col-span-3">
                <label class="block text-xxs font-bold text-hms-mid mb-1">Time Administered</label>
                <input type="time" class="w-full border border-hms-border rounded-lg p-2.5 text-xs outline-none focus:border-hms-accent nrs-med-time" value="${time}">
            </div>
            <div class="md:col-span-1">
                <button type="button" class="w-full border border-red-200 text-red-500 hover:bg-red-500 hover:text-white rounded-lg py-2.5 text-xs font-semibold remove-nrs-med-btn transition duration-150">&times;</button>
            </div>
        </div>
        <div>
            <label class="block text-xxs font-bold text-hms-muted mb-1">Nursing Notes for this Medication</label>
            <textarea class="w-full border border-hms-border rounded-lg p-2 text-xs outline-none focus:border-hms-accent nrs-med-notes" rows="1" placeholder="e.g., Infused over 30 minutes, no adverse reactions observed...">${notes}</textarea>
        </div>
    `;
    row.querySelector('.remove-nrs-med-btn').onclick = () => row.remove();
    nursingMedContainer.appendChild(row);
}

if (addNursingMedBtn) {
    addNursingMedBtn.onclick = () => createNursingMedRow();
}

// --- Repeatable Laser Parameter Rows ---
const laserParamsTableBody = document.getElementById('laserParamsTableBody');
const addLaserParamRowBtn = document.getElementById('addLaserParamRowBtn');

function addLaserParamRow(area = '', laser = '', fluence = '', spot = '', pulse = '', notes = '') {
    const tr = document.createElement('tr');
    tr.className = 'border-b border-hms-border laser-param-row';
    tr.innerHTML = `
        <td class="py-2 px-2">
            <input type="text" class="w-full border border-hms-border rounded-lg p-1.5 text-xs outline-none focus:border-hms-accent nrs-param-area" name="nrs_param_area[]" placeholder="e.g. Underarms" value="${area}" required>
        </td>
        <td class="py-2 px-2">
            <input type="text" class="w-full border border-hms-border rounded-lg p-1.5 text-xs outline-none focus:border-hms-accent nrs-param-laser" name="nrs_param_laser[]" placeholder="alex 755nm" value="${laser}">
        </td>
        <td class="py-2 px-2">
            <input type="text" class="w-full border border-hms-border rounded-lg p-1.5 text-xs outline-none focus:border-hms-accent nrs-param-fluence" name="nrs_param_fluence[]" placeholder="12j/cm2" value="${fluence}">
        </td>
        <td class="py-2 px-2">
            <input type="text" class="w-full border border-hms-border rounded-lg p-1.5 text-xs outline-none focus:border-hms-accent nrs-param-spot" name="nrs_param_spot[]" placeholder="18mm" value="${spot}">
        </td>
        <td class="py-2 px-2">
            <input type="text" class="w-full border border-hms-border rounded-lg p-1.5 text-xs outline-none focus:border-hms-accent nrs-param-pulse" name="nrs_param_pulse[]" placeholder="3ms" value="${pulse}">
        </td>
        <td class="py-2 px-2">
            <input type="text" class="w-full border border-hms-border rounded-lg p-1.5 text-xs outline-none focus:border-hms-accent nrs-param-notes" name="nrs_param_notes[]" placeholder="Status or notes" value="${notes}">
        </td>
        <td class="py-2 px-2 text-center">
            <button type="button" class="text-red-500 hover:text-red-700 text-lg font-bold remove-param-row-btn">&times;</button>
        </td>
    `;
    tr.querySelector('.remove-param-row-btn').onclick = () => tr.remove();
    laserParamsTableBody.appendChild(tr);
}

if (addLaserParamRowBtn) {
    addLaserParamRowBtn.onclick = () => addLaserParamRow();
}

// Quick add pills click handlers
document.querySelectorAll('.quick-add-param-pill').forEach(pill => {
    pill.onclick = function() {
        const area = this.getAttribute('data-area') || '';
        const laser = this.getAttribute('data-laser') || '';
        const fluence = this.getAttribute('data-fluence') || '';
        const spot = this.getAttribute('data-spot') || '';
        const pulse = this.getAttribute('data-pulse') || '';
        const notes = this.getAttribute('data-notes') || '';
        addLaserParamRow(area, laser, fluence, spot, pulse, notes);
    };
});

// Skin Assessment presets
document.querySelectorAll('.prep-skin-preset').forEach(btn => {
    btn.onclick = function() {
        const val = this.getAttribute('data-val') || '';
        const input = document.getElementById('nrs_prep_skin_type');
        if (input) {
            input.value = val;
        }
    };
});

// Skin Prep Notes presets
document.querySelectorAll('.prep-note-preset').forEach(btn => {
    btn.onclick = function() {
        const val = this.getAttribute('data-val') || '';
        const input = document.getElementById('nrs_prep_notes');
        if (input) {
            input.value = val;
        }
    };
});

// Pre-Treatment checklist helper [Select Standard Prep]
const btnSelectStandardPrep = document.getElementById('btnSelectStandardPrep');
if (btnSelectStandardPrep) {
    btnSelectStandardPrep.onclick = function() {
        const chks = [
            'prep_assessment_done',
            'prep_procedure_explained',
            'prep_consent',
            'prep_goggles_provided',
            'prep_markings_shaving'
        ];
        chks.forEach(id => {
            const el = document.getElementById(id);
            if (el) el.checked = true;
        });
    };
}

// Post-Procedure checklist helper [Select Standard Observations]
const btnSelectStandardObs = document.getElementById('btnSelectStandardObs');
if (btnSelectStandardObs) {
    btnSelectStandardObs.onclick = function() {
        const chks = [
            'obs_procedure_done',
            'obs_erythema_edema',
            'obs_no_complaints',
            'obs_fucicort_applied'
        ];
        chks.forEach(id => {
            const el = document.getElementById(id);
            if (el) el.checked = true;
        });
    };
}

// Advisory checklist helpers: [Select All] & [Clear All]
const btnSelectAllAdvisory = document.getElementById('btnSelectAllAdvisory');
const btnClearAllAdvisory = document.getElementById('btnClearAllAdvisory');
const advisoryCheckboxIds = [
    'adv_fall_prevention',
    'adv_medication_schedule',
    'adv_diet_restrictions',
    'adv_activity_limits',
    'adv_wound_care',
    'adv_red_flags',
    'adv_hydration',
    'adv_followup_reminder',
    'adv_emergency_contact',
    'adv_no_self_medicate'
];

if (btnSelectAllAdvisory) {
    btnSelectAllAdvisory.onclick = function() {
        advisoryCheckboxIds.forEach(id => {
            const el = document.getElementById(id);
            if (el) el.checked = true;
        });
    };
}

if (btnClearAllAdvisory) {
    btnClearAllAdvisory.onclick = function() {
        advisoryCheckboxIds.forEach(id => {
            const el = document.getElementById(id);
            if (el) el.checked = false;
        });
    };
}

// --- FOLLOWUP SCHEDULING ---
function toggleFollowup(checkbox) {
    const fields = document.getElementById('followupFields');
    const fDate = document.getElementById('followup_date');
    const fTime = document.getElementById('followup_time');

    if (checkbox.checked) {
        fields.classList.remove('hidden');
        fDate.setAttribute('required', 'required');
        fTime.setAttribute('required', 'required');
    } else {
        fields.classList.add('hidden');
        fDate.removeAttribute('required');
        fTime.removeAttribute('required');
    }
}

// --- DRAFT / FINALIZE SUBMIT ---
function submitConsultation(event, status) {
    if (event) event.preventDefault();

    // 1. Gather all variables
    const bp = document.getElementById('blood_pressure').value;
    const temp = parseFloat(document.getElementById('temperature').value);
    const hr = parseInt(document.getElementById('heart_rate').value);
    const wt = parseFloat(document.getElementById('weight').value);
    const sp = parseInt(document.getElementById('oxygen_saturation').value);
    const pain = parseInt(painSlider.value);
    const allergies = allergyInput.value.trim();

    const referred = document.getElementById('referred_by').value.trim();
    const history = document.getElementById('medical_history').value.trim();
    const surgical = document.getElementById('surgical_history').value.trim();
    const family = document.getElementById('family_history').value.trim();
    const social = document.getElementById('social_history').value.trim();

    const complaint = document.getElementById('chief_complaint').value.trim();
    const physical = document.getElementById('physical_examination').value.trim();
    const narrative = document.getElementById('narrative_diagnosis').value.trim();

    const pain_scale_type = document.getElementById('pain_scale_type').value;
    const hpi_location = document.getElementById('hpi_location').value.trim();
    const hpi_quality = document.getElementById('hpi_quality').value.trim();
    const hpi_duration = document.getElementById('hpi_duration').value.trim();
    const hpi_timing = document.getElementById('hpi_timing').value.trim();
    const hpi_context = document.getElementById('hpi_context').value.trim();
    const hpi_modifying_factor = document.getElementById('hpi_modifying_factor').value.trim();

    // Gather ROS fields
    const ros_fields = [
        'integumentary', 'constitutional', 'eyes', 'enmt', 'cardiovascular',
        'respiratory', 'gastrointestinal', 'genitourinary', 'musculoskeletal',
        'neurological', 'psychiatric', 'endocrine', 'hem_lymph', 'allergic_immuno'
    ];
    const ros_data = {};
    ros_fields.forEach(field => {
        const el = document.getElementById('ros_' + field);
        ros_data[field] = el ? el.value.trim() : 'No Complaints';
    });

    // Gather Exam fields
    const exam_data = {
        general: document.getElementById('exam_general') ? document.getElementById('exam_general').value.trim() : 'Normal',
        skin: document.getElementById('exam_skin') ? document.getElementById('exam_skin').value.trim() : 'Normal',
        notes: document.getElementById('exam_notes') ? document.getElementById('exam_notes').value.trim() : ''
    };

    // Validations (only if finalizing)
    if (status === 'Finalized') {
        if (!validateConsultationForm()) {
            return;
        }
    }

    // 2. Gather Diagnoses
    const diagnoses = [];
    const firstDiagRow = document.querySelector('.diagnosis-row');
    if (firstDiagRow) {
        const code = firstDiagRow.querySelector('.icd-code').value;
        const desc = firstDiagRow.querySelector('.icd-description').value;
        if (code && desc) {
            diagnoses.push({ icd_code: code, description: desc });
        }
    }

    // 3. Gather Prescriptions
    const prescriptions = [];
    document.querySelectorAll('.prescription-row').forEach(row => {
        const name = row.querySelector('.med-name').value;
        const dosage = row.querySelector('.med-dosage').value;
        const duration = row.querySelector('.med-duration').value;
        const inst = row.querySelector('.med-instructions').value;
        prescriptions.push({ medicine_name: name, dosage, duration, instructions: inst });
    });

    // 4. Gather Lab Tests
    const tests = [];
    const testCheckboxes = document.querySelectorAll('input[name="lab_tests"]:checked');
    const labCat = document.getElementById('lab_category').value;
    const labPri = document.getElementById('lab_priority').value;

    testCheckboxes.forEach(cb => {
        const name = cb.value;
        
        // Setup mock summaries for completed lab results
        let summary = 'Results pending - draft state.';
        if (status === 'Finalized') {
            if (name.includes('CBC')) {
                summary = 'White Blood Cells (WBC): 5.8 x10^3/uL\nHemoglobin: 14.2 g/dL\nPlatelets: 245 x10^3/uL';
            } else if (name.includes('HbA1c')) {
                summary = 'HbA1c (Glycated Hemoglobin): 5.6%\nEstimated Average Glucose: 114 mg/dL';
            } else if (name.includes('LFT')) {
                summary = 'ALT (SGPT): 25 U/L (Normal: < 45)\nAST (SGOT): 20 U/L (Normal: < 35)\nBilirubin Total: 0.7 mg/dL';
            } else if (name.includes('Renal')) {
                summary = 'Creatinine: 0.88 mg/dL (Normal: 0.60 - 1.20)\nBUN: 14 mg/dL\nSodium: 139 mEq/L';
            }
        }

        tests.push({
            test_name: name,
            category: labCat,
            priority: labPri,
            status: status === 'Finalized' ? 'Completed' : 'Ordered',
            result_summary: summary
        });
    });

    // 5a. Gather Nursing Note fields
    const getVal = (id) => { const el = document.getElementById(id); return el ? el.value.trim() : ''; };
    const getChecked = (id) => { const el = document.getElementById(id); return el ? el.checked : false; };
    const getRadio = (name) => { const el = document.querySelector(`input[name="${name}"]:checked`); return el ? el.value : ''; };

    const prep_checklist = {
        consent: getChecked('prep_consent'),
        id_verified: getChecked('prep_id_verified'),
        allergies_checked: getChecked('prep_allergies_checked'),
        fasting: getChecked('prep_fasting'),
        iv_access: getChecked('prep_iv_access'),
        positioning: getChecked('prep_positioning'),
        monitoring: getChecked('prep_monitoring'),
        emergency_kit: getChecked('prep_emergency_kit'),
        baseline_vitals: getChecked('prep_baseline_vitals'),
        labwork: getChecked('prep_labwork'),
        procedure_explained: getChecked('prep_procedure_explained'),
        goggles_provided: getChecked('prep_goggles_provided'),
        markings_shaving: getChecked('prep_markings_shaving')
    };

    const post_procedure_checklist = {
        procedure_done: getChecked('obs_procedure_done'),
        erythema_edema: getChecked('obs_erythema_edema'),
        no_complaints: getChecked('obs_no_complaints'),
        fucicort_applied: getChecked('obs_fucicort_applied'),
        fucidin_applied: getChecked('obs_fucidin_applied'),
        cold_compress: getChecked('obs_cold_compress')
    };

    const procedure_parameters = Array.from(document.querySelectorAll('.laser-param-row')).map(r => ({
        area: r.querySelector('.nrs-param-area').value.trim(),
        laser: r.querySelector('.nrs-param-laser').value.trim(),
        fluence: r.querySelector('.nrs-param-fluence').value.trim(),
        spot: r.querySelector('.nrs-param-spot').value.trim(),
        pulse: r.querySelector('.nrs-param-pulse').value.trim(),
        notes: r.querySelector('.nrs-param-notes').value.trim()
    })).filter(p => p.area !== '');

    const advisory_checklist = {
        fall_prevention: getChecked('adv_fall_prevention'),
        medication_schedule: getChecked('adv_medication_schedule'),
        diet_restrictions: getChecked('adv_diet_restrictions'),
        activity_limits: getChecked('adv_activity_limits'),
        wound_care: getChecked('adv_wound_care'),
        red_flags: getChecked('adv_red_flags'),
        hydration: getChecked('adv_hydration'),
        followup_reminder: getChecked('adv_followup_reminder'),
        emergency_contact: getChecked('adv_emergency_contact'),
        no_self_medicate: getChecked('adv_no_self_medicate')
    };

    const nursing_notes = {
        // Section 1 – Doctor Exam
        exam_date: getVal('nrs_exam_date'),
        exam_doctor: getVal('nrs_exam_doctor'),
        exam_findings: getVal('nrs_exam_findings'),
        exam_orders: getVal('nrs_exam_orders'),
        exam_vitals_note: getVal('nrs_exam_vitals_note'),
        // Section 2 – Preparation
        prep_assessment_done: getChecked('prep_assessment_done'),
        prep_skin_type: getVal('nrs_prep_skin_type'),
        prep_checklist: prep_checklist,
        prep_notes: getVal('nrs_prep_notes'),
        prep_nurse: getVal('nrs_prep_nurse'),
        prep_time: getVal('nrs_prep_time'),
        // Section 3 – Treatment
        medications_given: Array.from(document.querySelectorAll('.nursing-med-row')).map(r => ({
            name: r.querySelector('.nrs-med-name').value.trim(),
            dose: r.querySelector('.nrs-med-dose').value.trim(),
            route: r.querySelector('.nrs-med-route').value,
            time: r.querySelector('.nrs-med-time').value,
            notes: r.querySelector('.nrs-med-notes').value.trim()
        })).filter(m => m.name !== ''),
        procedure_parameters: procedure_parameters,
        post_procedure_checklist: post_procedure_checklist,
        changes_performed: getVal('nrs_changes_performed'),
        changes_response: getVal('nrs_changes_response'),
        changes_location: getVal('nrs_changes_location'),
        changes_time: getVal('nrs_changes_time'),
        tolerance: getRadio('nrs_tolerance'),
        tolerance_notes: getVal('nrs_tolerance_notes'),
        post_vitals: getVal('nrs_post_vitals'),
        // Section 4 – Advisory
        advisory_checklist: advisory_checklist,
        advisory_notes: getVal('nrs_advisory_notes'),
        advisory_by: getVal('nrs_advisory_by'),
        advisory_time: getVal('nrs_advisory_time')
    };

    // Auto-construct legacy columns for backward compatibility (Consolidate into comprehensive nurse_notes)
    const nurse_notes_parts = [];
    
    // 1. Doctor Examination Records / Seen by Doctor
    let seen_doctor = 'No';
    if (nursing_notes.exam_doctor) {
        let exam_time_str = '';
        if (nursing_notes.exam_date) {
            const dateObj = new Date(nursing_notes.exam_date);
            if (!isNaN(dateObj.getTime())) {
                const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
                const mStr = months[dateObj.getMonth()];
                const dStr = String(dateObj.getDate()).padStart(2, '0');
                const yStr = dateObj.getFullYear();
                let hours = dateObj.getHours();
                const ampm = hours >= 12 ? 'PM' : 'AM';
                hours = hours % 12;
                hours = hours ? hours : 12;
                const hrStr = String(hours).padStart(2, '0');
                const minStr = String(dateObj.getMinutes()).padStart(2, '0');
                exam_time_str = mStr + ' ' + dStr + ', ' + yStr + ' ' + hrStr + ':' + minStr + ' ' + ampm;
            }
        }
        seen_doctor = 'Yes - by ' + nursing_notes.exam_doctor + (exam_time_str ? ' at ' + exam_time_str : '');
    }
    
    // Special procedure applied
    let special_procedure = 'None';
    if (nursing_notes.changes_performed) {
        special_procedure = nursing_notes.changes_performed;
    } else if (nursing_notes.post_procedure_checklist.procedure_done) {
        special_procedure = 'Laser hair reduction';
    } else if (nursing_notes.procedure_parameters.length > 0) {
        special_procedure = 'Laser treatment';
    }

    // Location of the special feature applied
    let location_applied = 'None';
    if (nursing_notes.changes_location) {
        location_applied = nursing_notes.changes_location;
    } else if (nursing_notes.procedure_parameters.length > 0) {
        const uniqueAreas = [];
        nursing_notes.procedure_parameters.forEach(p => {
            if (p.area && !uniqueAreas.includes(p.area)) {
                uniqueAreas.push(p.area);
            }
        });
        location_applied = uniqueAreas.join(', ');
    }

    // Session Tolerance
    const tolerance_val = nursing_notes.tolerance || 'Tolerated Well';

    const summary_block = [];
    summary_block.push("Seen and examined by doctor: " + seen_doctor);
    summary_block.push("which special procedure applied: " + special_procedure);
    summary_block.push("Location of the special feature applied: " + location_applied);
    summary_block.push("Session tolerance: " + tolerance_val);
    nurse_notes_parts.push(summary_block.join("\n"));

    // Doctor details block
    const doc_exam_block = [];
    if (nursing_notes.exam_findings) {
        doc_exam_block.push("Doctor Examination Findings: " + nursing_notes.exam_findings);
    }
    if (nursing_notes.exam_orders) {
        doc_exam_block.push("Doctor's Orders / Instructions: " + nursing_notes.exam_orders);
    }
    if (nursing_notes.exam_vitals_note) {
        doc_exam_block.push("Doctor's Vitals Observation: " + nursing_notes.exam_vitals_note);
    }
    if (doc_exam_block.length > 0) {
        nurse_notes_parts.push(doc_exam_block.join("\n"));
    }
    
    // 2. Patient Preparation & Skin Assessment
    const prep_block = [];
    prep_block.push("--- Patient Preparation & Skin Assessment ---");
    if (nursing_notes.prep_assessment_done) {
        prep_block.push("Skin Assessment done");
    }
    if (nursing_notes.prep_skin_type) {
        prep_block.push("Skin Type & Details: " + nursing_notes.prep_skin_type);
    }
    
    const prep_checklist_labels = {
        consent: 'Consent signed and secured',
        id_verified: 'Patient identity verified',
        allergies_checked: 'Allergies checked and verified',
        fasting: 'Fasting status confirmed',
        iv_access: 'IV access secured',
        positioning: 'Patient positioning optimized',
        monitoring: 'Monitoring equipment connected',
        emergency_kit: 'Emergency kit verified',
        baseline_vitals: 'Baseline vitals recorded',
        labwork: 'Labwork reviewed'
    };
    const checked_prep = [];
    for (const [key, label] of Object.entries(prep_checklist_labels)) {
        if (nursing_notes.prep_checklist[key]) {
            checked_prep.push("- " + label);
        }
    }
    if (nursing_notes.prep_checklist.procedure_explained) {
        checked_prep.push("- Explained the procedure and possible outcome of the treatment");
    }
    if (nursing_notes.prep_checklist.goggles_provided) {
        checked_prep.push("- Protective eye goggles provided");
    }
    if (nursing_notes.prep_checklist.markings_shaving) {
        checked_prep.push("- Markings and shaving done");
    }
    
    if (checked_prep.length > 0) {
        prep_block.push(checked_prep.join("\n"));
    }
    if (nursing_notes.prep_notes) {
        prep_block.push("Prep Notes: " + nursing_notes.prep_notes);
    }
    if (nursing_notes.prep_nurse || nursing_notes.prep_time) {
        prep_block.push("Prepared by: " + (nursing_notes.prep_nurse || '') + (nursing_notes.prep_time ? " at " + nursing_notes.prep_time : ""));
    }
    nurse_notes_parts.push(prep_block.join("\n"));
    
    // 3. Treatment
    const treatment_block = [];
    treatment_block.push("--- Clinical Treatment & Parameters ---");
    
    if (nursing_notes.medications_given.length > 0) {
        const med_list_parts = [];
        nursing_notes.medications_given.forEach(mg => {
            let med_desc = "- " + mg.name + (mg.dose ? " " + mg.dose : "") + (mg.route ? " via " + mg.route : "") + (mg.time ? " at " + mg.time : "");
            if (mg.notes) {
                med_desc += " (" + mg.notes + ")";
            }
            med_list_parts.push(med_desc);
        });
        treatment_block.push("Medications Administered:\n" + med_list_parts.join("\n"));
    }
    
    if (nursing_notes.procedure_parameters.length > 0) {
        const param_lines = [];
        nursing_notes.procedure_parameters.forEach(p => {
            let param_line = "- " + p.area;
            const details = [];
            if (p.laser) details.push(p.laser);
            if (p.fluence) details.push(p.fluence);
            if (p.spot) details.push(p.spot);
            if (p.pulse) details.push(p.pulse);
            if (details.length > 0) {
                param_line += " : " + details.join(" : ");
            }
            if (p.notes) {
                param_line += " (" + p.notes + ")";
            }
            param_lines.push(param_line);
        });
        treatment_block.push("Parameters used:\n" + param_lines.join("\n"));
    }

    const obs_block = [];
    if (nursing_notes.post_procedure_checklist.procedure_done) obs_block.push("- Laser hair reduction done.");
    if (nursing_notes.post_procedure_checklist.erythema_edema) obs_block.push("- Mild erythema and perifollicular edema noted.");
    if (nursing_notes.post_procedure_checklist.no_complaints) obs_block.push("- No complaints of pain or burn sensation.");
    if (nursing_notes.post_procedure_checklist.fucicort_applied) obs_block.push("- Fucicort cream applied.");
    if (nursing_notes.post_procedure_checklist.fucidin_applied) obs_block.push("- Fucidin cream applied.");
    if (nursing_notes.post_procedure_checklist.cold_compress) obs_block.push("- Cold compress applied post-treatment.");
    
    if (obs_block.length > 0) {
        treatment_block.push("Post-Procedure Observations:\n" + obs_block.join("\n"));
    }
    
    if (nursing_notes.changes_performed) {
        treatment_block.push("Special Procedure Applied Notes: " + nursing_notes.changes_performed);
    }
    if (nursing_notes.changes_location) {
        treatment_block.push("Location of the special feature applied: " + nursing_notes.changes_location);
    }
    if (nursing_notes.changes_response) {
        treatment_block.push("Patient Response: " + nursing_notes.changes_response);
    }
    if (nursing_notes.changes_time) {
        treatment_block.push("Procedure Execution Time: " + nursing_notes.changes_time);
    }
    
    if (nursing_notes.tolerance_notes) {
        treatment_block.push("Session Tolerance Notes: " + nursing_notes.tolerance_notes);
    }
    if (nursing_notes.post_vitals) {
        treatment_block.push("Post-Treatment Vitals: " + nursing_notes.post_vitals);
    }
    nurse_notes_parts.push(treatment_block.join("\n"));
    
    // 4. Advisory Measures
    const advisory_block = [];
    advisory_block.push("--- Post Home Care Instructions (Advisory) ---");
    
    const advisory_checklist_labels = {
        fall_prevention: 'Fall prevention precautions instructed',
        medication_schedule: 'Medication schedule explained',
        diet_restrictions: 'Dietary restrictions explained',
        activity_limits: 'Activity limitations explained',
        wound_care: 'Wound care instructions provided',
        red_flags: 'Red flag symptoms explained',
        hydration: 'Hydration instructions given',
        followup_reminder: 'Follow-up appointments reminder given',
        emergency_contact: 'Emergency contact numbers provided',
        no_self_medicate: 'Advised not to self-medicate'
    };
    const checked_adv = [];
    for (const [key, label] of Object.entries(advisory_checklist_labels)) {
        if (nursing_notes.advisory_checklist[key]) {
            checked_adv.push("- " + label);
        }
    }
    if (checked_adv.length > 0) {
        advisory_block.push(checked_adv.join("\n"));
    }
    if (nursing_notes.advisory_notes) {
        advisory_block.push("Discharge / Advisory Notes: " + nursing_notes.advisory_notes);
    }
    if (nursing_notes.advisory_by || nursing_notes.advisory_time) {
        advisory_block.push("Advised by: " + (nursing_notes.advisory_by || '') + (nursing_notes.advisory_time ? " at " + nursing_notes.advisory_time : ""));
    }
    nurse_notes_parts.push(advisory_block.join("\n"));
    
    const nurse_notes = nurse_notes_parts.join("\n\n");
    const treatment_notes = nursing_notes.changes_performed || special_procedure;

    // 5. Store Consultation object
    const payload = {
        blood_pressure: bp,
        temperature: temp,
        heart_rate: hr,
        weight: wt,
        oxygen_saturation: sp,
        pain_scale: pain,
        allergy_notes: allergies,
        referred_by: referred,
        medical_history: history,
        surgical_history: surgical,
        family_history: family,
        social_history: social,
        chief_complaint: complaint,
        pain_scale_type,
        hpi_location,
        hpi_quality,
        hpi_duration,
        hpi_timing,
        hpi_context,
        hpi_modifying_factor,
        physical_examination: physical,
        narrative_diagnosis: narrative,
        ros_data: JSON.stringify(ros_data),
        exam_data: JSON.stringify(exam_data),
        nursing_notes: JSON.stringify(nursing_notes),
        nursing_plan: JSON.stringify(nursing_notes),
        treatment_notes: treatment_notes,
        nurse_notes: nurse_notes,
        status: status,
        diagnoses,
        prescriptions,
        lab_tests: tests,
        followup_enabled: document.getElementById('followup_enabled').checked,
        followup_date: document.getElementById('followup_enabled').checked ? document.getElementById('followup_date').value || null : null,
        followup_time: document.getElementById('followup_enabled').checked ? document.getElementById('followup_time').value || null : null,
        followup_doctor_id: document.getElementById('followup_enabled').checked ? (parseInt(document.getElementById('followup_doctor_id').value) || null) : null
    };

    window.db.saveConsultation(apptId, payload);

    // 6. Handle follow-up appointment if finalized
    if (status === 'Finalized' && document.getElementById('followup_enabled').checked) {
        const fDate = document.getElementById('followup_date').value;
        const fTime = document.getElementById('followup_time').value;
        const fDocIdVal = document.getElementById('followup_doctor_id').value;
        const fDocId = fDocIdVal ? parseInt(fDocIdVal) : currentUser.doctor_id;

        window.db.addAppointment({
            patient_id: appt.patient_id,
            doctor_id: fDocId,
            appointment_date: fDate,
            appointment_time: fTime.includes(':') && fTime.split(':').length === 2 ? fTime + ':00' : fTime,
            status: 'Scheduled'
        });
    }

    // Redirect to dashboard with success alert
    window.location.href = `../dashboard/index.html?selected_id=${apptId}`;
}

function handleFinalSubmit(event) {
    event.preventDefault();
    submitConsultation(event, 'Finalized');
}

// --- POPULATE FROM DRAFT ---
const draft = window.db.getConsultationByAppointmentId(apptId);
if (draft) {
    // Fill Vitals
    document.getElementById('blood_pressure').value = draft.blood_pressure || '';
    document.getElementById('temperature').value = draft.temperature || '';
    document.getElementById('heart_rate').value = draft.heart_rate || '';
    document.getElementById('weight').value = draft.weight || '';
    document.getElementById('oxygen_saturation').value = draft.oxygen_saturation || '';
    document.getElementById('pain_scale').value = draft.pain_scale || 5;
    updatePainDisplay(draft.pain_scale || 5);
    document.getElementById('allergy_notes').value = draft.allergy_notes || '';

    // Fill History
    document.getElementById('referred_by').value = draft.referred_by || '';
    document.getElementById('medical_history').value = draft.medical_history || '';
    document.getElementById('surgical_history').value = draft.surgical_history || '';
    document.getElementById('family_history').value = draft.family_history || '';
    document.getElementById('social_history').value = draft.social_history || '';

    // Fill Notes
    document.getElementById('chief_complaint').value = draft.chief_complaint || '';
    document.getElementById('physical_examination').value = draft.physical_examination || '';
    document.getElementById('narrative_diagnosis').value = draft.narrative_diagnosis || '';
    lastProcessedNarrative = (draft.narrative_diagnosis || '').trim();

    // Fill HPI fields
    document.getElementById('pain_scale_type').value = draft.pain_scale_type || '';
    document.getElementById('hpi_location').value = draft.hpi_location || '';
    document.getElementById('hpi_quality').value = draft.hpi_quality || '';
    document.getElementById('hpi_duration').value = draft.hpi_duration || '';
    document.getElementById('hpi_timing').value = draft.hpi_timing || '';
    document.getElementById('hpi_context').value = draft.hpi_context || '';
    document.getElementById('hpi_modifying_factor').value = draft.hpi_modifying_factor || '';

    // Fill ROS fields
    const ros_fields = [
        'integumentary', 'constitutional', 'eyes', 'enmt', 'cardiovascular',
        'respiratory', 'gastrointestinal', 'genitourinary', 'musculoskeletal',
        'neurological', 'psychiatric', 'endocrine', 'hem_lymph', 'allergic_immuno'
    ];
    const rosObj = draft.ros_data ? (typeof draft.ros_data === 'string' ? JSON.parse(draft.ros_data) : draft.ros_data) : {};
    ros_fields.forEach(field => {
        const el = document.getElementById('ros_' + field);
        if (el) el.value = rosObj[field] || 'No Complaints';
    });

    // Fill Exam fields
    const examObj = draft.exam_data ? (typeof draft.exam_data === 'string' ? JSON.parse(draft.exam_data) : draft.exam_data) : {};
    const examGeneralEl = document.getElementById('exam_general');
    if (examGeneralEl) examGeneralEl.value = examObj.general || 'Normal';
    const examSkinEl = document.getElementById('exam_skin');
    if (examSkinEl) examSkinEl.value = examObj.skin || 'Normal';
    const examNotesEl = document.getElementById('exam_notes');
    if (examNotesEl) examNotesEl.value = examObj.notes || '';

    // Fill Follow-up fields
    const isFollowup = draft.followup_enabled || false;
    const followupCheck = document.getElementById('followup_enabled');
    if (followupCheck) {
        followupCheck.checked = isFollowup;
        toggleFollowup(followupCheck);
    }
    const followupDateEl = document.getElementById('followup_date');
    if (followupDateEl) followupDateEl.value = draft.followup_date || '';
    const followupTimeEl = document.getElementById('followup_time');
    if (followupTimeEl) {
        let fTime = draft.followup_time || '';
        if (fTime && fTime.split(':').length === 3) {
            fTime = fTime.split(':').slice(0, 2).join(':'); // Format HH:MM
        }
        followupTimeEl.value = fTime;
    }
    const followupDocEl = document.getElementById('followup_doctor_id');
    if (followupDocEl) followupDocEl.value = draft.followup_doctor_id || '';

    // Fill Diagnoses
    if (draft.diagnoses) {
        draft.diagnoses.forEach(diag => {
            // Find card mapping (matching both ICD and description to resolve duplicates)
            let cardFound = null;
            const cleanDiagDesc = (diag.description || '').replace(/[:*;]/g, '').trim().toLowerCase();
            symptomCards.forEach(card => {
                const cardIcd = card.getAttribute('data-icd');
                const cardDesc = card.getAttribute('data-desc').replace(/[:*;]/g, '').trim().toLowerCase();
                if (cardIcd === diag.icd_code && cardDesc === cleanDiagDesc) {
                    cardFound = card;
                }
            });
            // Fallback to first matching ICD code if description didn't match
            if (!cardFound) {
                symptomCards.forEach(card => {
                    if (card.getAttribute('data-icd') === diag.icd_code && !cardFound) {
                        cardFound = card;
                    }
                });
            }

            if (cardFound) {
                cardFound.classList.add('active');
                createDiagnosisRow(cardFound.getAttribute('data-symptom-id'), diag.icd_code, diag.description, true);
            } else {
                createDiagnosisRow(null, diag.icd_code, diag.description, false);
            }
        });
    }

    // Fill Prescriptions
    if (draft.prescriptions) {
        draft.prescriptions.forEach(p => {
            createMedicineRow(p.medicine_name, p.dosage, p.duration, p.instructions);
        });
    }

    // Fill Lab checks
    if (draft.lab_tests) {
        draft.lab_tests.forEach(test => {
            const cb = Array.from(document.querySelectorAll('input[name="lab_tests"]')).find(input => input.value === test.test_name);
            if (cb) cb.checked = true;
        });
        if (draft.lab_tests.length > 0) {
            document.getElementById('lab_category').value = draft.lab_tests[0].category;
            document.getElementById('lab_priority').value = draft.lab_tests[0].priority;
        }
    }

    // Fill Nursing Notes
    const nrsObj = draft.nursing_notes ? (typeof draft.nursing_notes === 'string' ? JSON.parse(draft.nursing_notes) : draft.nursing_notes) : {};
    if (Object.keys(nrsObj).length > 0) {
        const sv = (id, val) => { const el = document.getElementById(id); if (el && val !== undefined) el.value = val; };
        const sc = (id, val) => { const el = document.getElementById(id); if (el) el.checked = !!val; };
        sv('nrs_exam_date', nrsObj.exam_date);
        sv('nrs_exam_doctor', nrsObj.exam_doctor);
        sv('nrs_exam_findings', nrsObj.exam_findings);
        sv('nrs_exam_orders', nrsObj.exam_orders);
        sv('nrs_exam_vitals_note', nrsObj.exam_vitals_note);
        
        // Prep checklist
        sc('prep_assessment_done', nrsObj.prep_assessment_done);
        sv('nrs_prep_skin_type', nrsObj.prep_skin_type);
        
        const pc = nrsObj.prep_checklist || {};
        sc('prep_consent', pc.consent); sc('prep_id_verified', pc.id_verified);
        sc('prep_allergies_checked', pc.allergies_checked); sc('prep_fasting', pc.fasting);
        sc('prep_iv_access', pc.iv_access); sc('prep_positioning', pc.positioning);
        sc('prep_monitoring', pc.monitoring); sc('prep_emergency_kit', pc.emergency_kit);
        sc('prep_baseline_vitals', pc.baseline_vitals); sc('prep_labwork', pc.labwork);
        sc('prep_procedure_explained', pc.procedure_explained);
        sc('prep_goggles_provided', pc.goggles_provided);
        sc('prep_markings_shaving', pc.markings_shaving);

        sv('nrs_prep_notes', nrsObj.prep_notes);
        sv('nrs_prep_nurse', nrsObj.prep_nurse);
        sv('nrs_prep_time', nrsObj.prep_time);
        
        // Nursing Medications
        if (nrsObj.medications_given && Array.isArray(nrsObj.medications_given)) {
            const container = document.getElementById('nursingMedContainer');
            if (container) container.innerHTML = '';
            nrsObj.medications_given.forEach(m => createNursingMedRow(m.name, m.dose, m.route, m.time, m.notes));
        }

        // Procedure parameters table reconstruction
        if (nrsObj.procedure_parameters && Array.isArray(nrsObj.procedure_parameters)) {
            const tableBody = document.getElementById('laserParamsTableBody');
            if (tableBody) tableBody.innerHTML = '';
            nrsObj.procedure_parameters.forEach(p => addLaserParamRow(p.area, p.laser, p.fluence, p.spot, p.pulse, p.notes));
        }

        // Post-procedure checklist
        const ppc = nrsObj.post_procedure_checklist || {};
        sc('obs_procedure_done', ppc.procedure_done);
        sc('obs_erythema_edema', ppc.erythema_edema);
        sc('obs_no_complaints', ppc.no_complaints);
        sc('obs_fucicort_applied', ppc.fucicort_applied);
        sc('obs_fucidin_applied', ppc.fucidin_applied);
        sc('obs_cold_compress', ppc.cold_compress);

        sv('nrs_changes_performed', nrsObj.changes_performed);
        sv('nrs_changes_response', nrsObj.changes_response);
        sv('nrs_changes_location', nrsObj.changes_location);
        sv('nrs_changes_time', nrsObj.changes_time);
        if (nrsObj.tolerance) {
            const tolEl = document.querySelector(`input[name="nrs_tolerance"][value="${nrsObj.tolerance}"]`);
            if (tolEl) tolEl.checked = true;
        }
        sv('nrs_tolerance_notes', nrsObj.tolerance_notes);
        sv('nrs_post_vitals', nrsObj.post_vitals);
        
        // Advisory checklist
        const ac = nrsObj.advisory_checklist || {};
        sc('adv_fall_prevention', ac.fall_prevention); sc('adv_medication_schedule', ac.medication_schedule);
        sc('adv_diet_restrictions', ac.diet_restrictions); sc('adv_activity_limits', ac.activity_limits);
        sc('adv_wound_care', ac.wound_care); sc('adv_red_flags', ac.red_flags);
        sc('adv_hydration', ac.hydration); sc('adv_followup_reminder', ac.followup_reminder);
        sc('adv_emergency_contact', ac.emergency_contact); sc('adv_no_self_medicate', ac.no_self_medicate);
        
        sv('nrs_advisory_notes', nrsObj.advisory_notes);
        sv('nrs_advisory_by', nrsObj.advisory_by);
        sv('nrs_advisory_time', nrsObj.advisory_time);
    }

    checkVitalsAlerts();
} else {
    // Fill defaultNKDA allergy suggestion for brand new sessions
    document.getElementById('allergy_notes').value = 'NKDA';
    updatePainDisplay(5);
}

// --- TIMELINE POPULATOR ---
const pastVisits = window.db.getPastConsultationsForPatient(appt.patient_id, apptId);
const timelineBox = document.getElementById('timelineContainer');
timelineBox.innerHTML = '';

if (pastVisits.length === 0) {
    timelineBox.innerHTML = '<p class="text-hms-muted text-xs italic">No previous finalized consultations recorded for this patient.</p>';
} else {
    pastVisits.forEach(visit => {
        const apptDate = formatApptDate(visit.appointment_date);
        const apptTime = formatApptTime(visit.appointment_time);

        const item = document.createElement('div');
        item.className = 'timeline-item pb-4';
        
        let diagsHTML = '';
        if (visit.diagnoses && visit.diagnoses.length > 0) {
            diagsHTML = `
                <div class="flex flex-wrap gap-1 items-center mt-1">
                    <strong class="mr-1">Diagnoses:</strong>
                    ${visit.diagnoses.map(d => `<span class="bg-hms-panel text-hms-accent text-[10px] font-bold px-2 py-0.5 rounded-full border border-blue-200">${d.icd_code}: ${d.description}</span>`).join('')}
                </div>
            `;
        }

        let medsHTML = '';
        if (visit.prescriptions && visit.prescriptions.length > 0) {
            medsHTML = `
                <div class="mt-1">
                    <strong>Medications Handout:</strong>
                    <ul class="list-disc pl-4 space-y-0.5 mt-0.5">
                        ${visit.prescriptions.map(p => `<li>${p.medicine_name} (${p.dosage}, ${p.duration})</li>`).join('')}
                    </ul>
                </div>
            `;
        }

        let testsHTML = '';
        if (visit.lab_tests && visit.lab_tests.length > 0) {
            testsHTML = `
                <div class="mt-1">
                    <strong>Laboratory Orders:</strong>
                    <ul class="list-disc pl-4 space-y-0.5 mt-0.5">
                        ${visit.lab_tests.map(t => `<li>${t.test_name} (Category: ${t.category}, Priority: ${t.priority})</li>`).join('')}
                    </ul>
                </div>
            `;
        }

        let notesHTML = '';
        if (visit.narrative_diagnosis) {
            notesHTML = `<div class="mt-1 text-hms-mid border-t border-dashed border-hms-border pt-1"><strong>Narrative Diagnosis:</strong> ${visit.narrative_diagnosis}</div>`;
        }

        item.innerHTML = `
            <div class="font-serif font-bold text-hms-accent text-sm mb-1">${apptDate} at ${apptTime}</div>
            <div class="bg-gray-50 border border-hms-border p-4 rounded-xl text-xs text-hms-dark space-y-1.5">
                <div><strong>Chief Complaint:</strong> ${visit.chief_complaint || 'Not recorded'}</div>
                <div><strong>Vitals Check:</strong> BP: ${visit.blood_pressure || 'N/A'} | Temp: ${visit.temperature || 'N/A'} °C | Pain Scale: ${visit.pain_scale || 'N/A'}/10</div>
                ${diagsHTML}
                ${medsHTML}
                ${testsHTML}
                ${notesHTML}
            </div>
        `;
        timelineBox.appendChild(item);
    });
}


// Recursive tab activation helper for nested Bootstrap tabs
function activateTabForElement(element) {
    let pane = element.closest('.tab-pane');
    const path = [];
    while (pane) {
        const paneId = pane.id;
        const trigger = document.querySelector(`[data-bs-target="#${paneId}"]`) || 
                        document.querySelector(`[data-bs-target="${paneId}"]`) || 
                        document.getElementById(pane.getAttribute('aria-labelledby'));
        if (trigger) {
            path.unshift(trigger);
        }
        pane = trigger ? trigger.closest('.tab-pane') : null;
    }
    path.forEach(trigger => {
        trigger.click();
    });
}

// Main validation function with automatic tab redirection and highlight/focus
function validateConsultationForm() {
    const form = document.getElementById('consultationForm');
    if (!form) return true;

    const inputs = form.querySelectorAll('input[required], textarea[required], select[required]');
    for (const input of inputs) {
        if (!input.checkValidity()) {
            let fieldName = '';
            const label = form.querySelector(`label[for="${input.id}"]`);
            if (label) {
                fieldName = label.textContent.trim();
            } else if (input.placeholder) {
                fieldName = input.placeholder.trim();
            } else if (input.name) {
                fieldName = input.name;
            } else {
                fieldName = 'Required field';
            }
            
            fieldName = fieldName.replace(/[:*]/g, '').trim();
            alert(`"${fieldName}" is required to finalize the consultation. Redirecting to the field...`);
            
            activateTabForElement(input);
            
            setTimeout(() => {
                input.scrollIntoView({ behavior: 'smooth', block: 'center' });
                input.focus();
                input.reportValidity();
            }, 250);
            
            return false;
        }
    }

    // Check that we have at least one diagnosis
    const diagnosesContainer = document.getElementById('diagnosesContainer');
    const count = diagnosesContainer ? diagnosesContainer.querySelectorAll('.diagnosis-row').length : 0;
    if (count === 0) {
        alert('Please select at least one symptom or add a custom diagnosis before finalizing.');
        const diseaseTabBtn = document.getElementById('notes-disease-tab');
        if (diseaseTabBtn) {
            activateTabForElement(diseaseTabBtn);
            setTimeout(() => {
                diseaseTabBtn.focus();
            }, 250);
        }
        return false;
    }

    return true;
}

// --- Practitioner / Specialty Referral Section ---
function validateAndFinalizeReferral() {
    const form = document.getElementById('consultationForm');
    if (!form) return;

    // 1. Validate Specialist selection first
    const refDocSelect = document.getElementById('diag_referral_doctor_id');
    const refDoc = refDocSelect.value;
    if (!refDoc) {
        alert('Please select a referral practitioner / specialty.');
        refDocSelect.focus();
        return;
    }

    // 2. Validate Referral Date & Time
    const refDateInput = document.getElementById('diag_referral_date');
    const refTimeInput = document.getElementById('diag_referral_time');
    if (!refDateInput.value) {
        alert('Please select a follow-up date.');
        refDateInput.focus();
        return;
    }
    if (!refTimeInput.value) {
        alert('Please select a follow-up time.');
        refTimeInput.focus();
        return;
    }

    // Run the main consultation form validation (validates required fields + diagnosis list count)
    if (!validateConsultationForm()) {
        return;
    }

    // 5. Populate Step 6 hidden follow-up fields
    const followupCheck = document.getElementById('followup_enabled');
    if (followupCheck) {
        followupCheck.checked = true;
        // Trigger the UI change handler
        window.toggleFollowup(followupCheck);

        // Set values
        const fDate = document.getElementById('followup_date');
        const fTime = document.getElementById('followup_time');
        const fDoc = document.getElementById('followup_doctor_id');
        if (fDate) fDate.value = refDateInput.value;
        if (fTime) fTime.value = refTimeInput.value;
        if (fDoc) fDoc.value = refDoc;
    }

    // 6. Submit consultation form immediately as Finalized
    submitConsultation(null, 'Finalized');
}

function formatApptDate(dateStr) {
    const d = new Date(dateStr + 'T00:00:00');
    return d.toLocaleDateString('en-US', { month: 'short', day: '2-digit', year: 'numeric' });
}

function formatApptTime(timeStr) {
    const [h, m] = timeStr.split(':');
    const hour = parseInt(h);
    const ampm = hour >= 12 ? 'PM' : 'AM';
    const hour12 = hour % 12 || 12;
    return `${String(hour12).padStart(2,'0')}:${m} ${ampm}`;
}

// Global hookups
window.toggleDrawer = toggleDrawer;
window.closeDrawer = closeDrawer;
window.openDrawer = openDrawer;
window.toggleFollowup = toggleFollowup;
window.goToTab = goToTab;
window.goToSubTab = goToSubTab;
window.addLaserParamRow = addLaserParamRow;
window.submitConsultation = submitConsultation;
window.handleFinalSubmit = handleFinalSubmit;
window.validateAndFinalizeReferral = validateAndFinalizeReferral;
window.activateTabForElement = activateTabForElement;
window.validateConsultationForm = validateConsultationForm;

// Prevent navigating away from a tab if its required fields are empty
document.querySelectorAll('[data-bs-toggle="tab"], [data-bs-toggle="pill"]').forEach(tabButton => {
    tabButton.addEventListener('show.bs.tab', function(e) {
        const previousTabButton = e.relatedTarget;
        if (!previousTabButton) return;
        
        const targetSelector = previousTabButton.getAttribute('data-bs-target');
        if (!targetSelector) return;
        const previousPane = document.querySelector(targetSelector);
        if (!previousPane) return;
        
        const inputs = previousPane.querySelectorAll('input[required], textarea[required], select[required]');
        for (const input of inputs) {
            if (!input.checkValidity()) {
                e.preventDefault();
                
                let fieldName = '';
                const label = document.querySelector(`label[for="${input.id}"]`);
                if (label) {
                    fieldName = label.textContent.trim();
                } else if (input.placeholder) {
                    fieldName = input.placeholder.trim();
                } else if (input.name) {
                    fieldName = input.name;
                } else {
                    fieldName = 'Required field';
                }
                fieldName = fieldName.replace(/[:*]/g, '').trim();
                
                alert(`"${fieldName}" is required before proceeding.`);
                setTimeout(() => {
                    input.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    input.focus();
                    input.reportValidity();
                }, 50);
                return;
            }
        }

        // Special Check: Notes & Diagnosis pane must have at least one diagnosis before leaving it for a later section
        const targetPaneSelector = tabButton.getAttribute('data-bs-target');
        if (previousPane.id === 'notes-pane' && targetPaneSelector !== '#vitals-pane' && targetPaneSelector !== '#history-pane') {
            const diagnosesContainer = document.getElementById('diagnosesContainer');
            const count = diagnosesContainer ? diagnosesContainer.querySelectorAll('.diagnosis-row').length : 0;
            if (count === 0) {
                e.preventDefault();
                alert('Please select at least one symptom or add a custom diagnosis before continuing.');
                return;
            }
        }
    });
});

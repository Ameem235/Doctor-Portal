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

const consult = window.db.getConsultationByAppointmentId(apptId);
if (!consult || consult.status !== 'Finalized') {
    alert('No finalized consultation record exists for this appointment.');
    window.location.href = `../dashboard/index.html?selected_id=${apptId}`;
}

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

function formatFullDate(dateStr) {
    const d = new Date(dateStr + 'T00:00:00');
    return d.toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });
}

// --- POPULATE HEADER ---
const patient = window.db.getPatientById(appt.patient_id);
const doctor = window.db.getDoctorById(appt.doctor_id);

document.getElementById('patient-name-title').textContent = patient.name;
document.getElementById('patient-gender-badge').textContent = patient.gender;
document.getElementById('patient-age-val').textContent = calculateAge(patient.dob);
document.getElementById('patient-dob-val').textContent = formatDOB(patient.dob);
document.getElementById('patient-id-val').textContent = patient.patient_id;
document.getElementById('appt-schedule-val').textContent = `${formatFullDate(appt.appointment_date)} at ${formatApptTime(appt.appointment_time)}`;
document.getElementById('back-to-dashboard-btn').href = `../dashboard/index.html?selected_id=${apptId}`;

// --- 1. VITALS & ALLERGIES ---
document.getElementById('vital-bp').textContent = consult.blood_pressure || 'N/A';
document.getElementById('vital-temp').textContent = consult.temperature ? `${consult.temperature} °C` : 'N/A';
document.getElementById('vital-hr').textContent = consult.heart_rate ? `${consult.heart_rate} bpm` : 'N/A';
document.getElementById('vital-wt').textContent = consult.weight ? `${consult.weight} kg` : 'N/A';
document.getElementById('vital-spo2').textContent = consult.oxygen_saturation ? `${consult.oxygen_saturation} %` : 'N/A';
document.getElementById('vital-pain').textContent = consult.pain_scale ? `${consult.pain_scale} / 10` : 'N/A';

if (consult.allergy_notes && consult.allergy_notes.trim() !== '' && consult.allergy_notes.trim().toUpperCase() !== 'NKDA') {
    const allergyBanner = document.getElementById('allergy-alert-banner');
    document.getElementById('allergy-notes-val').textContent = consult.allergy_notes;
    allergyBanner.classList.remove('hidden');
}

// --- 2. HISTORY ---
document.getElementById('history-referred').textContent = consult.referred_by || 'Self-referral';
document.getElementById('history-medical').textContent = consult.medical_history || 'No significant past medical history.';
document.getElementById('history-surgical').textContent = consult.surgical_history || 'None recorded.';
document.getElementById('history-family').textContent = consult.family_history || 'None recorded.';
document.getElementById('history-social').textContent = consult.social_history || 'None recorded.';

// --- 3. NOTES ---
document.getElementById('notes-complaint').textContent = consult.chief_complaint || 'None';
document.getElementById('hpi-pain-scale-type').textContent = consult.pain_scale_type || 'None';
document.getElementById('hpi-location').textContent = consult.hpi_location || 'None';
document.getElementById('hpi-quality').textContent = consult.hpi_quality || 'None';
document.getElementById('hpi-duration').textContent = consult.hpi_duration || 'None';
document.getElementById('hpi-timing').textContent = consult.hpi_timing || 'None';
document.getElementById('hpi-context').textContent = consult.hpi_context || 'None';
document.getElementById('hpi-modifying-factor').textContent = consult.hpi_modifying_factor || 'None';
document.getElementById('notes-physical').textContent = consult.physical_examination || 'Not recorded';
document.getElementById('narrative-diagnosis').textContent = consult.narrative_diagnosis || 'None recorded.';

// --- 3.5. Structured Clinical Treatment & Nursing Planning Section ---
let nursingPlanObj = {};
try {
    const rawNrs = consult.nursing_plan || consult.nursing_notes;
    nursingPlanObj = typeof rawNrs === 'string' ? JSON.parse(rawNrs || '{}') : (rawNrs || {});
} catch (e) {
    console.error(e);
}

function escapeHTML(str) {
    if (!str) return '';
    return str
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function formatDateTime(dtStr) {
    if (!dtStr) return 'N/A';
    try {
        const date = new Date(dtStr);
        if (isNaN(date.getTime())) return dtStr;
        return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) + ' ' + date.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
    } catch(e) {
        return dtStr;
    }
}

const container = document.getElementById('nursing-plan-container');

if (container) {
    let nurse_name = '';
    if (nursingPlanObj.advisory_by) {
        nurse_name = nursingPlanObj.advisory_by;
    } else if (nursingPlanObj.prep_nurse) {
        nurse_name = nursingPlanObj.prep_nurse;
    }
    
    container.innerHTML = `
        <div class="bg-white border border-hms-border rounded-xl shadow-sm p-5 mb-8 text-sm">
            <div class="flex flex-col md:flex-row gap-6">
                <div class="flex-1 text-hms-dark font-medium whitespace-pre-wrap leading-relaxed">${escapeHTML(consult.nurse_notes || 'No nurse notes recorded.')}</div>
                <div class="md:w-64 flex-shrink-0 flex flex-col justify-end items-center md:items-end border-t md:border-t-0 md:border-l border-hms-border pt-4 md:pt-0 md:pl-6 text-center md:text-right">
                    <span class="text-hms-muted text-xxs font-bold uppercase tracking-wider block mb-2">Signed By (Nurse)</span>
                    <div class="text-hms-accent font-serif font-bold text-lg">${escapeHTML(nurse_name || 'Not signed')}</div>
                </div>
            </div>
        </div>
    `;
}

// --- 4. DIAGNOSES TABLE ---
const diagTable = document.getElementById('diagnoses-table');
const diagBody = document.getElementById('diagnoses-table-body');
const diagNone = document.getElementById('no-diagnoses-text');
diagBody.innerHTML = '';

if (!consult.diagnoses || consult.diagnoses.length === 0) {
    diagTable.classList.add('hidden');
    diagNone.classList.remove('hidden');
} else {
    diagTable.classList.remove('hidden');
    diagNone.classList.add('hidden');
    consult.diagnoses.forEach(d => {
        const row = document.createElement('tr');
        row.className = 'border-b border-hms-border text-sm';
        row.innerHTML = `
            <td class="py-3 px-4">
                <span class="bg-hms-panel text-hms-accent px-3 py-1 rounded font-mono font-bold text-xs border border-blue-200">${d.icd_code}</span>
            </td>
            <td class="py-3 px-4 text-hms-dark font-medium">${d.description}</td>
        `;
        diagBody.appendChild(row);
    });
}

// --- 5. PRESCRIPTIONS LIST ---
const presListContainer = document.getElementById('prescriptions-list-container');
const presNone = document.getElementById('no-prescriptions-text');
presListContainer.innerHTML = '';

if (!consult.prescriptions || consult.prescriptions.length === 0) {
    if (presListContainer) presListContainer.classList.add('hidden');
    presNone.classList.remove('hidden');
} else {
    if (presListContainer) presListContainer.classList.remove('hidden');
    presNone.classList.add('hidden');
    consult.prescriptions.forEach((p, idx) => {
        const item = document.createElement('div');
        item.className = `${idx > 0 ? 'border-t border-hms-border/60 pt-3.5' : ''} text-sm`;
        item.innerHTML = `
            <div class="font-serif font-bold text-hms-dark text-base mb-1">${p.medicine_name}</div>
            <div class="text-xs text-hms-mid leading-relaxed">
                <span class="font-semibold text-hms-dark">Dosage:</span> ${p.dosage} &bull; 
                <span class="font-semibold text-hms-dark">Duration:</span> ${p.duration} &bull; 
                <span class="font-semibold text-hms-dark">Instructions:</span> ${p.instructions || 'No special instructions'}
            </div>
        `;
        presListContainer.appendChild(item);
    });
}

// --- 6. LAB TESTS ---
const labContainer = document.getElementById('lab-tests-container');
const labNone = document.getElementById('no-lab-tests-text');
labContainer.innerHTML = '';

if (!consult.lab_tests || consult.lab_tests.length === 0) {
    labNone.classList.remove('hidden');
} else {
    labNone.classList.add('hidden');
    consult.lab_tests.forEach(test => {
        let badgeClass = 'bg-gray-100 text-gray-700';
        if (test.priority === 'STAT') {
            badgeClass = 'bg-red-100 text-red-700 border border-red-200';
        } else if (test.priority === 'Urgent') {
            badgeClass = 'bg-orange-100 text-orange-700 border border-orange-200';
        }

        const card = document.createElement('div');
        card.className = 'border border-hms-border rounded-xl shadow-sm bg-white overflow-hidden mb-4';
        card.innerHTML = `
            <div class="bg-gray-50 border-b border-hms-border flex justify-between items-center py-2.5 px-4 text-sm">
                <div>
                    <strong class="text-hms-dark font-serif">${test.test_name}</strong>
                    <span class="bg-white text-hms-mid border border-hms-border text-xxs px-2.5 py-0.5 rounded-full font-bold ml-2">${test.category}</span>
                    <span class="text-xxs px-2.5 py-0.5 rounded-full font-bold ml-1.5 ${badgeClass}">${test.priority}</span>
                </div>
                <span class="bg-green-100 text-green-700 text-xs px-3 py-1 rounded-full font-semibold">Completed</span>
            </div>
            <div class="p-4">
                <div class="test-result-box shadow-sm">${test.result_summary}</div>
            </div>
        `;
        labContainer.appendChild(card);
    });
}

// --- 7. TIMELINE POPULATOR ---
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

// --- 8. POPULATE RX TICKET PRINT PREVIEW ---
document.getElementById('rx-doctor-name').textContent = doctor ? doctor.name : 'N/A';
document.getElementById('rx-doctor-email').textContent = doctor ? doctor.email : 'N/A';
document.getElementById('rx-appt-date').textContent = formatApptDate(appt.appointment_date);
document.getElementById('rx-patient-name').textContent = patient.name;
document.getElementById('rx-patient-age-gender').textContent = `${calculateAge(patient.dob)} Years / ${patient.gender}`;
document.getElementById('rx-patient-id').textContent = patient.patient_id;
document.getElementById('rx-appt-id').textContent = apptId;
document.getElementById('rx-signature-doctor-name').textContent = doctor ? doctor.name : 'N/A';

// Rx vitals
document.getElementById('rx-vitals-bp').textContent = consult.blood_pressure || 'N/A';
document.getElementById('rx-vitals-hr').textContent = consult.heart_rate ? `${consult.heart_rate} bpm` : 'N/A';
document.getElementById('rx-vitals-temp').textContent = consult.temperature ? `${consult.temperature} °C` : 'N/A';
document.getElementById('rx-vitals-wt').textContent = consult.weight ? `${consult.weight} kg` : 'N/A';

// Rx allergies
if (consult.allergy_notes && consult.allergy_notes.trim() !== '' && consult.allergy_notes.trim().toUpperCase() !== 'NKDA') {
    const rxAllergyDiv = document.getElementById('rx-allergy-alert');
    document.getElementById('rx-allergy-notes').textContent = consult.allergy_notes;
    rxAllergyDiv.classList.remove('hidden');
}

// Rx medications
const rxMedsContainer = document.getElementById('rx-meds-list-container');
if (rxMedsContainer) {
    rxMedsContainer.innerHTML = '';
    if (!consult.prescriptions || consult.prescriptions.length === 0) {
        rxMedsContainer.innerHTML = `
            <div class="text-hms-muted italic text-xs py-3">No medications prescribed.</div>
        `;
    } else {
        consult.prescriptions.forEach(p => {
            const item = document.createElement('div');
            item.className = 'border-b border-dashed border-hms-border pb-2 last:border-0';
            item.innerHTML = `
                <div class="font-serif font-bold text-sm text-hms-dark mb-0.5">${p.medicine_name}</div>
                <div><strong>Dosage:</strong> ${p.dosage}</div>
                <div><strong>Duration:</strong> ${p.duration}</div>
                ${p.instructions ? `<div class="text-hms-muted text-xs italic mt-0.5">Instructions: ${p.instructions}</div>` : ''}
            `;
            rxMedsContainer.appendChild(item);
        });
    }
}

// Rx Diagnoses
if (consult.diagnoses && consult.diagnoses.length > 0) {
    const rxDiagBlock = document.getElementById('rx-diagnoses-block');
    const rxDiagList = document.getElementById('rx-diagnoses-list');
    rxDiagList.textContent = consult.diagnoses.map(d => `${d.icd_code} - ${d.description}`).join(', ');
    rxDiagBlock.classList.remove('hidden');
}

// --- POPULATE DETAILED CASE SHEET PRINT AREA ---
const w = parseFloat(consult.weight || 0);
const h = parseFloat(consult.height || 0);
let bmiVal = '--';
if (w > 0 && h > 0) {
    bmiVal = (w / ((h / 100) ** 2)).toFixed(1);
}

let rosData = {};
try {
    rosData = typeof consult.ros_data === 'string' ? JSON.parse(consult.ros_data || '{}') : (consult.ros_data || {});
} catch (e) {
    console.error(e);
}

let examData = {};
try {
    examData = typeof consult.exam_data === 'string' ? JSON.parse(consult.exam_data || '{}') : (consult.exam_data || {});
} catch (e) {
    console.error(e);
}

const printApptDate = formatApptDate(appt.appointment_date);
document.getElementById('print-appt-date').textContent = printApptDate;
document.getElementById('print-appt-date-2').textContent = printApptDate;

document.getElementById('print-patient-name').textContent = patient.name;
document.getElementById('print-reg-no').textContent = `CBCR${30000 + patient.patient_id}`;
document.getElementById('print-visit-date').textContent = formatFullDate(appt.appointment_date);
document.getElementById('print-age-sex').textContent = `${calculateAge(patient.dob)} / ${patient.gender}`;
document.getElementById('print-dob').textContent = formatFullDate(patient.dob);
document.getElementById('print-email').textContent = `${patient.name.toLowerCase().replace(/\s+/g, '')}@gmail.com`;

document.getElementById('print-doctor-name').textContent = doctor ? doctor.name : 'N/A';
document.getElementById('print-doctor-signature-name').textContent = doctor ? doctor.name : 'N/A';

document.getElementById('print-complaint').textContent = consult.chief_complaint || 'None';
document.getElementById('print-pain-scale-type').textContent = consult.pain_scale_type || 'None';
document.getElementById('print-hpi-location').textContent = consult.hpi_location || 'None';
document.getElementById('print-hpi-quality').textContent = consult.hpi_quality || 'None';
document.getElementById('print-hpi-duration').textContent = consult.hpi_duration || 'None';
document.getElementById('print-hpi-timing').textContent = consult.hpi_timing || 'None';
document.getElementById('print-hpi-context').textContent = consult.hpi_context || 'None';
document.getElementById('print-hpi-modifying-factor').textContent = consult.hpi_modifying_factor || 'None';

document.getElementById('print-vitals-entered-by').textContent = doctor ? doctor.name : 'N/A';
document.getElementById('print-vitals-temp').textContent = consult.temperature ? `${consult.temperature} DegC` : 'N/A';

const bpParts = (consult.blood_pressure || '120/80').split('/');
document.getElementById('print-vitals-sys').textContent = `${bpParts[0] || '120'} mmHg`;
document.getElementById('print-vitals-dia').textContent = `${bpParts[1] || '80'} mmHg`;
document.getElementById('print-vitals-pulse').textContent = consult.heart_rate ? `${consult.heart_rate} bpm` : 'N/A';
document.getElementById('print-vitals-resp').textContent = consult.respiratory_rate ? `${consult.respiratory_rate} bpm` : 'N/A';
document.getElementById('print-vitals-spo2').textContent = consult.oxygen_saturation ? `${consult.oxygen_saturation} %` : 'N/A';
document.getElementById('print-vitals-height').textContent = consult.height ? `${consult.height} cm` : 'N/A';
document.getElementById('print-vitals-weight').textContent = consult.weight ? `${consult.weight} kg` : 'N/A';
document.getElementById('print-vitals-bmi').textContent = `${bmiVal} kg/m2`;

// Review of Systems print rows
const printRosTbody = document.getElementById('print-ros-tbody');
printRosTbody.innerHTML = '';
const rosSystems = {
    integumentary: 'Skin, Hair & Nails',
    constitutional: 'General / Constitutional',
    eyes: 'Eyes',
    enmt: 'Ear, Nose, Mouth & Throat',
    cardiovascular: 'Cardiovascular',
    respiratory: 'Respiratory',
    gastrointestinal: 'Gastrointestinal',
    genitourinary: 'Genitourinary',
    musculoskeletal: 'Musculoskeletal',
    neurological: 'Neurological',
    psychiatric: 'Psychiatric / Behavioral',
    endocrine: 'Endocrine',
    hem_lymph: 'Hematologic / Lymphatic',
    allergic_immuno: 'Allergic / Immunologic'
};
Object.keys(rosSystems).forEach(key => {
    const label = rosSystems[key];
    const val = rosData[key] || 'No Complaints';
    const tr = document.createElement('tr');
    tr.style.borderBottom = '1px solid #D1D5DB';
    tr.innerHTML = `
        <td style="width: 30%; padding: 5px; border-right: 1px solid #D1D5DB; font-weight: bold; color: #4B5563; background-color: #F9FAFB;">${label}</td>
        <td style="width: 70%; padding: 5px; color: #1F2937;">${val}</td>
    `;
    printRosTbody.appendChild(tr);
});

// Clinical exam print
document.getElementById('print-exam-general').textContent = examData.general || 'Normal';
document.getElementById('print-exam-skin').textContent = examData.skin || 'Normal';
document.getElementById('print-exam-notes').textContent = examData.notes || 'None recorded';

// Footer Date
const printedDateStr = `Printed Date: ${new Date().toLocaleDateString('en-GB')} ${new Date().toLocaleTimeString('en-US', { hour12: false, hour: '2-digit', minute: '2-digit' })}`;
document.getElementById('print-footer-date-1').textContent = printedDateStr;
document.getElementById('print-footer-date-2').textContent = printedDateStr;
document.getElementById('print-stamp-date').textContent = new Date().toLocaleDateString('en-GB').replace(/\//g, '-');

// Clinical Treatment & Procedure Notes
let nursingNotesObj = {};
try {
    const rawNrs = consult.nursing_plan || consult.nursing_notes;
    nursingNotesObj = typeof rawNrs === 'string' ? JSON.parse(rawNrs || '{}') : (rawNrs || {});
} catch (e) {
    console.error(e);
}

// Consolidate clinical summary, treatment, and nursing plan into NURSE NOTES section matching the print layout in the image
let nurse_name = '';
if (nursingNotesObj.advisory_by) {
    nurse_name = nursingNotesObj.advisory_by;
} else if (nursingNotesObj.prep_nurse) {
    nurse_name = nursingNotesObj.prep_nurse;
}

const printNurseNotesSection = document.getElementById('print-nurse-notes-section');
if (printNurseNotesSection) {
    printNurseNotesSection.innerHTML = `
        <div style="font-weight: bold; background-color: #EFEFEF; padding: 4px 6px; font-size: 10px; border: 1px solid #D1D5DB; border-bottom: none; text-transform: uppercase; letter-spacing: 0.05em; color: #1F2937;">
            NURSE NOTES
        </div>
        <table style="width: 100%; border-collapse: collapse; border: 1px solid #D1D5DB; font-size: 10px; margin-bottom: 10px;">
            <tr>
                <td style="width: 70%; padding: 8px; vertical-align: top; color: #1F2937; line-height: 1.5; white-space: pre-wrap; border-right: 1px solid #D1D5DB;">${escapeHTML(consult.nurse_notes || 'No nurse care plan or observations recorded.').replace(/\n/g, '<br>')}</td>
                <td style="width: 30%; padding: 8px; vertical-align: bottom; text-align: center; color: #1F2937; font-weight: bold; font-family: 'Inter', sans-serif;">
                    ${escapeHTML(nurse_name || 'Not signed')}
                </td>
            </tr>
        </table>
    `;
}

document.getElementById('print-narrative-diagnosis').textContent = consult.narrative_diagnosis || 'None recorded';

// Final diagnosis print rows
const printDiagsTbody = document.getElementById('print-diagnoses-tbody');
printDiagsTbody.innerHTML = '';
if (!consult.diagnoses || consult.diagnoses.length === 0) {
    printDiagsTbody.innerHTML = `
        <tr>
            <td style="padding: 6px; color: #6B7280; font-style: italic;">No final diagnosis recorded.</td>
        </tr>
    `;
} else {
    consult.diagnoses.forEach((d, idx) => {
        const prefix = (idx === 0) ? '[PRIMARY DIAGNOSIS]' : '[SECONDARY DIAGNOSIS]';
        const tr = document.createElement('tr');
        tr.style.borderBottom = '1px solid #D1D5DB';
        tr.innerHTML = `
            <td style="padding: 6px; color: #1F2937; font-weight: 500;">
                ${d.icd_code} - ${d.description} ${prefix}
            </td>
        `;
        printDiagsTbody.appendChild(tr);
    });
}

// Past medical history print rows
const printPastHistorySection = document.getElementById('print-past-history-section');
const printPastHistoryTbody = document.getElementById('print-past-history-tbody');
printPastHistoryTbody.innerHTML = '';
let hasPastHistory = false;

if (consult.referred_by) {
    hasPastHistory = true;
    printPastHistoryTbody.innerHTML += `
        <tr style="border-bottom: 1px solid #D1D5DB;">
            <td style="width: 30%; padding: 5px; border-right: 1px solid #D1D5DB; font-weight: bold; color: #4B5563; background-color: #F9FAFB;">Referred By</td>
            <td style="width: 70%; padding: 5px; color: #1F2937;">${consult.referred_by}</td>
        </tr>
    `;
}
if (consult.medical_history) {
    hasPastHistory = true;
    printPastHistoryTbody.innerHTML += `
        <tr style="border-bottom: 1px solid #D1D5DB;">
            <td style="width: 30%; padding: 5px; border-right: 1px solid #D1D5DB; font-weight: bold; color: #4B5563; background-color: #F9FAFB;">Medical History</td>
            <td style="width: 70%; padding: 5px; color: #1F2937;">${consult.medical_history.replace(/\n/g, '<br>')}</td>
        </tr>
    `;
}
if (consult.surgical_history) {
    hasPastHistory = true;
    printPastHistoryTbody.innerHTML += `
        <tr style="border-bottom: 1px solid #D1D5DB;">
            <td style="width: 30%; padding: 5px; border-right: 1px solid #D1D5DB; font-weight: bold; color: #4B5563; background-color: #F9FAFB;">Surgical History</td>
            <td style="width: 70%; padding: 5px; color: #1F2937;">${consult.surgical_history.replace(/\n/g, '<br>')}</td>
        </tr>
    `;
}
if (consult.family_history) {
    hasPastHistory = true;
    printPastHistoryTbody.innerHTML += `
        <tr style="border-bottom: 1px solid #D1D5DB;">
            <td style="width: 30%; padding: 5px; border-right: 1px solid #D1D5DB; font-weight: bold; color: #4B5563; background-color: #F9FAFB;">Family History</td>
            <td style="width: 70%; padding: 5px; color: #1F2937;">${consult.family_history.replace(/\n/g, '<br>')}</td>
        </tr>
    `;
}
if (consult.social_history) {
    hasPastHistory = true;
    printPastHistoryTbody.innerHTML += `
        <tr>
            <td style="width: 30%; padding: 5px; border-right: 1px solid #D1D5DB; font-weight: bold; color: #4B5563; background-color: #F9FAFB;">Social History</td>
            <td style="width: 70%; padding: 5px; color: #1F2937;">${consult.social_history.replace(/\n/g, '<br>')}</td>
        </tr>
    `;
}

if (hasPastHistory) {
    printPastHistorySection.style.display = 'block';
} else {
    printPastHistorySection.style.display = 'none';
}

// Medications print list
const printMedsSection = document.getElementById('print-medications-section');
const printMedsListContainer = document.getElementById('print-medications-list-container');
if (printMedsListContainer) {
    printMedsListContainer.innerHTML = '';
    if (consult.prescriptions && consult.prescriptions.length > 0) {
        printMedsSection.style.display = 'block';
        consult.prescriptions.forEach((p, index) => {
            const item = document.createElement('div');
            if (index > 0) {
                item.style.borderTop = '1px dashed #D1D5DB';
                item.style.paddingTop = '6px';
                item.style.marginTop = '6px';
            }
            item.innerHTML = `
                <div style="font-weight: bold; font-size: 11px;">${p.medicine_name}</div>
                <div style="margin-top: 2px;">
                    <strong>Dosage / Frequency:</strong> ${p.dosage} &bull;
                    <strong>Duration:</strong> ${p.duration} &bull;
                    <strong>Instructions:</strong> ${p.instructions || 'No special instructions'}
                </div>
            `;
            printMedsListContainer.appendChild(item);
        });
    } else {
        printMedsSection.style.display = 'none';
    }
}

// Lab tests print rows
const printLabSection = document.getElementById('print-lab-tests-section');
const printLabTbody = document.getElementById('print-lab-tests-tbody');
printLabTbody.innerHTML = '';
if (consult.lab_tests && consult.lab_tests.length > 0) {
    printLabSection.style.display = 'block';
    consult.lab_tests.forEach(t => {
        const tr = document.createElement('tr');
        tr.style.borderBottom = '1px solid #D1D5DB';
        tr.style.verticalAlign = 'top';
        tr.innerHTML = `
            <td style="padding: 5px; border-right: 1px solid #D1D5DB; font-weight: bold; color: #1F2937;">${t.test_name}</td>
            <td style="padding: 5px; border-right: 1px solid #D1D5DB; color: #4B5563;">${t.priority || 'Routine'}</td>
            <td style="padding: 5px; font-size: 9px; color: #4B5563; white-space: pre-wrap;">${t.result_summary}</td>
        `;
        printLabTbody.appendChild(tr);
    });
} else {
    printLabSection.style.display = 'none';
}

// --- PRINTING AND EXPORT HANDLERS ---
function startPrint(elementId) {
    const existing = document.getElementById('printContainer');
    if (existing) existing.remove();

    const printContainer = document.createElement('div');
    printContainer.id = 'printContainer';
    
    const target = document.getElementById(elementId);
    if (!target) return;
    printContainer.innerHTML = target.innerHTML;
    
    document.body.appendChild(printContainer);
    document.body.classList.add('printing-active');
    window.print();
}

function printRxTicket() {
    startPrint('prescriptionPrintArea');
}

function printCaseSheet() {
    startPrint('caseSheetPrintArea');
}

window.addEventListener('afterprint', () => {
    document.body.classList.remove('printing-active');
    const pc = document.getElementById('printContainer');
    if (pc) pc.remove();
});

function downloadRxXML() {
    function escapeXml(unsafe) {
        if (unsafe === null || unsafe === undefined) return '';
        return String(unsafe).replace(/[<>&'"]/g, function (c) {
            switch (c) {
                case '<': return '&lt;';
                case '>': return '&gt;';
                case '&': return '&amp;';
                case '\'': return '&apos;';
                case '"': return '&quot;';
                default: return c;
            }
        });
    }

    const patientId = patient.patient_id;
    const patientName = patient.name;
    const age = calculateAge(patient.dob);
    const gender = patient.gender;
    const doctorName = doctor ? doctor.name : 'N/A';
    const doctorEmail = doctor ? doctor.email : 'N/A';
    const date = appt.appointment_date;
    const apptIdVal = apptId;
    const bp = consult.blood_pressure || 'N/A';
    const hr = consult.heart_rate ? `${consult.heart_rate} bpm` : 'N/A';
    const temp = consult.temperature ? `${consult.temperature} °C` : 'N/A';
    const weight = consult.weight ? `${consult.weight} kg` : 'N/A';
    const height = consult.height ? `${consult.height} cm` : 'N/A';
    const respRate = consult.respiratory_rate ? `${consult.respiratory_rate} bpm` : 'N/A';
    const bmi = bmiVal;
    const painScale = consult.pain_scale || 'N/A';
    const allergy = consult.allergy_notes || '';
    
    let xml = '<?xml version="1.0" encoding="UTF-8"?>\n';
    xml += '<prescription>\n';
    xml += '  <clinic>MedCore Clinic</clinic>\n';
    xml += `  <appointment_id>${apptIdVal}</appointment_id>\n`;
    xml += `  <date>${date}</date>\n`;
    
    xml += '  <doctor>\n';
    xml += `    <name>${escapeXml(doctorName)}</name>\n`;
    xml += `    <email>${escapeXml(doctorEmail)}</email>\n`;
    xml += '  </doctor>\n';
    
    xml += '  <patient>\n';
    xml += `    <id>${patientId}</id>\n`;
    xml += `    <name>${escapeXml(patientName)}</name>\n`;
    xml += `    <age>${age}</age>\n`;
    xml += `    <gender>${gender}</gender>\n`;
    xml += '  </patient>\n';
    
    xml += '  <vitals>\n';
    xml += `    <blood_pressure>${bp}</blood_pressure>\n`;
    xml += `    <heart_rate>${hr}</heart_rate>\n`;
    xml += `    <temperature>${temp}</temperature>\n`;
    xml += `    <weight>${weight}</weight>\n`;
    xml += `    <height>${height}</height>\n`;
    xml += `    <respiratory_rate>${respRate}</respiratory_rate>\n`;
    xml += `    <bmi>${bmi}</bmi>\n`;
    xml += `    <pain_scale>${painScale}</pain_scale>\n`;
    xml += '  </vitals>\n';

    xml += '  <review_of_systems>\n';
    const rosSystemsKeys = [
        'integumentary', 'constitutional', 'eyes', 'enmt', 'cardiovascular',
        'respiratory', 'gastrointestinal', 'genitourinary', 'musculoskeletal',
        'neurological', 'psychiatric', 'endocrine', 'hem_lymph', 'allergic_immuno'
    ];
    rosSystemsKeys.forEach(system => {
        const val = rosData[system] || 'No Complaints';
        xml += `    <${system}>${escapeXml(val)}</${system}>\n`;
    });
    xml += '  </review_of_systems>\n';

    xml += '  <clinical_examination>\n';
    xml += `    <general>${escapeXml(examData.general || 'Normal')}</general>\n`;
    xml += `    <skin>${escapeXml(examData.skin || 'Normal')}</skin>\n`;
    xml += `    <notes>${escapeXml(examData.notes || 'No examination notes recorded.')}</notes>\n`;
    xml += '  </clinical_examination>\n';
    
    if (allergy) {
        xml += `  <allergies>${escapeXml(allergy)}</allergies>\n`;
    }
    
    xml += '  <diagnoses>\n';
    if (consult.diagnoses) {
        consult.diagnoses.forEach(d => {
            xml += '    <diagnosis>\n';
            xml += `      <code>${escapeXml(d.icd_code)}</code>\n`;
            xml += `      <description>${escapeXml(d.description)}</description>\n`;
            xml += '    </diagnosis>\n';
        });
    }
    xml += '  </diagnoses>\n';
    
    xml += '  <medications>\n';
    if (consult.prescriptions) {
        consult.prescriptions.forEach(p => {
            xml += '    <medication>\n';
            xml += `      <name>${escapeXml(p.medicine_name)}</name>\n`;
            xml += `      <dosage>${escapeXml(p.dosage)}</dosage>\n`;
            xml += `      <duration>${escapeXml(p.duration)}</duration>\n`;
            xml += `      <instructions>${escapeXml(p.instructions || '')}</instructions>\n`;
            xml += '    </medication>\n';
        });
    }
    xml += '  </medications>\n';
    
    xml += '</prescription>';

    const blob = new Blob([xml], { type: 'application/xml' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `rx_ticket_${apptIdVal}.xml`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}

// Bind to window object for button onclick access
window.printRxTicket = printRxTicket;
window.printCaseSheet = printCaseSheet;
window.downloadRxXML = downloadRxXML;


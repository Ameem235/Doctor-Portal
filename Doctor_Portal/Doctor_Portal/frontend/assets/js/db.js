// MedCore HMS Static Frontend Mock Database Wrapper (localStorage)

const DB_KEYS = {
    DOCTORS: 'hms_doctors',
    PATIENTS: 'hms_patients',
    APPOINTMENTS: 'hms_appointments',
    CONSULTATIONS: 'hms_consultations',
    CURRENT_USER: 'hms_current_user',
    INITIALIZED: 'hms_db_initialized'
};

// Helper: Get today's and tomorrow's date formatted as YYYY-MM-DD
function getFormattedDate(offsetDays = 0) {
    const d = new Date();
    d.setDate(d.getDate() + offsetDays);
    const yyyy = d.getFullYear();
    const mm = String(d.getMonth() + 1).padStart(2, '0');
    const dd = String(d.getDate()).padStart(2, '0');
    return `${yyyy}-${mm}-${dd}`;
}

// Initial seed data
const SEED_DATA = {
    doctors: [
        { doctor_id: 1, name: 'Dr. Elizabeth Blackwell', email: 'doctor@medical.com', password: 'password123' },
        { doctor_id: 2, name: 'Admin User', email: 'admin', password: 'admin' }
    ],
    patients: [
        { patient_id: 1, name: 'Muhammad Ali', dob: '1988-03-12', gender: 'Male' },
        { patient_id: 2, name: 'Fatima Bibi', dob: '1995-07-22', gender: 'Female' },
        { patient_id: 3, name: 'Aisha Khan', dob: '1991-11-05', gender: 'Female' },
        { patient_id: 4, name: 'Sana Mir', dob: '1992-12-05', gender: 'Female' },
        { patient_id: 5, name: 'Kamran Akmal', dob: '1985-06-15', gender: 'Male' },
        { patient_id: 6, name: 'Yasmin Rashid', dob: '1960-04-10', gender: 'Female' },
        { patient_id: 7, name: 'Imran Khan', dob: '1972-10-05', gender: 'Male' },
        { patient_id: 8, name: 'Bilal Ahmed', dob: '1982-01-30', gender: 'Male' },
        { patient_id: 9, name: 'Zainab Yousaf', dob: '2000-09-18', gender: 'Female' },
        { patient_id: 10, name: 'Tariq Mahmood', dob: '1975-05-14', gender: 'Male' }
    ]
};

function getSeedAppointments() {
    return [
        {
            appointment_id: 1,
            patient_id: 1,
            doctor_id: 1,
            appointment_date: getFormattedDate(0), // Today
            appointment_time: '09:00:00',
            status: 'Scheduled'
        },
        {
            appointment_id: 2,
            patient_id: 2,
            doctor_id: 1,
            appointment_date: getFormattedDate(0), // Today
            appointment_time: '10:30:00',
            status: 'Scheduled'
        },
        {
            appointment_id: 3,
            patient_id: 3,
            doctor_id: 1,
            appointment_date: getFormattedDate(0), // Today
            appointment_time: '11:45:00',
            status: 'Scheduled'
        },
        {
            appointment_id: 4,
            patient_id: 4,
            doctor_id: 1,
            appointment_date: getFormattedDate(0), // Today
            appointment_time: '13:00:00',
            status: 'Scheduled'
        },
        {
            appointment_id: 5,
            patient_id: 5,
            doctor_id: 1,
            appointment_date: getFormattedDate(0), // Today
            appointment_time: '15:00:00',
            status: 'Scheduled'
        },
        {
            appointment_id: 6,
            patient_id: 6,
            doctor_id: 1,
            appointment_date: getFormattedDate(1), // Tomorrow
            appointment_time: '10:00:00',
            status: 'Scheduled'
        },
        {
            appointment_id: 7,
            patient_id: 7,
            doctor_id: 1,
            appointment_date: getFormattedDate(1), // Tomorrow
            appointment_time: '11:30:00',
            status: 'Scheduled'
        },
        {
            appointment_id: 8,
            patient_id: 8,
            doctor_id: 1,
            appointment_date: getFormattedDate(1), // Tomorrow
            appointment_time: '14:15:00',
            status: 'Scheduled'
        },
        {
            appointment_id: 9,
            patient_id: 9,
            doctor_id: 1,
            appointment_date: getFormattedDate(1), // Tomorrow
            appointment_time: '15:30:00',
            status: 'Scheduled'
        },
        {
            appointment_id: 10,
            patient_id: 10,
            doctor_id: 1,
            appointment_date: getFormattedDate(1), // Tomorrow
            appointment_time: '16:45:00',
            status: 'Scheduled'
        }
    ];
}

// Initialize database
function initDatabase(force = false) {
    if (!localStorage.getItem(DB_KEYS.INITIALIZED) || force) {
        localStorage.setItem(DB_KEYS.DOCTORS, JSON.stringify(SEED_DATA.doctors));
        localStorage.setItem(DB_KEYS.PATIENTS, JSON.stringify(SEED_DATA.patients));
        localStorage.setItem(DB_KEYS.APPOINTMENTS, JSON.stringify(getSeedAppointments()));
        localStorage.setItem(DB_KEYS.CONSULTATIONS, JSON.stringify([]));
        localStorage.setItem(DB_KEYS.INITIALIZED, 'true');
        return true;
    }
    return false;
}

// Run initialization immediately on import
initDatabase();

// --- DATA ACCESS LAYER ---

const db = {
    // Reset database to seed data
    resetDatabase() {
        initDatabase(true);
        localStorage.removeItem(DB_KEYS.CURRENT_USER);
    },

    // Authentication & Session
    getCurrentUser() {
        const user = localStorage.getItem(DB_KEYS.CURRENT_USER);
        return user ? JSON.parse(user) : null;
    },

    setCurrentUser(user) {
        localStorage.setItem(DB_KEYS.CURRENT_USER, JSON.stringify(user));
    },

    logout() {
        localStorage.removeItem(DB_KEYS.CURRENT_USER);
    },

    verifyCredentials(email, password, role) {
        const doctors = JSON.parse(localStorage.getItem(DB_KEYS.DOCTORS) || '[]');
        
        // Match specific logins
        if (role === 'Admin') {
            if (email.toLowerCase() === 'admin' && password === 'admin') {
                return doctors.find(d => d.email === 'admin');
            }
            return null;
        } else if (role === 'Physician') {
            return doctors.find(d => d.email.toLowerCase() === email.toLowerCase() && d.password === password);
        }
        return null;
    },

    // Doctors
    getDoctorById(id) {
        const doctors = JSON.parse(localStorage.getItem(DB_KEYS.DOCTORS) || '[]');
        return doctors.find(d => d.doctor_id === parseInt(id)) || null;
    },

    // Patients
    getPatientById(id) {
        const patients = JSON.parse(localStorage.getItem(DB_KEYS.PATIENTS) || '[]');
        return patients.find(p => p.patient_id === parseInt(id)) || null;
    },

    // Appointments
    getAppointments() {
        return JSON.parse(localStorage.getItem(DB_KEYS.APPOINTMENTS) || '[]');
    },

    getAppointmentsWithPatientDetails(doctorId) {
        const appts = this.getAppointments();
        const patients = JSON.parse(localStorage.getItem(DB_KEYS.PATIENTS) || '[]');
        const consults = this.getConsultations();

        // Filter by doctorId
        const filtered = appts.filter(a => a.doctor_id === parseInt(doctorId));

        // Join patient and consultation status
        return filtered.map(a => {
            const patient = patients.find(p => p.patient_id === a.patient_id) || {};
            const consult = consults.find(c => c.appointment_id === a.appointment_id);
            return {
                ...a,
                patient_name: patient.name,
                dob: patient.dob,
                gender: patient.gender,
                consultation_status: consult ? consult.status : null
            };
        });
    },

    getAppointmentById(id) {
        const appts = this.getAppointments();
        return appts.find(a => a.appointment_id === parseInt(id)) || null;
    },

    updateAppointment(id, updatedData) {
        const appts = this.getAppointments();
        const idx = appts.findIndex(a => a.appointment_id === parseInt(id));
        if (idx !== -1) {
            appts[idx] = { ...appts[idx], ...updatedData };
            localStorage.setItem(DB_KEYS.APPOINTMENTS, JSON.stringify(appts));
            return appts[idx];
        }
        return null;
    },

    deleteAppointment(id) {
        const appts = this.getAppointments();
        const filtered = appts.filter(a => a.appointment_id !== parseInt(id));
        localStorage.setItem(DB_KEYS.APPOINTMENTS, JSON.stringify(filtered));

        // Also delete associated consultations
        const consults = this.getConsultations();
        const filteredConsults = consults.filter(c => c.appointment_id !== parseInt(id));
        localStorage.setItem(DB_KEYS.CONSULTATIONS, JSON.stringify(filteredConsults));
    },

    addAppointment(data) {
        const appts = this.getAppointments();
        const newId = appts.reduce((max, a) => a.appointment_id > max ? a.appointment_id : max, 0) + 1;
        const newAppt = {
            appointment_id: newId,
            ...data
        };
        appts.push(newAppt);
        localStorage.setItem(DB_KEYS.APPOINTMENTS, JSON.stringify(appts));
        return newAppt;
    },

    // Consultations
    getConsultations() {
        return JSON.parse(localStorage.getItem(DB_KEYS.CONSULTATIONS) || '[]');
    },

    getConsultationByAppointmentId(apptId) {
        const consults = this.getConsultations();
        return consults.find(c => c.appointment_id === parseInt(apptId)) || null;
    },

    saveConsultation(apptId, data) {
        const consults = this.getConsultations();
        let idx = consults.findIndex(c => c.appointment_id === parseInt(apptId));
        
        let consult = {};
        if (idx !== -1) {
            consult = { ...consults[idx], ...data };
            consults[idx] = consult;
        } else {
            const newId = consults.reduce((max, c) => c.consultation_id > max ? c.consultation_id : max, 0) + 1;
            consult = {
                consultation_id: newId,
                appointment_id: parseInt(apptId),
                ...data
            };
            consults.push(consult);
        }

        localStorage.setItem(DB_KEYS.CONSULTATIONS, JSON.stringify(consults));
        
        // Update appointment status to Accepted (if saving draft) or Completed (if finalizing)
        const apptStatus = data.status === 'Finalized' ? 'Completed' : 'Accepted';
        this.updateAppointment(apptId, { status: apptStatus });

        return consult;
    },

    getPastConsultationsForPatient(patientId, currentApptId) {
        // Find all finalized consultations for this patient
        const appts = this.getAppointments().filter(a => a.patient_id === parseInt(patientId) && a.appointment_id !== parseInt(currentApptId));
        const consults = this.getConsultations().filter(c => c.status === 'Finalized');

        const pastVisits = [];
        appts.forEach(appt => {
            const c = consults.find(c => c.appointment_id === appt.appointment_id);
            if (c) {
                pastVisits.push({
                    ...c,
                    appointment_date: appt.appointment_date,
                    appointment_time: appt.appointment_time
                });
            }
        });

        // Sort descending by date/time
        return pastVisits.sort((a, b) => {
            const dateA = new Date(a.appointment_date + 'T' + a.appointment_time);
            const dateB = new Date(b.appointment_date + 'T' + b.appointment_time);
            return dateB - dateA;
        });
    },

    // Activities log generator helper
    getRecentActivities(doctorId) {
        const appts = this.getAppointments().filter(a => a.doctor_id === parseInt(doctorId));
        const patients = JSON.parse(localStorage.getItem(DB_KEYS.PATIENTS) || '[]');
        const consults = this.getConsultations();

        const activities = [];

        appts.forEach(a => {
            const p = patients.find(p => p.patient_id === a.patient_id) || {};
            const c = consults.find(c => c.appointment_id === a.appointment_id);

            if (c) {
                if (c.status === 'Draft') {
                    activities.push({
                        activity_type: 'Completed Consultation', // String used by PHP
                        c_status: 'Draft',
                        patient_name: p.name,
                        appointment_date: a.appointment_date,
                        appointment_time: a.appointment_time
                    });
                } else {
                    activities.push({
                        activity_type: 'Completed Consultation',
                        c_status: 'Finalized',
                        patient_name: p.name,
                        appointment_date: a.appointment_date,
                        appointment_time: a.appointment_time
                    });
                }
            } else if (a.status === 'Accepted') {
                activities.push({
                    activity_type: 'Accepted Appointment',
                    c_status: 'Finalized',
                    patient_name: p.name,
                    appointment_date: a.appointment_date,
                    appointment_time: a.appointment_time
                });
            }
        });

        // Sort by date/time descending and slice top 5
        return activities.sort((a, b) => {
            const dateA = new Date(a.appointment_date + 'T' + a.appointment_time);
            const dateB = new Date(b.appointment_date + 'T' + b.appointment_time);
            return dateB - dateA;
        }).slice(0, 5);
    }
};

// Export to window object for ease of access in frontend scripts
window.db = db;

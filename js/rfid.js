// ============================================================
//  RFID.JS
//  RFID card management with tap simulation and live search
// ============================================================

(function() {
    'use strict';

    // ── DOM Elements ──
    const assignForm = document.getElementById('assignCardForm');
    const tapBtn = document.getElementById('tapBtn');
    const cardUidInput = document.getElementById('cardUid');
    const studentSearchInput = document.getElementById('studentSearchInput');
    const studentSearchResults = document.getElementById('studentSearchResults');

    // ── State ──
    let isTapping = false;
    let selectedStudent = null;

    // ── Tap Card (Simulate RFID Reader) ──
    if (tapBtn) {
        tapBtn.addEventListener('click', function() {
            if (isTapping) return;

            const input = cardUidInput;
            const btn = this;
            const btnText = document.getElementById('tapBtnText');

            isTapping = true;
            btn.className = 'btn-tap tapping';
            btnText.textContent = 'Reading...';
            input.placeholder = 'Reading card...';
            input.style.borderColor = '#2563eb';

            // Simulate reading a card
            const simulatedUid = Math.floor(Math.random() * 9000000000 + 1000000000).toString();

            setTimeout(function() {
                input.value = simulatedUid;
                input.style.borderColor = '#22c55e';
                input.style.background = '#f0fdf4';
                btn.className = 'btn-tap success';
                btnText.textContent = '✓ Read!';

                setTimeout(function() {
                    btn.className = 'btn-tap';
                    btnText.textContent = 'Tap';
                    input.style.background = 'white';
                    isTapping = false;
                    showToast('Card Read', 'UID: ' + simulatedUid, 'success');
                }, 800);
            }, 1200);
        });
    }

    // ── UID Validation ──
    if (cardUidInput) {
        cardUidInput.addEventListener('input', function() {
            // Only allow numbers
            this.value = this.value.replace(/[^0-9]/g, '');
            if (this.value.length > 10) this.value = this.value.slice(0, 10);

            // Visual feedback
            if (this.value.length === 10) {
                this.style.borderColor = '#22c55e';
                this.style.background = '#f0fdf4';
            } else if (this.value.length > 0) {
                this.style.borderColor = '#e2e8f0';
                this.style.background = 'white';
            } else {
                this.style.borderColor = '#e2e8f0';
                this.style.background = 'white';
            }
        });
    }

    // ── Student Search (for assign modal) ──
    if (studentSearchInput) {
        const allStudents = [];

        // Load students from select or data
        const select = document.getElementById('studentSelect');
        if (select) {
            Array.from(select.options).forEach(function(opt) {
                if (opt.value) {
                    allStudents.push({
                        id: opt.value,
                        name: opt.textContent
                    });
                }
            });
        }

        studentSearchInput.addEventListener('input', function() {
            const query = this.value.trim().toLowerCase();
            const resultsContainer = studentSearchResults;

            if (!query || query.length < 1) {
                resultsContainer.classList.remove('show');
                return;
            }

            const results = allStudents.filter(function(s) {
                return s.name.toLowerCase().includes(query);
            });

            if (results.length === 0) {
                resultsContainer.innerHTML = `<div class="no-results">No students found</div>`;
                resultsContainer.classList.add('show');
                return;
            }

            let html = '';
            results.slice(0, 10).forEach(function(s) {
                html += `
                    <div class="result-item" onclick="selectStudent('${s.id}', '${s.name}')">
                        <span class="student-name">${s.name}</span>
                    </div>
                `;
            });
            resultsContainer.innerHTML = html;
            resultsContainer.classList.add('show');
        });

        // Close results on outside click
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.student-search-wrapper')) {
                if (studentSearchResults) {
                    studentSearchResults.classList.remove('show');
                }
            }
        });
    }

    // ── Select Student (for assign modal) ──
    window.selectStudent = function(id, name) {
        const select = document.getElementById('studentSelect');
        const display = document.getElementById('selectedStudentDisplay');
        const displayName = document.getElementById('selectedName');
        const displayId = document.getElementById('selectedId');
        const hiddenId = document.getElementById('selectedStudentId');

        if (select) select.value = id;
        if (hiddenId) hiddenId.value = id;
        if (displayName) displayName.textContent = name;
        if (displayId) displayId.textContent = 'Selected';
        if (display) display.classList.add('show');

        if (studentSearchInput) {
            studentSearchInput.value = name;
            studentSearchInput.style.borderColor = '#22c55e';
            studentSearchInput.style.background = '#f0fdf4';
        }

        if (studentSearchResults) {
            studentSearchResults.classList.remove('show');
        }

        selectedStudent = { id, name };
        showToast('Student Selected', name, 'success');
    };

    // ── Clear Selected Student ──
    window.clearSelectedStudent = function() {
        const display = document.getElementById('selectedStudentDisplay');
        const hiddenId = document.getElementById('selectedStudentId');
        const select = document.getElementById('studentSelect');

        if (display) display.classList.remove('show');
        if (hiddenId) hiddenId.value = '';
        if (select) select.value = '';
        if (studentSearchInput) {
            studentSearchInput.value = '';
            studentSearchInput.style.borderColor = '#e2e8f0';
            studentSearchInput.style.background = 'white';
        }
        selectedStudent = null;
    };

    // ── Submit Assign Card ──
    if (assignForm) {
        assignForm.addEventListener('submit', async function(e) {
            e.preventDefault();

            const studentId = document.getElementById('selectedStudentId')?.value;
            const cardUid = document.getElementById('cardUid')?.value.trim();
            const submitBtn = document.getElementById('assignSubmitBtn');

            if (!studentId) {
                showToast('Error', 'Please select a student.', 'error');
                return;
            }

            if (!cardUid || cardUid.length !== 10) {
                showToast('Error', 'Card UID must be exactly 10 digits.', 'error');
                return;
            }

            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Assigning...';

            try {
                const response = await fetch('../api/rfid-assign.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        student_id: studentId,
                        card_uid: cardUid,
                        issued_date: document.getElementById('issuedDate')?.value,
                        expiry_date: document.getElementById('expiryDate')?.value,
                        notes: document.getElementById('cardNotes')?.value
                    })
                });

                const result = await response.json();

                if (result.success) {
                    showToast('Success', result.message, 'success');
                    setTimeout(function() {
                        window.location.reload();
                    }, 1000);
                } else {
                    showToast('Error', result.message, 'error');
                }
            } catch (error) {
                showToast('Error', 'Network error. Please try again.', 'error');
            } finally {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fas fa-save"></i> Assign Card';
            }
        });
    }

    // ── Toast Helper ──
    function showToast(title, message, type) {
        const container = document.getElementById('toastContainer');
        if (!container) return;

        const toast = document.createElement('div');
        toast.className = 'toast ' + (type || 'success');

        const icons = {
            success: 'fa-check-circle',
            error: 'fa-exclamation-circle',
            warning: 'fa-triangle-exclamation',
            info: 'fa-info-circle'
        };

        toast.innerHTML = `
            <i class="fas ${icons[type] || icons.success} toast-icon"></i>
            <div class="toast-content">
                <div class="toast-title">${title}</div>
                <div class="toast-message">${message}</div>
            </div>
            <button class="toast-close" onclick="this.closest('.toast').remove()">
                <i class="fas fa-times"></i>
            </button>
        `;

        container.appendChild(toast);

        setTimeout(function() {
            toast.classList.add('hiding');
            setTimeout(function() { toast.remove(); }, 300);
        }, 4000);
    }

    // ── Expose functions globally ──
    window.showToast = showToast;
    window.selectStudent = window.selectStudent;
    window.clearSelectedStudent = window.clearSelectedStudent;

})();
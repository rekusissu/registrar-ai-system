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
                const response = await fetch('../api/rfid.php', {
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
    window.openAssignModal = openAssignModal;
    window.closeAssignModal = closeAssignModal;
    window.openEditModal = openEditModal;
    window.closeEditModal = closeEditModal;
    window.openViewDrawer = openViewDrawer;
    window.closeViewDrawer = closeViewDrawer;

    // ── Live Search ──
    const rfidSearchInput = document.getElementById('rfidSearch');
    const rfidTableBody = document.getElementById('rfidTableBody');
    const searchClearBtn = document.getElementById('searchClear');
    const showingCount = document.getElementById('showingCount');
    const totalCount = document.getElementById('totalCount');

    function applyRfidSearch() {
        if (!rfidTableBody) return;
        const query = (rfidSearchInput?.value || '').trim().toLowerCase();
        const rows = rfidTableBody.querySelectorAll('tr[data-card]');
        let visible = 0;
        rows.forEach(function (row) {
            if (!query) {
                row.style.display = '';
                visible++;
                return;
            }
            const data = row.getAttribute('data-card') || '';
            const haystack = data.toLowerCase();
            const matches = haystack.indexOf(query) !== -1;
            row.style.display = matches ? '' : 'none';
            if (matches) visible++;
        });
        if (showingCount) showingCount.textContent = visible;
    }

    if (rfidSearchInput) {
        rfidSearchInput.addEventListener('input', applyRfidSearch);
    }
    if (searchClearBtn) {
        searchClearBtn.addEventListener('click', function () {
            if (rfidSearchInput) rfidSearchInput.value = '';
            applyRfidSearch();
            rfidSearchInput && rfidSearchInput.focus();
        });
    }

    // ── Open Assign Modal ──
    function openAssignModal() {
        const m = document.getElementById('assignModal');
        if (!m) return;
        m.classList.add('active');
        document.body.style.overflow = 'hidden';
        const uid = document.getElementById('cardUid');
        if (uid) setTimeout(function () { uid.focus(); }, 50);
    }
    function closeAssignModal() {
        const m = document.getElementById('assignModal');
        if (!m) return;
        m.classList.remove('active');
        document.body.style.overflow = '';
        const f = document.getElementById('assignCardForm');
        if (f) f.reset();
        clearSelectedStudent();
        const issued = document.getElementById('issuedDate');
        const expiry = document.getElementById('expiryDate');
        if (issued) issued.value = new Date().toISOString().slice(0, 10);
        if (expiry) {
            const d = new Date();
            d.setFullYear(d.getFullYear() + 1);
            expiry.value = d.toISOString().slice(0, 10);
        }
    }

    // ── Open Edit Modal ──
    async function openEditModal(id) {
        const m = document.getElementById('editModal');
        if (!m) return;
        try {
            const res = await fetch('../api/rfid.php?id=' + encodeURIComponent(id));
            const json = await res.json();
            if (!json.success || !json.data) {
                showToast('Error', json.message || 'Card not found.', 'error');
                return;
            }
            const c = json.data;
            const setVal = function (sel, v) { const el = document.querySelector(sel); if (el) el.value = v ?? ''; };
            const setText = function (sel, v) { const el = document.querySelector(sel); if (el) el.textContent = v ?? '—'; };
            setVal('#editCardId', c.id);
            setText('#editUid', c.card_uid);
            setText('#editStudentName', c.student_name || 'Unassigned');
            setText('#editStudentNumber', c.student_number || '');
            setVal('#editStatus', c.status);
            setVal('#editExpiryDate', c.expiry_date || '');
            setVal('#editNotes', c.notes || '');
            m.classList.add('active');
            document.body.style.overflow = 'hidden';
        } catch (e) {
            showToast('Error', 'Failed to load card details.', 'error');
        }
    }
    function closeEditModal() {
        const m = document.getElementById('editModal');
        if (!m) return;
        m.classList.remove('active');
        document.body.style.overflow = '';
    }

    // ── Edit submit ──
    const editForm = document.getElementById('editCardForm');
    if (editForm) {
        editForm.addEventListener('submit', async function (e) {
            e.preventDefault();
            const id = document.getElementById('editCardId')?.value;
            if (!id) return;
            const submitBtn = document.getElementById('editSubmitBtn');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
            try {
                const res = await fetch('../api/rfid.php?id=' + encodeURIComponent(id), {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        status: document.getElementById('editStatus')?.value,
                        expiry_date: document.getElementById('editExpiryDate')?.value,
                        notes: document.getElementById('editNotes')?.value
                    })
                });
                const json = await res.json();
                if (json.success) {
                    showToast('Card updated', json.message || 'Saved.', 'success');
                    setTimeout(function () { window.location.reload(); }, 800);
                } else {
                    showToast('Error', json.message || 'Update failed.', 'error');
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = '<i class="fas fa-save"></i> Save Changes';
                }
            } catch (err) {
                showToast('Error', 'Network error. Please try again.', 'error');
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fas fa-save"></i> Save Changes';
            }
        });
    }

    // ── Open View Drawer ──
    async function openViewDrawer(id) {
        const drawer = document.getElementById('rfidDrawer');
        if (!drawer) return;
        const overlay = document.getElementById('rfidDrawerOverlay');
        drawer.classList.add('active');
        if (overlay) overlay.classList.add('active');
        document.body.style.overflow = 'hidden';

        const setText = function (sel, v) { const el = drawer.querySelector(sel); if (el) el.textContent = v ?? '—'; };
        setText('[data-bind="uid"]', '…');
        setText('[data-bind="student"]', 'Loading…');

        try {
            const res = await fetch('../api/rfid.php?id=' + encodeURIComponent(id));
            const json = await res.json();
            if (!json.success || !json.data) {
                showToast('Error', json.message || 'Card not found.', 'error');
                closeViewDrawer();
                return;
            }
            const c = json.data;
            setText('[data-bind="uid"]', c.card_uid);
            setText('[data-bind="student"]', c.student_name || 'Unassigned');
            setText('[data-bind="student_number"]', c.student_number || '—');
            setText('[data-bind="course"]', c.course || '—');
            setText('[data-bind="year_level"]', c.year_level ? c.year_level + (['1','2','3','4'].includes(String(c.year_level)) ? ' Year' : '') : '—');
            setText('[data-bind="issued"]', c.issued_date ? new Date(c.issued_date).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: '2-digit' }) : '—');
            setText('[data-bind="expiry"]', c.expiry_date ? new Date(c.expiry_date).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: '2-digit' }) : '—');
            setText('[data-bind="status"]', (c.status || '').replace(/^./, function (s) { return s.toUpperCase(); }));
            const statusEl = drawer.querySelector('[data-bind="status-badge"]');
            if (statusEl) {
                statusEl.className = 'status-badge ' + (c.status || '');
            }
            setText('[data-bind="notes"]', c.notes || '—');
            // Last scans
            const list = drawer.querySelector('[data-bind="scans"]');
            if (list) {
                list.innerHTML = '<p style="color:#64748b;font-size:13px;text-align:center;padding:16px;">Loading scans…</p>';
                try {
                    const sres = await fetch('../api/rfid-scan.php?limit=10');
                    const sjson = await sres.json();
                    const scans = (sjson.success ? sjson.data : []).filter(function (s) { return s.card_uid === c.card_uid; }).slice(0, 5);
                    if (!scans.length) {
                        list.innerHTML = '<p style="color:#94a3b8;font-size:13px;text-align:center;padding:16px;">No scans recorded yet.</p>';
                    } else {
                        list.innerHTML = scans.map(function (s) {
                            const when = new Date(s.scanned_at).toLocaleString(undefined, { month: 'short', day: '2-digit', hour: '2-digit', minute: '2-digit' });
                            return '<div class="scan-row">' +
                                '<span class="status-badge ' + (s.status || '') + '">' + ((s.status || '') ? s.status[0].toUpperCase() + s.status.slice(1) : '—') + '</span>' +
                                '<span class="scan-meta">' + when + ' · ' + (s.location || 'Main Gate') + '</span>' +
                            '</div>';
                        }).join('');
                    }
                } catch (e) {
                    list.innerHTML = '<p style="color:#dc2626;font-size:13px;text-align:center;padding:16px;">Failed to load scans.</p>';
                }
            }
        } catch (err) {
            showToast('Error', 'Failed to load card details.', 'error');
            closeViewDrawer();
        }
    }
    function closeViewDrawer() {
        const drawer = document.getElementById('rfidDrawer');
        const overlay = document.getElementById('rfidDrawerOverlay');
        if (drawer) drawer.classList.remove('active');
        if (overlay) overlay.classList.remove('active');
        document.body.style.overflow = '';
    }

    // ── Close on overlay click / Escape ──
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        const am = document.getElementById('assignModal');
        const em = document.getElementById('editModal');
        const dr = document.getElementById('rfidDrawer');
        if (am && am.classList.contains('active')) closeAssignModal();
        else if (em && em.classList.contains('active')) closeEditModal();
        else if (dr && dr.classList.contains('active')) closeViewDrawer();
    });
    document.querySelectorAll('[data-close-modal]').forEach(function (el) {
        el.addEventListener('click', function () {
            const target = el.getAttribute('data-close-modal');
            if (target === 'assign') closeAssignModal();
            else if (target === 'edit') closeEditModal();
            else if (target === 'drawer') closeViewDrawer();
        });
    });

})();
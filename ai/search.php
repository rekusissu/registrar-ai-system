<?php
// ============================================================
//  AI/SEARCH.PHP
//  AI-powered search page with natural language understanding
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';

if (empty($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$page_title = 'AI Search';
$APP_ROOT = '../';
$ACTIVE_NAV = 'aisearch';

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<main class="main">
    <header class="header">
        <div class="title">
            <h1><i class="fas fa-robot" style="color: var(--primary-500);"></i> AI Search</h1>
            <p>Natural language search for students, cards, and documents</p>
        </div>
    </header>

    <!-- Search Section -->
    <div class="card" style="max-width: 800px; margin: 0 auto;">
        <div style="display: flex; gap: 12px; margin-bottom: 16px;">
            <div class="search-wrapper" style="flex: 1; position: relative;">
                <i class="fas fa-search search-icon" style="position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: #94a3b8;"></i>
                <input type="text" id="aiSearchInput" class="form-control" style="padding-left: 40px; font-size: 16px;" placeholder="Try: 'Show me at-risk students' or 'Find expired RFID cards'" />
            </div>
            <button id="aiSearchBtn" class="btn btn-primary"><i class="fas fa-search"></i> Search</button>
            <button id="aiClearBtn" class="btn btn-secondary"><i class="fas fa-times"></i> Clear</button>
        </div>

        <!-- Example Queries -->
        <div style="display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 16px;">
            <span style="font-size: 12px; color: #94a3b8; margin-right: 4px;">Try:</span>
            <button class="example-query" data-query="Show me at-risk students" style="padding: 4px 12px; border-radius: 20px; border: 1px solid #e2e8f0; background: white; font-size: 12px; color: #64748b; cursor: pointer; transition: all 0.2s;">At-risk students</button>
            <button class="example-query" data-query="Find expired RFID cards" style="padding: 4px 12px; border-radius: 20px; border: 1px solid #e2e8f0; background: white; font-size: 12px; color: #64748b; cursor: pointer; transition: all 0.2s;">Expired RFID cards</button>
            <button class="example-query" data-query="Show me all active students" style="padding: 4px 12px; border-radius: 20px; border: 1px solid #e2e8f0; background: white; font-size: 12px; color: #64748b; cursor: pointer; transition: all 0.2s;">Active students</button>
            <button class="example-query" data-query="Find students with low grades" style="padding: 4px 12px; border-radius: 20px; border: 1px solid #e2e8f0; background: white; font-size: 12px; color: #64748b; cursor: pointer; transition: all 0.2s;">Students with low grades</button>
            <button class="example-query" data-query="Show me pending document requests" style="padding: 4px 12px; border-radius: 20px; border: 1px solid #e2e8f0; background: white; font-size: 12px; color: #64748b; cursor: pointer; transition: all 0.2s;">Pending documents</button>
            <button class="example-query" data-query="Find graduating students" style="padding: 4px 12px; border-radius: 20px; border: 1px solid #e2e8f0; background: white; font-size: 12px; color: #64748b; cursor: pointer; transition: all 0.2s;">Graduating students</button>
        </div>

        <!-- AI Interpretation Banner -->
        <div id="aiInterpretation" style="display: none; padding: 12px 16px; background: #eef4ff; border: 1px solid #bfdbfe; border-radius: 10px; margin-bottom: 16px;">
            <i class="fas fa-brain" style="color: #2563eb;"></i>
            <span id="aiExplanation" style="color: #1e40af; margin-left: 8px;">AI interpreted your search as...</span>
        </div>

        <!-- Results -->
        <div id="aiResults" style="margin-top: 16px;">
            <div id="resultsPlaceholder" style="text-align: center; padding: 40px 20px; color: #94a3b8;">
                <i class="fas fa-search" style="font-size: 48px; display: block; margin-bottom: 12px; color: #e2e8f0;"></i>
                <p style="font-size: 16px; font-weight: 500;">Search for anything</p>
                <p style="font-size: 14px;">Try a natural language query above</p>
            </div>
            <div id="resultsContent" style="display: none;"></div>
        </div>
    </div>
</main>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('aiSearchInput');
    const searchBtn = document.getElementById('aiSearchBtn');
    const clearBtn = document.getElementById('aiClearBtn');
    const resultsPlaceholder = document.getElementById('resultsPlaceholder');
    const resultsContent = document.getElementById('resultsContent');
    const aiInterpretation = document.getElementById('aiInterpretation');
    const aiExplanation = document.getElementById('aiExplanation');

    // Example query buttons
    document.querySelectorAll('.example-query').forEach(function(btn) {
        btn.addEventListener('click', function() {
            searchInput.value = this.dataset.query;
            performSearch();
        });
    });

    // Search button
    searchBtn.addEventListener('click', performSearch);

    // Enter key
    searchInput.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            performSearch();
        }
    });

    // Clear button
    clearBtn.addEventListener('click', function() {
        searchInput.value = '';
        resultsPlaceholder.style.display = 'block';
        resultsContent.style.display = 'none';
        resultsContent.innerHTML = '';
        aiInterpretation.style.display = 'none';
    });

    // ─── PERFORM SEARCH ──────────────────────────────────────────
    async function performSearch() {
        const query = searchInput.value.trim();
        if (!query || query.length < 3) {
            alert('Please enter a search query (at least 3 characters).');
            return;
        }

        // Show loading
        searchBtn.disabled = true;
        searchBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Searching...';

        try {
            const response = await fetch('../api/students.php');
            const students = await response.json();

            // Simple AI search logic - expand as needed
            const queryLower = query.toLowerCase();
            let results = [];
            let interpretation = '';

            // Status detection
            if (queryLower.includes('at-risk') || queryLower.includes('risk') || queryLower.includes('struggling')) {
                results = students.data.filter(s => s.status === 'at-risk' || s.status === 'probation');
                interpretation = 'Showing at-risk and probationary students';
            } else if (queryLower.includes('expired') || queryLower.includes('expire')) {
                results = students.data.filter(s => s.status === 'expired' || s.status === 'dropped');
                interpretation = 'Showing expired/dropped students';
            } else if (queryLower.includes('active')) {
                results = students.data.filter(s => s.status === 'active');
                interpretation = 'Showing active students';
            } else if (queryLower.includes('graduating') || queryLower.includes('graduate')) {
                results = students.data.filter(s => s.status === 'graduated');
                interpretation = 'Showing graduated students';
            } else {
                // Keyword search
                results = students.data.filter(s => {
                    const searchable = (s.first_name + ' ' + s.last_name + ' ' + s.student_number + ' ' + s.course).toLowerCase();
                    return searchable.includes(queryLower);
                });
                interpretation = results.length > 0 ? 'Showing matching students' : 'No results found';
            }

            // Display results
            displayResults(results, interpretation, query);

        } catch (error) {
            console.error('Search error:', error);
            alert('Search failed. Please try again.');
        } finally {
            searchBtn.disabled = false;
            searchBtn.innerHTML = '<i class="fas fa-search"></i> Search';
        }
    }

    // ─── DISPLAY RESULTS ─────────────────────────────────────────
    function displayResults(students, interpretation, query) {
        resultsPlaceholder.style.display = 'none';
        resultsContent.style.display = 'block';

        // Show interpretation
        aiInterpretation.style.display = 'block';
        aiExplanation.textContent = '🔍 ' + interpretation + ' (Query: "' + query + '")';

        if (students.length === 0) {
            resultsContent.innerHTML = `
                <div style="text-align: center; padding: 30px 20px; color: #94a3b8;">
                    <i class="fas fa-search" style="font-size: 36px; display: block; margin-bottom: 12px; color: #e2e8f0;"></i>
                    <p style="font-size: 16px; font-weight: 500;">No results found</p>
                    <p style="font-size: 14px;">Try a different search term</p>
                </div>
            `;
            return;
        }

        let html = `
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                <span style="font-weight: 500; color: #0f172a;">Found ${students.length} student(s)</span>
            </div>
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse; font-size: 14px;">
                    <thead>
                        <tr style="border-bottom: 1px solid #e2e8f0;">
                            <th style="padding: 8px 12px; text-align: left; color: #64748b; font-weight: 600;">Student ID</th>
                            <th style="padding: 8px 12px; text-align: left; color: #64748b; font-weight: 600;">Name</th>
                            <th style="padding: 8px 12px; text-align: left; color: #64748b; font-weight: 600;">Course</th>
                            <th style="padding: 8px 12px; text-align: left; color: #64748b; font-weight: 600;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
        `;

        students.slice(0, 20).forEach(function(student) {
            html += `
                <tr style="border-bottom: 1px solid #f1f5f9;">
                    <td style="padding: 8px 12px;">${student.student_number || 'N/A'}</td>
                    <td style="padding: 8px 12px; font-weight: 500;">${student.first_name} ${student.last_name}</td>
                    <td style="padding: 8px 12px;">${student.course || 'N/A'}</td>
                    <td style="padding: 8px 12px;">
                        <span class="badge badge-${student.status === 'active' ? 'success' : (student.status === 'at-risk' ? 'danger' : 'warning')}">
                            ${student.status || 'Unknown'}
                        </span>
                    </td>
                </tr>
            `;
        });

        if (students.length > 20) {
            html += `<tr><td colspan="4" style="padding: 8px 12px; color: #64748b; text-align: center;">... and ${students.length - 20} more results</td></tr>`;
        }

        html += `
                    </tbody>
                </table>
            </div>
        `;

        resultsContent.innerHTML = html;
    }
});
</script>

<style>
.example-query:hover {
    background: #eef4ff;
    border-color: #2563eb;
    color: #2563eb;
}
</style>

<?php include '../includes/footer.php'; ?>
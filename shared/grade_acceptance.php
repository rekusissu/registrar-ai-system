<?php
// ============================================================
//  SHARED/GRADE_ACCEPTANCE.PHP
//  The roster read behind a section's acceptance gate.
//
//  Split out of api/grade-acceptance.php because api/grade-chaser-ai.php
//  needs the identical roster to describe the same findings the gate
//  reported. Two roster builders could disagree about who is on a section,
//  and the AI note would then explain problems the gate never saw.
//
//  Grouping comes from students.section, because the section code is what
//  the Masterlist assigned (shared/section_code.php). A student with no
//  section is in no section: there is no block whose acceptance covers
//  them, so they are excluded rather than guessed at.
// ============================================================

if (defined('GRADE_ACCEPTANCE_LOADED')) { return; }
define('GRADE_ACCEPTANCE_LOADED', true);

/**
 * How a section is named in prose, when the program matters.
 *
 * "Section 11001" is ambiguous on a board holding BSIT 11001 and BSCS 11001,
 * so the program goes in front of it wherever a human reads the name.
 *
 * @param  string $program Program as stored on students.course.
 * @param  string $section Section code.
 * @return string
 */
function ah_section_label(string $program, string $section): string
{
    return $program !== '' ? "$program section $section" : "section $section";
}

/**
 * The section's roster for one term, in the shape termAudit() expects.
 *
 * Grouping comes from students.section, because the section code is what the
 * Masterlist assigned (shared/section_code.php). A student with no section is
 * in no section: there is no block whose acceptance covers them.
 *
 * THE PROGRAM IS PART OF THE KEY, and this is not belt-and-braces. A section
 * code is scoped by program + year level + term, so BSIT 11001 and BSCS 11001
 * are two different sections sharing five characters. Filtering on the code
 * alone returned five students for one section and would have accepted two
 * cohorts with one click - and the accept UPDATE, which also filters on the
 * code, would have stamped every term row in both.
 *
 * @param  mixed  $db
 * @param  string $sy      School year.
 * @param  string $sem     Semester.
 * @param  string $section Section code, e.g. "11001".
 * @param  string $program Program as stored on students.course. Empty means
 *                         "no program filter", which is only safe on a database
 *                         where the code is unique across programs.
 * @return array           One row per rostered student.
 */
function grade_acceptance_roster($db, string $sy, string $sem, string $section, string $program = ''): array
{
    if ($section === '') {
        return [];
    }

    $where  = 's.section = ? AND s.status != \'archived\'';
    $params = [$sy, $sem, $section];
    if ($program !== '') {
        $where .= ' AND s.course = ?';
        $params[] = $program;
    }

    $rows = $db->fetchAll(
        "SELECT s.id, s.student_number, s.first_name, s.last_name, ah.id AS record_id
           FROM students s
           LEFT JOIN academic_history ah
             ON ah.student_id = s.id AND ah.school_year = ? AND ah.semester = ?
          WHERE $where
          ORDER BY s.last_name, s.first_name",
        $params
    );

    $roster = [];
    foreach ($rows as $r) {
        $rid      = (int) ($r['record_id'] ?? 0);
        $subjects = [];
        $stored   = null;
        if ($rid) {
            foreach ($db->fetchAll(
                "SELECT units, final_rating, grade_status, subject, subject_code
                   FROM academic_grades
                  WHERE academic_history_id = ? ORDER BY id ASC",
                [$rid]
            ) as $g) {
                // The subject NAME travels with the finding. Without it
                // termAudit() falls back to the literal string "subject", so a
                // blocking finding read "Nicole Buenaventura — subject,
                // subject" and named nothing a registrar could chase. The code
                // is the fallback label for a row with no name, because a bare
                // code is still actionable and "subject" is not.
                $label = trim((string) ($g['subject'] ?? ''));
                if ($label === '') {
                    $label = trim((string) ($g['subject_code'] ?? ''));
                }
                $subjects[] = [
                    'units'        => (float) $g['units'],
                    'final_rating' => $g['final_rating'],
                    'grade_status' => (string) $g['grade_status'],
                    'subject'      => $label !== '' ? $label : 'an unnamed subject',
                ];
            }
            // The STORED gwa is what the audit compares the computed figure
            // against. Passing the computed one would make gwa_mismatch
            // unfireable - that check exists to catch a record whose stored
            // figure was written by something other than this computation.
            $stored = $db->fetchColumn(
                "SELECT gwa FROM academic_history WHERE id = ?",
                [$rid]
            );
        }
        $roster[] = [
            'id'        => (int) $r['id'],
            'name'      => trim($r['first_name'] . ' ' . $r['last_name']),
            'number'    => (string) ($r['student_number'] ?? ''),
            'record_id' => $rid,
            'gwa'       => $stored,
            'subjects'  => $subjects,
        ];
    }

    return $roster;
}

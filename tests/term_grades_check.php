<?php
// Arithmetic and rules for shared/term_grades.php.
//
//   php tests/term_grades_check.php
//
// The GWA is now computed rather than typed, which means every consumer -
// the status rules, the TOR, this page - trusts one function. A rounding
// error here is not a display fault: it moves a student across the 3.00
// line that status_evidence.php reads. So the weighting is checked
// against hand-worked numbers rather than against itself.

require_once __DIR__ . '/../shared/term_grades.php';

$fail = 0;
$ok   = 0;
// $detail is optional and printed only on failure or when given, because a
// passing line that also explains itself is worth more than a bare "ok" when
// the assertion is about counts - "3 scoped, 5 unscoped" says what happened,
// "ok" says nothing did.
function check(string $label, $cond, string $detail = ''): void
{
    global $fail, $ok;
    if ($cond) {
        $ok++;
        printf("  ok    %s%s\n", $label, $detail !== '' ? "  ($detail)" : '');
    } else {
        $fail++;
        printf("  FAIL  %s%s\n", $label, $detail !== '' ? "  ($detail)" : '');
    }
}

echo "term_grades - computation and audit\n";

// ── termGwa ──────────────────────────────────────────────────
// Hand-worked: (3x1.50 + 3x2.50 + 2x3.00) / 8 = 18/8 = 2.25
$gwa = termGwa([
    ['units' => 3, 'final_rating' => 1.5],
    ['units' => 3, 'final_rating' => 2.5],
    ['units' => 2, 'final_rating' => 3.0],
]);
check('weights by units', $gwa === 2.25);

// A subject with no rating is missing data, not a zero. Averaging it in
// would drag every GWA down, which is the most damaging way this function
// could be wrong.
check('a subject with no rating is excluded, not zeroed',
    termGwa([['units' => 3, 'final_rating' => 1.5], ['units' => 3, 'final_rating' => null]]) === 1.5);

// A subject with no units cannot shift an average.
check('a subject with no units is excluded',
    termGwa([['units' => 3, 'final_rating' => 1.5], ['units' => 0, 'final_rating' => 5.0]]) === 1.5);

check('no usable data yields null, never 0.00',
    termGwa([['units' => 3, 'final_rating' => null]]) === null);
check('an empty list yields null', termGwa([]) === null);
check('a 5.00 is a legal rating, not a missing one',
    termGwa([['units' => 3, 'final_rating' => 5.0]]) === 5.0);

// ── careerGwa ────────────────────────────────────────────────
check('career GWA weights across terms',
    careerGwa([
        ['subjects' => [['units' => 3, 'final_rating' => 1.5]]],
        ['subjects' => [['units' => 3, 'final_rating' => 2.5]]],
    ]) === 2.0);
check('career GWA over nothing is null', careerGwa([]) === null);

// ── Rating validation ────────────────────────────────────────
check('1.00 is valid',    termRatingValid(1.0) === true);
check('5.00 is valid',    termRatingValid(5.0) === true);
check('0.00 is not valid', termRatingValid(0.0) === false);
check('5.01 is not valid', termRatingValid(5.01) === false);
check('a letter grade is not a number', termRatingValid('A') === false);
check('empty is not valid', termRatingValid('') === false);
check('null is not valid',  termRatingValid(null) === false);

// ── Audit ────────────────────────────────────────────────────
$roster = [
    [
        'name' => 'Dela Cruz', 'number' => '2024-0117', 'gwa' => 1.75,
        'subjects' => [
            ['subject' => 'IT 101', 'units' => 3, 'final_rating' => 1.5, 'grade_status' => 'passed'],
            ['subject' => 'IT 102', 'units' => 3, 'final_rating' => 2.0, 'grade_status' => 'passed'],
        ],
    ],
    [
        'name' => 'Reyes, J.', 'number' => '2024-0180', 'gwa' => null,
        'subjects' => [
            ['subject' => 'IT 101', 'units' => 3, 'final_rating' => 1.5, 'grade_status' => 'passed'],
            ['subject' => 'IT 102', 'units' => 3, 'final_rating' => null, 'grade_status' => 'passed'],
        ],
    ],
    ['name' => 'Lim, A.', 'number' => '2024-0155', 'gwa' => null, 'subjects' => []],
    [
        'name' => 'Santos, M.', 'number' => '2024-0166', 'gwa' => 3.40,
        'subjects' => [['subject' => 'PE', 'units' => 2, 'final_rating' => 3.4, 'grade_status' => 'passed']],
    ],
];

$a     = termAudit('2026-2027', '1st', $roster);
$codes = array_column($a['blocking'], 'code');
$adv   = array_column($a['advisory'], 'code');

check('a complete record is not blocking', !in_array('gwa_mismatch', $codes, true));
check('a missing rating blocks', in_array('missing_rating', $codes, true));
check('passed with no rating blocks separately', in_array('passed_without_rating', $codes, true));
check('an empty subject list blocks', in_array('no_subjects', $codes, true));
check('a GWA at or above 3.00 is advisory, not blocking', in_array('gwa_at_risk', $adv, true));
check('a GWA over 3.00 is not itself a block', !in_array('gwa_at_risk', $codes, true));
check('audit counts the roster', $a['stats']['students'] === 4);
check('audit counts the students with subjects', $a['stats']['with_grades'] === 3);
check('audit sums units across the term', $a['stats']['total_units'] > 0);

// A stored GWA that disagrees with the subjects must block: the TOR prints
// one figure and the status rules read the other.
$mismatch = termAudit('2026-2027', '1st', [[
    'name' => 'Wrong', 'number' => 'X', 'gwa' => 1.00,
    'subjects' => [['subject' => 'A', 'units' => 3, 'final_rating' => 2.50, 'grade_status' => 'passed']],
]]);
check('a stored GWA that disagrees blocks', in_array('gwa_mismatch', array_column($mismatch['blocking'], 'code'), true));

$outOfScale = termAudit('2026-2027', '1st', [[
    'name' => 'Bad', 'number' => 'Y', 'gwa' => null,
    'subjects' => [['subject' => 'A', 'units' => 3, 'final_rating' => 9.0, 'grade_status' => 'passed']],
]]);
check('a rating outside the scale blocks', in_array('rating_out_of_scale', array_column($outOfScale['blocking'], 'code'), true));

$empty = termAudit('2026-2027', '1st', []);
check('an empty term says so', str_contains($empty['summary'], 'No grades'));
check('an empty term raises nothing', $empty['blocking'] === []);

// Every finding must name an action. A finding with no next step is just an
// accusation.
$all = array_merge($a['blocking'], $a['advisory']);
check('every finding says what to do about it',
    array_filter($all, fn($f) => trim((string) ($f['action'] ?? '')) === '') === []);
check('every finding states the fact',
    array_filter($all, fn($f) => trim((string) ($f['detail'] ?? '')) === '') === []);

// ── Section acceptance ────────────────────────────────────────
//
// The gate is what a registrar clicks Accept against, so the things it must
// refuse are checked here rather than only in the UI.
//
// The STORED gwa in each fixture is the units-weighted figure the subjects
// compute to, because termAudit blocks a stored figure that disagrees with
// the ratings - which is correct behaviour, and would otherwise mask every
// other case below. Santos: (3x1.50 + 3x2.00)/6 = 1.75. Reyes: (3x1.80 +
// 2x2.20)/5 = 1.96.
$clean = [
    ['name' => 'Santos', 'number' => '1', 'gwa' => 1.75, 'subjects' => [
        ['units' => 3, 'final_rating' => 1.5],
        ['units' => 3, 'final_rating' => 2.0],
    ]],
    ['name' => 'Reyes', 'number' => '2', 'gwa' => 1.96, 'subjects' => [
        ['units' => 3, 'final_rating' => 1.8],
        ['units' => 2, 'final_rating' => 2.2],
    ]],
];

$ready = sectionAcceptance('2026-2027', '1st', $clean);
check('a fully graded section can be accepted', $ready['can_accept'] === true);
check('a ready section reads as ready', $ready['state'] === 'ready');

// One blank rating in a section of two blocks the WHOLE section. This is the
// rule the feature exists for: a partially graded term cannot be accepted.
$oneShort = $clean;
$oneShort[1]['subjects'][0]['final_rating'] = null;
$blocked = sectionAcceptance('2026-2027', '1st', $oneShort);
check('one student missing a grade blocks the whole section',
    $blocked['can_accept'] === false);
check('a blocked section reads as waitlist', $blocked['state'] === 'waiting');
check('the finding names the student who is short',
    strpos(json_encode($blocked['blocking']), 'Reyes') !== false);

// An advisory finding - a GWA at 3.00 - must NOT block. A term can be
// complete and academically poor, and refusing to accept it would be the
// system making a decision about a student that is not its own.
$poor = [
    ['name' => 'Santos', 'number' => '1', 'gwa' => 3.20, 'subjects' => [
        ['units' => 3, 'final_rating' => 3.2],
    ]],
    $clean[1],
];
$poorGate = sectionAcceptance('2026-2027', '1st', $poor);
check('a GWA at or above 3.00 does not block acceptance',
    $poorGate['can_accept'] === true);
check('but it is still reported as advisory', $poorGate['advisory'] !== []);

$empty = sectionAcceptance('2026-2027', '1st', []);
check('a section with no students cannot be accepted', $empty['can_accept'] === false);
check('a section with no students is empty, not waiting', $empty['state'] === 'empty');

// A student with no subjects at all is a different fault from a student with
// a subject and no rating, and termAudit already says so. The gate must not
// flatten the two into one "waiting" that sends someone after the wrong thing.
$nothingEntered = [['name' => 'Cruz', 'number' => '3', 'gwa' => null, 'subjects' => []]];
$nothingGate = sectionAcceptance('2026-2027', '1st', $nothingEntered);
check('a student with nothing entered blocks', $nothingGate['can_accept'] === false);
check('"nothing entered" is distinguished from "a rating is missing"',
    $nothingGate['blocking'][0]['code'] === 'no_subjects');

// Two programs may hold the SAME section code - it is scoped by program + year
// + term, not globally. The gate is handed ONE section's roster, so the
// property worth pinning is that it never widens its own scope: a clean BSIT
// section stays acceptable, and adding a blocked BSCS student to the same
// array is what makes it wait. What must never happen is one call silently
// covering both, which is why grade_acceptance_roster() takes a program and
// the accept UPDATE filters on it.
$bsit = [['name' => 'Santos', 'number' => '1', 'gwa' => 1.75, 'subjects' => [
    ['units' => 3, 'final_rating' => 1.5],
    ['units' => 3, 'final_rating' => 2.0],
]]];
check('a clean section of one program is unaffected by the code being shared',
    sectionAcceptance('2026-2027', '1st', $bsit)['can_accept'] === true);

// ── A section is program + code, not code ────────────────────
//
// A section code is scoped by program + year level + term, so BSIT 11001 and
// BSCS 11001 are two different sections that share five characters. Everything
// downstream - the board row, the gate, the accept UPDATE - is keyed on both.
// If any one of them drops the program, one click accepts two cohorts and the
// damage is invisible until someone reads the other program's record.
require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/grade_acceptance.php';

$db = Database::getInstance();
$sections = $db->fetchAll(
    "SELECT DISTINCT s.course, s.section FROM students s
      WHERE s.section IS NOT NULL AND s.section <> ''
        AND s.email LIKE '%@seed.sections.test'
      ORDER BY s.course, s.section"
);

$collision = 0;
$shared    = [];
$codes = [];
foreach ($sections as $s) {
    $codes[$s['section']][] = $s['course'];
}
foreach ($codes as $code => $programs) {
    if (count($programs) > 1) {
        $collision++;
        $shared[] = $code;
        printf("  ..   code %s is held by %d programs (%s)\n",
            $code, count($programs), implode(', ', $programs));
    }
}

foreach ($sections as $s) {
    $scoped   = grade_acceptance_roster($db, '2026-2027', '1st', $s['section'], $s['course']);
    $unscoped = grade_acceptance_roster($db, '2026-2027', '1st', $s['section']);
    $label    = $s['course'] . ' ' . $s['section'];

    // A code held by ONE program cannot show the difference: both queries
    // return the same students whether or not the program is passed. Asserting
    // "scoped is fewer" there would fail on correct code, so the check is
    // "scoped is never MORE" - the property that actually matters, since
    // over-counting is what would accept another cohort. The narrowing itself
    // is proved on the shared codes below, where it is observable.
    check("roster for {$label} never over-counts without its program",
        count($scoped) <= count($unscoped),
        count($scoped) . ' scoped, ' . count($unscoped) . ' unscoped'
        . (in_array($s['section'], $shared, true) ? ' (shared code)' : ''));
}

// The real proof, on a code two programs actually hold: scoping must narrow it
// to one cohort, and the cohorts must add up to the unscoped total. Without
// this the check above would pass on a roster function that ignored the
// program entirely.
foreach ($shared as $code) {
    $byProgram = [];
    foreach ($sections as $s) {
        // Loose on purpose. The code came out of array_keys() on a map built
        // from the same column, so === should hold - and it did not, which
        // meant this loop silently matched nothing and the check below passed
        // or failed on an empty set rather than on the roster. A cast is the
        // honest fix: both sides are section codes, and a mismatch would show
        // up as a wrong count rather than as an empty loop.
        if ((string) $s['section'] !== (string) $code) {
            continue;
        }
        $byProgram[$s['course']] = count(grade_acceptance_roster($db, '2026-2027', '1st', $code, $s['course']));
    }
    $all = count(grade_acceptance_roster($db, '2026-2027', '1st', $code));
    $sum = array_sum($byProgram);
    $desc = [];
    foreach ($byProgram as $p => $n) {
        $desc[] = "$n in $p";
    }

    check("code {$code} narrows to one program at a time",
        count($byProgram) > 1 && $sum === $all,
        count($byProgram) . ' program(s): ' . implode(' + ', $desc)
        . " = $sum, the same $all found with the program ignored");
}

// If nothing on this database shares a code, the scoping cannot be told apart
// from a no-op here. Say so rather than let a green line imply coverage it
// does not have - the same trap tests/clear_e2e_residue.php documents about a
// marker check that matches zero rows.
if ($collision === 0) {
    printf("  ..   no code is shared on this database, so scoping is not exercised here\n");
    printf("       (seed_section_acceptance.php plants BSCS 11001 alongside BSIT 11001)\n");
}

printf("\n  %d passed, %d failed\n", $ok + $fail, $fail);
exit($fail === 0 ? 0 : 1);

// ── Labels and ordering ──────────────────────────────────────
check('term label reads naturally', termLabel('2026-2027', '1st') === '1st · 2026-2027');
check('a missing year still labels', termLabel('', '2nd') === '2nd');
check('an empty term does not look real', termLabel('', '') === 'Unspecified term');

check('within a year, 1st precedes 2nd',
    termSortKey('2026-2027', '1st') < termSortKey('2026-2027', '2nd'));
check('an "SY 2026-2027" prefix still sorts',
    termSortKey('SY 2026-2027', '1st') === termSortKey('2026-2027', '1st'));
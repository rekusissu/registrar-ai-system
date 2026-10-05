<?php
// Confirms the Prepare Full List control is fully removed from the masterlist
// page and that the hand-off it sat in front of is intact.
//
// Reads the source rather than the served page: section_e2e.php already drives
// the real thing over HTTP, but it needs seeded students and a live database,
// so this is the check that runs anywhere.
$src = (string) file_get_contents(__DIR__ . '/../registrar/masterlist.php');

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $note = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  OK   $label\n"; }
    else    { $fail++; echo "  FAIL $label" . ($note !== '' ? "  <- $note" : '') . "\n"; }
}

// The comment that records the removal is stripped before matching, so the
// checks below cannot be satisfied by the very words describing the removal.
$code = preg_replace('#^\s*//.*$#m', '', $src);
$code = preg_replace('#/\*.*?\*/#s', '', $code);

echo "Prepare Full List is gone\n";
check('no btnPrepareList id',      !str_contains($code, 'btnPrepareList'));
check('no "Prepare Full List" label', !str_contains($code, 'Prepare Full List'));
check('no listener binding it',    !str_contains($code, 'getElementById(\'btnPrepareList\')'));
check('no navigation to ?prepared=1', !str_contains($code, 'prepared=1'));
check('no $prepared variable',     !str_contains($code, '$prepared'));
check('no "Full list prepared" banner', !str_contains($code, 'Full list prepared'));
check('no leftover fa-list-check icon', !str_contains($code, 'fa-list-check'));

echo "\nThe hand-off it sat in front of survived\n";
check('Send List is still there',  str_contains($code, 'sendList()'));
check('Export is still there',     str_contains($code, 'exportBtn'));
check('Export menu still offers CSV/Excel/PDF',
      str_contains($code, 'exportCSV()') && str_contains($code, 'exportExcel()')
      && str_contains($code, 'window.print()'));
check('the status line still reports unassigned students',
      str_contains($code, 'id="sectionStatus"'));
check('the search box still filters on input',
      str_contains($code, 'id="masterlistSearch"'));

echo "\nOrder: Send List before Export\n";
check('send precedes export',
    preg_match('/sendList\(\).*?id="exportBtn"/s', $code) === 1);

echo "\n" . ($fail === 0 ? "OK - $pass check(s) passed.\n"
                        : "FAILED - $fail of " . ($pass + $fail) . " check(s).\n");
exit($fail === 0 ? 0 : 1);
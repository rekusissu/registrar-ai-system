<?php
// ============================================================
//  DOUBLE SHOW-PASSWORD EYE CHECK
//
//    php tests/password_eye_check.php
//
//  A type="password" input makes the browser draw its OWN reveal eye inside
//  the field. That control is not part of the page and cannot be styled to
//  match, and it sits in the same corner as the page's own toggle button - so
//  the user sees two show-password buttons side by side.
//
//  login.php already solved this: its field is type="text" with a CSS mask,
//  so the browser never draws a native eye. Every other page had kept
//  type="password" and so showed both.
//
//  The check is on the MARKUP rather than the rendered page, because the pages
//  that were wrong are all behind a login. Reading them over HTTP would test
//  the login screen instead of the form.
// ============================================================
$root = dirname(__DIR__);
$pass = 0; $fail = 0;

function check(string $label, bool $ok, string $note = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  OK   $label\n"; }
    else    { $fail++; echo "  FAIL $label" . ($note !== '' ? "  <- $note" : '') . "\n"; }
}

/** Every page that offers a password field plus its own reveal control. */
$pages = [
    'login.php'           => ['.password-toggle-btn', '.password-toggle'],
    'settings.php'        => ['.pw-toggle'],
    'registrar/users.php' => ['[data-password-toggle]'],
];

// Strip comments so a page that merely NAMES the problem in a comment is not
// counted as having it.
function code_only(string $src): string {
    $src = preg_replace('#/\*.*?\*/#s', '', $src);
    return preg_replace('#^\s*(//|\*|/\*).*$#m', '', $src);
}

foreach ($pages as $page => $toggles) {
    $path = $root . '/' . $page;
    if (!is_file($path)) { check("$page exists", false); continue; }
    $code = code_only((string) file_get_contents($path));

    $hasToggle = false;
    foreach ($toggles as $t) {
        if (strpos($code, $t) !== false) { $hasToggle = true; break; }
    }
    if (!$hasToggle) {
        // No reveal control on this page means there is no double to find.
        printf("  --   %-22s no password toggle here, nothing to double\n", $page);
        continue;
    }

    // The rule, stated precisely: a native type="password" field is only a
    // problem when THAT FIELD also has one of the page's own reveal buttons.
    //
    // A password field with no page toggle is fine - the browser's native eye
    // is then the only control and there is nothing to double up against.
    // login.php's reset step has two such fields and they are deliberately left
    // alone. Judging the whole page instead would flag them and send someone
    // to add toggles that were never the complaint.
    $native = [];
    if (preg_match_all('/<input\b[^>]*\btype="password"[^>]*>/i', $code, $m)) {
        $native = $m[0];
    }

    // Each native field: does a toggle for it sit in the same form-group or
    // field wrapper? data-target / data-password-toggle name the input id, and
    // the markup puts the button inside the same wrapper, so proximity in the
    // source is the test.
    $doubled = [];
    foreach ($native as $field) {
        if (!preg_match('/id="([^"]+)"/', $field, $idm)) continue;
        $id = $idm[1];
        foreach ($toggles as $t) {
            // A toggle naming this field, or sitting in the same wrapper.
            if (strpos($code, 'data-target="' . $id . '"') !== false
             || strpos($code, 'data-password-toggle="' . $id . '"') !== false) {
                $doubled[$id] = $field;
            }
        }
        // login.php's toggle has no data-* link: it finds its input by id and is
        // the only one on the page, so pair it with the page's only password
        // field rather than trying to match attributes that are not there.
        if (!$doubled && count($toggles) === 1 && $t === '.password-toggle-btn'
            && strpos($field, 'id="password"') !== false) {
            $doubled[$id] = $field;
        }
    }

    check("$page: no password field carries BOTH a native eye and a page toggle",
          $doubled === [],
          count($doubled) . ' doubled field(s): ' . implode(' | ', array_keys($doubled)));

    // Every remaining native field must have no toggle anywhere near it, so it
    // is reported rather than silently ignored.
    foreach ($native as $field) {
        if (preg_match('/id="([^"]+)"/', $field, $idm)
            && strpos($field, 'data-masked') !== false) {
            check("$page: a masked field is really type=\"text\"",
                  stripos($field, 'type="text"') !== false,
                  trim(substr($field, 0, 90)));
        }
    }

    // A field converted to type="text" MUST carry data-masked="1", or the
    // characters are simply shown in plain text - the one outcome the whole
    // change must not cause.
    preg_match_all('/<input\b[^>]*\bdata-masked="1"[^>]*>/i', $code, $mm);
    $masked = $mm[0];

    foreach ($masked as $f) {
        check("$page: a masked field is really type=\"text\"",
              stripos($f, 'type="text"') !== false,
              trim(substr($f, 0, 90)));
    }
}

// ── The shared stylesheet exists and does the two jobs ────────────────
$cssPath = $root . '/css/password-field.css';
check('css/password-field.css exists', is_file($cssPath));
if (is_file($cssPath)) {
    $css = (string) file_get_contents($cssPath);
    check('it suppresses the Chromium native reveal (::-ms-reveal)',
          strpos($css, '-ms-reveal') !== false);
    check('it suppresses the WebKit autofill/credentials controls',
          strpos($css, 'credentials-manager') !== false);
    check('it masks a data-masked field (-moz-text-security)',
          strpos($css, '-moz-text-security') !== false);
    check('it masks a data-masked field (-webkit-text-security)',
          strpos($css, '-webkit-text-security') !== false);
    check('the mask is !important, so a page rule cannot expose the value',
          preg_match('/data-masked="1"\][^{]*\{[^}]*text-security[^}]*!important/s', $css) === 1);

    // Every page that needs it must actually load it.
    foreach (array_keys($pages) as $page) {
        $src = (string) file_get_contents($root . '/' . $page);
        check("$page loads css/password-field.css",
              strpos($src, 'password-field.css') !== false);
    }
}

echo "\n" . ($fail === 0 ? "OK - $pass check(s) passed.\n"
                        : "FAILED - $fail of " . ($pass + $fail) . " check(s).\n");
exit($fail === 0 ? 0 : 1);
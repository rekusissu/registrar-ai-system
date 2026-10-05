// Reproduces the two toggle handlers from js/auth.js and settings.php in
// isolation, with no browser. If these disagree, the disagreement is the bug
// and does not need a page to prove it.
'use strict';

// A minimal stand-in for DOMTokenList, including toggle()'s two-argument
// form. A plain Set is not enough: Set has no toggle, and the handlers call
// classList.toggle(name, force), where the force argument is exactly what the
// bug is about.
function makeTokenList(cls) {
    const set = new Set(cls.split(' ').filter(Boolean));
    return {
        set,
        contains: (n) => set.has(n),
        add: (n) => set.add(n),
        remove: (n) => set.delete(n),
        toggle(n, force) {
            const on = force === undefined ? !set.has(n) : !!force;
            if (on) set.add(n); else set.delete(n);
            return on;
        },
    };
}

function makeIcon(cls) {
    const classList = makeTokenList(cls);
    return { classList };
}

// js/auth.js
function authHandler(icon, revealed) {
    icon.classList.toggle('fa-eye', !revealed);
    icon.classList.toggle('fa-eye-slash', revealed);
}

// settings.php
function settingsHandler(icon, show) {
    icon.classList.toggle('fa-eye', !show);
    icon.classList.toggle('fa-eye-slash', show);
}

const render = (icon) => Array.from(icon.classList.set).join(' ');

let bad = 0;
function check(label, got, want) {
    if (got === want) { console.log('  ok    ' + label + '  -> "' + got + '"'); }
    else { bad++; console.log('  FAIL  ' + label + '\n          got  "' + got + '"\n          want "' + want + '"'); }
}

console.log('js/auth.js — a click with fa-eye present (field just revealed)');
let i = makeIcon('fa-solid fa-eye');
authHandler(i, true);
check('carries exactly one eye class', render(i), 'fa-solid fa-eye-slash');
check('and no bare fa-eye left over', i.classList.contains('fa-eye'), false);

console.log('\njs/auth.js — a click with fa-eye-slash present (field re-hidden)');
i = makeIcon('fa-solid fa-eye-slash');
authHandler(i, false);
check('carries exactly one eye class', render(i), 'fa-solid fa-eye');

console.log('\nsettings.php — a click showing the field');
i = makeIcon('fa-solid fa-eye');
settingsHandler(i, true);
check('carries exactly one eye class', render(i), 'fa-solid fa-eye-slash');

console.log('\nsettings.php — a click hiding the field');
i = makeIcon('fa-solid fa-eye-slash');
settingsHandler(i, false);
check('carries exactly one eye class', render(i), 'fa-solid fa-eye');

console.log(bad
    ? `\nFAILED - ${bad} case(s) produce an <i> with both eye classes.`
    : '\nOK - every transition leaves exactly one eye class.');
process.exit(bad ? 1 : 0);

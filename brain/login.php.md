---
tags: [page]
---

# 🔐 `login.php`

The login screen — root of the app. Dev credentials pre-filled.

## Flow (Phase 5)

1. User enters **ID number / username** (staff: `users.username`; student: `students.student_number`) + password → `POST` to [[shared/auth_actions.php]] (`action=login`)
2. Correct password → **OTP step** (6-digit code, emailed or shown on screen in dev) → `action=verify_otp`
3. Verified → role-based redirect: student → `student/dashboard.php`, else `dashboard.php`
4. **Email field appears only in the forgot-password flow** (`action=forgot` → reset OTP → `action=reset_password`)
5. 5 failed attempts → 10-minute lockout message
6. Timeout/access issues redirect back with `?timeout=1` (via `app_url()`, works from any depth) / `?error=access_denied`

See [[Auth System]] for the full session/OTP/lockout machinery.

## This page renders; it does not authenticate

There is **no PHP branch here that grants a session**. Every submit posts
to `shared/auth_actions.php` through `post()`. A reviewer reading this file
should not conclude that a credential check happens in front of the user.

Consequences worth preserving:

- A correct password answers `step: 'otp'`, not `step: 'complete'`. Only
  `complete` skips the code screen, and only the loopback-gated local
  bypass produces it.
- `step: 'complete'` is the **only** shape allowed to redirect. A success
  response with an unrecognised step is refused rather than followed,
  because following it would drop the user into a session the second
  factor was meant to guard.

## The instrument strip is gone

There used to be a row of chips below the card reporting transport, database
reachability, PHP build and a Manila clock — all real, all observed by PHP.
It was removed on request.

Two things went with it, and both were consequences of removing rather than
just the four chips:

- **The page no longer opens a database connection.** The connect existed
  only to fill that one chip. This is a genuine win: the login page was
  making a DB round trip on every view for a decorative readout.
- **The "database is not reachable, please contact IT" notice is gone too.**
  It shared the connect, and it lived in the same place. Worth knowing what
  is lost: during an outage the server returns the ordinary
  "Invalid ID / username or password", which is indistinguishable from a
  wrong password. That sends staff to reset passwords they did not need to
  reset. If that ever bites, the fix is a cheap `SELECT 1` behind a
  try/catch, not the old strip.

`?timeout=1` and its session-expired notice are unaffected — that is
separate markup.

## Form state carries across screens

`showForm()` clears the previous screen's error. Without that, failing a
sign-in and then clicking "Forgot password" left "Invalid ID / username or
password." sitting above a form asking for an email — true, but answering a
question nobody asked any more, which reads as though the reset form is
broken.

## Design

Two panels on a light ground, and the container is what carries the
technical character.

The concept is still the system's own **RFID card read** — Bestlink
students already carry cards — so the card is drawn as a card: **crop marks
at all four corners**, a **readout bar** across the top, and a **fold accent**
down the seam. On a dark ground this idea leans on glow; on
white it has to be *drawn*, and line work does it with no colour at all.

On a light ground the accent is **royal blue**, taken from the crest's own
blue, not the cyan of the earlier dark version — cyan cannot carry 12px text
on white. `tools/_login_shot.js` asserts the ground and card are actually
light on computed background, so "make it white" is evidence rather than a
screenshot somebody has to trust.

### The corners are identical, and the ink is not

The four crop marks share one geometry: 10px inset, drawing the two edges
that actually meet at their corner. A chamfer used to sit on the top right
only, which made that corner not match the top left and read as a rendering
fault rather than as a detail.

The **ink differs for exactly one mark**, and it is deliberate. The card is
two-tone — paper on the readout bar and the form panel, royal blue on the
brand panel — so three corners land on paper and the bottom-left lands on
blue, where a paper hairline drops to about 2.7:1 and disappears. Rather than
drag the other three down to muddy to match it, that one is cut in the
panel's own ink. The probe measures each mark against its own backdrop and
requires 3:1.

### The brand panel

Royal blue, with a **fading tile field** behind it — 88px blocks from a conic
gradient plus a 44px hairline lattice — masked by a radial so it is strongest
in the upper left where there is no text and gone by the lower right. The
mask is on a pseudo-element, not on the panel itself: `mask-image` on the
element would clip the panel's own edges, and the rounded corner is half of
why the card reads as a card.

It carries the module list and nothing else. A description of the office sat
here for a while and was better copy — but it made this panel about 130px
taller than a sign-in form, which stretched the card to roughly 660px and
left the form adrift in the middle of a very tall box. Removed; the probe
asserts both panels are the same height so the card cannot silently grow
again.

The form panel is **centred vertically**, so any shortfall splits above and
below rather than pooling into one void under the Terms link.

### Two things that were in the DOM and not on screen

All four crop marks were present, correctly sized, and completely invisible:
the readout bar covered the two top marks and the panel backgrounds covered
the two bottom ones, because all of them are declared after the marks. A DOM
assertion reported them fine the whole time. Only a zoomed screenshot showed
that none of them existed.

They moved outside the card at one point, which fixed the occlusion and read
as a mismatch against the rest of the card — a card that represents itself
should carry its marks like a printed sheet carries them. They are inside
again, with an explicit stacking level (`z-index` 1 panels, 2 readout, 4
marks).

### Three measurement bugs that hid things

- `document.elementFromPoint` skips `pointer-events: none`, and every one
  of these is decorative — so the occlusion check reported "covered by body"
  for a mark that was plainly visible, and the reduced-motion check went
  quiet while real animations ran. Both probes now enable `pointer-events`
  for the check and restore it after.
- Counting lines by dividing an element's height by its line-height counts
  `padding-top` as a line box, reporting a correct two-line footer as
  three. Line counts use a `Range` over the text and count distinct rect
  tops.
- Comparing the four marks by their raw `borderWidth` quadruples never
  matches, because a top-left mark carries top+left and a top-right carries
  top+right — and those are *supposed* to differ. The symmetry check
  compares geometry (inset, and which edges are drawn) and checks ink
  separately.
- Asserting `animationName` proved the CSS was **declared**, not that
  anything moved. That mattered while the page was animated; now the page
  is inert, so the check is inverted — every element's computed transform,
  opacity, background-position, animation-name and transition-property are
  sampled 1.1s apart and must all be identical. That also catches a Web
  Animations call or a script-driven change, which reading `animationName`
  never would.

### A filled ID field greys out — and it is not this stylesheet

Measured, because every guess about which declaration was responsible was
wrong. `tools/_login_field.js` types into the field and reports every
colour-affecting property before and after: the pair is **identical**. A
value typed by hand paints `rgb(16, 25, 43)` on white at 17.6:1, and the
pixels in the field come out near-black.

So the grey is painted by the browser. On a sign-in page the one thing that
paints over a field like that is **Chrome's autofill** filling in a saved
credential — and its background is not reachable from an author
`background` rule, which is why it survived every earlier pass.

The fix is the canonical one, and it has two parts, both required:

1. A 1000px inset `box-shadow` in the field's own colour, painted over
   Chrome's highlight.
2. `transition: background-color 600000s` — a workaround for a long-standing
   Chrome bug where the highlight is never re-evaluated after load. It looks
   like nonsense and does nothing visually; it gives the browser something to
   transition so it stops reasserting the highlight.

`:focus` is re-stated separately, because part 1 replaces `box-shadow`
wholesale and without that an autofilled field would lose its focus ring —
trading one defect for a worse one.

**Verified structurally, not by pixels.** Chrome will not autofill headlessly,
so this state cannot be reproduced here; the probe asserts the rules are
present, that they paint the field's own colour, and that focus still gets a
ring. An earlier attempt simulated autofill with author CSS, reported "no
difference", and would have misled the next person to look — that tool was
deleted rather than left in the tree.

### The pip reports real state

The readout bar's pip is not decoration. It has four distinct colours,
driven by classes on the card: grey idle, amber while a request is in
flight, green after one succeeds, red after one fails. Each is set from
the outcome of a real request, so the colour always reports something that
happened rather than decorating.

The colour used to be read **after a 200ms transition settled** — a
synchronous read returns the colour being animated from, which yields four
identical readings and looks exactly like a pip that never changes. The
transition is gone, so a plain read is now correct.

A bug worth recording: removing the reject nudge also took
`.plate.is-rejected .pip` with it, because the orphan sweep matched on the
line above. The probe caught it — three states instead of four — which is
the argument for asserting the *count* and not just that each colour is
set.

### The fold is gone

The seam is now a plain 1px `--hairline` rule on the paper side. The full
reasoning — and why no single colour was ever able to serve this edge — is
under **The seam is a plain border** in the ground section.
`.plate-brand::after` no longer exists, so `_login_corners.js` no longer
photographs it. The probe asserts the element is *absent* as well as the
rule being present, because either half alone would pass while the other
regressed.

## Nothing moves

The whole page is inert. No `@keyframes`, no transitions, no
`requestAnimationFrame`, nothing on a timer.

This was a request, not a technical position, so the reasoning is recorded
here only to explain what was removed and why the result is not a page with
something conspicuously missing.

**Removed:**

| Was | Now |
|---|---|
| Card entrance, `cardIn` 560ms | nothing — it is simply there |
| Ground drift, 128s per tile | nothing |
| Breathing light, `groundBreath` 11s | removed, and `.ground::after` with it |
| Fold dot run, 2.6s | the dots stand still |
| Readout sweep, 1.15s | element removed from markup and CSS |
| Pip pulse on busy | the colour still reports state |
| Reject nudge, 280ms | removed, and the reflow hack with it |
| Button hover sweep | removed |
| Every `transition`, nine of them | removed |
| `prefers-reduced-motion` block | removed — it had nothing left to reduce |

**Kept on purpose:** the readout pip. Its four colours — blue idle, amber in
flight, green success, red refused — are set by the outcome of real requests,
so they report state rather than decorate. Colour is not motion.

**Kept out of necessity:** `transition: background-color 600000s 0s` on the
autofill override. That is not an animation; it is the standard trick for
stopping Chrome transitioning its own autofill background in, and removing it
brings back the grey-filled field.

### The page loader went too

`page-loader.css` is a full-screen splash: a fade-in on the logo, two lines
of text, and three dots bouncing on a 1.4s loop. It is animation on this page
by any reading, so `login.php` no longer loads it.

The two shared files are left in place. Both are documented as "include this
in every page" and `login.php` was the only page that did, so deleting them
would take the capability away from the whole codebase to satisfy one page.
Re-adding is one `<link>` and one `<script>`.

### What the probe checks instead

Five assertions about motion were replaced by one that samples `transform`,
`opacity`, `backgroundPosition`, `animationName` and `transitionProperty`
across every element, 1.1 seconds apart, and requires all 156 to be
identical. Reading `animationName` alone would not catch a Web Animations
call or a script-driven style change, and those are what creep back.

The reduced-motion check was inverted: it now asserts that no element
declares an animation in either preference state.

## The ground

A **white page** carrying a **mesh**: plain dark nodes joined by hairline
links. No grid, no glow, no animation.

| Element | Carries |
|---|---|
| `.ground` | the page itself: `#FFFFFF` |
| `svg.stellar` | the mesh, tiled by a `<pattern>`, masked away from the card |

### Why a real SVG and not a background image

The field was a data-uri `background-image` for most of its life, and in that
form the links did not appear to reach the nodes. A flattened tile allows
exactly one paint order, and any glow painted over the wires swallowed their
last few pixels — the coordinates were always right, they were just hidden.
A `<pattern>` lets the mesh be drawn first and the nodes sit on top of it,
which is the only order in which a net looks like a net.

The probe proves the net is closed rather than assuming it: it counts link
endpoints that land exactly on a node centre (`onNode=32/32`).

### Why there is no glow

Halos and diffraction spikes were built and then removed. Three reasons, in
order of weight:

- **They turned nodes into stars, and stars pull the eye off the card.** The
  brief is a drawn mesh. A drawn diagram is made of its lines.
- A 64px halo measured **135 -> 227 across six pixels** at 1x. That is a soft
  wash, not a point — it was the "blurry" complaint, and upscaling was ruled
  out by measurement first (the tile was always drawn 1:1).
- The spikes had to be at least 1.5px to survive anti-aliasing at 1x, at which
  point they are competing with the links for attention.

The probe now asserts the **absence** of glow — `gradients=0`, `refs=0`, no
blue anywhere in the mesh — so it cannot creep back unnoticed. The dot fill
is read back as `rgb(16, 25, 43)`, which is `--ink`.

### The mesh, not the grid

A blueprint lattice was tried and removed: on a pure white page it reads as
graph paper rather than drafting, because there is nothing else in the field
to give it a subject.

Worth recording that the connecting lines were removed once too, on a reading
of "remove the graph" that turned out to mean the grid. The probe had encoded
the mistake — the link count was asserted as **exactly zero**. An assertion
written from a misread brief does not just pass the wrong thing, it actively
blocks the right thing.

### The tile is bigger than the pattern

560px tile around a 420px pattern. The margin is not padding: the pattern has
to stop short of the tile edge or the links touching it are cut and the repeat
is visibly seamed. The probe measures the margin from the markup and requires
**at least 30px**; it reads **70px**.

### Nothing moves

No `@keyframes`, no transitions, no timer, nothing on a timeout. The two
`transition: background-color 600000s` rules on the autofill override survive
because they are not motion — they are the standard way of stopping Chrome
transitioning its own autofill background in, and removing them brings back
the grey-filled field.

A twinkle was added to the nodes and then removed again. The probe samples the
whole tree twice, 1.1s apart, and requires all 191 elements to be identical —
reading `animationName` alone would not catch a Web Animations call or a
script-driven change, and those are what creep back in.

### The seam is a plain border

The green dotted fold — maroon, then shadow, then white, then green, then a
column of pale mint dots at 2.6s per cycle — is removed entirely.

This is also the only answer that was ever available. The seam sits between a
royal blue panel and white paper, and **no single colour can serve it at
all**: no green clears 3:1 against blue and white simultaneously, which is
why the dot column existed as a *third* element, added purely because one
line could not carry two edges.

So it is a **1px `--hairline` rule on the paper side**, 3.47:1 on white.
The blue side already has its own edge from `.plate-brand`'s `border-right`.
One line, no accent, no motion — and suppressed below 900px, where the brand
panel is dropped and there is no seam left to draw.


### Three bugs that made a page look fine and render as nothing

All three produced a page that looked broken with nothing in the console.

**A multi-line `url("data:image/svg+xml,…")` kills the whole
stylesheet.** A quoted CSS string cannot contain a raw newline. The browser
parsed five rules and stopped, which presented as a completely unstyled page:
card transparent, no tokens, 19px inputs. The give-away was
`document.styleSheets[i].cssRules.length === 5`.

**A missing semicolon deletes two declarations, silently.** The splice that
rewrote the star SVG cut at `indexOf('");') + 3`, swallowing the
**semicolon** along with the closing quote and paren. A declaration with no
terminator merges with the one after it, so Chrome dropped *both*
`background-image` and `background-size` and reported recovery silently. The
`.ground::before` rule still appeared in the CSSOM, the rule count stayed
at 92, and the console stayed empty.

The tell was comparing `[...rule.style]` against the file: the rule
had `content`, `position`, `inset` and `mask-image` but neither
background property. Bisecting the block declaration by declaration found
nothing, and injecting the same text into a fresh `<style>` **passed** —
because that earlier test had double-prefixed the data URI, so it was
validating a broken string, which Chrome accepts and renders as nothing.

**`getComputedStyle` resolves gradients, so a regex written against the
authored CSS does not match.** The authored form is
`repeating-linear-gradient(180deg, #EAFFF6 0 4px, …)`; the resolved form
has the `180deg` dropped and the shorthand expanded to four
`rgba(...)` stops. Worse, `/repeating-linear-gradient\([^)]*\)/` stops at the
first `)` — which is *inside* `rgba(...)` — so it captures an opening
fragment containing no lengths at all. The probe walks the string and counts
parens instead.

The lesson from all three: a rendered page is not evidence that its
stylesheet applied. Count the parsed rules, and diff the declarations you
wrote against the declarations the CSSOM kept.

### Clipped screenshots freeze CSS animations

Historical, and retired when the page stopped moving — but the lesson is
general and the tool comment in `tools/_fold_shot.js` still carries it.

Puppeteer's `clip` screenshot path re-renders the page, which restarts CSS
animations from zero. Three clipped captures of the fold, 400ms apart, came
out **byte-identical** while the animation was demonstrably running:

```
   clipped   26cfacdb2599 / 26cfacdb2599 / 26cfacdb2599   <- frozen
   full      a2775873dda6 / 42b59f6f8961 / 1867dae430fd   <- moving
   computed  9.3px -> 11.4px -> 1.2px (wrapped)           <- moving
```

So a clipped screenshot is evidence about geometry and nothing about motion,
and a byte-comparison of two of them will happily report "no change" for
something running at 2.6s per cycle. Use a full capture, or read a computed
value.

## Detail worth preserving

- `css/auth.css` is loaded only by this page. `css/password-field.css` loads
  after it and owns the password field's masking; neither file sets the
  other's properties, so they cannot fight.
- The OTP field is centred with `letter-spacing`, and `text-indent` is
  `0.25em` — **half** the letter-spacing, measured rather than derived.
  `tools/_css_probe.js` produced:

  ```
  text-indent 0px       glyph offset -5.3px
  text-indent 10.56px   glyph offset +5.3px
  text-indent 5.28px    glyph offset  0.0px   <- 0.25em
  ```

  Half a gap is the obvious fix and it overshoots by exactly as much:
  `text-indent` shifts the line's start, which re-centres the whole line, so
  X of indent moves the glyphs by only X/2.
- The brand footer's two-line break is set by a `<br>` and nothing else. A
  `max-width` added to force it wrapped it to **three** lines.
- Narrow-phone rules once leaked OUTSIDE their media query and overrode the
  desktop sizes while every screenshot still looked plausible. Desktop
  heights and paddings are now asserted directly.

## Tests

```
node tools/_login_shot.js      # every state, plus keyboard, reduced motion, overflow
node tools/_login_corners.js   # corner marks and the seam, at 2x, clipped
node tools/_login_field.js     # is the page or the browser painting a field oddly?
node tools/_fold_shot.js       # the seam at 4x, to look at. NOT to test motion.
php tests/otp_login_check.php  # real sign-in against auth_actions.php
php tests/clear_login_probe.php <identifier>
```

`tools/_login_corners.js` exists because the corner marks are the failure
mode that hides: they are present, sized, and correctly bordered in the DOM,
so only a magnified, tightly-clipped screenshot reveals that something is
painted over them.

`tools/_login_shot.js` fails against an identifier that **cannot belong to
anyone**. An earlier version typed a real registrar ID; five failures from
127.0.0.1 is exactly the throttle threshold, so the probe locked a real
account and the run had to be cleaned up by hand. The server answers
unknown-account and wrong-password identically on purpose, so a fake
identifier exercises the identical path.

## Related

- [[Auth System]] · [[dashboard.php]] · [[shared/auth_actions.php]] · [[users]]

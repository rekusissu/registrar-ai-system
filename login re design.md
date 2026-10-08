# Login Architecture — Techno Style

> Bestlink College of the Philippines · Registrar Management System
> Version 2.0 · Last updated: 2026-10-08

---

## Table of Contents

1. [Design Goal](#design-goal)
2. [Design Language](#design-language)
3. [Layout Overview](#layout-overview)
4. [Color Palette](#color-palette)
5. [Typography](#typography)
6. [Screens](#screens)
   - [Screen 1 — Full Login](#screen-1--full-login)
   - [Screen 2 — Authenticating](#screen-2--authenticating)
   - [Screen 3 — Error State](#screen-3--error-state)
   - [Screen 4 — Two-Factor Authentication](#screen-4--two-factor-authentication)
   - [Screen 5 — Success Transition](#screen-5--success-transition)
7. [Component Breakdown](#component-breakdown)
8. [Detailed Component Designs](#detailed-component-designs)
9. [Animation Specs](#animation-specs)
10. [Full Login Flow](#full-login-flow)
11. [Backend Authentication Flow](#backend-authentication-flow)
12. [API Endpoints](#api-endpoints)
13. [Database Schema](#database-schema)
14. [Security Checklist](#security-checklist)
15. [Responsive Behavior](#responsive-behavior)
16. [File Structure](#file-structure)
17. [Key Design Principles](#key-design-principles)

---

## Design Goal

Keep the **two-panel layout** (brand on left, form on right), but transform the visual
language into a **techno / cyberpunk-inspired** system that still feels professional
for a government-adjacent educational institution.

**Transformations from current design:**

| Element | Current | Techno Redesign |
|---|---|---|
| Left panel | Solid royal blue | Deep navy with animated hex grid + cyan glow edge |
| Right form panel | White | Dark glass with cyan corner brackets |
| Logo | Flat shield | Pulsing cyan ring around logo |
| Title | Plain white text | Orbitron font with cyan glow |
| Subtitle | Light blue | Monospace with `▸` prefix |
| Inputs | Light gray fill | Dark bg with cyan border on focus |
| Sign In button | Solid blue | Cyan→blue gradient with scan-line sweep |
| Background | Static | Animated hex grid + drifting particles |

---

## Design Language

### Visual Effects

- **Glassmorphism panels** — `backdrop-filter: blur(20px)`, semi-transparent dark bg
- **Neon glow borders** — `box-shadow: 0 0 20px rgba(0, 240, 255, 0.35)`
- **Animated hex grid** — slow diagonal drift in background
- **Scanline overlay** — thin horizontal lines across the screen (subtle)
- **HUD corner brackets** — L-shaped cyan strokes on card corners
- **Typing animations** — text appears like a terminal
- **Glitch effect on error** — brief screen glitch on failed login

### Typography

```
Headings:    Orbitron / Rajdhani / Space Grotesk
Body:        Inter
Data/Labels: JetBrains Mono (monospace for tech feel)
```

---

## Layout Overview

```
╔══════════════════════════════════════════════════════════════════════╗
║ ●●● BESTLINK · REGISTRAR SYSTEM v2.0          ⬡ SECURE · TLS 1.3    ║
╠═══════════════════════════════╤══════════════════════════════════════╣
║                               │                                      ║
║   ┌───────────────────────┐   │        ┌────────────────────┐       ║
║   │  ⬡ BRAND PANEL        │   │        │   ⬡  LOGIN PANEL    │       ║
║   │  ─────────────────    │   │        │   ──────────────    │       ║
║   │                       │   │        │                     │       ║
║   │   [LOGO]              │   │        │      [LOGO]         │       ║
║   │                       │   │        │                     │       ║
║   │   REGISTRAR           │   │        │   Sign In Account   │       ║
║   │   MANAGEMENT          │   │        │                     │       ║
║   │   SYSTEM              │   │        │  ┌───────────────┐  │       ║
║   │                       │   │        │  │ ID / Username │  │       ║
║   │   ▸ AI-Supported      │   │        │  └───────────────┘  │       ║
║   │     Records Mgmt      │   │        │  ┌───────────────┐  │       ║
║   │                       │   │        │  │ Password   👁 │  │       ║
║   │   ── feature list ──  │   │        │  └───────────────┘  │       ║
║   │   ✅ Student Records  │   │        │                     │       ║
║   │   ✅ Documents        │   │        │  [ ▶ SIGN IN ]      │       ║
║   │   ✅ RFID Cards       │   │        │                     │       ║
║   │   ✅ Academic History │   │        │  Forgot password?   │       ║
║   │                       │   │        │  ────────────────   │       ║
║   │   [status indicator]  │   │        │  Terms & Conditions │       ║
║   └───────────────────────┘   │        └────────────────────┘       ║
║                               │                                      ║
╚═══════════════════════════════╧══════════════════════════════════════╝
   ● SECURE   │  NODE: PH-QC-01  │  IP: 192.168.x.x  │  ⏱ 00:00:00
```

---

## Color Palette

```css
:root {
  /* ── Backgrounds ───────────────────────── */
  --bg-deep:        #0A0E1A;   /* main canvas */
  --bg-panel:       #111827;   /* left brand panel */
  --bg-glass:       rgba(17, 24, 39, 0.75);  /* form panel */

  /* ── Bestlink Brand ───────────────────── */
  --brand-navy:     #0B2447;
  --brand-blue:     #1E40AF;
  --brand-cyan:     #00F0FF;

  /* ── Neon Accents ─────────────────────── */
  --cyan:           #00F0FF;
  --cyan-dim:       #00A8B5;
  --blue:           #0080FF;
  --purple:         #8B5CF6;
  --green:          #00FF9F;
  --red:            #FF3366;
  --amber:          #FFB800;

  /* ── Text ─────────────────────────────── */
  --text-primary:   #E5F4FF;
  --text-secondary: #7A8FA8;
  --text-muted:     #4A5568;

  /* ── Glow ─────────────────────────────── */
  --glow-cyan:      0 0 20px rgba(0, 240, 255, 0.35);
  --glow-cyan-soft: 0 0 40px rgba(0, 240, 255, 0.15);
  --glow-green:     0 0 20px rgba(0, 255, 159, 0.45);
  --glow-red:       0 0 20px rgba(255, 51, 102, 0.45);

  /* ── Radius & Fonts ───────────────────── */
  --radius:         12px;
  --radius-sm:      6px;
  --font-display:   'Orbitron', sans-serif;
  --font-body:      'Inter', sans-serif;
  --font-mono:      'JetBrains Mono', monospace;
}
```

---

## Typography

| Use | Font | Weight | Size |
|---|---|---|---|
| Brand title | Orbitron | 700 | 28–32px |
| Section headings | Orbitron | 600 | 18–22px |
| Body | Inter | 400 | 14–16px |
| Labels | JetBrains Mono | 500 | 11–12px uppercase |
| Data / IDs | JetBrains Mono | 500 | 14px |
| Status bar | JetBrains Mono | 400 | 11px |

---

## Screens

### Screen 1 — Full Login

```
╔══════════════════════════════════════════════════════════════════════╗
║ ●●● BESTLINK REGISTRAR v2.0                     ⬡ SECURE · TLS 1.3  ║
╠═══════════════════════════════╤══════════════════════════════════════╣
║                               │                                      ║
║  ░░░ ANIMATED HEX GRID ░░░    │   ░░░ SUBTLE PARTICLE DRIFT ░░░     ║
║                               │                                      ║
║  ┌───────────────────────┐    │   ┌──────────────────────────────┐  ║
║  │ ┌─                 ─┐ │    │   │ ┌─                        ─┐ │  ║
║  │                       │    │   │                              │  ║
║  │      ◈ [LOGO] ◈       │    │   │                              │  ║
║  │      ╰─ pulsing ─╯    │    │   │         ◈ [LOGO] ◈           │  ║
║  │                       │    │   │                              │  ║
║  │   ── BESTLINK ──      │    │   │      ▸ SIGN IN ACCOUNT       │  ║
║  │   COLLEGE OF THE      │    │   │      ──────────────────      │  ║
║  │   PHILIPPINES         │    │   │                              │  ║
║  │                       │    │   │   ▸ ID NUMBER / USERNAME     │  ║
║  │   ┌───────────────┐   │    │   │   ┌──────────────────────┐  │  ║
║  │   │ REGISTRAR     │   │    │   │   │ 2023-00123           │  │  ║
║  │   │ MANAGEMENT    │   │    │   │   └──────────────────────┘  │  ║
║  │   │ SYSTEM        │   │    │   │                              │  ║
║  │   └───────────────┘   │    │   │   ▸ PASSWORD                 │  ║
║  │                       │    │   │   ┌──────────────────────┐  │  ║
║  │   ▸ AI-SUPPORTED      │    │   │   │ ••••••••        👁  │  │  ║
║  │     RECORDS MGMT      │    │   │   └──────────────────────┘  │  ║
║  │                       │    │   │                              │  ║
║  │   ┌─ FEATURES ─────┐  │    │   │   ☐ KEEP ME SIGNED IN        │  ║
║  │   │ ◉ Student Rec. │  │    │   │                              │  ║
║  │   │ ◉ Documents    │  │    │   │   ┌──────────────────────┐  │  ║
║  │   │ ◉ RFID Cards   │  │    │   │   │   ▶  S I G N   I N   │  │  ║
║  │   │ ◉ Academic     │  │    │   │   └──────────────────────┘  │  ║
║  │   └────────────────┘  │    │   │                              │  ║
║  │                       │    │   │   ▸ FORGOT PASSWORD?         │  ║
║  │   ● SYSTEM ONLINE     │    │   │   ───────────────────────    │  ║
║  │   ● 47 ACTIVE USERS   │    │   │   ▸ TERMS & CONDITIONS       │  ║
║  │   ● SYNC 2m ago       │    │   │                              │  ║
║  │                       │    │   │ ┌─                        ─┐ │  ║
║  │ ┌─                 ─┐ │    │   └──────────────────────────────┘  ║
║  └───────────────────────┘    │                                      ║
║                               │                                      ║
╠═══════════════════════════════╧══════════════════════════════════════╣
║ ● SECURE   │ NODE: PH-QC-01 │ IP: 192.168.x.x │ ⏱ 14:23:45        ║
╚══════════════════════════════════════════════════════════════════════╝
```

**Buttons & Actions:**

| Button | Frontend Action | Backend Call |
|---|---|---|
| `[👁]` password toggle | Toggle visibility | — |
| `☐ KEEP ME SIGNED IN` | Toggles long-lived refresh | — |
| `[ ▶ SIGN IN ]` | Submit form | `POST /auth/login` |
| `▸ FORGOT PASSWORD?` | Navigate to `/forgot` | — |
| `▸ TERMS & CONDITIONS` | Open terms modal | — |

---

### Screen 2 — Authenticating

```
╔══════════════════════════════════════════════════════════════════════╗
║                                                                      ║
║           ⬡  VERIFYING IDENTITY                                     ║
║                                                                      ║
║           ░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░                    ║
║           ░  [████████████░░░░░░░░░░░░░░░░░░]  62%  ░              ║
║           ░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░                    ║
║                                                                      ║
║           ▸ Validating credentials...      ✅                       ║
║           ▸ Checking account status...     ✅                       ║
║           ▸ Loading security profile...    ⏳                       ║
║                                                                      ║
║           DO NOT CLOSE THIS WINDOW                                   ║
║                                                                      ║
╚══════════════════════════════════════════════════════════════════════╝
```

**Animated steps (streamed from backend):**
1. `▸ Connecting to auth server...`
2. `▸ Validating credentials...`
3. `▸ Checking account status...`
4. `▸ Loading security profile...`

Streamed via WebSocket or Server-Sent Events.

---

### Screen 3 — Error State

```
╔══════════════════════════════════════════════════════════════════════╗
║                                                                      ║
║           ▓▒░ SCREEN GLITCHES BRIEFLY ░▒▓                            ║
║                                                                      ║
║           ⚠  AUTHENTICATION FAILED                                   ║
║           ──────────────────────                                     ║
║                                                                      ║
║           Invalid ID or passcode.                                    ║
║           Attempt 2 of 5.                                            ║
║                                                                      ║
║           ⚠  After 5 failed attempts, your account                  ║
║              will be temporarily locked (15 minutes).                ║
║                                                                      ║
║           [ TRY AGAIN ]   [ RESET PASSCODE ]                         ║
║                                                                      ║
╚══════════════════════════════════════════════════════════════════════╝
```

**Effects:**
- Red border pulses
- Screen glitches for ~300ms
- Error sound (optional)
- Attempt counter increments

---

### Screen 4 — Two-Factor Authentication

```
╔══════════════════════════════════════════════════════════════════════╗
║                                                                      ║
║           ⬡  TWO-FACTOR VERIFICATION                                ║
║           ─────────────────────────────                              ║
║                                                                      ║
║           A 6-digit code was sent to:                                ║
║           ┌──────────────────────────────┐                          ║
║           │  📱 +63 912 ••• 6789         │                          ║
║           │  ✉  j•••@email.com           │                          ║
║           └──────────────────────────────┘                          ║
║                                                                      ║
║           Enter code:                                                ║
║                                                                      ║
║           ┌──┐ ┌──┐ ┌──┐ ┌──┐ ┌──┐ ┌──┐                             ║
║           │ 1│ │ 2│ │ 3│ │ 4│ │  │ │  │                             ║
║           └──┘ └──┘ └──┘ └──┘ └──┘ └──┘                             ║
║                                                                      ║
║           ⏱ Code expires in: 04:32                                  ║
║                                                                      ║
║           [ RESEND CODE ]   [ USE BACKUP CODE ]                     ║
║                                                                      ║
║           [ ← BACK ]                                                 ║
║                                                                      ║
╚══════════════════════════════════════════════════════════════════════╝
```

**Buttons:**

| Button | Action |
|---|---|
| `[ RESEND CODE ]` | `POST /auth/2fa/resend` (rate-limited) |
| `[ USE BACKUP CODE ]` | Switch to backup code input |
| `[ ← BACK ]` | Return to login screen |

Auto-submits when all 6 digits entered.

---

### Screen 5 — Success Transition

```
╔══════════════════════════════════════════════════════════════════════╗
║                                                                      ║
║                                                                      ║
║                        ✅                                            ║
║                                                                      ║
║           ⬡  ACCESS GRANTED                                          ║
║                                                                      ║
║           Welcome back,                                               ║
║           ┌──────────────────────────────┐                           ║
║           │  JUAN DELA CRUZ              │                           ║
║           │  Role: REGISTRAR STAFF       │                           ║
║           │  Node: PH-QC-01              │                           ║
║           └──────────────────────────────┘                           ║
║                                                                      ║
║           Loading workspace...                                       ║
║           ░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░                       ║
║                                                                      ║
╚══════════════════════════════════════════════════════════════════════╝
```

Auto-redirects to dashboard after ~1.5s.

---

## Component Breakdown

### Component Tree

```
<AuthPage>
 ├── <GlobalBackground>          ← hex grid + particles
 ├── <TopStatusBar>              ← branding + security info
 ├── <AuthSplitLayout>
 │    ├── <BrandPanel>           ← left
 │    │    ├── <BrandLogo />
 │    │    ├── <BrandTitle />
 │    │    ├── <BrandSubtitle />
 │    │    ├── <FeatureList />
 │    │    └── <SystemTicker />
 │    │
 │    └── <LoginPanel>           ← right
 │         ├── <PanelCornerBrackets />
 │         ├── <LoginLogo />
 │         ├── <LoginHeading />
 │         ├── <LoginForm>
 │         │    ├── <InputField id="identifier" icon="🆔" label="ID / Username" />
 │         │    ├── <InputField id="password" icon="🔒" label="Password" toggle />
 │         │    ├── <RememberToggle />
 │         │    └── <SubmitButton />
 │         ├── <ForgotLink />
 │         └── <TermsLink />
 └── <BottomStatusBar>           ← IP, node, time
```

---

## Detailed Component Designs

### BrandPanel

```jsx
<BrandPanel style={{
  background: 'linear-gradient(135deg, #0A0E1A 0%, #0B2447 100%)',
  position: 'relative',
  overflow: 'hidden',
}}>
  <HexGridOverlay opacity={0.08} color="var(--cyan)" />

  <div style={{
    position: 'absolute', right: 0, top: 0, bottom: 0, width: 2,
    background: 'linear-gradient(180deg, transparent, var(--cyan), transparent)',
    boxShadow: 'var(--glow-cyan)',
  }} />

  <BrandLogo glow />
  <BrandTitle>REGISTRAR MANAGEMENT SYSTEM</BrandTitle>
  <BrandSubtitle>AI-SUPPORTED RECORDS MANAGEMENT</BrandSubtitle>
  <FeatureList items={['Student Records', 'Documents', 'RFID Cards', 'Academic History']} />
  <SystemTicker />
</BrandPanel>
```

**Feature list items styled as:**

```
◉ STUDENT RECORDS          ← cyan dot + monospace uppercase
◉ DOCUMENTS
◉ RFID CARDS
◉ ACADEMIC HISTORY
```

Each item has a subtle animated pulse on the dot.

---

### LoginPanel

```jsx
<LoginPanel style={{
  background: 'var(--bg-glass)',
  backdropFilter: 'blur(20px)',
  border: '1px solid rgba(0, 240, 255, 0.15)',
  borderRadius: 16,
  padding: '48px 40px',
  position: 'relative',
}}>
  <PanelCornerBrackets />
  <LoginLogo glow />
  <LoginHeading>▸ SIGN IN ACCOUNT</LoginHeading>
  <LoginForm />
  <ForgotLink />
  <TermsLink />
</LoginPanel>
```

**Corner brackets CSS:**

```css
.corner-tl, .corner-tr, .corner-bl, .corner-br {
  position: absolute;
  width: 20px; height: 20px;
  border-color: var(--cyan);
  border-style: solid;
  filter: drop-shadow(0 0 6px var(--cyan));
}
.corner-tl { top: 8px; left: 8px; border-width: 2px 0 0 2px; }
.corner-tr { top: 8px; right: 8px; border-width: 2px 2px 0 0; }
.corner-bl { bottom: 8px; left: 8px; border-width: 0 0 2px 2px; }
.corner-br { bottom: 8px; right: 8px; border-width: 0 2px 2px 0; }
```

---

### InputField — Techno Input

```
┌──────────────────────────────────────────┐
│  ▸ ID NUMBER / USERNAME                  │  ← cyan monospace label
│                                          │
│  ┌────────────────────────────────────┐  │
│  │ 🆔  2023-00123                     │  │  ← dark bg, cyan border on focus
│  └────────────────────────────────────┘  │
│     └─ subtle cyan underline glow ─┘     │
└──────────────────────────────────────────┘
```

**States:**

| State | Visual |
|---|---|
| Default | Dark bg `#0A0E1A`, border `#1E3A5F`, text `#E5F4FF` |
| Hover | Border brightens to `#00A8B5` |
| Focus | Border `#00F0FF` + outer glow + animated bottom line |
| Filled | Small cyan check `✓` in top-right of input |
| Error | Border `#FF3366` + shake animation + red glow |
| Disabled | Muted text, no border |

---

### SubmitButton — SIGN IN

```
Default state:
┌───────────────────────────────────────┐
│  ▶  S I G N   I N                     │
└───────────────────────────────────────┘

Hover state:
┌───────────────────────────────────────┐
│  ▶  S I G N   I N                     │
└───────────────────────────────────────┘
     ╰─── scan line sweeps L→R ───╯

Loading state:
┌───────────────────────────────────────┐
│  ◌  AUTHENTICATING...                 │
└───────────────────────────────────────┘
```

**Styling:**

```css
.sign-in-btn {
  background: linear-gradient(135deg, #00F0FF 0%, #0080FF 100%);
  color: #0A0E1A;
  font-family: var(--font-display);
  font-weight: 700;
  letter-spacing: 0.3em;
  padding: 16px 32px;
  border: none;
  border-radius: 10px;
  box-shadow: 0 0 20px rgba(0, 240, 255, 0.35);
  transition: all 0.2s ease;
  position: relative;
  overflow: hidden;
}

.sign-in-btn:hover {
  box-shadow: 0 0 40px rgba(0, 240, 255, 0.65);
  transform: translateY(-1px);
}

.sign-in-btn::before {
  content: '';
  position: absolute;
  top: 0; left: -100%;
  width: 100%; height: 100%;
  background: linear-gradient(90deg, transparent, rgba(255,255,255,0.4), transparent);
  transition: left 0.6s ease;
}

.sign-in-btn:hover::before {
  left: 100%;
}
```

---

### TopStatusBar & BottomStatusBar

```
╔══════════════════════════════════════════════════════════════════╗
║ ●●● BESTLINK REGISTRAR v2.0                ⬡ SECURE · TLS 1.3    ║  ← TOP
╚══════════════════════════════════════════════════════════════════╝

╔══════════════════════════════════════════════════════════════════╗
║ ● SECURE │ NODE: PH-QC-01 │ IP: 192.168.x.x │ ⏱ 14:23:45        ║  ← BOTTOM
╚══════════════════════════════════════════════════════════════════╝
```

- Monospace font
- Green `●` dots for status indicators
- Live clock updating every second
- Blinking cursor at end of time (optional)

---

## Animation Specs

| Element | Animation | Duration | Trigger |
|---|---|---|---|
| Background hex grid | Slow diagonal drift | 60s loop | Always |
| Brand panel glow edge | Pulse intensity 0.3 → 0.6 | 3s loop | Always |
| Logo ring | Rotate 360° | 8s loop | Always |
| Feature dot | Opacity 0.4 → 1.0 | 2s loop | Always |
| Input focus | Border glow expand | 200ms | On focus |
| Submit hover | Scan-line sweep | 600ms | On hover |
| Submit loading | Spinner rotate | 1s loop | On submit |
| Error | Glitch shake | 300ms | On failure |
| Success | Green flash + fade | 1.5s | On success |
| Card entrance | Fade + slide up | 400ms | Page load |
| Panel brackets | Draw-in from corners | 600ms | Page load |

---

## Full Login Flow

```
┌─────────────────────────────────────────────────────────────────────┐
│  1. LANDING                                                         │
│     • Card fades in                                                 │
│     • Grid starts animating                                         │
│     • Logo pulses                                                   │
└──────────────────────┬──────────────────────────────────────────────┘
                       │ user types credentials
                       ▼
┌─────────────────────────────────────────────────────────────────────┐
│  2. INPUT                                                           │
│     • Field glows on focus                                          │
│     • Live validation: ✓ green check appears                        │
│     • Sign In button stays disabled until both fields filled        │
└──────────────────────┬──────────────────────────────────────────────┘
                       │ click SIGN IN
                       ▼
┌─────────────────────────────────────────────────────────────────────┐
│  3. AUTHENTICATING                                                  │
│     • Button: ▶ SIGN IN → ◌ AUTHENTICATING...                       │
│     • Top bar: "● VERIFYING IDENTITY"                               │
│     • Progress shimmer sweeps across card                           │
│     • POST /auth/login                                              │
└──────────────────────┬──────────────────────────────────────────────┘
                       │
              ┌────────┴────────┐
              ▼                 ▼
      ┌───────────────┐  ┌───────────────┐
      │ 4a. SUCCESS   │  │ 4b. FAILURE   │
      ├───────────────┤  ├───────────────┤
      │ • Green flash │  │ • Red glitch  │
      │ • "ACCESS     │  │ • Error msg   │
      │   GRANTED"    │  │ • Attempt     │
      │ • Fade to     │  │   counter++   │
      │   Dashboard   │  │ • Shake card  │
      └───────────────┘  └───────────────┘
```

---

## Backend Authentication Flow

```
┌──────────────────────────────────────────────────────────────────────┐
│                         CLIENT (Browser)                             │
│  • Stores access token in memory                                    │
│  • Stores refresh token in httpOnly cookie                          │
└────────────────────────────┬─────────────────────────────────────────┘
                             │ HTTPS
                             ▼
┌──────────────────────────────────────────────────────────────────────┐
│                       API GATEWAY                                    │
│  • Rate limiting (5 attempts / 15 min per IP)                       │
│  • Request logging                                                  │
│  • CORS                                                             │
└────────────────────────────┬─────────────────────────────────────────┘
                             │
                             ▼
┌──────────────────────────────────────────────────────────────────────┐
│                     AUTH SERVICE                                     │
│                                                                      │
│  POST /auth/login         → validate → check → issue tokens or 2FA  │
│  POST /auth/2fa/verify    → verify OTP → issue tokens               │
│  POST /auth/refresh       → rotate refresh token                    │
│  POST /auth/logout        → revoke session                          │
│  POST /auth/forgot        → request reset                           │
│  POST /auth/reset         → complete reset                          │
│  POST /auth/webauthn/*    → biometric register/verify               │
└────────────────────────────┬─────────────────────────────────────────┘
                             │
        ┌────────────────────┼────────────────────┐
        ▼                    ▼                    ▼
┌───────────────┐  ┌───────────────┐  ┌───────────────┐
│  PostgreSQL   │  │    Redis      │  │  SMS/Email    │
│  • users      │  │  • sessions   │  │  • OTP send   │
│  • auth_logs  │  │  • rate limit │  │  • alerts     │
│  • 2FA secrets│  │  • blacklist  │  │               │
│  • webauthn   │  │  • temp tokens│  │               │
└───────────────┘  └───────────────┘  └───────────────┘
```

### Process 1 — Standard Login

```
POST /auth/login
{ "identifier": "2023-00123", "password": "••••••••" }
       │
       ▼
[1] Validate request body
[2] Rate limit check (Redis: login:{ip}, 5/15min)
[3] Find user by student_no OR email
[4] Check account state (not deleted, not locked, active)
[5] Verify password (argon2.verify)
       │
       ├── INVALID ──► increment fail_count ──► 401
       │                log auth_logs
       │                if fail_count >= 5 → lock 15 min
       │
       ▼ VALID
[6] Check 2FA
    IF totp_enabled → generate temp_token (JWT, 5 min)
                    → send OTP via SMS/email
                    → return { requires_2fa: true, temp_token }
    ELSE continue
       │
       ▼
[7] Issue tokens
    access_token  = JWT (15 min)   { sub, role, perms }
    refresh_token = JWT (7 days)   { sub, jti }
[8] Store session in Redis
[9] Set refresh_token in httpOnly cookie
[10] Log success to auth_logs
       │
       ▼
Return 200 { access_token, user: {...} }
```

### Process 2 — 2FA Verify

```
POST /auth/2fa/verify
{ "temp_token": "...", "code": "123456" }
       │
       ▼
[1] Verify temp_token (JWT)
[2] Fetch user
[3] Verify OTP (TOTP or Redis-stored SMS)
       │
       ├── INVALID (max 3 tries) → 401
       │
       ▼ VALID
[4] Issue access + refresh tokens
Return 200
```

### Process 3 — Token Refresh

```
POST /auth/refresh
Cookie: refresh_token=...
       │
       ▼
[1] Verify JWT signature + expiry
[2] Check Redis: session:{user_id}:{jti} exists?
       ├── NO → 401
       ▼ YES
[3] Rotate: delete old jti → create new → issue new access token
Return 200
```

---

## API Endpoints

```
POST   /auth/login                 → standard login
POST   /auth/2fa/verify            → verify OTP
POST   /auth/2fa/resend            → resend OTP
POST   /auth/refresh               → rotate tokens
POST   /auth/logout                → revoke session
POST   /auth/logout-all            → revoke all sessions
POST   /auth/forgot                → request reset
POST   /auth/reset                 → complete reset
POST   /auth/change-password       → authenticated change
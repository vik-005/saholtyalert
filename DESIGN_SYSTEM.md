# SAHOTLY SYSTEM — Design System v2.0
*Professional Operational Intelligence Platform*

---

## 1. DESIGN PRINCIPLES

### Core Values
- **Clarity** - Information must be immediately understandable
- **Consistency** - Every element follows a unified visual language
- **Professionalism** - Institutional design, suitable for high-stakes operations
- **Functionality** - Every visual element serves a purpose
- **Accessibility** - WCAG AA compliance, keyboard navigation, screen reader support
- **Responsiveness** - Works seamlessly from mobile to desktop (4K)

### Anti-Patterns (Forbidden)
- No excessive gradients (max 1-2 subtle linear gradients at system level)
- No decorative elements without function
- No artificial AI-generated aesthetics
- No disproportionate shadows or borders
- No unnecessary animations
- No technical jargon in UI labels
- No redundant visual hierarchy

---

## 2. COLOR PALETTE

### Primary Colors
| Token | Value | Usage | Light Mode | Dark Mode |
|-------|-------|-------|-----------|-----------|
| `--amber` | #059669 | Primary actions, active states | Standard | #34d399 |
| `--amber-hover` | #047857 | Button hover, interactive focus | Standard | #10b981 |
| `--amber-bg` | #ecfdf5 | Light backgrounds, secondary info | Standard | rgba(52,211,153,0.12) |
| `--amber-bd` | #a7f3d0 | Borders for teal/amber elements | Standard | rgba(52,211,153,0.25) |

### Status Colors
| Status | Color | Usage | Score/Level |
|--------|-------|-------|-------------|
| **Critique** | #ef4444 (red) | Urgent alerts, critical priority | ≥ 18 |
| **Élévé** | #f97316 (orange) | High priority alerts | 14-17 |
| **Modéré** | #eab308 (yellow) | Medium priority alerts | 10-13 |
| **Faible** | #10b981 (green) | Low priority alerts | < 10 |

### Neutral Grays
| Token | Value | Usage |
|-------|-------|-------|
| `--bg` | #f1f5f9 | Main background (light) |
| `--card` | #ffffff | Card/panel backgrounds |
| `--card2` | #f8fafc | Secondary card backgrounds |
| `--border` | #e2e8f0 | Primary borders |
| `--border2` | #cbd5e1 | Secondary borders |
| `--text` | #0f172a | Primary text |
| `--muted` | #475569 | Secondary text |
| `--dim` | #64748b | Tertiary text |

### Additional Colors
| Token | Value | Usage |
|-------|-------|-------|
| `--red` | #ef4444 | Errors, destructive actions |
| `--green` | #10b981 | Success, completed |
| `--blue` | #3b82f6 | Information, secondary actions |
| `--orange` | #f97316 | Warnings, pending |

### Dark Mode
- Background: #0b1612 → #12221b → #182e25
- Text: #e6f2ec (light green-tinted white)
- Muted: #85a697
- All other colors inverted/adjusted for contrast

---

## 3. TYPOGRAPHY

### Font Family
**Primary**: Inter (300, 400, 500, 600, 700, 800, 900)
- Variable weights for dynamic adaptation
- Excellent readability at small sizes
- Professional appearance

### Font Sizes & Weights

| Element | Size | Weight | Line Height | Usage |
|---------|------|--------|-------------|-------|
| **Page Title** | 28px | 700 | 1.2 | `<h1>` |
| **Section Title** | 22px | 700 | 1.3 | `<h2>` |
| **Subsection Title** | 18px | 600 | 1.3 | `<h3>` |
| **Component Title** | 14px | 600 | 1.4 | Card title, form group |
| **Body Text** | 13px | 400 | 1.5 | Default text, paragraphs |
| **Small Text** | 12px | 400 | 1.4 | Labels, captions |
| **Tiny Text** | 11px | 500 | 1.3 | Badges, timestamps |
| **Monospace** | 12px | 400 | 1.5 | Code, technical values |

### Font Weight Meanings
- **300** - Disabled, placeholder, muted secondary info
- **400** - Default body text, normal content
- **500** - Emphasized text, secondary labels
- **600** - Section titles, form labels, strong emphasis
- **700** - Page titles, primary labels, strong hierarchy
- **800** - Modal titles, critical alerts
- **900** - Not typically used in UI

---

## 4. SPACING & LAYOUT

### Spacing Scale (all in pixels)
| Token | Value | Usage |
|-------|-------|-------|
| `xs` | 4px | Micro spacings (padding between items) |
| `sm` | 8px | Small gaps (between icon+text) |
| `md` | 12px | Medium gaps (form row spacing) |
| `lg` | 16px | Large gaps (section padding) |
| `xl` | 24px | Extra large (page padding, card gaps) |
| `2xl` | 32px | Double (major section separation) |
| `3xl` | 48px | Triple (layout padding) |

### Layout Dimensions
| Element | Width | Height | Fixed/Flex |
|---------|-------|--------|-----------|
| **Sidebar** | 260px (68px collapsed) | 100vh | Fixed |
| **Topbar** | 100% | 60px | Fixed |
| **Main Content** | Remaining flex space | Auto | Flex |
| **Content Padding** | 32px left/right | 24px top/bottom | Fixed |
| **Card** | Responsive grid | Auto | Flex |
| **Modal** | 90vw or 500px max | Auto (scrollable) | Fixed width |

### Breakpoints
| Device | Width | Layout |
|--------|-------|--------|
| **Mobile** | < 640px | Single column, collapsed sidebar |
| **Tablet** | 640-1024px | 1-2 columns, responsive sidebar |
| **Desktop** | > 1024px | Full 3+ columns, full sidebar |
| **Large Desktop** | > 1400px | Grid expansion |

---

## 5. BORDER RADIUS & SHADOWS

### Border Radius
| Token | Value | Usage |
|-------|-------|-------|
| `--r` | 10px | Buttons, inputs, small components |
| `--r2` | 14px | Cards, larger components |
| `--radius` | 10px | Alias for `--r` |
| `--radius-lg` | 14px | Alias for `--r2` |
| None | 0px | Modals (full-bleed), sections |

### Shadows
| Token | Value | Usage |
|-------|-------|-------|
| `--shadow-sm` | 0 1px 2px rgba(0,0,0,0.05) | Subtle, input focus |
| `--shadow` | 0 1px 3px rgba(0,0,0,0.07), 0 1px 2px -1px rgba(0,0,0,0.07) | Default elevation |
| `--shadow-md` | 0 4px 6px -1px rgba(0,0,0,0.07), 0 2px 4px -2px rgba(0,0,0,0.05) | Medium elevation |
| `--shadow-card` | 0 1px 3px rgba(0,0,0,0.04), 0 6px 16px -2px rgba(15,23,42,0.04) | Card hover |

**Shadow Principles**
- Use shadows to convey depth (elevated vs. flat)
- Never stack multiple shadows
- Dark mode shadows are darker/more pronounced
- Use `box-shadow` for layering effect (modal, popover)

---

## 6. COMPONENT LIBRARY

### 6.1 Buttons

**Primary Button**
```html
<button class="btn btn-primary">Action Label</button>
```
- Background: `--amber` (#059669)
- Hover: `--amber-hover` (#047857)
- Text: White
- Height: 44px (md), 40px (sm), 48px (lg)
- Padding: 12px 20px (md)
- Border: None
- Border-radius: `--r` (10px)
- Font-weight: 600

**Secondary Button**
```html
<button class="btn btn-secondary">Secondary Action</button>
```
- Background: `--card` (#ffffff)
- Border: 1px `--border` (#e2e8f0)
- Text: `--text` (#0f172a)
- Hover: Background `--card2` (#f8fafc)

**Ghost Button**
```html
<button class="btn btn-ghost">Subtle Action</button>
```
- Background: Transparent
- Border: 1px `--border`
- Text: `--text`
- Hover: Background `--card2` (5% opacity)

**Danger Button**
```html
<button class="btn btn-danger">Delete / Destructive</button>
```
- Background: `--red` (#ef4444)
- Text: White
- Hover: Darker red shade
- Confirm: Requires confirmation modal

**Button States**
- `:hover` - Slightly darker background, subtle scale
- `:active` - Pressed appearance
- `:focus` - Keyboard focus ring (3px outline)
- `:disabled` - 50% opacity, `cursor: not-allowed`
- `.loading` - Spinner icon, text hidden

### 6.2 Form Elements

**Input Fields**
```html
<input type="text" class="form-control" placeholder="Label">
```
- Background: `--card` (#ffffff)
- Border: 1px `--border` (#e2e8f0)
- Border-radius: `--r` (10px)
- Padding: 10px 12px
- Font-size: 13px
- Focus: Border `--amber`, box-shadow with amber focus ring
- Error: Border `--red`, error text below

**Textarea**
- Same as input but min-height: 100px
- Resizable both directions
- Scroll on overflow

**Select/Dropdown**
- Native select with custom styling
- Bootstrap Select or similar
- Minimum 40px height
- Arrow icon right-aligned

**Checkboxes & Radio**
- Custom styled with teal accent
- 18px square (checkbox), 20px circle (radio)
- Label clickable
- `:focus-visible` ring

**Toggle Switch**
```html
<input type="checkbox" class="toggle-switch">
```
- Width: 44px, Height: 24px
- Off: Gray background
- On: `--amber` background
- Thumb: White circle, animated

**Form Validation**
- Required: `*` after label in `--red` or `color: --red`
- Success: Green checkmark icon
- Error: Red `X` icon + red border + error message below
- Warning: Orange `!` icon + orange border

### 6.3 Status & Priority Badges

**Status Pills**
```html
<span class="status-pill status-nouveau">Nouveau</span>
<span class="status-pill status-validee">Validée</span>
<span class="status-pill status-transmis">Transmis</span>
```
- Padding: 6px 12px
- Font-size: 11px
- Font-weight: 600
- Border-radius: 20px (pill)
- Background: status-specific light color
- Border: 1px status-specific color
- Colored dot prefix

**Priority Badges**
```html
<span class="priority-badge priority-critique">Critique</span>
```
- Same as status but uses priority colors
- Critique: Red background + text
- Élévé: Orange
- Modéré: Yellow
- Faible: Green

### 6.4 KPI Card

```html
<div class="kpi-card" data-kpi-color="teal">
  <div class="kpi-card-top">
    <div>
      <p class="kpi-label">Alertes Totales</p>
      <p class="kpi-value">1,247</p>
    </div>
    <div class="kpi-icon-wrap kpi-icon-teal">
      <i class="bi bi-clipboard-data"></i>
    </div>
  </div>
  <div class="kpi-card-bottom">
    <span class="kpi-trend kpi-trend-up">
      <i class="bi bi-arrow-up"></i> +12%
    </span>
    <span class="kpi-trend-label">vs month ago</span>
  </div>
</div>
```

**Styling**
- Background: `--card` (#ffffff)
- Border: 1px `--border`
- Border-radius: `--r2` (14px)
- Padding: 20px
- Margin-bottom: 16px
- Shadow: `--shadow-card`

**Subcomponents**
- `.kpi-label` - 12px, `--dim`, font-weight 500
- `.kpi-value` - 28px, `--text`, font-weight 700
- `.kpi-icon-wrap` - 48x48px circle, color-specific background
- `.kpi-trend` - Green (up) / Red (down) / Gray (neutral) with arrow
- `.kpi-trend-label` - 11px, `--dim`

### 6.5 Table

**Standard Table**
```html
<table class="data-table">
  <thead>
    <tr>
      <th>Column 1</th>
      <th>Column 2</th>
    </tr>
  </thead>
  <tbody>
    <tr>
      <td>Data 1</td>
      <td>Data 2</td>
    </tr>
  </tbody>
</table>
```

**Styling**
- Background: `--card`
- Border: Top/bottom 1px `--border`
- Row border: 1px `--border`
- Header background: `--card2`
- Header text: `--text`, font-weight 600, 12px
- Body text: 13px, `--text`
- Padding: 12px per cell
- Hover row: Background `--amber-bg` (5% opacity)

**Responsive Table**
- On mobile (< 640px), convert to card view
- Each row becomes a card
- Labels shown as data attributes or separate column

### 6.6 Alerts/Toast Notifications

**Alert Box**
```html
<div class="alert alert-danger">
  <i class="bi bi-exclamation-triangle"></i>
  <p>Error message here</p>
</div>
```

**Types**
- `.alert-danger` - Red background (#fecaca), red text (#dc2626), red border
- `.alert-warning` - Orange background, orange text, orange border
- `.alert-success` - Green background, green text, green border
- `.alert-info` - Blue background, blue text, blue border

**Styling**
- Padding: 12px 16px
- Border-radius: `--r` (10px)
- Border: 1px colored
- Icon: 18px, left-aligned
- Close button: 24px icon, right side

**Toast (temporary)**
- Position: fixed bottom-right, 12px margin
- Max-width: 360px
- Auto-dismiss: 5 seconds
- Slide-in animation: 300ms ease-out
- Slide-out animation: 200ms ease-in before dismiss

### 6.7 Modal/Dialog

```html
<div class="modal" role="dialog" aria-labelledby="modal-title">
  <div class="modal-backdrop"></div>
  <div class="modal-container">
    <div class="modal-header">
      <h2 id="modal-title">Modal Title</h2>
      <button class="modal-close">&times;</button>
    </div>
    <div class="modal-body">
      <!-- Content -->
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary">Cancel</button>
      <button class="btn btn-primary">Confirm</button>
    </div>
  </div>
</div>
```

**Styling**
- Backdrop: Black 40% opacity, fixed full screen
- Container: Max-width 500px, `--card` background, centered
- Border-radius: 0 (full-bleed top/bottom)
- Header: Padding 20px, border-bottom 1px `--border`
- Body: Padding 20px, scrollable if > 60vh
- Footer: Padding 16px 20px, border-top 1px `--border`

**Animations**
- Fade-in backdrop: 150ms ease-out
- Slide-up container: 200ms ease-out from bottom
- Keyboard: Esc to close, Tab trapped inside

### 6.8 Progress Indicator / Wizard Steps

```html
<div class="wizard-progress">
  <div class="wizard-step active">
    <span class="step-number">1</span>
    <span class="step-label">Identification</span>
  </div>
  <div class="wizard-step">
    <span class="step-number">2</span>
    <span class="step-label">Contenu</span>
  </div>
  <!-- ... -->
</div>
```

**Styling**
- Flex row, centered
- Steps connected by lines
- Line: 1px `--border`, flex: 1 between steps
- Step number: 32px circle, centered icon-like
  - Incomplete: `--border` border, `--text` number
  - Active: `--amber` background, white text, font-weight 700
  - Complete: `--green` background, white checkmark, font-weight 700
- Step label: 12px, `--text`, below number, `text-align: center`

---

## 7. COMMON PATTERNS

### Page Layout
```
┌─────────────────────────────────────┐
│ Sidebar (260px) │ Topbar (60px)     │
│                 ├─────────────────────┤
│                 │ Content (flex)      │
│                 │ Padding: 32px       │
│                 │                     │
│                 └─────────────────────┘
```

### Card Grid
- Desktop: 3-4 columns
- Tablet: 2 columns
- Mobile: 1 column
- Gap: 20px
- Responsive: `grid-template-columns: repeat(auto-fit, minmax(280px, 1fr))`

### Form Layout
- Field groups: Margin-bottom 20px
- Label: 12px, `--text`, font-weight 600, margin-bottom 6px
- Input: Full width, height 40px
- Help text: 11px, `--dim`, margin-top 4px
- Error message: 11px, `--red`, margin-top 4px

### Data Display
- Tables for ≥ 3 rows
- Cards for 1-2 items
- List for hierarchical data

---

## 8. ACCESSIBILITY

### Color Contrast
- All text ≥ 4.5:1 (WCAG AA)
- Labels ≥ 3:1
- Decorative elements can be exempt

### Keyboard Navigation
- Tab order logical top→bottom, left→right
- Focus visible outlines (3px ring)
- Form validation feedback announced via ARIA

### Screen Readers
- Semantic HTML (`<button>`, `<label>`, `<nav>`)
- `aria-label` for icons
- `aria-hidden="true"` for decorative elements
- Form fields must have `<label for="id">`
- Status messages: `role="status"` with `aria-live="polite"`

### Motion
- `prefers-reduced-motion: reduce` supported
- Animations respect user preference
- No auto-playing videos

---

## 9. IMPLEMENTATION CHECKLIST

### CSS Files
- [ ] `/assets/styles/design-tokens.css` - Variables & root styles
- [ ] `/assets/styles/components.css` - Reusable component styles
- [ ] `/assets/styles/layout.css` - Shell, sidebar, topbar
- [ ] `/assets/styles/forms.css` - Form elements
- [ ] `/assets/styles/tables.css` - Data tables
- [ ] `/assets/styles/responsive.css` - Breakpoint utilities
- [ ] `/assets/styles/animations.css` - Transitions & keyframes

### Twig Components
- [ ] `components/_kpi_card.html.twig` - KPI display (updated)
- [ ] `components/_status_badge.html.twig` - Status pills
- [ ] `components/_priority_badge.html.twig` - Priority badges
- [ ] `components/_button.html.twig` - Button variants (updated)
- [ ] `components/_form_field.html.twig` - Input wrapper with label+validation
- [ ] `components/_table.html.twig` - Data table (updated)
- [ ] `components/_modal.html.twig` - Modal dialog
- [ ] `components/_alert_box.html.twig` - Alert/toast container
- [ ] `components/_progress_indicator.html.twig` - Wizard steps
- [ ] `components/_empty_state.html.twig` - Empty data display

### Pages to Update
- [ ] `/auth/login.html.twig` - Two-column layout with slides
- [ ] `/dashboard/operationnel.html.twig` - KPI cards + charts
- [ ] `/alert/index.html.twig` - Alert table + filters
- [ ] `/wizard/step*.html.twig` - Form refinement
- [ ] `/partials/_navbar.html.twig` - Topbar improvements
- [ ] `/partials/_sidebar.html.twig` - Sidebar refinement

---

## 10. REFERENCES

### Design Tokens
- Figma: [Link to Figma file if available]
- Colors: See Section 2
- Spacing: See Section 4
- Typography: See Section 3

### External Libraries
- Bootstrap Icons 1.11+
- Bootstrap CSS 5.x (custom overrides)
- Chart.js 4.4.3
- Leaflet 1.9.4
- Stimulus.js (via Symfony UX)

### Standards
- WCAG 2.1 AA
- Web Vitals (LCP, FID, CLS)
- Mobile-first responsive design

---

*Last updated: November 2026*
*Version: 2.0 (Professional Platform Redesign)*

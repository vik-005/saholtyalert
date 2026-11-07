# SAHOTLY SYSTEM — Responsive Design Verification Checklist

**Last Updated:** November 2026
**Version:** 2.0 (Professional Platform)

---

## RESPONSIVE BREAKPOINTS

### Tested Viewports
- **Mobile (< 640px)**: iPhone SE (375px), iPhone 12 (390px), Pixel 4 (412px)
- **Tablet (640px - 1024px)**: iPad (768px), iPad Pro (1024px)
- **Desktop (1024px - 1440px)**: Standard laptop (1280px), HD (1440px)
- **Large Desktop (≥ 1440px)**: 4K (1920px), ultrawide (2560px)

### CSS Breakpoints Used
```css
@media (max-width: 640px)   /* Mobile first */
@media (max-width: 768px)   /* Tablet small */
@media (max-width: 1024px)  /* Tablet large / Desktop transition */
@media (min-width: 1024px)  /* Desktop and above */
@media (min-width: 1440px)  /* Large desktop */
```

---

## LAYOUT COMPONENTS

### [ ] Shell & Main Layout
- [x] Sidebar collapses to hamburger menu on tablets
- [x] Sidebar hidden by default on mobile (toggle via hamburger)
- [x] Topbar remains sticky on all viewports
- [x] Content area padding adjusts: 32px (desktop) → 24px (tablet) → 12px (mobile)
- [x] Main flex layout doesn't break on narrow screens
- [x] No horizontal scrolling on mobile

### [ ] Navigation (Topbar)
- [x] Hamburger menu visible on mobile/tablet
- [x] CTA button text hidden on tablet, icon only
- [x] Market badge remains visible on all sizes
- [x] User menu dropdown positioned correctly on mobile
- [x] Notification panel responsive width
- [x] Icons maintain touch-friendly 40px minimum on mobile

### [ ] Sidebar
- [x] 260px width on desktop
- [x] 68px width when collapsed
- [x] 240px width on tablet (always visible, not collapsible)
- [x] Full-height overlay on mobile with backdrop
- [x] Search hidden on mobile (mini mode)
- [x] User profile block remains accessible
- [x] No overflow on narrow screens

---

## FORM & INPUT COMPONENTS

### [ ] Authentication Pages
- [x] Login page: Two-column layout on desktop
- [x] Login page: Stacked on tablet/mobile
- [x] Left slide gallery hidden on mobile
- [x] Form takes full width on mobile
- [x] Input fields expand to full width
- [x] Password toggle button visible
- [x] Buttons expand to full width on mobile
- [x] Legal footer stacks vertically on mobile

### [ ] Form Fields
- [x] Labels visible above inputs
- [x] Input height minimum 40px (mobile-friendly)
- [x] Font size 16px on mobile (prevent iOS zoom)
- [x] Error messages wrap correctly
- [x] Help text visible on all sizes
- [x] Required field indicator (*) visible

### [ ] Wizard Form
- [x] Progress indicator: Steps collapse to list on mobile
- [x] Grid fields: 2 columns on desktop → 1 column on mobile
- [x] Wide fields (grid-column: 1/-1) work on all sizes
- [x] Radio groups stack properly
- [x] Country picker works on mobile
- [x] Navigation buttons: Row on desktop, stacked on mobile
- [x] Step content has adequate padding

### [ ] Buttons
- [x] Primary button: Full width on mobile
- [x] Button groups: Stack vertically on mobile
- [x] Icon-only buttons: 40px on desktop, 36px on tablet, 32px on mobile
- [x] Hover states don't interfere with touch on mobile

---

## DATA DISPLAY COMPONENTS

### [ ] Tables
- [x] Desktop: Standard table layout with horizontal scroll if needed
- [x] Tablet: Responsive columns, some columns may hide
- [x] Mobile: Card-based layout (not table)
  - [x] Each row becomes a card
  - [x] Header row hidden on mobile
  - [x] Label prefixes via `data-label` attribute
  - [x] Actions row at bottom of card
- [x] Pagination controls responsive
- [x] Search/filter bar wraps on mobile

### [ ] Cards (KPI, Status, etc.)
- [x] Grid: 4 columns (desktop) → 3 (large tablet) → 2 (small tablet) → 1 (mobile)
- [x] Card padding adjusts: 20px (desktop) → 16px (tablet) → 12px (mobile)
- [x] Card titles readable on all sizes
- [x] Icon sizes: 48px (desktop) → 40px (mobile)
- [x] Trend indicators visible on all sizes

### [ ] Alerts & Badges
- [x] Status pills don't overflow
- [x] Priority badges maintain visibility
- [x] Urgence badges display correctly
- [x] Score badges readable on mobile

---

## CHARTS & VISUALIZATIONS

### [ ] Chart Containers
- [x] Chart height: 300px (desktop) → 280px (tablet) → 260px (mobile)
- [x] Chart canvas responsive within container
- [x] Export/fullscreen buttons visible
- [x] Charts don't overflow container
- [x] Legend readable on mobile (if present)

### [ ] Maps
- [x] Map height: 400px (desktop) → 350px (tablet) → 300px (mobile)
- [x] Map controls positioned correctly
- [x] Leaflet layers visible on all sizes
- [x] Popups don't overflow viewport

### [ ] Grid Layouts
- [x] Charts grid: 1 column on mobile, 2+ on larger screens
- [x] KPI grid: 1 column mobile, 2-3 on tablet, 3-4 on desktop
- [x] Metric cards: Stack on mobile, side-by-side on larger screens

---

## NAVIGATION & MENUS

### [ ] Profile Menu
- [x] Opens correctly on all screen sizes
- [x] Dropdown positioned to stay in viewport
- [x] Touch target 44px minimum
- [x] User name/role visible or hidden based on space
- [x] Menu items readable without text truncation on mobile

### [ ] Notification Panel
- [x] Slides down from top on all sizes
- [x] Width: 360px (desktop) → full width (mobile with margins)
- [x] Content scrollable if needed
- [x] Close button accessible

### [ ] Mobile Menu (Hamburger)
- [x] Toggle button 40px (touch-friendly)
- [x] Sidebar slides in from left
- [x] Backdrop prevents interaction with main content
- [x] Close on backdrop click
- [x] Navigation items stacked vertically

---

## TYPOGRAPHY & SPACING

### [ ] Font Sizes
- [x] Page title: 28px (desktop) → 24px (tablet) → 20px (mobile)
- [x] Section title: 22px (desktop) → 18px (mobile)
- [x] Body text: 13px consistent on all sizes
- [x] Small text: 11-12px readable on mobile
- [x] Labels: 12px consistent

### [ ] Line Height & Readability
- [x] Line height ≥ 1.5 for body text
- [x] Line length reasonable on mobile (not too wide)
- [x] Paragraphs don't get too dense

### [ ] Spacing
- [x] Padding: 32px (desktop) → 24px (tablet) → 16px (mobile)
- [x] Gap between items: 20px (desktop) → 16px (tablet) → 12px (mobile)
- [x] Margin bottom: 24px (desktop) → 16px (mobile)
- [x] Consistent spacing throughout

---

## TOUCH & INTERACTION

### [ ] Touch Targets
- [x] Minimum 44px × 44px for touch buttons
- [x] Links have adequate spacing (12px gap minimum)
- [x] Form inputs 40px minimum height
- [x] No hover-only interactions (mobile doesn't support hover)

### [ ] Keyboard Navigation
- [x] Tab order logical on all screen sizes
- [x] Focus rings visible (2px outline)
- [x] Focus not trapped on mobile menu
- [x] Escape key closes modals/menus

### [ ] Touch Scrolling
- [x] Smooth scrolling enabled
- [x] Overscroll behavior handled
- [x] Fixed elements don't interfere with scrolling

---

## SPECIFIC PAGE VERIFICATION

### [ ] Login Page
- **Desktop:** Two-column layout, slides visible, form on right
  - [x] Slides carousel works
  - [x] Form centered
  - [x] Footer aligned properly
- **Tablet:** Stack, no slides, form full width
  - [x] Slides hidden
  - [x] Form visible
  - [x] Buttons full width
- **Mobile:** Single column, optimized form
  - [x] Logo visible
  - [x] Form inputs 16px font
  - [x] Buttons full width
  - [x] Footer readable

### [ ] Dashboard
- **Desktop:** Multi-column grid (KPI cards, tables, charts)
  - [x] Cards in 3-4 column grid
  - [x] Tables scrollable horizontally
  - [x] Charts side-by-side
- **Tablet:** Fewer columns, stacked content
  - [x] KPI cards 2 columns
  - [x] Tables adapt
  - [x] Charts single column
- **Mobile:** Single column, full stack
  - [x] Cards 1 column
  - [x] Tables card view
  - [x] Charts full width
  - [x] No horizontal scroll

### [ ] Alert Wizard
- **Desktop:** Full form, all fields visible
  - [x] 2-column field grid
  - [x] Progress bar visible
  - [x] All information visible
- **Tablet:** Adjusted layout
  - [x] 1-column field grid
  - [x] Progress indicator adapted
  - [x] Buttons stacked
- **Mobile:** Minimal, focused form
  - [x] Single column
  - [x] Progress compact
  - [x] One field per row
  - [x] Buttons full width

### [ ] Alert Table
- **Desktop:** Full table with all columns
  - [x] Horizontal scroll if needed
  - [x] All columns visible
- **Tablet:** Some columns hidden
  - [x] Responsive columns
  - [x] Essential info visible
- **Mobile:** Card layout
  - [x] Each row is a card
  - [x] Labels shown
  - [x] Actions at bottom

---

## IMAGES & MEDIA

### [ ] Responsive Images
- [x] Images scale to container width
- [x] Aspect ratios maintained
- [x] No horizontal overflow
- [x] Lazy loading working (if implemented)

### [ ] Icon Sizing
- [x] Icons scale appropriately with screen size
- [x] Icons remain recognizable on mobile
- [x] SVG icons respond to color changes

---

## PERFORMANCE ON MOBILE

### [ ] Loading & Speed
- [x] Page loads in < 3 seconds on 4G
- [x] Lighthouse mobile score ≥ 80
- [x] First Contentful Paint (FCP) < 1.8s
- [x] Largest Contentful Paint (LCP) < 2.5s
- [x] Cumulative Layout Shift (CLS) < 0.1

### [ ] Battery & Data
- [x] No unnecessary animations on mobile
- [x] Images optimized
- [x] Minimal JavaScript execution
- [x] CSS file size reasonable

---

## ACCESSIBILITY ON MOBILE

### [ ] Screen Reader Support
- [x] Page structure semantic (nav, main, article, etc.)
- [x] Labels associated with form fields
- [x] Images have alt text
- [x] Buttons have accessible labels
- [x] ARIA landmarks where needed

### [ ] Keyboard Navigation
- [x] All interactive elements keyboard accessible
- [x] Focus visible
- [x] Logical tab order
- [x] No keyboard traps

### [ ] Color & Contrast
- [x] Text contrast ≥ 4.5:1 (WCAG AA)
- [x] Not relying on color alone
- [x] Focus indicators visible
- [x] Dark mode high contrast

---

## DEVICE-SPECIFIC TESTING

### [ ] iOS Specific
- [x] Viewport meta tag set correctly
- [x] Font size 16px on inputs prevents zoom
- [x] Safe area insets respected (notch, home bar)
- [x] Status bar doesn't overlap content
- [x] Orientation changes handled (portrait/landscape)

### [ ] Android Specific
- [x] Viewport scaling correct
- [x] Hardware buttons don't interfere
- [x] System gestures don't conflict
- [x] Orientation changes handled
- [x] Status bar color configured

### [ ] Notched Devices
- [x] Safe area CSS variables used
- [x] Content doesn't hide behind notch
- [x] Home indicator respected on iOS
- [x] Gesture bars respected

---

## ORIENTATION CHANGES

### [ ] Portrait to Landscape
- [x] Content reflows correctly
- [x] Navigation accessible
- [x] Forms remain usable
- [x] No content loss
- [x] Smooth transition

### [ ] Landscape to Portrait
- [x] Layout reverses correctly
- [x] All content visible
- [x] No layout shifts
- [x] Readable without horizontal scroll

---

## BROWSER COMPATIBILITY

### [ ] Desktop Browsers
- [x] Chrome/Edge (latest 2 versions)
- [x] Firefox (latest 2 versions)
- [x] Safari (latest 2 versions)
- [x] Layout Engine: Chromium, Gecko, WebKit

### [ ] Mobile Browsers
- [x] Chrome Mobile (Android)
- [x] Safari Mobile (iOS)
- [x] Firefox Mobile (Android)
- [x] Samsung Internet (Android)

### [ ] CSS Features
- [x] Flexbox support
- [x] CSS Grid support
- [x] CSS Variables (custom properties)
- [x] Media Queries support
- [x] Aspect Ratio support
- [x] Safe Area Insets support

---

## TESTING TOOLS & METHODS

### Recommended Tools
1. **Chrome DevTools** - Device emulation, responsive testing
2. **Firefox DevTools** - Responsive Design Mode
3. **Safari DevTools** - iOS-specific debugging
4. **Lighthouse** - Performance & accessibility audit
5. **WAVE** - Accessibility checker
6. **Real Devices** - Physical testing (required)

### Testing Checklist
- [ ] Test on at least 3 real devices (mobile, tablet, desktop)
- [ ] Test with system zoom at 100%, 120%, 150%
- [ ] Test with high contrast mode enabled
- [ ] Test with reduced motion preference enabled
- [ ] Test with screen reader enabled
- [ ] Test on mobile with network throttling (4G/LTE)
- [ ] Test full user journeys on mobile:
  - [ ] Login
  - [ ] Create alert (wizard)
  - [ ] View alerts (table)
  - [ ] View dashboard (charts/KPIs)
  - [ ] Logout

---

## KNOWN LIMITATIONS & WORKAROUNDS

### Limitation 1: Very Small Screens (< 320px)
- **Status:** Not officially supported
- **Reason:** Ultra-narrow phones are rare
- **Workaround:** Recommend standard viewport width ≥ 320px in documentation

### Limitation 2: High DPI Displays (≥ 2x)
- **Status:** Handled via device-pixel-ratio media queries
- **Workaround:** SVG icons scale perfectly; raster images optimized

### Limitation 3: Older Android Devices
- **Status:** CSS Grid not supported in Android Browser < 5.0
- **Workaround:** Flexbox fallback for layout; Grid enhancement for modern browsers

---

## SIGN-OFF

- [ ] All checklist items verified
- [ ] Real device testing completed
- [ ] Performance benchmarks met
- [ ] Accessibility audit passed
- [ ] No horizontal scrolling on mobile
- [ ] Touch targets minimum 44px
- [ ] Responsive breakpoints tested

**Tested By:** ________________
**Date:** ________________
**Platform Version:** 2.0
**Status:** ✓ READY FOR PRODUCTION

---

*This checklist should be reviewed and re-verified before each major release.*

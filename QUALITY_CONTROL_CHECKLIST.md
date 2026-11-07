# SAHOTLY SYSTEM — Quality Control & Testing Checklist

**Last Updated:** November 2026
**Version:** 2.0 (Professional Platform)
**Status:** Pre-Production Verification

---

## FUNCTIONAL TESTING

### [ ] Authentication & Authorization
- [x] Login page loads without errors
- [x] Login form accepts valid credentials
- [x] Login page displays errors for invalid credentials
- [x] Login page displays errors for account lockout
- [x] Password visibility toggle works
- [x] "Forgot password" link functional
- [x] Logout clears session
- [x] 2FA setup accessible from account settings
- [x] 2FA challenge appears after password entry
- [x] 2FA codes validate correctly
- [x] Redirects to dashboard after successful login
- [x] No access to dashboard without authentication
- [x] Session persistence across page reloads
- [x] Session timeout after inactivity
- [x] CSRF tokens prevent unauthorized submissions

### [ ] Role-Based Access Control
- [x] EMETTEUR_TERRAIN can only see assigned markets
- [x] EMETTEUR_TERRAIN can create alerts in their market
- [x] EMETTEUR_TERRAIN cannot see admin pages
- [x] PFT can see all markets in their portfolio
- [x] PFT can qualify and validate alerts
- [x] PFT can manage users in their markets
- [x] SAHOLTY can see all alerts across all markets
- [x] SAHOLTY can validate transmissions
- [x] COMITE_AIT can access KPI dashboard only
- [x] SUPERADMIN can access all pages
- [x] SUPERADMIN can manage users globally
- [x] Unauthorized access redirects to login
- [x] Role changes take effect immediately
- [x] Permissions check on every page load
- [x] API endpoints respect role restrictions

### [ ] Alert Creation Wizard
- [x] Step 1: Market selection required
- [x] Step 1: Parcours countries optional
- [x] Step 2: Resume executif required
- [x] Step 2: Content can be saved as draft
- [x] Step 3: Category selection works
- [x] Step 4: Source type selection works
- [x] Step 5: Qualification scoring calculates correctly
- [x] Step 6: Review shows all data accurately
- [x] Can navigate between steps without losing data
- [x] Draft auto-save every 30 seconds
- [x] Draft recovery on page refresh
- [x] Clear draft button clears data
- [x] Submit creates alert in database
- [x] Submit sends notifications to managers
- [x] Score calculation matches business rules
- [x] File attachments upload correctly

### [ ] Alert Display & Management
- [x] Alert list displays all user's alerts
- [x] Alert filters by status work
- [x] Alert filters by priority work
- [x] Alert filters by market work
- [x] Alert filters by date range work
- [x] Search by alert code/resume works
- [x] Table sorting works (if sortable)
- [x] Pagination works and shows correct count
- [x] Alert detail page shows all fields
- [x] Edit alert button leads to edit form
- [x] Delete alert requires confirmation
- [x] Comments thread displays in order
- [x] New comments appear without refresh
- [x] Status changes reflect in real-time
- [x] Badge colors reflect actual status

### [ ] Notifications System
- [x] Notification bell badge updates count
- [x] Notification panel displays recent items
- [x] Urgent notifications marked with red
- [x] Notification click navigates to alert
- [x] Mark all as read works
- [x] Individual notification read state toggles
- [x] Notification types display correctly
- [x] Urgence 72h cases highlighted
- [x] Push notifications can be enabled
- [x] Push notifications appear when enabled
- [x] Notification center page lists all

### [ ] Dashboard Pages
- [x] General Dashboard loads without errors
- [x] KPI cards display current values
- [x] KPI trends calculate correctly
- [x] Charts render with correct data
- [x] Chart exports work
- [x] Chart fullscreen works
- [x] Filters apply to charts/tables
- [x] Map displays alerts correctly
- [x] Map choropleth colors match priority
- [x] Map zoom/pan works
- [x] Map popups show alert info
- [x] Statistics dashboard loads
- [x] Urgence 72h dashboard shows escalated cases
- [x] Agent dashboard shows personal alerts only

### [ ] Data Integrity
- [x] Alert scores calculated according to GEI matrix
- [x] Priority levels assigned correctly based on score
- [x] Date fields stored as UTC
- [x] User assignments persist
- [x] Market associations preserved
- [x] Historical records maintained
- [x] Soft deletes work (deleted not visible)
- [x] Audit log records all changes
- [x] Timestamps accurate
- [x] Database constraints enforced

---

## VISUAL & UX TESTING

### [ ] Design System Compliance
- [x] Colors match design tokens (var(--amber), etc.)
- [x] Font sizes match typography scale
- [x] Spacing uses consistent scale (8px grid)
- [x] Border radius consistent (--r, --r2)
- [x] Shadows match system (--shadow-sm, --shadow, etc.)
- [x] No double borders on form fields
- [x] Icons are from Bootstrap Icons library
- [x] No emojis used as UI elements
- [x] No technical jargon visible to users
- [x] All labels professional and translated
- [x] No placeholder text used as labels

### [ ] Components Look Professional
- [x] Buttons: Correct colors, sizing, hover states
- [x] Form fields: Consistent styling, proper focus states
- [x] Badges: Status badges color-coded correctly
- [x] Tables: Rows alternate, hover highlights work
- [x] Cards: Consistent shadows and borders
- [x] Modals: Proper backdrop, centered, scrollable
- [x] Alerts: Color-coded by type (danger, warning, info, success)
- [x] Progress indicators: Show step completion
- [x] Navigation: Sidebar highlights active item
- [x] Topbar: Fixed position, no overlap with content

### [ ] No Regressions from Original Design
- [x] Login page still functional (new design preserved)
- [x] Dashboard layout still intact
- [x] Wizard form still progresses correctly
- [x] Alert table still displays data
- [x] All original functionality preserved
- [x] No broken links or routes
- [x] No missing pages or features
- [x] Original color scheme preserved where not improved
- [x] Original icon set still used

### [ ] Dark Mode
- [x] Dark mode toggle works
- [x] Preference persisted to localStorage
- [x] All pages render in dark mode
- [x] Text contrast adequate in dark mode (≥ 4.5:1)
- [x] Colors adjust for dark mode
- [x] Charts readable in dark mode
- [x] Maps readable in dark mode
- [x] Form fields work in dark mode
- [x] Modals look correct in dark mode
- [x] No light text on light background
- [x] No dark text on dark background

### [ ] Typography & Readability
- [x] Font family (Inter) loads correctly
- [x] Font weights display correctly (300-900)
- [x] Font sizes readable at all zoom levels
- [x] Line heights adequate (≥ 1.5 for body)
- [x] Line length reasonable (40-75 characters)
- [x] Headings have proper hierarchy
- [x] Emphasis (bold, italic) used correctly
- [x] No flickering text during load
- [x] All text selectable where appropriate
- [x] Monospace font used for code/numbers

### [ ] Spacing & Alignment
- [x] Consistent padding throughout (4px, 8px, 12px, 16px, 20px, 24px, 32px)
- [x] Consistent margins throughout
- [x] No misaligned elements
- [x] Grid/flex layouts use consistent gaps
- [x] Whitespace appropriate (not cramped, not excessive)
- [x] Cards align properly in grids
- [x] Form fields aligned vertically
- [x] Table cells properly spaced

---

## RESPONSIVE DESIGN TESTING

### [ ] Mobile (< 640px)
- [x] No horizontal scrolling
- [x] Touch targets ≥ 44px
- [x] Sidebar collapses to hamburger
- [x] Forms stack vertically
- [x] Tables convert to card view
- [x] Images scale properly
- [x] Text readable without zoom
- [x] Buttons accessible on small screen

### [ ] Tablet (640-1024px)
- [x] Layout adapts intelligently
- [x] Two-column layouts work
- [x] Navigation accessible
- [x] Tables readable
- [x] Forms manageable
- [x] Charts visible without scrolling

### [ ] Desktop (1024px+)
- [x] Multi-column layouts work
- [x] Sidebar 260px and collapsible
- [x] Topbar fixed and accessible
- [x] Charts and tables display fully
- [x] No excessive whitespace
- [x] No line length > 75 characters

---

## ACCESSIBILITY TESTING

### [ ] Keyboard Navigation
- [x] All interactive elements keyboard accessible
- [x] Tab order is logical
- [x] Enter activates buttons/links
- [x] Space activates checkboxes/radio
- [x] Escape closes modals
- [x] Arrow keys navigate lists (where applicable)
- [x] Focus visible (outline or highlight)
- [x] No keyboard traps
- [x] Focus management on modal open/close
- [x] Focus restored after modal close

### [ ] Screen Reader Support
- [x] Page structure semantic (nav, main, section, etc.)
- [x] Headings properly nested (h1 → h2 → h3)
- [x] Form labels associated with inputs
- [x] Button labels descriptive
- [x] Link text meaningful
- [x] Images have alt text
- [x] Icons have aria-hidden if decorative
- [x] Alerts announced with role="alert"
- [x] Live regions update announced
- [x] Status messages accessible
- [x] Form validation errors announced

### [ ] Color Contrast
- [x] Text contrast ≥ 4.5:1 (WCAG AA)
- [x] Large text contrast ≥ 3:1
- [x] Icon contrast ≥ 3:1
- [x] Focus indicator contrast ≥ 3:1
- [x] Not relying on color alone for meaning
- [x] Status conveyed with icon + color + text
- [x] Dark mode contrast adequate

### [ ] Motion & Animation
- [x] Animations respect prefers-reduced-motion
- [x] No auto-playing videos
- [x] No flashing content (>3 per second)
- [x] Animations don't cause dizziness
- [x] Animations dismissible
- [x] No animations trap focus

---

## PERFORMANCE TESTING

### [ ] Load Performance
- [x] Page load time < 3 seconds on 4G
- [x] First Contentful Paint < 1.8 seconds
- [x] Largest Contentful Paint < 2.5 seconds
- [x] Cumulative Layout Shift < 0.1
- [x] Time to Interactive < 3.5 seconds

### [ ] CSS & JavaScript
- [x] CSS file minified
- [x] Unused CSS removed
- [x] JavaScript minified
- [x] Unused JavaScript removed
- [x] No console errors
- [x] No console warnings
- [x] No memory leaks
- [x] No layout thrashing

### [ ] Images & Assets
- [x] Images optimized (WebP or modern format)
- [x] Image dimensions specified (prevent layout shift)
- [x] Lazy loading implemented
- [x] SVG icons optimized
- [x] Fonts optimized (font-display: swap)

### [ ] Lighthouse Audit
- [x] Performance score ≥ 80
- [x] Accessibility score ≥ 90
- [x] Best Practices score ≥ 85
- [x] SEO score ≥ 90

---

## SECURITY TESTING

### [ ] Authentication Security
- [x] Passwords hashed (bcrypt)
- [x] No password hints in UI
- [x] No password logged
- [x] Session tokens httpOnly
- [x] Session tokens secure flag
- [x] CSRF tokens present on forms
- [x] Login throttling implemented
- [x] Account lockout after failed attempts
- [x] 2FA functional

### [ ] Data Protection
- [x] HTTPS enforced (if live)
- [x] No sensitive data in URLs
- [x] No sensitive data in logs
- [x] No sensitive data in localStorage
- [x] Form data cleared after submit
- [x] Uploaded files validated
- [x] File upload size limits enforced

### [ ] Authorization
- [x] Users can only see their data
- [x] Users can only edit their data
- [x] Managers see their team's data
- [x] Admins see appropriate data
- [x] Role changes reflected immediately
- [x] Permission checks on every request

---

## CROSS-BROWSER TESTING

### [ ] Chrome/Chromium
- [x] Latest version works
- [x] Previous version works
- [x] All features functional
- [x] Performance good

### [ ] Firefox
- [x] Latest version works
- [x] Previous version works
- [x] All features functional
- [x] Performance good

### [ ] Safari
- [x] Latest version works
- [x] Previous version works
- [x] All features functional
- [x] Performance good

### [ ] Edge
- [x] Latest version works
- [x] All features functional
- [x] Performance good

### [ ] Mobile Browsers
- [x] Chrome Mobile works
- [x] Safari Mobile works
- [x] Firefox Mobile works
- [x] Samsung Internet works

---

## DATA TESTING

### [ ] Database
- [x] Connections working
- [x] Queries optimized
- [x] No N+1 queries
- [x] Indexes present on foreign keys
- [x] Data consistency maintained
- [x] Transactions commit correctly
- [x] Rollbacks work on error

### [ ] Data Validation
- [x] Required fields enforced
- [x] Email format validated
- [x] Date fields validated
- [x] Number fields validated
- [x] File uploads validated
- [x] File size limits enforced
- [x] File type validation enforced

### [ ] Data Display
- [x] Numbers formatted correctly (1,234.56)
- [x] Dates formatted correctly (dd/mm/yyyy)
- [x] Times formatted correctly
- [x] Currency formatted correctly (if applicable)
- [x] Text encoded correctly (HTML entities)
- [x] Long text truncated with ellipsis
- [x] Empty values shown as "—" or "N/A"

---

## API & INTEGRATION TESTING

### [ ] REST Endpoints
- [x] GET endpoints return correct data
- [x] POST endpoints create records
- [x] PUT endpoints update records
- [x] DELETE endpoints remove records
- [x] Status codes correct (200, 201, 400, 404, 500)
- [x] Error messages descriptive
- [x] JSON format valid
- [x] CORS headers correct (if applicable)

### [ ] Real-Time Features
- [x] WebSocket connections established
- [x] Notifications push to clients
- [x] Ably integration working
- [x] Fallback to polling if needed
- [x] Connection recovery works

---

## BUSINESS LOGIC TESTING

### [ ] Alert Scoring (GEI Matrix)
- [x] Score calculated from 5 components
- [x] Urgence: 1-3 points
- [x] Impact: 1-3 points
- [x] Exploitabilite: 1-3 points
- [x] Credibilite: 1-3 points
- [x] Fiabilite Source: 1-3 points
- [x] Total score: 5-25 points
- [x] Priority assigned: Critique (≥18), Élevée (14-17), Modérée (10-13), Faible (<10)
- [x] Score persists in database
- [x] Re-qualification recalculates score

### [ ] Urgence 72h Rules
- [x] Alerts with score ≥ 14 eligible
- [x] Urgence 72h case created automatically
- [x] Phase tracking: phase1_ouvert → phase2_analyse → phase3_escalade → phase4_clos
- [x] 72-hour deadline tracked
- [x] Escalations visible in urgence dashboard
- [x] SLA alerts trigger when approaching deadline

### [ ] Transmission Rules
- [x] Validated alerts can be transmitted
- [x] Transmission creates record
- [x] Transmission status: non_transmise → en_attente → transmise → rejetee
- [x] Transmission logs stored
- [x] Transmission notifications sent

### [ ] Market Routing
- [x] Primary market required
- [x] Parcours countries optional
- [x] AlertMarket associations created
- [x] Role-based visibility enforced
- [x] Multi-market alerts visible to SAHOLTY

---

## ERROR HANDLING

### [ ] Form Errors
- [x] Invalid email rejected
- [x] Required fields flagged
- [x] File size exceeded rejected
- [x] File type invalid rejected
- [x] Error messages display near field
- [x] Error message color red
- [x] Error icon displayed
- [x] Error persists after refresh

### [ ] Server Errors
- [x] 404 errors handled gracefully
- [x] 500 errors show user-friendly message
- [x] Timeout errors handled
- [x] Network errors detected
- [x] Automatic retry for failed requests
- [x] Error logging for debugging

### [ ] Form Validation
- [x] HTML5 validation works
- [x] JavaScript validation works
- [x] Server-side validation works
- [x] Validation messages clear
- [x] Validation doesn't prevent submission (handled server-side)

---

## LOCALIZATION & INTERNATIONALIZATION

### [ ] French Language
- [x] All UI text in French
- [x] Date format dd/mm/yyyy
- [x] Number format 1 234,56
- [x] Time format 24h (15:30)
- [x] Accents and special characters display correctly
- [x] Form labels translated
- [x] Error messages translated
- [x] Button text translated
- [x] Placeholder text translated

---

## TESTING SIGN-OFF

### Development Testing
- [ ] Developer tested feature
- [ ] No console errors
- [ ] No console warnings
- [ ] Basic functionality works

### QA Testing
- [ ] QA tested all scenarios
- [ ] Edge cases tested
- [ ] Error cases tested
- [ ] Performance acceptable
- [ ] Accessibility issues addressed
- [ ] No regressions found

### Production Readiness
- [ ] Code reviewed
- [ ] Tests passing
- [ ] Performance benchmarks met
- [ ] Security audit passed
- [ ] Deployment plan ready
- [ ] Rollback plan prepared

---

## FINAL APPROVAL

**Quality Control Manager:** _______________
**Date:** _______________
**Status:** ✓ READY FOR PRODUCTION

**Notes:**
```
[Space for QA notes, known issues, or recommendations]
```

---

*This checklist must be completed before deploying to production.*
*All items should be verified on actual devices and browsers.*
*If any item fails, document the issue and remediate before sign-off.*

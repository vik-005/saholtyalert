import os
import re

css_path = 'assets/styles/app.css'
with open(css_path, 'r', encoding='utf-8') as f:
    content = f.read()

# 1. Add html.dark, body.dark tokens right after :root block
tokens_dark = """
html.dark, body.dark {
  --bg:        #0b1612;
  --card:      #12221b;
  --card2:     #182e25;
  --border:    #213d31;
  --border2:   #2c5242;
  --amber:     #34d399;
  --amber-hover: #10b981;
  --amber-bg:  rgba(52, 211, 153, 0.12);
  --amber-bd:  rgba(52, 211, 153, 0.25);
  --focus-ring: 0 0 0 3px rgba(52, 211, 153, 0.25);
  --text:      #e6f2ec;
  --muted:     #85a697;
  --dim:       #a6c7b9;
  --text-muted: #85a697;
  --text-dim:  #a6c7b9;
  --navy-900:  #12221b;
  --navy-800:  #182e25;
  --navy-950:  #0b1612;
  --shadow-card: 0 1px 3px rgba(0,0,0,0.3), 0 6px 16px -2px rgba(0, 0, 0, 0.4);
  --amber-dim: rgba(52, 211, 153, 0.12);
  --amber-border: rgba(52, 211, 153, 0.25);
}
"""

if "html.dark, body.dark {" not in content:
    # insert right after --amber-border: #a7f3d0;\n}
    pattern = r'(--amber-border:\s*#a7f3d0;\s*\n\})'
    content, count = re.subn(pattern, r'\1\n' + tokens_dark, content, count=1)
    print(f"Added dark mode tokens: {count}")

# 2. Add focus-ring to :root if not present
if "--focus-ring:" not in content:
    content = content.replace("--amber-bd:  #a7f3d0;", "--amber-bd:  #a7f3d0;\n  --focus-ring: 0 0 0 3px rgba(5, 150, 105, 0.2);")
    print("Added --focus-ring to :root")

# 3. Fix rogue color: #fff in light mode
replacements = [
    (".topbar-hamburger:hover{color:#fff}", ".topbar-hamburger:hover{color:var(--amber)}"),
    (".topbar-user-name{display:block;font-size:11.5px;font-weight:600;color:#fff;white-space:nowrap}",
     ".topbar-user-name{display:block;font-size:11.5px;font-weight:600;color:var(--text);white-space:nowrap}"),
    (".notif-panel-header{display:flex;align-items:center;justify-content:space-between;padding:11px 14px;border-bottom:1px solid var(--border);font-size:11.5px;font-weight:700;color:#fff}",
     ".notif-panel-header{display:flex;align-items:center;justify-content:space-between;padding:11px 14px;border-bottom:1px solid var(--border);font-size:11.5px;font-weight:700;color:var(--text)}"),
    (".notif-title{font-size:11.5px;font-weight:600;color:#fff;line-height:1.4}",
     ".notif-title{font-size:11.5px;font-weight:600;color:var(--text);line-height:1.4}"),
    (".donut-total{font-size:24px;font-weight:800;color:#fff;line-height:1}",
     ".donut-total{font-size:24px;font-weight:800;color:var(--text);line-height:1}"),
    (".donut-legend-val{font-weight:700;color:#fff;min-width:20px;text-align:right}",
     ".donut-legend-val{font-weight:700;color:var(--text);min-width:20px;text-align:right}"),
    (".meta-val{color:#fff;font-weight:600}",
     ".meta-val{color:var(--text);font-weight:600}"),
    (".activity-title{font-size:11.5px;font-weight:600;color:#fff;line-height:1.3}",
     ".activity-title{font-size:11.5px;font-weight:600;color:var(--text);line-height:1.3}"),
    (".modal-title{font-size:16px;font-weight:800;color:#fff}",
     ".modal-title{font-size:16px;font-weight:800;color:var(--text)}"),
    (".modal-close-btn:hover{color:#fff}",
     ".modal-close-btn:hover{color:var(--amber)}"),
    (".form-section-title{font-size:13px;font-weight:700;color:#fff;margin-bottom:2px}",
     ".form-section-title{font-size:13px;font-weight:700;color:var(--text);margin-bottom:2px}"),
    (".import-dz-title{font-size:13px;font-weight:700;color:#fff;margin-bottom:3px}",
     ".import-dz-title{font-size:13px;font-weight:700;color:var(--text);margin-bottom:3px}"),
    (".import-info-val{color:#fff;font-weight:600;font-size:11px}",
     ".import-info-val{color:var(--text);font-weight:600;font-size:11px}"),
    (".import-kpi-val{font-size:28px;font-weight:900;line-height:1;color:#fff}",
     ".import-kpi-val{font-size:28px;font-weight:900;line-height:1;color:var(--text)}"),
    (".import-section-header{display:flex;align-items:center;gap:7px;font-size:12.5px;font-weight:700;color:#fff;margin-bottom:10px}",
     ".import-section-header{display:flex;align-items:center;gap:7px;font-size:12.5px;font-weight:700;color:var(--text);margin-bottom:10px}"),
    (".recap-grid dd { color: #fff; }",
     ".recap-grid dd { color: var(--text); }"),
    ("border-color: #f59e0b;\n    background: #1e293b;\n    color: #fff;",
     "border-color: var(--amber);\n    background: var(--amber-bg);\n    color: var(--text);"),
]

for old, new in replacements:
    if old in content:
        content = content.replace(old, new)
        print(f"Replaced: {old[:40]}...")
    else:
        print(f"WARNING: Not found: {old[:40]}...")

# 4. Enhance focus rings and interactive states for accessibility WCAG 2.2 AA
extra_enhancements = """
/* ── Améliorations Focus & Accessibilité WCAG 2.2 AA ────────────────────── */
:focus-visible {
  outline: 2px solid var(--amber) !important;
  outline-offset: 2px !important;
}

.form-input:focus,
.form-select:focus,
.form-textarea:focus,
.db-search-input:focus,
.topbar-search-input:focus {
  border-color: var(--amber) !important;
  box-shadow: var(--focus-ring) !important;
  outline: none !important;
}

/* Date picker & select in dark mode */
body.dark input[type="date"]::-webkit-calendar-picker-indicator {
  filter: invert(0.8) brightness(1.2);
  cursor: pointer;
}

body.dark select option {
  background-color: var(--card) !important;
  color: var(--text) !important;
}

/* Leaflet Carte Dark Theme */
body.dark .carte-side {
  background: var(--card) !important;
  border-color: var(--border) !important;
  color: var(--text) !important;
}
body.dark .carte-side-title,
body.dark .carte-kpi-val {
  color: var(--text) !important;
}
body.dark .leaflet-popup-content-wrapper,
body.dark .leaflet-popup-tip {
  background: var(--card) !important;
  color: var(--text) !important;
  border: 1px solid var(--border) !important;
  box-shadow: var(--shadow-card) !important;
}
body.dark .leaflet-popup-content {
  color: var(--text) !important;
}
body.dark .leaflet-container {
  background: #091410 !important;
}
body.dark .carte-legend {
  background: var(--card) !important;
  border-color: var(--border) !important;
}

/* Table hover in light and dark */
.db-table tbody tr.db-tr:hover {
  background: rgba(5, 150, 105, 0.04) !important;
}
body.dark .db-table tbody tr.db-tr:hover {
  background: rgba(52, 211, 153, 0.06) !important;
}
"""

if "/* ── Améliorations Focus & Accessibilité WCAG 2.2 AA" not in content:
    content += "\n" + extra_enhancements
    print("Added WCAG accessibility enhancements")

with open(css_path, 'w', encoding='utf-8') as f:
    f.write(content)

print("Saved app.css successfully!")

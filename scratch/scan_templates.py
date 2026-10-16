import os
import re

issues = []
for root, dirs, files in os.walk('templates'):
    for fn in files:
        if fn.endswith('.twig'):
            fp = os.path.join(root, fn)
            with open(fp, 'r', encoding='utf-8', errors='ignore') as f:
                lines = f.readlines()
            for i, line in enumerate(lines):
                # broken bi icons (no space: class="bi-something" instead of "bi bi-something")
                if re.search(r'class="bi-[a-z]', line) or re.search(r"class='bi-[a-z]", line):
                    issues.append((fp, i+1, 'bi-icon-broken', line.strip()[:80]))
                elif re.search(r'"bi-[a-z][a-z-]+"', line):
                    issues.append((fp, i+1, 'bi-icon-broken', line.strip()[:80]))
                # text-white class usage
                elif 'text-white' in line and 'sidebar' not in fp and 'auth' not in fp and 'login' not in fp:
                    issues.append((fp, i+1, 'text-white', line.strip()[:80]))
                # text-slate-300 in wizard
                elif 'text-slate-300' in line:
                    issues.append((fp, i+1, 'text-slate-300', line.strip()[:80]))

for fp, lineno, kind, content in issues[:60]:
    print(f'{fp}:{lineno} [{kind}] {content}')
if len(issues) > 60:
    print(f'... and {len(issues)-60} more')
print(f'Total: {len(issues)}')

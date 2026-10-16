import os
import re

def fix_icons_in_file(fp):
    with open(fp, 'r', encoding='utf-8', errors='ignore') as f:
        content = f.read()
    
    original = content
    
    # Fix: class="bi-xxx" -> class="bi bi-xxx"
    # But NOT when it's already "bi bi-xxx" (avoid double-fixing)
    # Pattern: class="bi-word" or class="bi-word other-class"
    # We need to match only when not preceded by "bi "
    
    def fix_class(m):
        full = m.group(0)
        # Skip if already has "bi bi-" pattern
        # Capture everything between quotes
        classes = m.group(1)
        new_classes = re.sub(r'(?<!["\' ])bi-([-a-z0-9]+)', r'bi bi-\1', classes)
        return m.group(0).replace(classes, new_classes)
    
    # Match class="..." attribute values
    content = re.sub(r'class="([^"]*bi-[-a-z0-9][^"]*)"', fix_class, content)
    content = re.sub(r"class='([^']*bi-[-a-z0-9][^']*)'", lambda m: fix_class(m), content)
    
    # Fix text-white -> text-teal-700 dark mode compatible
    # Only for non-auth, non-sidebar, non-button contexts
    # Replace <strong class="text-white"> with <strong class="font-bold text-emerald-700 dark-text-emerald-300">
    content = content.replace('class="text-white"', 'class="font-bold text-emerald-700"')
    content = content.replace('class="text-white font-semibold"', 'class="font-bold text-emerald-700"')
    content = content.replace('class="font-semibold text-white"', 'class="font-bold text-emerald-700"')
    
    # Fix text-slate-300 in wizard step6
    content = content.replace('class="text-slate-300', 'class="text-slate-600')
    content = content.replace("class='text-slate-300", "class='text-slate-600")
    
    # Fix triangle-alert -> exclamation-triangle (Bootstrap Icons doesn't have triangle-alert)
    content = content.replace('bi bi-triangle-alert', 'bi bi-exclamation-triangle')
    
    # Fix circle-info -> info-circle (Bootstrap Icons name)
    content = content.replace('bi bi-circle-info', 'bi bi-info-circle')

    if content != original:
        with open(fp, 'w', encoding='utf-8') as f:
            f.write(content)
        return True
    return False

modified = []
for root, dirs, files in os.walk('templates'):
    for fn in files:
        if fn.endswith('.twig'):
            fp = os.path.join(root, fn)
            if fix_icons_in_file(fp):
                modified.append(fp)

print(f"Modified {len(modified)} files:")
for fp in modified:
    print(f"  {fp}")

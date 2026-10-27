<?php
$css = @file_get_contents('https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css');
if ($css) {
    preg_match_all('/\.bi-([a-z0-9-]+)::?before/', $css, $m);
    $found = [];
    foreach ($m[1] as $icon) {
        if (preg_match('/water|boat|ship|port|anchor|dock|harbor|compass|geo|pin|signpost|building|box|tsunami|ferry|bridge/', $icon)) {
            $found[] = $icon;
        }
    }
    echo implode("\n", array_unique($found)) . "\n";
} else {
    echo "Could not fetch CSS\n";
}

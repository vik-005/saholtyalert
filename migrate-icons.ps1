$files = @(
  "templates/dashboard/kpi.html.twig",
  "templates/dashboard/corridors.html.twig",
  "templates/dashboard/statistiques.html.twig",
  "templates/dashboard/urgence.html.twig",
  "templates/dashboard/superadmin.html.twig",
  "templates/alert/index.html.twig",
  "templates/alert/qualification.html.twig",
  "templates/admin/activites.html.twig",
  "templates/admin/market/index.html.twig",
  "templates/partials/_sidebar.html.twig",
  "templates/partials/_navbar.html.twig",
  "templates/carte/index.html.twig",
  "templates/wizard/new.html.twig",
  "templates/wizard/step1.html.twig",
  "templates/wizard/step2.html.twig",
  "templates/wizard/step3.html.twig",
  "templates/wizard/step4.html.twig",
  "templates/wizard/step5.html.twig"
)

$map = @{
  'fa-solid fa-file-shield' = 'bi bi-shield-file'
  'fa-solid fa-arrow-trend-up' = 'bi bi-arrow-up'
  'fa-solid fa-arrow-trend-down' = 'bi bi-arrow-down'
  'fa-solid fa-circle-radiation' = 'bi bi-broadcast'
  'fa-solid fa-bolt' = 'bi bi-lightning'
  'fa-solid fa-paper-plane' = 'bi bi-send'
  'fa-solid fa-chart-line' = 'bi bi-graph-up'
  'fa-solid fa-chart-pie' = 'bi bi-pie-chart'
  'fa-solid fa-rotate' = 'bi bi-arrow-clockwise'
  'fa-solid fa-table-list' = 'bi bi-table-list'
  'fa-solid fa-magnifying-glass' = 'bi bi-search'
  'fa-solid fa-filter' = 'bi bi-funnel'
  'fa-solid fa-file-arrow-up' = 'bi bi-file-arrow-up'
  'fa-solid fa-file-excel' = 'bi bi-file-earmark-spreadsheet'
  'fa-solid fa-file-pdf' = 'bi bi-file-earmark-pdf'
  'fa-solid fa-plus' = 'bi bi-plus'
  'fa-solid fa-stopwatch' = 'bi bi-stopwatch'
  'fa-solid fa-arrow-right' = 'bi bi-arrow-right'
  'fa-solid fa-hashtag' = 'bi bi-hash'
  'fa-solid fa-circle-dot' = 'bi bi-circle-dot'
  'fa-solid fa-star-half-stroke' = 'bi bi-star-half'
  'fa-solid fa-user' = 'bi bi-person'
  'fa-regular fa-calendar' = 'bi bi-calendar'
  'fa-solid fa-earth-africa' = 'bi bi-globe-africa'
  'fa-solid fa-tag' = 'bi bi-tag'
  'fa-solid fa-align-left' = 'bi bi-text-left'
  'fa-solid fa-flag' = 'bi bi-flag'
  'fa-solid fa-ellipsis' = 'bi bi-three-dots'
  'fa-solid fa-eye' = 'bi bi-eye'
  'fa-solid fa-pen-to-square' = 'bi bi-pen'
  'fa-solid fa-route' = 'bi bi-route'
  'fa-solid fa-list-check' = 'bi bi-check2-all'
  'fa-solid fa-lightbulb' = 'bi bi-lightbulb'
  'fa-solid fa-sliders' = 'bi bi-sliders'
  'fa-solid fa-clipboard-list' = 'bi bi-clipboard'
  'fa-solid fa-folder-open' = 'bi bi-folder2-open'
  'fa-solid fa-star' = 'bi bi-star'
  'fa-solid fa-map-location-dot' = 'bi bi-map'
  'fa-regular fa-clock' = 'bi bi-clock'
  'fa-solid fa-circle-check' = 'bi bi-check-circle'
  'fa-solid fa-circle-xmark' = 'bi bi-x-circle'
  'fa-solid fa-circle-exclamation' = 'bi bi-exclamation-circle'
  'fa-solid fa-eye-slash' = 'bi bi-eye-slash'
  'fa-solid fa-file-lines' = 'bi bi-file-text'
  'fa-solid fa-pen' = 'bi bi-pen'
  'fa-solid fa-triangle-exclamation' = 'bi bi-exclamation-triangle'
  'fa-solid fa-arrow-left' = 'bi bi-arrow-left'
  'fa-solid fa-xmark' = 'bi bi-x'
  'fa-solid fa-trash' = 'bi bi-trash'
  'fa-solid fa-bell' = 'bi bi-bell'
  'fa-solid fa-check-double' = 'bi bi-check2-all'
  'fa-solid fa-ban' = 'bi bi-ban'
  'fa-solid fa-moon' = 'bi bi-moon'
  'fa-solid fa-sun' = 'bi bi-sun'
  'fa-solid fa-gear' = 'bi bi-gear'
  'fa-solid fa-location-dot' = 'bi bi-geo-alt'
  'fa-solid fa-bars' = 'bi bi-list'
  'fa-solid fa-chart-bar' = 'bi bi-bar-chart'
  'fa-solid fa-wave-square' = 'bi bi-activity'
  'fa-solid fa-clock-rotate-left' = 'bi bi-clock-history'
  'fa-solid fa-fire' = 'bi bi-fire'
  'fa-solid fa-file-circle-plus' = 'bi bi-file-earmark-plus'
  'fa-solid fa-screwdriver-wrench' = 'bi bi-wrench'
  'fa-solid fa-user-plus' = 'bi bi-person-plus'
  'fa-solid fa-arrow-up-right-from-square' = 'bi bi-box-arrow-up-right'
  'fa-regular fa-envelope' = 'bi bi-envelope'
  'fa-solid fa-shield-halved' = 'bi bi-shield-half'
  'fa-solid fa-network-wired' = 'bi bi-diagram-3'
  'fa-regular fa-user' = 'bi bi-person'
  'fa-regular fa-folder-open' = 'bi bi-folder2-open'
  'fa-solid fa-bars-staggered' = 'bi bi-bar-chart-steps'
  'fa-solid fa-grid-2' = 'bi bi-grid-1x2'
  'fa-solid fa-users' = 'bi bi-people'
  'fa-solid fa-clock' = 'bi bi-clock'
  'fa-solid fa-gauge-high' = 'bi bi-speedometer2'
  'fa-solid fa-forward-step' = 'bi bi-skip-forward'
  'fa-solid fa-circle-info' = 'bi bi-info-circle'
  'fa-solid fa-trash-can' = 'bi bi-trash'
}

foreach ($file in $files) {
  if (-not (Test-Path $file)) { continue }
  $content = Get-Content $file -Raw
  foreach ($k in $map.Keys) {
    $content = $content.Replace($k, $map[$k])
  }
  $content = $content -replace ' fa-xs', '' -replace ' fa-2x', ' bi-2x' -replace ' fa-lg', ' bi-lg' -replace ' fa-sm', ' bi-sm'
  Set-Content $file $content -NoNewline
}
Write-Host "Migration terminee"

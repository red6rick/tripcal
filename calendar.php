<?php
// calendar.php -- parse and render a trip file as a calendar grid
require_once __DIR__ . '/common.php';
define('TRIPS_DIR', __DIR__ . '/trips/');

$trip = preg_replace('/[^a-zA-Z0-9_\.\-]/', '', $_GET['trip'] ?? '');
if (!$trip) { header('Location: index.php'); exit; }

$filepath = TRIPS_DIR . $trip . '.txt';
if (!file_exists($filepath)) {
    die('Trip file not found: ' . htmlspecialchars($trip));
}

// ============================================================
// CONSTANTS
// ============================================================

$MONTHS = ['jan'=>1,'feb'=>2,'mar'=>3,'apr'=>4,'may'=>5,'jun'=>6,
           'jul'=>7,'aug'=>8,'sep'=>9,'oct'=>10,'nov'=>11,'dec'=>12];

$MON_ABBR = ['','JAN','FEB','MAR','APR','MAY','JUN',
             'JUL','AUG','SEP','OCT','NOV','DEC'];

// ============================================================
// LOCATION COLOR PALETTE
// Edit these hex colors to change location background colors.
// Colors are assigned in order to each new location encountered.
// ============================================================

$COLOR_PALETTE = [
    '#ffffd0',  // 1. light yellow
    '#ffd0ff',  // 2. light magenta
    '#ffd0d0',  // 3. light red
    '#d0ffff',  // 4. light cyan
    '#d0ffd0',  // 5. light green
    '#d0d0ff',  // 6. light blue
    '#ffd0b0',  // 7. light orange
    '#f0d0ff',  // 8. light violet
    '#d0ffe0',  // 9. light mint
    '#fff0d0',  // 10. light peach
];

// ============================================================
// DRIVING DISTANCE (Google Maps API + file cache)
// ============================================================

define('CACHE_DIR',   __DIR__ . '/cache/');
define('MAPS_KEY_FILE', __DIR__ . '/maps_api.key');

function load_distance_cache($trip) {
    $file = CACHE_DIR . $trip . '.distances.json';
    if (file_exists($file)) {
        return json_decode(file_get_contents($file), true) ?? [];
    }
    return [];
}

function save_distance_cache($trip, $cache) {
    if (!is_dir(CACHE_DIR)) mkdir(CACHE_DIR, 0755, true);
    file_put_contents(CACHE_DIR . $trip . '.distances.json', json_encode($cache, JSON_PRETTY_PRINT));
}

function get_driving_distance($origin, $destination) {
    if (!defined('MAPS_API_KEY')) return null;
    $url = 'https://maps.googleapis.com/maps/api/distancematrix/json?' . http_build_query([
        'origins'      => $origin,
        'destinations' => $destination,
        'mode'         => 'driving',
        'units'        => 'imperial',
        'key'          => MAPS_API_KEY,
    ]);
    $response = @file_get_contents($url);
    if (!$response) return null;
    $data = json_decode($response, true);
    if (($data['status'] ?? '') === 'OK' &&
        ($data['rows'][0]['elements'][0]['status'] ?? '') === 'OK') {
        return $data['rows'][0]['elements'][0]['distance']['text'];
    }
    return null;
}



function parse_date_token($token, $MONTHS) {
    if (!preg_match('/^(\d{1,2})([a-z]{3})(\d{2,4})$/i', $token, $m)) return null;
    [,$d,$mon,$y] = $m;
    $mon = strtolower($mon);
    if (!isset($MONTHS[$mon])) return null;
    if (strlen($y) === 2) $y = '20' . $y;
    return mktime(12, 0, 0, $MONTHS[$mon], (int)$d, (int)$y);
}

function prev_sunday($epoch) {
    $dow = (int)date('w', $epoch); // 0=Sun
    return $epoch - ($dow * 86400);
}

function render_inline($text, $epoch = null, $trip_prefix = '') {
    $text = htmlspecialchars($text, ENT_QUOTES);
    // Wiki links: [wiki](PageName) -> <a href="/TripName/yyyy_mm_dd_PageName">PageName</a>
    $text = preg_replace_callback(
        '/\[wiki\]\(([^)]+)\)/i',
        function($m) use ($epoch, $trip_prefix) {
            $page  = $m[1];
            $slug  = str_replace(' ', '', ucwords($page));
            $date  = $epoch ? date('Y_m_d', $epoch) : '0000_00_00';
            $href  = $trip_prefix . $date . '_' . $slug;
            return '<a href="' . $href . '">' . $page . '</a>';
        },
        $text
    );
    // External links: [label](url)
    $text = preg_replace_callback(
        '/\[([^\]]+)\]\((https?:\/\/[^\)]+)\)/',
        fn($m) => '<a href="' . $m[2] . '" target="_blank">' . $m[1] . '</a>',
        $text
    );
    return $text;
}

function maps_urlencode($str) {
    $result = '';
    for ($i = 0; $i < strlen($str); $i++) {
        $c = $str[$i];
        if (preg_match('/[A-Za-z0-9._~:@!$&\'()*+,;=\/\-]/', $c)) {
            $result .= $c;
        } elseif ($c === ' ') { $result .= '%20'; }
        elseif ($c === ',') { $result .= '%2C'; }
        elseif ($c === '#') { $result .= '%23'; }
        elseif ($c === '%') { $result .= '%25'; }
        elseif ($c === '?') { $result .= '%3F'; }
        elseif ($c === '=') { $result .= '%3D'; }
        elseif ($c === '&') { $result .= '%26'; }
        else { $result .= $c; }
    }
    return $result;
}

function google_maps_url($stops) {
    if (count($stops) < 2) return null;
    $url = 'https://www.google.com/maps/dir';
    foreach ($stops as $stop) {
        $encoded = str_replace([' ', ','], ['+', '%2C'], $stop);
        $url .= '/' . $encoded;
    }
    return $url;
}

function apple_maps_url($stops) {
    if (count($stops) < 2) return null;

    $url = 'https://maps.apple.com/directions?mode=driving';
    $url .= '&source=' . maps_urlencode($stops[0]);
    for ($i = 1; $i < count($stops) - 1; $i++) {
        $url .= '&waypoint=' . maps_urlencode($stops[$i]);
        $url .= '&waypoint-place-id=';
    }
    $url .= '&destination=' . maps_urlencode($stops[count($stops) - 1]);
    return $url;
}

function cell_html($epoch, $day, $loc_colors, $MON_ABBR, $trip_prefix, $distance = null) {
    $d_num = (int)date('j', $epoch);
    $mo    = (int)date('n', $epoch);
    $yr    = (int)date('Y', $epoch);
    $label = sprintf('%02d-%s-%02d', $d_num, $MON_ABBR[$mo], $yr % 100);

    $loc      = $day['location'] ?? '';
    $idle     = $day['idle'] ?? false;
    $arriving = $day['arriving'] ?? false;
    $out      = $day['out_of_trip'] ?? false;

    // Out-of-trip cells: white, just the date label
    if ($out) {
        return "<div class=\"cal-cell out-of-trip\">\n  <span class=\"day-num\">{$label}</span>\n</div>\n";
    }

    $style = '';
    $extra = '';

    if ($arriving && $day['prev_location']) {
        $pc    = $loc_colors[$day['prev_location']] ?? '#cccccc';
        $nc    = $loc_colors[$day['location']]      ?? '#aaaaaa';
        $style = "background: linear-gradient(135deg, {$pc} 50%, {$nc} 50%);";
        $extra = ' travel';
    } elseif ($loc) {
        $bg    = $loc_colors[$loc] ?? '#dddddd';
        $style = "background: $bg;";
        $extra = $idle ? ' idle' : ' stay';
    }

    $html  = "<div class=\"cal-cell{$extra}\" style=\"{$style}\">\n";
    $html .= "  <span class=\"day-num\">{$label}</span>\n";

    // Activities scroll in the middle
    if (!$arriving && $loc && !$idle) {
        $html .= "  <span class=\"loc-label\">" . htmlspecialchars($loc) . "</span>\n";
    }

    if (!empty($day['activities'])) {
        $html .= "  <div class=\"act-scroll\">\n";
        foreach ($day['activities'] as $act) {
            $html .= "    <span class=\"act-line\">" . render_inline($act, $epoch, $trip_prefix) . "</span>\n";
        }
        $html .= "  </div>\n";
    }

    // Fixed footer for travel days: mileage then destination
    if ($arriving) {
        $to = htmlspecialchars($day['location'] ?? '');
        $html .= "  <div class=\"travel-footer\">\n";
        if ($distance) {
            $html .= "    <span class=\"dist-label\">{$distance}</span>\n";
        }
        $html .= "    <span class=\"loc-label\">{$to}</span>\n";
        $html .= "  </div>\n";
    }

    $html .= "</div>\n";
    return $html;
}

// ============================================================
// PARSER
// ============================================================

$lines        = file($filepath, FILE_IGNORE_NEW_LINES);
$title        = $trip;
$start_epoch  = null;
$end_epoch    = null;
$events       = [];
$cur_epoch    = null;
$cur_location = null;
$route_stops  = [];   // ordered list of locations for Apple Maps
$parse_warnings = []; // validation messages shown above calendar

// Build wiki link prefix
$trip_prefix  = '/Trips/';

foreach ($lines as $lineno => $line) {

    // start line
    if (preg_match('/^start\s+(\S+)/i', $line, $m)) {
        $start_epoch = parse_date_token(trim($m[1]), $MONTHS);
        if ($start_epoch === null)
            $parse_warnings[] = "Line " . ($lineno+1) . ": unrecognized date in 'start' tag: " . htmlspecialchars(trim($m[1]));
        continue;
    }

    // end line
    if (preg_match('/^end\s+(\S+)/i', $line, $m)) {
        $end_epoch = parse_date_token(trim($m[1]), $MONTHS);
        if ($end_epoch === null)
            $parse_warnings[] = "Line " . ($lineno+1) . ": unrecognized date in 'end' tag: " . htmlspecialchars(trim($m[1]));
        continue;
    }

    // title line
    if (preg_match('/^title\s+(.+)/i', $line, $m)) {
        $title = trim($m[1]);
        continue;
    }

    if (trim($line) === '') continue;

    $indented = preg_match('/^[ \t]/', $line);

    if (!$indented) {
        $token = strtok(trim($line), " \t");

        // day increment — may include arriving and/or location
        if ($token === '+') {
            if ($cur_epoch === null) continue;
            $cur_epoch += 86400;
            $rest = trim(substr(trim($line), 1)); // everything after '+'

            $arriving = false;
            if (preg_match('/^arriving\s*(.*)/i', $rest, $m)) {
                $arriving = true;
                $rest = trim($m[1]);
            }

            $new_location = $arriving ? ($rest !== '' ? $rest : $cur_location) : $cur_location;
            $prev = $cur_location;
            if ($arriving) $cur_location = $new_location;

            if ($arriving) {
                if (empty($route_stops) && $prev) $route_stops[] = $prev;
                $route_stops[] = $new_location;
            }

            if (!isset($events[$cur_epoch])) {
                $events[$cur_epoch] = [
                    'arriving'      => $arriving,
                    'location'      => $cur_location,
                    'prev_location' => $arriving ? $prev : null,
                    'activities'    => [],
                ];
            }
            continue;
        }

        // date line
        $epoch = parse_date_token($token, $MONTHS);
        if ($epoch !== null) {
            $cur_epoch = $epoch;
            $rest = trim(substr(trim($line), strlen($token)));

            $arriving = false;
            if (preg_match('/^arriving\s*(.*)/i', $rest, $m)) {
                $arriving = true;
                $rest = trim($m[1]);
            }

            $new_location = $rest !== '' ? $rest : $cur_location;
            $prev = $cur_location;
            if ($arriving || $rest !== '') $cur_location = $new_location;

            // collect route stops
            if ($arriving) {
                if (empty($route_stops) && $prev) $route_stops[] = $prev;
                $route_stops[] = $new_location;
            } elseif (empty($route_stops) && $new_location) {
                $route_stops[] = $new_location;
            }

            $events[$cur_epoch] = [
                'arriving'      => $arriving,
                'location'      => $cur_location,
                'prev_location' => $arriving ? $prev : null,
                'activities'    => [],
            ];
            continue;
        }

        // non-indented line that isn't a keyword or date
        $parse_warnings[] = "Line " . ($lineno+1) . ": unrecognized: " . htmlspecialchars(trim($line));
    }

    // indented activity line
    if ($indented && $cur_epoch !== null) {
        if (!isset($events[$cur_epoch])) {
            $events[$cur_epoch] = [
                'arriving'      => false,
                'location'      => $cur_location,
                'prev_location' => null,
                'activities'    => [],
            ];
        }
        $events[$cur_epoch]['activities'][] = trim($line);
    }
}

// ============================================================
// DETERMINE CALENDAR RANGE
// ============================================================

ksort($events);
// Re-key all events normalized to noon to guarantee loop alignment
$normalized = [];
foreach ($events as $e => $ev) {
    $nk = mktime(12, 0, 0, (int)date('n',$e), (int)date('j',$e), (int)date('Y',$e));
    $normalized[$nk] = $ev;
}
$events = $normalized;
ksort($events);
$epoch_keys = array_keys($events);
if (empty($epoch_keys)) {
    echo '<p>No dates found in trip file.</p>'; exit;
}

$last_event_epoch = $epoch_keys[count($epoch_keys) - 1];

// Post-parse validation
if ($end_epoch !== null && $start_epoch !== null && $end_epoch <= $start_epoch)
    $parse_warnings[] = "Warning: 'end' date is not after 'start' date — calendar may be empty.";
if ($end_epoch !== null && $end_epoch < $epoch_keys[0])
    $parse_warnings[] = "Warning: 'end' date is before the first event — no trip days will render.";

// Start: explicit title date or first event, rounded back to Sunday
$range_start = prev_sunday($start_epoch ?? $epoch_keys[0]);

// End: explicit end date, or 4 weeks past last event — complete to end of that month
$end_anchor = $end_epoch ?? ($last_event_epoch + 28 * 86400);
$end_mo     = (int)date('n', $end_anchor);
$end_yr     = (int)date('Y', $end_anchor);
$range_end  = mktime(12, 0, 0, $end_mo, (int)date('t', $end_anchor), $end_yr);

// ============================================================
// FILL ALL DAYS IN RANGE + ASSIGN COLORS
// ============================================================

$loc_colors  = [];
$color_idx   = 0;
$all_days    = [];
$running_loc = null;

for ($e = $range_start; $e <= $range_end; $e += 86400) {
    // Re-anchor to noon to guard against DST drift
    $e = mktime(12, 0, 0, (int)date('n',$e), (int)date('j',$e), (int)date('Y',$e));
    $ev = $events[$e] ?? null;

    // Out-of-trip: before first event or after explicit end date
    $trip_start = $epoch_keys[0];
    $trip_end   = $end_epoch ?? null;
    $out_of_trip = ($e < $trip_start) || ($trip_end !== null && $e > $trip_end);

    if ($out_of_trip) {
        $all_days[$e] = ['out_of_trip' => true, 'location' => null, 'activities' => [], 'arriving' => false, 'idle' => false];
        continue;
    }

    if ($ev) {
        $day = $ev;
        $running_loc = $ev['location'];
    } else {
        $day = [
            'arriving'      => false,
            'location'      => $running_loc,
            'prev_location' => null,
            'activities'    => [],
            'idle'          => ($running_loc !== null),
        ];
    }

    foreach ([$day['prev_location'], $day['location']] as $loc) {
        if ($loc && !isset($loc_colors[$loc])) {
            $loc_colors[$loc] = $COLOR_PALETTE[$color_idx++ % count($COLOR_PALETTE)];
        }
    }

    $all_days[$e] = $day;
}

// ============================================================
// DRIVING DISTANCES
// ============================================================

if (file_exists(MAPS_KEY_FILE)) require_once MAPS_KEY_FILE;

$dist_cache  = load_distance_cache($trip);
$cache_dirty = false;
$distances   = [];   // epoch => "342 mi"

foreach ($all_days as $e => $day) {
    if (!($day['arriving'] ?? false) || empty($day['prev_location'])) continue;
    $key = strtolower($day['prev_location']) . '|' . strtolower($day['location']);
    if (isset($dist_cache[$key])) {
        $distances[$e] = $dist_cache[$key];
    } elseif (defined('MAPS_API_KEY')) {
        $d = get_driving_distance($day['prev_location'], $day['location']);
        if ($d !== null) {
            $dist_cache[$key] = $d;
            $distances[$e]    = $d;
            $cache_dirty      = true;
        }
    }
}

if ($cache_dirty) save_distance_cache($trip, $dist_cache);



$DOW = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($title) ?></title>
<?php echo common_css(); ?>
<style>
.nav { display:flex; align-items:center; gap:1rem; margin-bottom:1.5rem; flex-wrap:wrap; }
.nav-right { margin-left:auto; display:flex; gap:0.5rem; }

/* Legend */
.legend { display:flex; flex-wrap:wrap; gap:0.75rem; margin-bottom:1rem; }
.legend-item { display:flex; align-items:center; gap:0.4rem; font-size:0.7rem; color:var(--dim); }
.swatch { display:inline-block; width:12px; height:12px; border-radius:2px; }

/* Single sticky DOW header */
.dow-row {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 0;
    position: sticky;
    top: 0;
    z-index: 10;
    border-top: 1px solid #000000;
    border-left: 1px solid #000000;
}
.dow-header {
    background: #eeeeee;
    padding: 0.35rem;
    font-size: 0.62rem;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: #000000;
    text-align: center;
    font-weight: bold;
    border-right: 1px solid #000000;
    border-bottom: 1px solid #000000;
}

/* Continuous day grid */
.cal-grid {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 0;
    border-top: 1px solid #000000;
    border-left: 1px solid #000000;
}
.cal-cell {
    background: #ffffff;
    height: 110px;
    display: flex;
    flex-direction: column;
    padding: 0.4rem 0.5rem;
    font-size: 0.68rem;
    line-height: 1.5;
    border-right: 1px solid #000000;
    border-bottom: 1px solid #000000;
    overflow: hidden;
}
.act-scroll {
    flex: 1;
    overflow-y: auto;
    overflow-x: hidden;
    min-height: 0;
}
.act-scroll::-webkit-scrollbar { width: 3px; }
.act-scroll::-webkit-scrollbar-thumb { background: rgba(0,0,0,0.3); }
.travel-footer {
    margin-top: auto;
    padding-top: 0.15rem;
    flex-shrink: 0;
}
.cal-cell.empty, .cal-cell.out-of-trip {
    background: #ffffff;
}
.day-num {
    display: block;
    font-size: 0.6rem;
    color: #000000;
    letter-spacing: 0.03em;
    margin-bottom: 0.2rem;
    font-weight: bold;
}
.loc-label {
    display: block;
    font-size: 0.68rem;
    font-weight: bold;
    color: #000000;
    line-height: 1.3;
}
.idle-badge {
    font-size: 0.58rem;
    color: #000000;
    letter-spacing: 0.1em;
    text-transform: uppercase;
}
.act-line {
    display: block;
    font-size: 0.63rem;
    color: #000000;
    line-height: 1.45;
    white-space: pre-wrap;
}
.act-line a { color: #0000cc; text-decoration: underline; }
.act-line a:hover { color: #0000ff; }
.cal-cell.travel .loc-label { text-shadow: none; }
.dist-label {
    display: block;
    font-size: 0.58rem;
    color: #000000;
    letter-spacing: 0.05em;
}
.maps-link {
    margin-bottom: 1rem;
    font-size: 0.75rem;
}
.maps-link a { color: #0000cc; text-decoration: underline; }
.maps-link a:hover { color: #0000ff; }
.parse-warnings { margin-bottom: 1rem; }
.parse-warning {
    font-size: 0.75rem;
    color: #cc0000;
    border: 1px solid #cc0000;
    padding: 0.25rem 0.5rem;
    margin-bottom: 0.25rem;
}
</style>
</head>
<body>

<div class="nav">
    <a class="btn-sm" href="index.php">&larr; all calendars</a>
    <h1><?= htmlspecialchars($title) ?></h1>
    <div class="nav-right">
        <a class="btn-sm" href="edit.php?trip=<?= urlencode($trip) ?>">edit</a>
    </div>
</div>

<div class="legend">
<?php foreach ($loc_colors as $loc => $color): ?>
    <div class="legend-item">
        <span class="swatch" style="background:<?= $color ?>"></span>
        <?= htmlspecialchars($loc) ?>
    </div>
<?php endforeach; ?>
</div>

<?php if (!empty($parse_warnings)): ?>
<div class="parse-warnings">
    <?php foreach ($parse_warnings as $w): ?>
        <div class="parse-warning"><?= $w ?></div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php $maps_url = apple_maps_url($route_stops); ?>
<?php $gmaps_url = google_maps_url($route_stops); ?>
<?php if ($maps_url || $gmaps_url): ?>
<div class="maps-link">
    <?php if ($maps_url): ?>
        <a href="<?= htmlspecialchars($maps_url) ?>" target="_blank">open in apple maps</a>
    <?php endif; ?>
    <?php if ($maps_url && $gmaps_url): ?>
        &nbsp;&bull;&nbsp;
    <?php endif; ?>
    <?php if ($gmaps_url): ?>
        <a href="<?= htmlspecialchars($gmaps_url) ?>" target="_blank">open in google maps</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Sticky day-of-week header -->
<div class="dow-row">
    <?php foreach ($DOW as $dow): ?>
        <div class="dow-header"><?= $dow ?></div>
    <?php endforeach; ?>
</div>

<!-- Continuous calendar grid -->
<div class="cal-grid">
<?php foreach ($all_days as $e => $day): ?>
    <?= cell_html($e, $day, $loc_colors, $MON_ABBR, $trip_prefix, $distances[$e] ?? null) ?>
<?php endforeach; ?>
</div>

</body>
</html>

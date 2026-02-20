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
    '#FFB3BA',   // 01 Light Pink
    '#FFFDB3',   // 02 Pale Yellow / Cream
    '#B3F1C8',   // 03 Mint Green
    '#B3EEF1',   // 04 Pale Cyan / Powder Blue
    '#C4B3F1',   // 05 Pale Lavender / Periwinkle
    '#F1B3C4',   // 06 Pastel Rose
    '#FFCBA4',   // 07 Peach
    '#D4F1E0',   // 08 Pale Seafoam
    '#B3D4F1',   // 10 Light Cornflower Blue
    '#E0B3F1',   // 11 Pale Violet / Wisteria
    '#FFE4A6',   // 12 Pale Gold / Buff
    '#F1B3E0',   // 13 Pale Orchid / Pink Lavender
    '#F1D4B3',   // 14 Pale Apricot
    '#E0E0F1',   // 15 Lavender Mist
    '#D4F1B3',   // 09 Light Lime Green
];

// ============================================================
// HELPERS
// ============================================================

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

function render_inline($text) {
    $text = htmlspecialchars($text, ENT_QUOTES);
    $text = preg_replace_callback(
        '/\[([^\]]+)\]\((https?:\/\/[^\)]+)\)/',
        fn($m) => '<a href="' . $m[2] . '" target="_blank">' . $m[1] . '</a>',
        $text
    );
    return $text;
}

function cell_html($epoch, $day, $loc_colors, $MON_ABBR) {
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

    if ($arriving) {
        $from = htmlspecialchars($day['prev_location'] ?? '');
        $to   = htmlspecialchars($day['location']      ?? '');
        $html .= "  <span class=\"loc-label\">{$from} &rarr; {$to}</span>\n";
    } elseif ($loc && !$idle) {
        $html .= "  <span class=\"loc-label\">" . htmlspecialchars($loc) . "</span>\n";
    } elseif ($idle && empty($day['activities'])) {
        $html .= "  <span class=\"idle-badge\">idle</span>\n";
    }

    foreach ($day['activities'] as $act) {
        $html .= "  <span class=\"act-line\">" . render_inline($act) . "</span>\n";
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

foreach ($lines as $line) {

    // end line
    if (preg_match('/^end\s+(\S+)/i', $line, $m)) {
        $end_epoch = parse_date_token(trim($m[1]), $MONTHS);
        continue;
    }

    // title line — optional date token first, then title text
    if (preg_match('/^title\s+(.+)/i', $line, $m)) {
        $rest  = trim($m[1]);
        $first = strtok($rest, " \t");
        $maybe = parse_date_token($first, $MONTHS);
        if ($maybe !== null) {
            $start_epoch = $maybe;
            $title = trim(substr($rest, strlen($first)));
        } else {
            $title = $rest;
        }
        continue;
    }

    if (trim($line) === '') continue;

    $indented = preg_match('/^[ \t]/', $line);

    if (!$indented) {
        $token = strtok(trim($line), " \t");

        // day increment
        if ($token === '+') {
            if ($cur_epoch !== null) {
                $cur_epoch += 86400;
                if (!isset($events[$cur_epoch])) {
                    $events[$cur_epoch] = [
                        'arriving'      => false,
                        'location'      => $cur_location,
                        'prev_location' => null,
                        'activities'    => [],
                    ];
                }
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

            $events[$cur_epoch] = [
                'arriving'      => $arriving,
                'location'      => $cur_location,
                'prev_location' => $arriving ? $prev : null,
                'activities'    => [],
            ];
            continue;
        }
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
// OUTPUT
// ============================================================

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
    overflow-y: auto;
    overflow-x: hidden;
    padding: 0.4rem 0.5rem;
    font-size: 0.68rem;
    line-height: 1.5;
    border-right: 1px solid #000000;
    border-bottom: 1px solid #000000;
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

<!-- Sticky day-of-week header -->
<div class="dow-row">
    <?php foreach ($DOW as $dow): ?>
        <div class="dow-header"><?= $dow ?></div>
    <?php endforeach; ?>
</div>

<!-- Continuous calendar grid -->
<div class="cal-grid">
<?php foreach ($all_days as $e => $day): ?>
    <?= cell_html($e, $day, $loc_colors, $MON_ABBR) ?>
<?php endforeach; ?>
</div>

</body>
</html>

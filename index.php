<?php
// index.php -- landing page: list calendars, upload, create new
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

require_once __DIR__ . '/common.php';
define('TRIPS_DIR', __DIR__ . '/trips/');

// --- Handle new calendar creation ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'new') {
        $name = trim($_POST['filename'] ?? '');
        $title = trim($_POST['title'] ?? '');
        if ($name && $title) {
            $name = preg_replace('/[^a-zA-Z0-9_\-]/', '', $name);
            $file = TRIPS_DIR . $name . '.txt';
            if (!file_exists($file)) {
                file_put_contents($file, "title $title\n\n");
            }
            header('Location: edit.php?trip=' . urlencode($name));
            exit;
        }
    }

    if ($_POST['action'] === 'upload' && isset($_FILES['tripfile'])) {
        $tmp  = $_FILES['tripfile']['tmp_name'];
        $orig = basename($_FILES['tripfile']['name']);
        // keep original name, ensure .txt
        if (!preg_match('/\.txt$/i', $orig)) $orig .= '.txt';
        $dest = TRIPS_DIR . $orig;
        move_uploaded_file($tmp, $dest);
        // derive trip name for redirect (strip .txt)
        $tripname = preg_replace('/\.txt$/i', '', $orig);
        header('Location: calendar.php?trip=' . urlencode($tripname));
        exit;
    }
}

// --- Scan trips directory ---
$trips = [];
if (is_dir(TRIPS_DIR)) {
    foreach (glob(TRIPS_DIR . '*.txt') as $f) {
        $name  = basename($f, '.txt');
        $title = $name;
        // read first non-blank line for title
        $fh = fopen($f, 'r');
        while (($line = fgets($fh)) !== false) {
            $line = trim($line);
            if ($line === '') continue;
            if (preg_match('/^title\s+(.+)/i', $line, $m)) {
                $title = $m[1];
            }
            break;
        }
        fclose($fh);
        $trips[] = ['name' => $name, 'title' => $title, 'file' => $f];
    }
}
usort($trips, fn($a,$b) => strcmp($a['title'], $b['title']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>RV Travel Calendars</title>
<?php echo common_css(); ?>
<style>
.trip-list { margin: 2rem 0; }
.trip-row {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 0.75rem 1rem;
    border-bottom: 1px solid var(--border);
}
.trip-row:first-child { border-top: 1px solid var(--border); }
.trip-title { flex: 1; font-size: 0.9rem; }
.trip-title a { color: var(--text); text-decoration: none; }
.trip-title a:hover { color: #aaa; }
.trip-name { font-size: 0.65rem; color: var(--dim); }
.action-row { margin-top: 2rem; display: flex; gap: 1rem; flex-wrap: wrap; }
.panel {
    background: var(--surface);
    border: 1px solid var(--border);
    padding: 1.25rem;
    flex: 1;
    min-width: 220px;
}
.panel h3 {
    font-size: 0.7rem;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: var(--dim);
    margin-bottom: 1rem;
}
.panel input[type=text], .panel input[type=file] {
    width: 100%;
    background: var(--bg);
    border: 1px solid var(--border);
    color: var(--text);
    font-family: 'DM Mono', monospace;
    font-size: 0.78rem;
    padding: 0.4rem 0.6rem;
    margin-bottom: 0.6rem;
    outline: none;
}
.panel input[type=file] { padding: 0.3rem 0; }
</style>
</head>
<body>
<h1>RV Travel Calendars</h1>
<p class="subtitle">personal trip planner</p>

<?php if (empty($trips)): ?>
<p style="color:var(--dim); font-size:0.8rem;">No calendars yet. Create or upload one below.</p>
<?php else: ?>
<div class="trip-list">
<?php foreach ($trips as $t): ?>
<div class="trip-row">
    <div class="trip-title">
        <a href="calendar.php?trip=<?= urlencode($t['name']) ?>"><?= htmlspecialchars($t['title']) ?></a>
        <div class="trip-name"><?= htmlspecialchars($t['name']) ?>.txt</div>
    </div>
    <a class="btn-sm" href="calendar.php?trip=<?= urlencode($t['name']) ?>">view</a>
    <a class="btn-sm" href="edit.php?trip=<?= urlencode($t['name']) ?>">edit</a>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<div class="action-row">

    <div class="panel">
        <h3>New Calendar</h3>
        <form method="post">
            <input type="hidden" name="action" value="new">
            <input type="text" name="title" placeholder="Display title (e.g. Spring 2026)">
            <input type="text" name="filename" placeholder="Filename (e.g. spring2026, no spaces)">
            <button type="submit">Create</button>
        </form>
    </div>

    <div class="panel">
        <h3>Upload Trip File</h3>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="upload">
            <input type="file" name="tripfile" accept=".txt">
            <button type="submit" style="margin-top:0.4rem">Upload</button>
        </form>
    </div>

</div>
</body>
</html>


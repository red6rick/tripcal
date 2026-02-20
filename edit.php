<?php
// edit.php -- edit a trip file in a textarea
require_once __DIR__ . '/common.php';
define('TRIPS_DIR', __DIR__ . '/trips/');

$trip = preg_replace('/[^a-zA-Z0-9_\.\-]/', '', $_GET['trip'] ?? '');
if (!$trip) { header('Location: index.php'); exit; }

$filepath = TRIPS_DIR . $trip . '.txt';

// Handle save
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save'])) {
        file_put_contents($filepath, str_replace("\r\n", "\n", $_POST['content'] ?? ''));
        header('Location: calendar.php?trip=' . urlencode($trip));
        exit;
    }
    if (isset($_POST['cancel'])) {
        header('Location: calendar.php?trip=' . urlencode($trip));
        exit;
    }
}

$content = file_exists($filepath) ? file_get_contents($filepath) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Edit — <?= htmlspecialchars($trip) ?></title>
<?php echo common_css(); ?>
<style>
.editor-wrap { margin-top: 1.5rem; }
textarea {
    width: 100%;
    height: calc(100vh - 220px);
    min-height: 300px;
    background: #ffffff;
    border: 1px solid var(--border);
    color: var(--text);
    font-family: 'DM Mono', monospace;
    font-size: 0.82rem;
    line-height: 1.6;
    padding: 1rem;
    resize: vertical;
    outline: none;
    tab-size: 2;
}
textarea:focus { border-color: #8a8478; }
.btn-row { display: flex; gap: 0.75rem; margin-top: 0.75rem; }
</style>
</head>
<body>
<div style="display:flex; align-items:center; gap:1rem; margin-bottom:1.5rem; flex-wrap:wrap;">
    <a class="btn-sm" href="calendar.php?trip=<?= urlencode($trip) ?>">&larr; cancel</a>
    <h1><?= htmlspecialchars($trip) ?></h1>
</div>

<div class="editor-wrap">
    <form method="post">
        <textarea name="content" spellcheck="false"><?= htmlspecialchars($content) ?></textarea>
        <div class="btn-row">
            <button type="submit" name="save">Save &amp; View</button>
            <button type="submit" name="cancel" style="background:transparent; color:var(--dim); border-color:var(--border);">Cancel</button>
        </div>
    </form>
</div>

</body>
</html>

<?php
// edit.php -- edit a trip file in a textarea
require_once __DIR__ . '/common.php';
define('TRIPS_DIR', __DIR__ . '/trips/');

$trip = preg_replace('/[^a-zA-Z0-9_\.\-]/', '', $_GET['trip'] ?? '');
if (!$trip) { header('Location: index.php'); exit; }

$filepath = TRIPS_DIR . $trip . '.txt';

// Handle save
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $content = str_replace("\r\n", "\n", $_POST['content'] ?? '');

    if (isset($_POST['save'])) {
        file_put_contents($filepath, $content);
        header('Location: calendar.php?trip=' . urlencode($trip));
        exit;
    }
    if (isset($_POST['save_continue'])) {
        file_put_contents($filepath, $content);
        header('Location: edit.php?trip=' . urlencode($trip));
        exit;
    }
    if (isset($_POST['save_as'])) {
        $newname = preg_replace('/[^a-zA-Z0-9_\-]/', '', trim($_POST['new_filename'] ?? ''));
        if ($newname) {
            $newpath = TRIPS_DIR . $newname . '.txt';
            file_put_contents($newpath, $content);
            header('Location: edit.php?trip=' . urlencode($newname));
        } else {
            header('Location: edit.php?trip=' . urlencode($trip) . '&err=badname');
        }
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
    <?php if (isset($_GET['err']) && $_GET['err'] === 'badname'): ?>
        <div style="color:#cc0000; font-size:0.75rem; margin-bottom:0.5rem;">Save-as filename was empty or invalid — letters, numbers, underscores, hyphens only.</div>
    <?php endif; ?>
    <form method="post">
        <textarea name="content" spellcheck="false"><?= htmlspecialchars($content) ?></textarea>
        <div class="btn-row">
            <button type="submit" name="save">Save &amp; View</button>
            <button type="submit" name="save_continue">Save &amp; Continue</button>
            <button type="submit" name="save_as">Save As</button>
            <input type="text" name="new_filename" placeholder="new filename (no .txt)" style="font-family:'DM Mono',monospace; font-size:0.75rem; padding:0.3rem 0.5rem; border:1px solid #000; width:16rem;">
            <button type="submit" name="cancel" style="background:transparent; border-color:#000; margin-left:auto;">Cancel</button>
        </div>
    </form>
</div>

</body>
</html>

<?php
// admin/import_tasks.php — bulk task ("clue") import from a CSV file.
//
// Three-stage flow, all on this page:
//   1. GET               → upload form.
//   2. POST stage=upload → parse the CSV, stash rows in the session, show a
//      column-mapping form (with auto-guessed defaults + preview).
//   3. POST stage=import → apply the mapping and insert, either appending to
//      the existing task list or purging it first.
//
// Deliberately tolerant: missing columns and empty cells fall back to
// defaults, extra CSV columns are simply never mapped, and the penalty
// column accepts "25", "-25", "−25", or "25 pts" — it is always stored as a
// positive number that the tallying queries subtract.

require_once __DIR__ . '/../lib/auth.php';
require_admin();

const CSV_MAX_BYTES = 5 * 1024 * 1024;
const CSV_MAX_ROWS  = 2000;
const CSV_SESSION_KEY = 'task_csv_import';

// Target fields, in display order. 'title' is the only one that must be mapped.
$FIELDS = [
    'title'       => 'Title',
    'description' => 'Description',
    'points'      => 'Points',
    'penalty'     => 'Point penalty (if skipped)',
    'photos'      => '# Photos required',
    'videos'      => '# Videos required',
    'priority'    => 'Priority ⭐',
];

// Header keywords for auto-guessing the mapping. Checked in this order and
// matched by substring against the normalized header, so put the more
// specific names (penalty) before the ones they contain (points).
$GUESSES = [
    'penalty'     => ['penalty', 'deduction', 'deduct', 'minus'],
    'points'      => ['points', 'pts', 'value', 'worth', 'score'],
    'photos'      => ['photo', 'pic', 'image'],
    'videos'      => ['video', 'vid'],
    'priority'    => ['priority', 'mandatory', 'required', 'star'],
    'description' => ['description', 'desc', 'detail', 'instruction', 'note'],
    'title'       => ['title', 'task', 'clue', 'name', 'challenge'],
];

function norm_header(string $s): string {
    return preg_replace('/[^a-z0-9]/', '', strtolower(trim($s)));
}

/** field => column index, for headers that match a guess keyword. */
function guess_mapping(array $header_row, array $guesses): array {
    $map = [];
    $taken = [];
    foreach ($guesses as $field => $keywords) {
        foreach ($header_row as $i => $cell) {
            if (isset($taken[$i])) continue;
            $h = norm_header((string)$cell);
            if ($h === '') continue;
            foreach ($keywords as $kw) {
                if (strpos($h, $kw) !== false) {
                    $map[$field] = $i;
                    $taken[$i] = true;
                    continue 3;
                }
            }
        }
    }
    return $map;
}

/** First integer found in the cell, as a positive number. "-25", "−25",
 *  "(25)" and "25 pts" all become 25. Blank / no digits → 0. */
function parse_int_loose(?string $s): int {
    $s = str_replace(["\xE2\x88\x92", "\xE2\x80\x93"], '-', trim((string)$s)); // unicode minus / en-dash
    if ($s === '' || !preg_match('/-?\d+/', $s, $m)) return 0;
    return abs((int)$m[0]);
}

function parse_truthy(?string $s): bool {
    $s = strtolower(trim((string)$s));
    if ($s === '') return false;
    if (is_numeric($s)) return (float)$s != 0;
    return in_array($s, ['y', 'yes', 'true', 'x', 'p', 'star', 'priority', 'mandatory', 'required', '⭐'], true);
}

/** Parse raw CSV text into rows. Sniffs the delimiter (comma/semicolon/tab)
 *  from the first line; fgetcsv handles quoted cells with embedded newlines. */
function parse_csv_text(string $text): array {
    if (strncmp($text, "\xEF\xBB\xBF", 3) === 0) $text = substr($text, 3); // strip UTF-8 BOM

    $first_line = strtok($text, "\r\n") ?: '';
    $delims = [',' => substr_count($first_line, ','),
               ';' => substr_count($first_line, ';'),
               "\t" => substr_count($first_line, "\t")];
    arsort($delims);
    $delim = array_key_first($delims);
    if ($delims[$delim] === 0) $delim = ',';

    $fh = fopen('php://temp', 'r+');
    fwrite($fh, $text);
    rewind($fh);

    $rows = [];
    while (($row = fgetcsv($fh, 0, $delim, '"', '\\')) !== false) {
        // Skip rows where every cell is blank.
        $all_blank = true;
        foreach ($row as $c) if (trim((string)$c) !== '') { $all_blank = false; break; }
        if ($all_blank) continue;
        $rows[] = array_map(fn($c) => (string)$c, $row);
        if (count($rows) > CSV_MAX_ROWS) break;
    }
    fclose($fh);
    return $rows;
}

$stage = 'upload';
$err   = '';
$result = null;   // set after a successful import
$rows   = null;   // parsed CSV rows for the mapping stage

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $posted_stage = $_POST['stage'] ?? '';

    if ($posted_stage === 'upload') {
        $f = $_FILES['csv'] ?? null;
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
            $err = 'Please choose a CSV file to upload.';
        } elseif ($f['size'] > CSV_MAX_BYTES) {
            $err = 'File too large (max ' . (CSV_MAX_BYTES / 1024 / 1024) . ' MB).';
        } else {
            $parsed = parse_csv_text((string)file_get_contents($f['tmp_name']));
            if (count($parsed) > CSV_MAX_ROWS) {
                $err = 'Too many rows (max ' . CSV_MAX_ROWS . ').';
            } elseif (!$parsed) {
                $err = 'That file appears to be empty.';
            } else {
                $_SESSION[CSV_SESSION_KEY] = ['rows' => $parsed, 'name' => (string)$f['name'], 'ts' => time()];
                $rows  = $parsed;
                $stage = 'map';
            }
        }
    } elseif ($posted_stage === 'import') {
        $stash = $_SESSION[CSV_SESSION_KEY] ?? null;
        if (!$stash || empty($stash['rows'])) {
            $err = 'Upload session expired — please upload the CSV again.';
        } else {
            $rows       = $stash['rows'];
            $num_cols   = max(array_map('count', $rows));
            $has_header = !empty($_POST['has_header']);
            $mode       = ($_POST['mode'] ?? 'append') === 'replace' ? 'replace' : 'append';

            $map = [];
            foreach (array_keys($FIELDS) as $field) {
                $v = $_POST['map'][$field] ?? '';
                if ($v === '' || !is_numeric($v)) continue;
                $i = (int)$v;
                if ($i >= 0 && $i < $num_cols) $map[$field] = $i;
            }

            if (!isset($map['title'])) {
                $err   = 'You must map a column to Title.';
                $stage = 'map';
            } else {
                $cell = function (array $row, string $field) use ($map): ?string {
                    return isset($map[$field]) ? ($row[$map[$field]] ?? null) : null;
                };

                $tasks   = [];
                $skipped = 0;
                $data_rows = $has_header ? array_slice($rows, 1) : $rows;
                foreach ($data_rows as $row) {
                    $title = trim((string)$cell($row, 'title'));
                    if ($title === '') { $skipped++; continue; }
                    $tasks[] = [
                        'title'       => mb_substr($title, 0, 255),
                        'description' => trim((string)$cell($row, 'description')),
                        'points'      => parse_int_loose($cell($row, 'points')),
                        'penalty'     => parse_int_loose($cell($row, 'penalty')),
                        'photos'      => min(255, parse_int_loose($cell($row, 'photos'))),
                        'videos'      => min(255, parse_int_loose($cell($row, 'videos'))),
                        'priority'    => parse_truthy($cell($row, 'priority')) ? 1 : 0,
                    ];
                }

                if (!$tasks) {
                    $err   = 'No importable rows found (every row had an empty Title).';
                    $stage = 'map';
                } else {
                    $pdo = db();
                    try {
                        $pdo->beginTransaction();
                        if ($mode === 'replace') {
                            // FK cascade also removes the tasks' submissions and
                            // submission_files rows. Uploaded files stay on disk.
                            $pdo->exec('DELETE FROM tasks');
                            $base_sort = 0;
                        } else {
                            $base_sort = (int)$pdo->query('SELECT COALESCE(MAX(sort_order), 0) FROM tasks')->fetchColumn();
                        }
                        $ins = $pdo->prepare(
                            'INSERT INTO tasks (title, description, points, penalty, photos_required, videos_required, mandatory, sort_order)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                        );
                        foreach ($tasks as $i => $t) {
                            $ins->execute([
                                $t['title'], $t['description'], $t['points'], $t['penalty'],
                                $t['photos'], $t['videos'], $t['priority'],
                                $base_sort + $i + 1,
                            ]);
                        }
                        $pdo->commit();
                    } catch (Throwable $e) {
                        $pdo->rollBack();
                        $err   = 'Import failed, nothing was changed: ' . $e->getMessage();
                        $stage = 'map';
                    }
                    if (!$err) {
                        unset($_SESSION[CSV_SESSION_KEY]);
                        $result = [
                            'inserted'   => count($tasks),
                            'skipped'    => $skipped,
                            'mode'       => $mode,
                            'zero_point' => count(array_filter($tasks, fn($t) => $t['points'] === 0)),
                        ];
                        $stage = 'done';
                    }
                }
            }
        }
    }
}

// Mapping-stage view data (also used when re-showing the form after an error).
if ($stage === 'map' && $rows === null) {
    $rows = $_SESSION[CSV_SESSION_KEY]['rows'] ?? null;
    if (!$rows) { $stage = 'upload'; $err = $err ?: 'Upload session expired — please upload the CSV again.'; }
}
if ($stage === 'map') {
    $num_cols  = max(array_map('count', $rows));
    $guessed   = guess_mapping($rows[0], $GUESSES);
    $has_header_guess = !empty($guessed);
    // Column labels: header text when present, else "Column N".
    $col_labels = [];
    for ($i = 0; $i < $num_cols; $i++) {
        $h = trim((string)($rows[0][$i] ?? ''));
        $col_labels[$i] = $h !== '' ? $h : 'Column ' . ($i + 1);
    }
    $preview = array_slice($rows, 0, 6);
    $task_count = (int)db()->query('SELECT COUNT(*) FROM tasks')->fetchColumn();
}
?>
<!doctype html>
<html<?=theme_html_attr()?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="<?=BASE_URL?>/style.css?v=<?=@filemtime(__DIR__.'/../style.css')?>">
<title>Bulk Import Tasks – Admin</title>
<style>
  table.preview { width:100%; border-collapse:collapse; font-size:.85rem; overflow-x:auto; }
  table.preview th, table.preview td { padding:4px 8px; border:1px solid rgba(0,0,0,0.12); text-align:left; max-width:260px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
  .preview-scroll { overflow-x:auto; }
  .map-grid { display:grid; grid-template-columns:max-content 1fr; gap:8px 16px; align-items:center; max-width:520px; margin:0 auto; }
  .warn { color:#c00; font-weight:600; }
</style>
</head>
<body class="container">
<h1>Bulk Import Tasks</h1>
<p class="center small"><a href="<?=BASE_URL?>/admin/dashboard.php">← Dashboard</a></p>

<?php if ($err): ?><div class="alert error"><?=htmlspecialchars($err)?></div><?php endif; ?>

<?php if ($stage === 'upload'): ?>
<div class="card">
  <h2>Step 1 — Upload a CSV</h2>
  <p class="small">Upload a CSV of tasks/clues. On the next screen you'll choose which
     CSV column feeds which field, so the column names and order don't matter.
     Missing columns and blank cells are fine — they fall back to sensible defaults —
     and extra columns are ignored.</p>
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>">
    <input type="hidden" name="stage" value="upload">
    <label>CSV file: <input type="file" name="csv" accept=".csv,text/csv,text/plain" required></label>
    <button type="submit">Upload &amp; preview</button>
  </form>
</div>

<?php elseif ($stage === 'map'): ?>
<div class="card">
  <h2>Step 2 — Match columns to fields</h2>
  <p class="small center">
    <?=count($rows)?> row<?=count($rows)===1?'':'s'?> parsed from
    <strong><?=htmlspecialchars($_SESSION[CSV_SESSION_KEY]['name'] ?? 'upload')?></strong>.
    Only <strong>Title</strong> is required — leave anything your CSV doesn't have as "not in CSV".
  </p>

  <div class="preview-scroll">
    <table class="preview">
      <?php foreach ($preview as $ri => $row): ?>
        <tr>
          <?php for ($ci = 0; $ci < $num_cols; $ci++): ?>
            <?php if ($ri === 0): ?>
              <th><?=htmlspecialchars((string)($row[$ci] ?? ''))?></th>
            <?php else: ?>
              <td><?=htmlspecialchars((string)($row[$ci] ?? ''))?></td>
            <?php endif; ?>
          <?php endfor; ?>
        </tr>
      <?php endforeach; ?>
    </table>
  </div>
  <p class="small center">Preview of the first <?=count($preview)?> rows.</p>

  <form method="post">
    <input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>">
    <input type="hidden" name="stage" value="import">

    <label style="display:flex; align-items:center; gap:8px; justify-content:center;">
      <input type="checkbox" name="has_header" value="1" <?=$has_header_guess?'checked':''?>>
      First row is a header (don't import it)
    </label>

    <div class="map-grid" style="margin-top:12px;">
      <?php foreach ($FIELDS as $field => $label): ?>
        <span><?=htmlspecialchars($label)?><?= $field === 'title' ? ' <span class="warn">*</span>' : '' ?></span>
        <select name="map[<?=$field?>]">
          <option value="">— not in CSV —</option>
          <?php for ($i = 0; $i < $num_cols; $i++): ?>
            <option value="<?=$i?>" <?= (isset($guessed[$field]) && $guessed[$field] === $i) ? 'selected' : '' ?>>
              <?=htmlspecialchars($col_labels[$i])?>
            </option>
          <?php endfor; ?>
        </select>
      <?php endforeach; ?>
    </div>

    <p class="small center" style="margin-top:8px;">
      Point penalties may be written as <code>25</code> or <code>-25</code> in the CSV —
      either way they're stored as a positive amount that gets deducted when the task isn't completed.
      The Priority column counts values like <code>1</code>, <code>yes</code>, <code>x</code>, or <code>⭐</code> as priority.
    </p>

    <h3 class="center" style="margin-top:16px;">Existing tasks</h3>
    <label style="display:flex; align-items:center; gap:8px; justify-content:center;">
      <input type="radio" name="mode" value="append" checked>
      Append to the <?=$task_count?> existing task<?=$task_count===1?'':'s'?>
    </label>
    <label style="display:flex; align-items:center; gap:8px; justify-content:center;">
      <input type="radio" name="mode" value="replace">
      <span class="warn">Purge all existing tasks first</span>
    </label>
    <p class="small center warn">Purging deletes every existing task <em>and all team submissions
      for them</em> (uploaded files stay on disk). There is no undo.</p>

    <div class="center" style="margin-top:12px;">
      <button type="submit" onclick="return this.form.mode.value !== 'replace' || confirm('Really delete ALL existing tasks and their submissions before importing?');">Import tasks</button>
    </div>
  </form>
  <p class="center small" style="margin-top:8px;"><a href="<?=BASE_URL?>/admin/import_tasks.php">Start over with a different file</a></p>
</div>

<?php elseif ($stage === 'done'): ?>
<div class="card center">
  <h2>Import complete ✅</h2>
  <p><strong><?=$result['inserted']?></strong> task<?=$result['inserted']===1?'':'s'?> imported
     (<?= $result['mode'] === 'replace' ? 'replaced the old task list' : 'appended to the existing list' ?>).</p>
  <?php if ($result['skipped'] > 0): ?>
    <p class="small"><?=$result['skipped']?> row<?=$result['skipped']===1?'':'s'?> skipped because the Title cell was empty.</p>
  <?php endif; ?>
  <?php if ($result['zero_point'] > 0): ?>
    <p class="small warn"><?=$result['zero_point']?> imported task<?=$result['zero_point']===1?'':'s'?> ended up with 0 points —
      edit them on the dashboard if that's not intended.</p>
  <?php endif; ?>
  <p><a href="<?=BASE_URL?>/admin/dashboard.php">← Back to dashboard</a> &nbsp;·&nbsp;
     <a href="<?=BASE_URL?>/admin/import_tasks.php">Import another CSV</a></p>
</div>
<?php endif; ?>

<p class="center"><a href="<?=BASE_URL?>/logout.php">Log Out</a></p>
</body>
</html>

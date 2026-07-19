<?php
require_once __DIR__ . '/lib/auth.php';
require_team();
require_once __DIR__ . '/lib/thumbs.php';

date_default_timezone_set('America/New_York');

$team    = current_team();
$team_id = current_team_id();
$task_id = intval($_GET['id'] ?? 0);

$st = db()->prepare('SELECT id, task_no, title, description, points, photos_required, videos_required, bonus FROM tasks WHERE id = ?');
$st->execute([$task_id]);
$task = $st->fetch();
if (!$task) { header('Location: ' . BASE_URL . '/team.php'); exit; }

$ss = db()->prepare('SELECT id, status, note FROM submissions WHERE team_id = ? AND task_id = ?');
$ss->execute([$team_id, $task_id]);
$sub = $ss->fetch();
$status = $sub['status'] ?? 'not_started';
$note   = $sub['note']   ?? '';

// Starter-task unlock gate: a locked (non-starter) task can't be opened or
// submitted until the team has cleared the gate. Enforced server-side here so
// hiding the link on team.php isn't the only defense.
$starter_ids = starter_task_ids(starter_gate_count());
$task_locked = !in_array($task_id, $starter_ids, true)
            && !starter_gate_open($team_id, $starter_ids);

// Human task label for headings ("1a"/"3b"), falling back to the numeric id.
$task_no_label = trim((string)($task['task_no'] ?? ''));
if ($task_no_label === '') $task_no_label = (string)(int)$task['id'];

$num_photos = (int)$task['photos_required'];
$num_videos = (int)$task['videos_required'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $task_locked) {
    // Locked task: refuse the submission. The uploader XHR surfaces this text.
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('This task is locked. Submit your starting tasks first to unlock it.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $status !== 'approved') {
    // Guard the host's hard POST cap. A body over the limit is silently
    // discarded by PHP before this script runs — $_POST and $_FILES arrive
    // empty even though the browser sent the bytes. Detect both the "too
    // big" case (large Content-Length) and the resulting empty payload, and
    // fail loudly instead of recording an empty submission.
    $content_length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    $body_discarded = empty($_FILES) && empty($_POST) && $content_length > 0;
    if ($content_length > MAX_POST_BYTES || $body_discarded) {
        $limit_mb = round(MAX_POST_BYTES / (1024 * 1024));
        http_response_code(413);
        header('Content-Type: text/plain; charset=utf-8');
        exit("Your upload is too large. The total size of all files for one task must stay under {$limit_mb} MB. Please retake with lower resolution / shorter video, or submit fewer files, and try again.");
    }

    $upload_dir = DATA_DIR . "/uploads/$team/$task_id";
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0775, true);

    if ($sub && ($status === 'pending' || $status === 'rejected') && is_dir($upload_dir)) {
        $reject_dir = DATA_DIR . "/uploads/$team/rejections/{$task_id}_" . date('Ymd_His');
        @mkdir($reject_dir, 0775, true);
        foreach (glob("$upload_dir/*") as $oldf) {
            if (is_file($oldf)) @rename($oldf, "$reject_dir/" . basename($oldf));
        }
        // Move stale thumb dir aside too (it will be regenerated).
        if (is_dir("$upload_dir/thumbs")) @rename("$upload_dir/thumbs", "$reject_dir/thumbs");
    }

    $allowed_types = [
        'image/jpeg','image/png','image/heic','image/heif','image/webp',
        'video/mp4','video/quicktime','video/mov','video/3gpp','video/webm',
        'application/octet-stream',
    ];

    $accepted = []; // [['filename','mime','bytes','has_thumb'], ...]
    $upload_errors = [];

    // The form only renders photos_required + videos_required inputs, but the
    // POST body is client-controlled — cap how many files get the expensive
    // image processing below, or one crafted request can peg the CPU.
    $max_files = max(1, $num_photos + $num_videos);

    foreach (($_FILES['upload']['tmp_name'] ?? []) as $i => $tmp) {
        $name = $_FILES['upload']['name'][$i]  ?? 'unknown';
        $err  = $_FILES['upload']['error'][$i] ?? UPLOAD_ERR_OK;

        if (count($accepted) >= $max_files) { $upload_errors[] = "$name (over the {$max_files}-file limit)"; continue; }
        if ($err !== UPLOAD_ERR_OK) { $upload_errors[] = "$name (error $err)"; continue; }
        if (!is_uploaded_file($tmp)) { $upload_errors[] = "$name (not uploaded)"; continue; }

        $type = mime_content_type($tmp);
        if (!$type || $type === 'application/octet-stream') {
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg','jpeg','png','heic','heif','webp'])) $type = 'image/' . ($ext === 'jpg' ? 'jpeg' : $ext);
            elseif (in_array($ext, ['mp4','mov','qt','3gp','webm']))      $type = 'video/' . $ext;
        }

        if (!in_array($type, $allowed_types)) {
            $upload_errors[] = "$name (type $type not allowed)"; continue;
        }

        // The stored name is built here, not from user input — only the
        // extension survives, and only after being reduced to a safe charset.
        $ext = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', pathinfo($name, PATHINFO_EXTENSION)));
        $ext = substr($ext !== '' ? $ext : 'bin', 0, 10);
        $fname = 'file' . ($i + 1) . '_' . time() . '.' . $ext;
        $dest  = "$upload_dir/$fname";
        if (!move_uploaded_file($tmp, $dest)) {
            $upload_errors[] = "$name (move failed)"; continue;
        }

        // One pass: strip GPS/EXIF, bake orientation, transcode HEIC to JPEG
        // (never re-encode HEIC — that runs x265 and melts the CPU), and
        // generate the thumb from the same decode. May rename the file.
        $res = process_upload_image($upload_dir, $fname, $type);

        $accepted[] = [
            'filename'  => $res['filename'],
            'mime'      => $res['mime'],
            'bytes'     => filesize("$upload_dir/{$res['filename']}") ?: 0,
            'has_thumb' => $res['has_thumb'] ? 1 : 0,
        ];
    }

    if ($upload_errors) {
        @file_put_contents(
            DATA_DIR . '/upload_errors.log',
            date('Y-m-d H:i:s') . " [$team task $task_id] " . implode('; ', $upload_errors) . "\n",
            FILE_APPEND
        );
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        if ($sub) {
            $u = $pdo->prepare(
                "UPDATE submissions
                    SET status='pending', note=NULL, submitted_at=NOW(), reviewed_at=NULL
                  WHERE id = ?"
            );
            $u->execute([$sub['id']]);
            $submission_id = (int)$sub['id'];

            $del = $pdo->prepare('DELETE FROM submission_files WHERE submission_id = ?');
            $del->execute([$submission_id]);
        } else {
            $i = $pdo->prepare(
                "INSERT INTO submissions (team_id, task_id, status, submitted_at)
                 VALUES (?, ?, 'pending', NOW())"
            );
            $i->execute([$team_id, $task_id]);
            $submission_id = (int)$pdo->lastInsertId();
        }

        if ($accepted) {
            $f = $pdo->prepare(
                'INSERT INTO submission_files (submission_id, filename, mime_type, byte_size, has_thumb)
                 VALUES (?, ?, ?, ?, ?)'
            );
            foreach ($accepted as $row) {
                $f->execute([$submission_id, $row['filename'], $row['mime'], $row['bytes'], $row['has_thumb']]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        @file_put_contents(
            DATA_DIR . '/upload_errors.log',
            date('Y-m-d H:i:s') . " [$team task $task_id] db: " . $e->getMessage() . "\n",
            FILE_APPEND
        );
        http_response_code(500);
        exit('Upload failed.');
    }

    header('Location: ' . BASE_URL . '/team.php');
    exit;
}
?>
<!doctype html>
<html<?=theme_html_attr()?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="<?=BASE_URL?>/style.css?v=<?=@filemtime(__DIR__.'/style.css')?>">
<title>Submit Task – The Ultimate Quest</title>
</head>
<body class="container">

<h1>Task <?=htmlspecialchars($task_no_label)?><?= !empty($task['bonus']) ? ' 🎁' : '' ?> – <?=htmlspecialchars($task['title'])?></h1>
<div class="card">
  <p><strong>Points:</strong> <?=(int)$task['points']?><?= !empty($task['bonus']) ? ' <span style="color:#6a3fb5; font-weight:600;">(Bonus challenge)</span>' : '' ?></p>
  <p><?=nl2br(htmlspecialchars($task['description'] ?? ''))?></p>

  <?php if ($task_locked): ?>
    <p class="alert error"><strong>🔒 Locked.</strong> Submit your starting tasks first. The rest of the quest unlocks once your team has submitted the first <?=starter_gate_count()?> task<?=starter_gate_count()===1?'':'s'?>.</p>
  <?php elseif ($status === 'rejected' && $note): ?>
    <p class="alert error"><strong>Note:</strong> <?=htmlspecialchars($note)?></p>
  <?php elseif ($status === 'pending'): ?>
    <p class="alert"><em>This task is pending approval. You may resubmit if needed.</em></p>
  <?php elseif ($status === 'approved'): ?>
    <p class="alert success"><em>This task has already been approved!</em></p>
  <?php endif; ?>

  <?php if ($status !== 'approved' && !$task_locked): ?>
  <form id="upload-form" method="post" enctype="multipart/form-data" class="grid-2">
    <?php for ($i = 0; $i < $num_photos; $i++): ?>
      <label>Photo <?=($i+1)?>: <input type="file" name="upload[]" accept="image/*" required></label>
    <?php endfor; ?>
    <?php for ($i = 0; $i < $num_videos; $i++): ?>
      <label>Video <?=($i+1)?>: <input type="file" name="upload[]" accept="video/*" required></label>
    <?php endfor; ?>

    <div class="center" style="grid-column:1/-1;">
      <button type="submit">Submit for Approval</button>
    </div>
  </form>
  <?php endif; ?>
</div>

<p class="center"><a href="<?=BASE_URL?>/team.php">← Back to Tasks</a></p>

<div id="upload-overlay" class="hidden">
  <div class="upload-message">
    <div class="spinner"></div>
    <p id="upload-status">Preparing upload…</p>
    <progress id="upload-bar" value="0" max="100" style="width:80%; max-width:400px;"></progress>
    <p id="upload-bytes" class="small"></p>
  </div>
</div>

<script>
(function () {
  const form = document.getElementById('upload-form');
  if (!form) return;

  const overlay = document.getElementById('upload-overlay');
  const bar     = document.getElementById('upload-bar');
  const status  = document.getElementById('upload-status');
  const bytes   = document.getElementById('upload-bytes');

  const MAX_POST_BYTES = <?=MAX_POST_BYTES?>;
  const MAX_POST_MB    = Math.round(MAX_POST_BYTES / (1024 * 1024));

  function fmtMB(n) { return (n / (1024 * 1024)).toFixed(1) + ' MB'; }

  form.addEventListener('submit', (e) => {
    e.preventDefault();
    if (form.classList.contains('submitting')) return;

    // Reject over-limit uploads before sending. Over the host's POST cap the
    // body is silently discarded server-side, so catch it here first.
    let totalBytes = 0;
    for (const input of form.querySelectorAll('input[type=file]')) {
      for (const file of input.files) totalBytes += file.size;
    }
    if (totalBytes > MAX_POST_BYTES) {
      alert(
        'Your files total ' + fmtMB(totalBytes) + ', which is over the ' +
        MAX_POST_MB + ' MB limit for one task.\n\n' +
        'Please pick a lower-resolution photo or a shorter video (or fewer files) and try again.'
      );
      return;
    }

    form.classList.add('submitting');
    overlay.classList.remove('hidden');
    status.textContent = 'Uploading…';

    const xhr = new XMLHttpRequest();
    xhr.open('POST', form.action || window.location.href, true);
    xhr.upload.onprogress = (ev) => {
      if (!ev.lengthComputable) return;
      const pct = Math.round((ev.loaded / ev.total) * 100);
      bar.value = pct;
      bytes.textContent = fmtMB(ev.loaded) + ' / ' + fmtMB(ev.total) + '  (' + pct + '%)';
    };
    xhr.upload.onload = () => {
      bar.removeAttribute('value');
      status.textContent = 'Upload complete, saving on server…';
    };
    xhr.onload = () => {
      if (xhr.status >= 200 && xhr.status < 400) {
        window.location.href = '<?=BASE_URL?>/team.php';
      } else {
        const msg = (xhr.responseText || '').trim();
        status.textContent = msg || ('Upload failed (HTTP ' + xhr.status + '). Please try again.');
        form.classList.remove('submitting');
      }
    };
    xhr.onerror = () => {
      status.textContent = 'Network error. Check your connection and retry.';
      form.classList.remove('submitting');
    };
    xhr.send(new FormData(form));
  });
})();
</script>

</body>
</html>

<?php
require_once __DIR__ . '/../lib/auth.php';
require_admin();

date_default_timezone_set('America/New_York');

$msg = '';

// All inline POST handlers in this page are admin-form submissions and must
// carry a CSRF token. Validate before doing any state changes.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
}

$archived_label = '';
if (!empty($_GET['archived'])) {
    $archived_label = preg_replace('/[^0-9_]/', '', $_GET['archived']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_event'])) {
    $start  = $_POST['start_time'] ?? '';
    $end    = $_POST['end_time']   ?? '';
    $reveal = isset($_POST['reveal_leaderboard']);
    $min_mandatory = max(0, intval($_POST['mandatory_min_required'] ?? 0));

    $start_dt = DateTime::createFromFormat('Y-m-d\TH:i', $start, new DateTimeZone('America/New_York'));
    $end_dt   = DateTime::createFromFormat('Y-m-d\TH:i', $end,   new DateTimeZone('America/New_York'));

    if ($start_dt && $end_dt) {
        set_setting('event_start_time',         $start_dt->format('Y-m-d H:i:s'));
        set_setting('event_end_time',           $end_dt->format('Y-m-d H:i:s'));
        set_setting('reveal_leaderboard',       $reveal ? '1' : '0');
        set_setting('mandatory_min_required',   (string)$min_mandatory);
        header('Location: ' . $_SERVER['REQUEST_URI']); exit;
    }
    $msg = 'Invalid date/time format.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_team'])) {
    $raw_name = trim($_POST['team_name'] ?? '');
    $name     = normalize_team_name($raw_name);
    $pin      = trim($_POST['team_pin'] ?? '');
    if ($name && $pin) {
        try {
            $st = db()->prepare('INSERT INTO teams (name, display_name, pin_hash) VALUES (?, ?, ?)');
            $st->execute([$name, $raw_name, password_hash($pin, PASSWORD_DEFAULT)]);
            $msg = "Team '$name' added.";
        } catch (PDOException $e) {
            $msg = ($e->errorInfo[1] ?? 0) === 1062 ? "Team '$name' already exists." : 'Add failed.';
        }
    } else {
        $msg = 'Team name and PIN required.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_team'])) {
    $name = $_POST['delete_team'];
    $st = db()->prepare('DELETE FROM teams WHERE name = ?');
    $st->execute([$name]);
    $msg = $st->rowCount() > 0 ? "Team '$name' deleted." : "Team '$name' not found.";
}

$start_val = '';
$end_val   = '';
$start_str = setting('event_start_time');
$end_str   = setting('event_end_time');
$reveal    = setting('reveal_leaderboard') === '1';
$min_mandatory_val = (int)(setting('mandatory_min_required') ?? 0);

if ($start_str) {
    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $start_str, new DateTimeZone('America/New_York'));
    $start_val = $dt ? $dt->format('Y-m-d\TH:i') : '';
}
if ($end_str) {
    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $end_str, new DateTimeZone('America/New_York'));
    $end_val = $dt ? $dt->format('Y-m-d\TH:i') : '';
}
?>
<!doctype html>
<html<?=theme_html_attr()?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="csrf-token" content="<?=htmlspecialchars(csrf_token())?>">
<link rel="stylesheet" href="<?=BASE_URL?>/style.css?v=<?=@filemtime(__DIR__.'/../style.css')?>">
<title>Admin Dashboard – The Ultimate Quest</title>
</head>
<body class="container">
<h1>Admin Dashboard</h1>
<p class="center small">
  <a href="<?=BASE_URL?>/admin/status.php">📊 Site &amp; game status</a> &nbsp;·&nbsp;
  <a href="<?=BASE_URL?>/admin/settings.php">⚙️ Site settings</a> &nbsp;·&nbsp;
  <a href="<?=BASE_URL?>/leaderboard.php">🏆 Leaderboard</a>
</p>

<div class="card" id="pending-card">
  <h2>Pending Approvals</h2>
  <div id="pending-list">Loading…</div>
</div>

<div class="card" id="event-status-card">
  <h2>Event Status</h2>
  <?php
    $now = new DateTime('now', new DateTimeZone('America/New_York'));
    $start_dt = $start_str ? new DateTime($start_str, new DateTimeZone('America/New_York')) : null;
    $end_dt   = $end_str   ? new DateTime($end_str,   new DateTimeZone('America/New_York')) : null;
    $offset = $now->format('P');

    if ($start_dt && $now < $start_dt) {
        echo '<p>The event has not started yet.</p>';
        echo '<p><strong>Starts:</strong> ' . $start_dt->format('M j, Y g:i A') . ' ET</p>';
    } elseif ($end_dt && $start_dt && $now >= $start_dt && $now <= $end_dt) {
        echo '<p>The event is currently <strong>active</strong>.</p>';
        echo '<p><strong>Ends:</strong> ' . $end_dt->format('M j, Y g:i A') . ' ET</p>';
    } elseif ($end_dt && $now > $end_dt) {
        echo '<p>The event has ended.</p>';
        echo '<p><strong>Ended:</strong> ' . $end_dt->format('M j, Y g:i A') . ' ET</p>';
    } else {
        echo '<p>No event scheduled.</p>';
    }
  ?>
</div>

<?php if ($archived_label): ?>
  <div class="alert success">
    Game archived as <strong><?=htmlspecialchars($archived_label)?></strong>.
    <a href="<?=BASE_URL?>/api/download_game_archive.php?label=<?=urlencode($archived_label)?>">Download ZIP</a>.
  </div>
<?php endif; ?>
<?php if ($msg): ?>
  <div class="alert success"><?=htmlspecialchars($msg)?></div>
<?php endif; ?>

<div class="card">
  <h2>Event Controls</h2>
  <form method="post">
    <input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>">
    <input type="hidden" name="update_event" value="1">
    <label>Start Time (Eastern):
      <input type="datetime-local" name="start_time" value="<?=htmlspecialchars($start_val)?>">
    </label>
    <label>End Time (Eastern):
      <input type="datetime-local" name="end_time" value="<?=htmlspecialchars($end_val)?>">
    </label>
    <label style="display:flex; align-items:center; gap:8px; justify-content:space-between; max-width:220px;">
      <span>Reveal Leaderboard</span>
      <input type="checkbox" name="reveal_leaderboard" <?=$reveal?'checked':''?> style="margin:0;">
    </label>
    <label>Mandatory tasks required to qualify:
      <input type="number" name="mandatory_min_required" min="0" value="<?=$min_mandatory_val?>">
    </label>
    <button type="submit">Save Event Settings</button>
  </form>
</div>

<div class="card" id="tasks-card">
  <h2>Tasks</h2>
  <div id="tasks-table">Loading tasks…</div>
  <hr>
  <h3 style="margin-top: 1em; text-align:center;">Add New Task</h3>
  <form method="post" class="grid-5" id="add-task-form">
    <input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>">
    <input type="hidden" name="add_task" value="1">
    <label>Title: <input type="text" name="title" required></label>
    <label>Points: <input type="number" name="points" min="0" required></label>
    <label># Photos: <input type="number" name="photos" min="0" required></label>
    <label># Videos: <input type="number" name="videos" min="0" required></label>
    <label>Description:
      <textarea name="description" rows="1" class="auto-expand" placeholder="Enter task description…"></textarea>
    </label>
    <label style="display:flex; align-items:center; gap:8px;">
      <input type="checkbox" name="mandatory" value="1"> Mandatory ⭐
    </label>
    <button type="submit">Add</button>
  </form>
</div>

<div class="card" id="teams-card">
  <h2>Teams</h2>
  <div id="teams-table">Loading teams…</div>
  <hr>
  <form method="post" class="grid-2">
    <input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>">
    <input type="hidden" name="add_team" value="1">
    <label>Team Name: <input type="text" name="team_name" required></label>
    <label>PIN: <input type="text" name="team_pin" required></label>
    <button type="submit">Add Team</button>
  </form>
</div>

<div class="card" id="game-lifecycle-card">
  <h2>Game Lifecycle</h2>
  <p class="small">When the event is over, archive everything and reset for the next game.
    Submissions and uploaded files are always cleared. Teams and tasks are preserved unless you opt in.</p>
  <div class="center">
    <button id="end-game-btn" class="secondary" type="button">⚑ End game and archive…</button>
  </div>
</div>

<div id="reviewModal" class="modal hidden">
  <div class="modal-content">
    <span class="close">&times;</span>
    <h3 id="modal-title"></h3>
    <div id="modal-media"></div>
    <div id="reject-block" class="hidden">
      <textarea id="reject-note" rows="3" placeholder="Rejection note…"></textarea>
      <button id="reject-send" class="secondary">Send Rejection</button>
    </div>
    <div class="center" id="modal-actions">
      <button id="approve-btn">Approve</button>
      <button id="reject-btn" class="secondary">Reject</button>
    </div>
  </div>
</div>

<div id="endGameModal" class="modal hidden">
  <div class="modal-content">
    <span class="close-end-game">&times;</span>
    <h3>End the current game</h3>
    <p>This will <strong>archive all submissions, uploaded files, and event settings</strong>
       under <code>DATA_DIR/games/&lt;timestamp&gt;/</code>, then clear the live state.
       The admin password is never touched.</p>
    <p>Type <strong>END GAME</strong> below to enable the button.</p>
    <label>Confirmation: <input type="text" id="end-game-confirm" placeholder="END GAME"></label>
    <label style="display:flex; align-items:center; gap:8px;">
      <input type="checkbox" id="end-delete-teams"> Also delete all teams
    </label>
    <label style="display:flex; align-items:center; gap:8px;">
      <input type="checkbox" id="end-delete-tasks"> Also delete all tasks
    </label>
    <label style="display:flex; align-items:center; gap:8px;">
      <input type="checkbox" id="end-reset-times" checked> Reset event start/end times
    </label>
    <div class="center" style="margin-top:1em;">
      <button id="end-game-go" type="button" disabled>Archive &amp; reset</button>
    </div>
  </div>
</div>

<div id="editTaskModal" class="modal hidden">
  <div class="modal-content">
    <span class="close-edit">&times;</span>
    <h3>Edit Task</h3>
    <form id="edit-task-form">
      <input type="hidden" name="task_id">
      <label>Title: <input type="text" name="title" required></label>
      <label>Points: <input type="number" name="points" min="0" required></label>
      <label># Photos: <input type="number" name="photos" min="0" required></label>
      <label># Videos: <input type="number" name="videos" min="0" required></label>
      <label>Description:
        <textarea name="description" rows="2" class="auto-expand"></textarea>
      </label>
      <label style="display:flex; align-items:center; gap:8px;">
        <input type="checkbox" name="mandatory" value="1"> Mandatory ⭐
      </label>
      <div class="center">
        <button type="submit">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<script>
const BASE = '<?=BASE_URL?>';
const CSRF = document.querySelector('meta[name=csrf-token]').content;

function jsonPost(url, body) {
  return fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
    body: JSON.stringify(body),
  }).then(r => r.json());
}
function formPost(url, formEl) {
  const fd = new FormData(formEl);
  if (!fd.has('_csrf')) fd.append('_csrf', CSRF);
  return fetch(url, { method: 'POST', body: fd }).then(r => r.json());
}
function fieldPost(url, fields) {
  const fd = new FormData();
  for (const k in fields) fd.append(k, fields[k]);
  fd.append('_csrf', CSRF);
  return fetch(url, { method: 'POST', body: fd }).then(r => r.json());
}

const list       = document.getElementById('pending-list');
const modal      = document.getElementById('reviewModal');
const modalTitle = document.getElementById('modal-title');
const modalMedia = document.getElementById('modal-media');
const rejectBlock = document.getElementById('reject-block');
const rejectNote  = document.getElementById('reject-note');
const teamsTable  = document.getElementById('teams-table');
const tasksTable  = document.getElementById('tasks-table');
let current = null;
const selectedItems = new Map(); // key = `${team}|${task}` → {team, task}

async function loadQueue() {
  const r = await fetch(`${BASE}/api/get_pending.php`);
  const j = await r.json();
  if (!j.success) { list.textContent = j.error || 'Error loading queue'; return; }
  if (!j.items.length) {
    list.textContent = 'No pending submissions.';
    selectedItems.clear();
    renderBulkBar();
    return;
  }

  // Drop selections that no longer exist in the queue.
  const valid = new Set(j.items.map(i => `${i.team}|${i.task_id}`));
  for (const k of [...selectedItems.keys()]) if (!valid.has(k)) selectedItems.delete(k);

  let html = '<div id="bulk-bar" class="hidden" style="position:sticky; top:0; background:#fffbe6; border:1px solid #e6c200; padding:8px; margin-bottom:8px; border-radius:6px;">' +
             '<span id="bulk-count">0</span> selected · ' +
             '<button id="bulk-approve" type="button">Approve selected</button> ' +
             '<button id="bulk-reject" type="button" class="secondary">Reject selected…</button> ' +
             '<button id="bulk-clear" type="button" class="secondary">Clear</button>' +
             '</div>';
  html += '<table style="width:100%; border-collapse:collapse;">' +
          '<tr>' +
          '<th style="padding:6px; width:1%;"><input type="checkbox" id="bulk-select-all"></th>' +
          '<th style="text-align:left; padding:6px;">Team</th>' +
          '<th style="text-align:left; padding:6px;">Task</th>' +
          '<th style="text-align:left; padding:6px;">Submitted</th>' +
          '<th style="text-align:left; padding:6px;">Actions</th>' +
          '</tr>';
  for (const i of j.items) {
    const key = `${i.team}|${i.task_id}`;
    const checked = selectedItems.has(key) ? 'checked' : '';
    html += `<tr>
      <td style="padding:6px;"><input type="checkbox" class="bulk-row" data-key="${key}" data-team="${i.team}" data-task="${i.task_id}" ${checked}></td>
      <td style="padding:6px;">${i.team}</td>
      <td style="padding:6px;">${i.task}</td>
      <td style="padding:6px;">${i.submitted_at} ET</td>
      <td style="padding:6px;"><button data-team="${i.team}" data-task="${i.task_id}" class="view-btn">👁 View</button></td>
    </tr>`;
  }
  html += '</table>';
  list.innerHTML = html;

  document.querySelectorAll('.view-btn').forEach(b => b.onclick = () => openModal(b.dataset.team, b.dataset.task));
  document.querySelectorAll('.bulk-row').forEach(cb => cb.addEventListener('change', () => {
    const key = cb.dataset.key;
    if (cb.checked) selectedItems.set(key, { team: cb.dataset.team, task: parseInt(cb.dataset.task, 10) });
    else            selectedItems.delete(key);
    renderBulkBar();
  }));
  document.getElementById('bulk-select-all').addEventListener('change', (e) => {
    document.querySelectorAll('.bulk-row').forEach(cb => {
      cb.checked = e.target.checked;
      cb.dispatchEvent(new Event('change'));
    });
  });
  document.getElementById('bulk-clear').onclick = () => { selectedItems.clear(); loadQueue(); };
  document.getElementById('bulk-approve').onclick = () => bulkReview('approve');
  document.getElementById('bulk-reject').onclick  = () => {
    const note = prompt('Rejection note for all selected (optional):', '');
    if (note === null) return;
    bulkReview('reject', note);
  };
  renderBulkBar();
}

function renderBulkBar() {
  const bar   = document.getElementById('bulk-bar');
  const count = document.getElementById('bulk-count');
  if (!bar || !count) return;
  if (selectedItems.size === 0) bar.classList.add('hidden');
  else                          bar.classList.remove('hidden');
  count.textContent = String(selectedItems.size);
}

async function bulkReview(action, note = '') {
  if (selectedItems.size === 0) return;
  const items = [...selectedItems.values()];
  const j = await jsonPost(`${BASE}/api/bulk_review.php`, { action, items, note });
  alert(j.message || (j.success ? 'Done' : 'Failed'));
  selectedItems.clear();
  loadQueue(); loadTeams(); loadTasks();
}

async function loadTeams() {
  const r = await fetch(`${BASE}/api/get_teams.php`);
  const j = await r.json();
  if (!j.success) { teamsTable.textContent = j.error || 'Error loading teams'; return; }
  if (!j.teams || j.teams.length === 0) { teamsTable.textContent = 'No teams found.'; return; }
  let html = '<table style="width:100%; border-collapse:collapse;">' +
             '<tr>' +
             '<th style="text-align:left; padding:6px;">Team Name</th>' +
             '<th style="text-align:center; padding:6px;">Score Awarded</th>' +
             '<th style="text-align:center; padding:6px;">Score Pending</th>' +
             '<th style="text-align:center; padding:6px;">Actions</th>' +
             '</tr>';
  for (const team of j.teams) {
    html += `<tr>
      <td style="padding:6px;">${team.name}</td>
      <td style="padding:6px; text-align:center;">${team.score_awarded}</td>
      <td style="padding:6px; text-align:center;">${team.score_pending}</td>
      <td style="padding:6px; text-align:center;">
        <form method="post" style="display:inline" onsubmit="return confirm('Delete team ${team.name}?');">
          <input type="hidden" name="_csrf" value="${CSRF}">
          <input type="hidden" name="delete_team" value="${team.name}">
          <button type="submit" class="secondary">Delete</button>
        </form>
      </td>
    </tr>`;
  }
  html += '</table>';
  teamsTable.innerHTML = html;
}

async function loadTasks() {
  const r = await fetch(`${BASE}/api/get_tasks.php`);
  const j = await r.json();
  if (!j.success) { tasksTable.textContent = j.error || 'Error loading tasks'; return; }
  if (!j.tasks || j.tasks.length === 0) { tasksTable.textContent = 'No tasks found.'; return; }
  let html = '<table style="width:100%; border-collapse:collapse;">' +
             '<tr>' +
             '<th style="text-align:left; padding:6px;">ID</th>' +
             '<th style="text-align:left; padding:6px;">Title</th>' +
             '<th style="text-align:center; padding:6px;">Points</th>' +
             '<th style="text-align:center; padding:6px;">Photos</th>' +
             '<th style="text-align:center; padding:6px;">Videos</th>' +
             '<th style="text-align:center; padding:6px;">Mandatory</th>' +
             '<th style="text-align:center; padding:6px;">Actions</th>' +
             '</tr>';
  for (const task of j.tasks) {
    html += `<tr style="border-bottom:1px solid rgba(0,0,0,0.1);">
      <td style="padding:6px;">${task.id}</td>
      <td style="padding:6px;">${task.mandatory ? '⭐ ' : ''}${task.title}</td>
      <td style="padding:6px; text-align:center;">${task.points}</td>
      <td style="padding:6px; text-align:center;">${task.photos}</td>
      <td style="padding:6px; text-align:center;">${task.videos}</td>
      <td style="padding:6px; text-align:center;">${task.mandatory ? '⭐' : ''}</td>
      <td style="padding:6px; text-align:center; display:flex; flex-direction:column; align-items:center; gap:6px;">
        <button class="edit-task-btn secondary" data-id="${task.id}">Edit</button>
        <button class="delete-task-btn secondary" data-id="${task.id}" data-title="${task.title}">Delete</button>
      </td>
    </tr>`;
  }
  html += '</table>';
  tasksTable.innerHTML = html;

  document.querySelectorAll('.delete-task-btn').forEach(btn => {
    btn.addEventListener('click', () => deleteTask(btn.dataset.id, btn.dataset.title));
  });
}

async function deleteTask(taskId, taskTitle) {
  if (!confirm(`Delete task "${taskTitle}"?`)) return;
  const j = await fieldPost(`${BASE}/api/delete_task.php`, { task_id: taskId });
  if (j.success) { alert(j.message || 'Task deleted!'); loadTasks(); }
  else           { alert(j.error || 'Failed to delete task.'); }
}

document.addEventListener('click', async (e) => {
  if (!e.target.classList.contains('edit-task-btn')) return;
  const id = e.target.dataset.id;
  const r = await fetch(`${BASE}/api/get_tasks.php`);
  const j = await r.json();
  if (!j.success) { alert('Failed to load task data.'); return; }
  const t = j.tasks.find(x => x.id == id);
  if (!t) { alert('Task not found.'); return; }

  const m = document.getElementById('editTaskModal');
  const f = document.getElementById('edit-task-form');
  f.task_id.value     = t.id;
  f.title.value       = t.title;
  f.points.value      = t.points;
  f.photos.value      = t.photos;
  f.videos.value      = t.videos;
  f.description.value = t.description || '';
  f.mandatory.checked = !!t.mandatory;
  m.classList.remove('hidden');
});

const editForm = document.getElementById('edit-task-form');
if (editForm) {
  editForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const j = await formPost(`${BASE}/api/edit_task.php`, editForm);
    alert(j.message || j.error || 'Update complete.');
    document.getElementById('editTaskModal').classList.add('hidden');
    loadTasks();
  });
}
document.querySelector('.close-edit').onclick = () => document.getElementById('editTaskModal').classList.add('hidden');

async function openModal(team, task) {
  const r = await fetch(`${BASE}/api/get_submission.php?team=${encodeURIComponent(team)}&task=${task}`);
  const j = await r.json();
  if (!j.success) { alert(j.error || 'Load failed'); return; }
  current = { team, task };
  modalTitle.textContent = `${team} – ${j.task.title} (${j.task.points} pts)`;
  modalMedia.innerHTML = '';

  j.files.forEach((f, idx) => {
    const tile = document.createElement('div');
    tile.className = 'media-tile';
    tile.style.cssText = 'display:inline-block; vertical-align:top; margin:6px; max-width:220px;';

    if (f.type.startsWith('image/')) {
      if (f.has_thumb && f.thumb_url) {
        const img = document.createElement('img');
        img.src = f.thumb_url;
        img.className = 'preview-img';
        img.style.cursor = 'zoom-in';
        img.title = f.name;
        img.addEventListener('click', () => window.open(f.url, '_blank', 'noopener'));
        tile.appendChild(img);
      } else {
        const link = document.createElement('a');
        link.href = f.url;
        link.target = '_blank';
        link.rel = 'noopener';
        link.textContent = '📷 Open ' + f.name;
        link.className = 'secondary';
        link.style.cssText = 'display:inline-block; padding:24px; border:1px dashed #aaa; border-radius:8px; text-align:center;';
        tile.appendChild(link);
      }
    } else if (f.type.startsWith('video/')) {
      const placeholder = document.createElement('button');
      placeholder.type = 'button';
      placeholder.className = 'secondary';
      placeholder.textContent = '▶ Load video ' + (idx + 1);
      placeholder.style.cssText = 'padding:24px; min-width:180px;';
      placeholder.addEventListener('click', () => {
        const v = document.createElement('video');
        v.src = f.url;
        v.controls = true;
        v.preload = 'metadata';
        v.className = 'preview-video';
        tile.replaceChild(v, placeholder);
      });
      tile.appendChild(placeholder);
    }
    modalMedia.appendChild(tile);
  });

  rejectNote.value = '';
  rejectBlock.classList.add('hidden');
  modal.classList.remove('hidden');
}

document.getElementById('approve-btn').onclick = async () => {
  if (!current) return;
  const j = await jsonPost(`${BASE}/api/approve.php`, current);
  alert(j.message || 'Approved');
  modal.classList.add('hidden');
  loadQueue(); loadTeams(); loadTasks();
};

document.getElementById('reject-btn').onclick = () => {
  rejectBlock.classList.toggle('hidden');
};
document.getElementById('reject-send').onclick = async () => {
  if (!current) return;
  const note = rejectNote.value.trim();
  const j = await jsonPost(`${BASE}/api/reject.php`, { ...current, note });
  alert(j.message || 'Rejected');
  modal.classList.add('hidden');
  loadQueue(); loadTeams(); loadTasks();
};

const addTaskForm = document.getElementById('add-task-form');
if (addTaskForm) {
  addTaskForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const j = await formPost(`${BASE}/api/add_task.php`, addTaskForm);
    if (j.success) { alert(j.message || 'Task added!'); addTaskForm.reset(); loadTasks(); }
    else           { alert(j.error || 'Failed to add task.'); }
  });
}

// End-game flow
const endGameModal = document.getElementById('endGameModal');
const endGameConfirm = document.getElementById('end-game-confirm');
const endGameGo      = document.getElementById('end-game-go');

document.getElementById('end-game-btn').onclick = () => {
  endGameConfirm.value = '';
  endGameGo.disabled = true;
  endGameModal.classList.remove('hidden');
};
document.querySelector('.close-end-game').onclick = () => endGameModal.classList.add('hidden');
endGameModal.onclick = e => { if (e.target === endGameModal) endGameModal.classList.add('hidden'); };

endGameConfirm.addEventListener('input', () => {
  endGameGo.disabled = endGameConfirm.value.trim() !== 'END GAME';
});

endGameGo.onclick = async () => {
  if (endGameGo.disabled) return;
  endGameGo.disabled = true;
  endGameGo.textContent = 'Working…';
  const j = await jsonPost(`${BASE}/api/end_game.php`, {
    delete_teams:      document.getElementById('end-delete-teams').checked,
    delete_tasks:      document.getElementById('end-delete-tasks').checked,
    reset_event_times: document.getElementById('end-reset-times').checked,
  });
  if (j.success) {
    window.location.href = `${BASE}/admin/dashboard.php?archived=${encodeURIComponent(j.label)}`;
  } else {
    alert(j.message || 'End game failed.');
    endGameGo.disabled = false;
    endGameGo.textContent = 'Archive & reset';
  }
};

document.querySelectorAll('textarea.auto-expand').forEach(area => {
  area.addEventListener('input', () => { area.style.height = 'auto'; area.style.height = area.scrollHeight + 'px'; });
});

document.querySelector('.close').onclick = () => modal.classList.add('hidden');
modal.onclick = e => { if (e.target === modal) modal.classList.add('hidden'); };

loadQueue(); loadTeams(); loadTasks();
setInterval(loadQueue, 10000);
setInterval(loadTeams, 15000);
setInterval(loadTasks, 20000);
</script>

<p class="center"><a href="<?=BASE_URL?>/logout.php">Log Out</a></p>
</body>
</html>

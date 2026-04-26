<?php
// lib/theme_picker.php — player-facing theme selector.
// Stores the pick in a cookie scoped to BASE_URL; the admin's site-wide
// default in settings.theme still applies to anyone without a cookie.
// Falls through to the site default when the user picks "Site default".

if (!defined('BASE_URL')) require_once __DIR__ . '/config.php';
$_tp_active = current_theme();
$_tp_path   = BASE_URL !== '' ? BASE_URL : '/';
?>
<div class="card">
  <details>
    <summary><strong>Theme</strong> &nbsp;<span class="small">(current: <?=htmlspecialchars($_tp_active)?>)</span></summary>
    <p class="small">Pick a colour scheme for your view. Saved on this device only.</p>
    <div class="theme-grid">
      <?php foreach (THEMES as $_tp_slug):
        $_tp_sel = $_tp_slug === $_tp_active ? ' selected' : ''; ?>
        <button type="button" class="theme-card<?=$_tp_sel?>" data-theme-pick="<?=htmlspecialchars($_tp_slug)?>">
          <div class="theme-swatch" data-preview="<?=htmlspecialchars($_tp_slug)?>"></div>
          <strong><?=htmlspecialchars(ucfirst($_tp_slug))?></strong>
        </button>
      <?php endforeach; ?>
      <button type="button" class="theme-card" data-theme-pick="">
        <div class="theme-swatch" style="background:repeating-linear-gradient(45deg,#ccc,#ccc 6px,#fff 6px,#fff 12px);"></div>
        <strong>Site default</strong>
      </button>
    </div>
  </details>
</div>

<script>
(function () {
  const path = <?=json_encode($_tp_path)?>;
  document.querySelectorAll('[data-theme-pick]').forEach(btn => {
    btn.addEventListener('click', () => {
      const t = btn.getAttribute('data-theme-pick');
      if (t === '') {
        document.cookie = 'theme=; path=' + path + '; max-age=0; samesite=Lax';
      } else {
        document.cookie = 'theme=' + encodeURIComponent(t) + '; path=' + path + '; max-age=31536000; samesite=Lax';
      }
      location.reload();
    });
  });
})();
</script>

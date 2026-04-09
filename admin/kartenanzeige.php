<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/layout.php';

$admin = sv_require_admin();
$pdo   = sv_pdo();
$base  = sv_base_url();

// Settings lesen
function getSetting(PDO $pdo, string $key, string $default = ''): string {
  $stmt = $pdo->prepare("SELECT value FROM settings WHERE `key` = ?");
  $stmt->execute([$key]);
  $row = $stmt->fetch();
  return $row ? $row['value'] : $default;
}
function setSetting(PDO $pdo, string $key, string $value): void {
  $pdo->prepare("INSERT INTO settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)")
      ->execute([$key, $value]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  sv_csrf_check();
  $fields = ['difficulty', 'composer', 'arranger', 'duration', 'genre', 'info', 'shop_url'];
  $show = [];
  foreach ($fields as $f) {
    if (!empty($_POST['show_' . $f])) $show[] = $f;
  }
  setSetting($pdo, 'vote_display_fields', implode(',', $show));
  sv_flash_set('success', 'Anzeigeeinstellungen gespeichert.');
  header('Location: ' . $base . '/admin/kartenanzeige.php');
  exit;
}

$currentFields = array_filter(explode(',', getSetting($pdo, 'vote_display_fields', '')));

sv_header('Admin – Abstimmungsanzeige', $admin);
?>

<div class="page-header">
  <div>
    <h2>Abstimmungsanzeige</h2>
    <div class="muted">Welche Zusatzinfos sollen auf den Abstimmungskarten angezeigt werden?</div>
  </div>
  <a class="btn" href="<?=h($base)?>/admin/">← Verwaltung</a>
</div>

<div class="card">
  <form method="post" class="grid" style="gap:16px">
    <input type="hidden" name="csrf" value="<?=h(sv_csrf_token())?>">

    <div>
      <div class="sv-dialog__section-label" style="margin-bottom:12px">Standard (immer sichtbar)</div>
      <div style="display:flex;flex-direction:column;gap:8px">
        <div style="display:flex;align-items:center;gap:10px;padding:10px 14px;background:#f5f2ee;border-radius:10px;opacity:.7;min-width:0">
          <span style="font-size:16px;flex-shrink:0">🎵</span>
          <span style="font-weight:600;font-size:14px">Titel</span>
          <span class="badge" style="margin-left:auto;flex-shrink:0">immer</span>
        </div>
        <div style="display:flex;align-items:center;gap:10px;padding:10px 14px;background:#f5f2ee;border-radius:10px;opacity:.7;min-width:0">
          <span style="font-size:16px;flex-shrink:0">▶</span>
          <span style="font-weight:600;font-size:14px">YouTube-Link</span>
          <span class="badge" style="margin-left:auto;flex-shrink:0">immer</span>
        </div>
      </div>
    </div>

    <hr style="border:none;border-top:1px solid var(--border)">

    <div>
      <div class="sv-dialog__section-label" style="margin-bottom:12px">Optionale Felder</div>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:10px">

        <?php
        $options = [
          'difficulty' => ['icon' => '🎯', 'label' => 'Grad', 'sub' => 'Als farbige Pille'],
          'composer'   => ['icon' => '🎼', 'label' => 'Komponist',          'sub' => 'Name des Komponisten'],
          'arranger'   => ['icon' => '✏️', 'label' => 'Arrangeur',           'sub' => 'Name des Arrangeurs'],
          'duration'   => ['icon' => '⏱',  'label' => 'Dauer',               'sub' => "z.B. 6:30"],
          'genre'      => ['icon' => '🏷️', 'label' => 'Genre',               'sub' => 'z.B. Marsch'],
          'info'       => ['icon' => 'ℹ️',  'label' => 'Info-Text',           'sub' => 'Freitext-Infofeld'],
          'shop_url'   => ['icon' => '🛒', 'label' => 'Noten-Link',          'sub' => 'Link zum Händler'],
        ];
        foreach ($options as $key => $opt):
          $checked = in_array($key, $currentFields);
        ?>
        <label style="display:flex;align-items:center;gap:10px;padding:12px 14px;border:1.5px solid <?=$checked?'var(--green)':'var(--border)'?>;border-radius:12px;cursor:pointer;transition:border-color .15s;background:<?=$checked?'var(--green-light)':'#fff'?>;min-width:0">
          <input type="checkbox" name="show_<?=h($key)?>" value="1" <?=$checked?'checked':''?>
                 onchange="this.closest('label').style.borderColor=this.checked?'var(--green)':'var(--border)';this.closest('label').style.background=this.checked?'var(--green-light)':'#fff'">
          <span style="font-size:18px;line-height:1"><?=$opt['icon']?></span>
          <div>
            <div style="font-weight:700;font-size:14px;color:var(--text)"><?=h($opt['label'])?></div>
            <div style="font-size:12px;color:var(--muted)"><?=h($opt['sub'])?></div>
          </div>
        </label>
        <?php endforeach; ?>

      </div>
    </div>

    <div>
      <button class="btn primary" type="submit">Einstellungen speichern</button>
    </div>

  </form>
</div>

<div class="card" style="margin-top:12px">
  <h3>Vorschau</h3>
  <div class="small" style="margin-bottom:12px">So sieht eine Abstimmungskarte mit den gewählten Feldern aus:</div>
  <div class="song-card voted-ja" style="pointer-events:none">
    <div style="position:relative">
      <div class="song-title">Beispieltitel – Musterkomponist</div>
      <?php if(in_array('composer',$currentFields) || in_array('arranger',$currentFields)): ?>
        <div class="small" style="color:var(--muted);margin-bottom:2px">
          <?= implode(' · ', array_filter([
            in_array('composer',$currentFields) ? 'Max Muster' : '',
            in_array('arranger',$currentFields) ? 'Arr. Anna Beispiel' : '',
          ])) ?>
        </div>
      <?php endif; ?>
      <a class="song-link">▶ YouTube öffnen</a>
      <?php
        $pb=[];
        if(in_array('difficulty',$currentFields)) $pb[]=sv_diff_pill(3.5);
        if(in_array('duration',$currentFields))   $pb[]='<span class="badge">&#9203; 6\'30</span>';
        if(in_array('genre',$currentFields))       $pb[]='<span class="badge">Konzertmarsch</span>';
        if(in_array('shop_url',$currentFields))   $pb[]='<a class="badge" style="text-decoration:none" title="Noten ansehen">🛒 Noten</a>';
        if($pb):
      ?><div style="display:flex;flex-wrap:wrap;gap:4px;margin-top:5px"><?=implode('',$pb)?></div><?php endif; ?>
      <?php if(in_array('info',$currentFields)): ?>
        <div class="small" style="color:var(--muted);margin-top:6px">ℹ Noten können bei Musikhaus Muster geliehen werden.</div>
      <?php endif; ?>
    </div>
    <div class="vote-buttons">
        <button class="vote-btn ja selected">✓ Ja</button>
        <button class="vote-btn neutral">– Neutral</button>
        <button class="vote-btn nein">✗ Nein</button>
        <button class="vote-btn reset-btn">✕</button>
    </div>
    <div class="vote-note">
      <textarea rows="2" placeholder="Optionale Notiz…" disabled></textarea>
    </div>
  </div>
</div>

<?php sv_footer(); ?>

<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/layout.php';

$admin  = sv_require_leitung();
$frozen = sv_is_frozen();
$pdo    = sv_pdo();

function sv_fmt_last_activity($dt): string {
  if (!$dt) return '–';
  $ts = strtotime($dt);
  if (!$ts) return '–';
  $diff = time() - $ts;
  $abs  = abs($diff);
  if ($abs < 60)    $rel = 'gerade eben';
  elseif($abs < 3600)  $rel = (int)round($abs/60).' Min.';
  elseif($abs < 86400) $rel = (int)round($abs/3600).' Std.';
  else                  $rel = (int)round($abs/86400).' Tg.';
  return date('d.m.Y H:i', $ts) . " <span class='small'>($rel)</span>";
}

$total = (int)$pdo->query("SELECT COUNT(*) FROM songs WHERE is_active=1 AND deleted_at IS NULL")->fetchColumn();

$rows = $pdo->query("
  SELECT u.id, u.username, u.display_name, u.is_admin, u.role, u.is_active,
         ua.last_activity,
         (SELECT COUNT(*) FROM votes v JOIN songs s ON s.id=v.song_id WHERE v.user_id=u.id AND s.is_active=1 AND s.deleted_at IS NULL) AS done,
         (SELECT MAX(v.updated_at) FROM votes v JOIN songs s ON s.id=v.song_id WHERE v.user_id=u.id AND s.is_active=1 AND s.deleted_at IS NULL) AS last_vote
  FROM users u
  LEFT JOIN user_activity ua ON ua.user_id=u.id
  ORDER BY done DESC, last_vote ASC, u.display_name ASC, u.username ASC
")->fetchAll();

sv_header('Admin – Fortschritt', $admin);
$base = sv_base_url();
?>

<div class="page-header">
  <div>
    <h2>Fortschritt</h2>
    <div class="small">Sortiert nach Fortschritt. Bei Gleichstand steht der oben, der früher fertig war.</div>
  </div>
  <a class="btn" href="<?=h($base)?>/admin/">← Verwaltung</a>
</div>

<?php if ($frozen): ?>
<div class="freeze-banner" style="margin-bottom:16px">
  🔒 <span>Abstimmung ist aktuell eingefroren.</span>
</div>
<?php endif; ?>

<div class="card" style="overflow:auto">
  <table>
    <thead>
      <tr>
        <th>Teilnehmer</th>
        <th>Letzte Aktivität</th>
        <th>Bewertet</th>
        <th>Offen</th>
        <th style="min-width:140px">Fortschritt</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r):
        $done = (int)$r['done'];
        $open = max(0, $total - $done);
        $pct  = $total ? round(($done/$total)*100) : 0;
        $name = $r['display_name'] ?: $r['username'];
      ?>
      <tr>
        <td>
          <strong><?=h($name)?></strong>
          <div class="small"><?=h($r['username'])?>
            · <?= sv_role_badge($r['role'] ?? ($r['is_admin'] ? 'admin' : 'user')) ?>
            <?php if((int)$r['is_active']!==1): ?> · <span class="badge">inaktiv</span><?php endif; ?>
          </div>
        </td>
        <td class="small"><?= sv_fmt_last_activity($r['last_activity']) ?></td>
        <td><?=h($done)?> / <?=h($total)?></td>
        <td><?=h($open)?></td>
        <td>
          <div class="small" style="margin-bottom:4px"><?=h($pct)?>%</div>
          <div class="progress"><div style="width:<?=$pct?>%"></div></div>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if(!$rows): ?><tr><td colspan="5" class="small">Keine Benutzer.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<?php sv_footer(); ?>

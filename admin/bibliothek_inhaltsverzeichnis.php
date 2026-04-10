<?php
require_once __DIR__ . '/../lib/auth.php';

$user = sv_require_login();
if (!sv_can_edit_noten($user)) { http_response_code(403); exit('Forbidden'); }
$pdo  = sv_pdo();
$base = sv_base_url();
$cfg  = sv_config();

// Alle Stücke mit Mappe, alphabetisch
$pieces = $pdo->query("
  SELECT title, composer, arranger, publisher, duration, difficulty, owner
  FROM pieces
  WHERE binder IS NOT NULL AND binder != ''
  ORDER BY title ASC
")->fetchAll();

$date  = date('d.m.Y');
$count = count($pieces);
$org   = $cfg['branding']['org_name'] ?? 'SBO Hildesheim';

// Logo als Base64 einbetten
$logoPath = __DIR__ . '/../assets/logo.svg';
$logoData = file_exists($logoPath) ? base64_encode(file_get_contents($logoPath)) : '';
$logoSrc   = $logoData ? 'data:image/svg+xml;base64,' . $logoData : '';
$accentRed   = sv_setting_get('color_primary', '#c1090f');
$accentHover = sv_color_darken($accentRed, 0.15);

function h2(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8'); }
function diffLabel(mixed $d): string {
  if ($d === null || $d === '') return '';
  $d = (float)$d;
  return number_format($d, 1);
}
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<title>Inhaltsverzeichnis – <?=h2($org)?></title>
<style>
  * { margin:0; padding:0; box-sizing:border-box; }
  body {
    font-family: 'Georgia', serif;
    font-size: 11pt;
    color: #1a1a1a;
    background: #fff;
    padding: 0;
  }

  /* Print & screen layout */
  .page {
    width: 210mm;
    min-height: 297mm;
    margin: 0 auto;
    padding: 18mm 18mm 20mm 18mm;
    background: #fff;
  }

  /* Header */
  .header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 20px;
    margin-bottom: 10mm;
    padding-bottom: 6mm;
    border-bottom: 2px solid <?=$accentRed?>;
  }
  .header-text h1 {
    font-size: 22pt;
    font-weight: 700;
    color: <?=$accentRed?>;
    letter-spacing: -0.02em;
    line-height: 1.1;
    margin-bottom: 3px;
  }
  .header-text .subtitle {
    font-size: 10pt;
    color: #666;
  }
  .header-logo img {
    height: 28mm;
    width: auto;
  }

  /* Meta */
  .meta {
    display: flex;
    justify-content: space-between;
    font-size: 9pt;
    color: #888;
    margin-bottom: 7mm;
  }

  /* Table */
  table {
    width: 100%;
    border-collapse: collapse;
  }
  thead tr {
    background: <?=$accentRed?>;
    color: #fff;
  }
  thead th {
    padding: 5px 8px;
    text-align: left;
    font-size: 8.5pt;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
  }
  thead th:last-child { text-align: center; }

  tbody tr { border-bottom: 0.5px solid #e0e0e0; }
  tbody tr:nth-child(even) { background: #fafafa; }
  tbody tr:last-child { border-bottom: none; }

  td {
    padding: 4.5px 8px;
    vertical-align: top;
    font-size: 9.5pt;
    line-height: 1.35;
  }
  td.title { font-weight: 600; }
  td.composer { color: #444; }
  td.small-col { font-size: 8.5pt; color: #555; }
  td.center { text-align: center; }

  .arr { font-size: 8pt; color: #888; display: block; }

  /* Footer */
  .footer {
    margin-top: 8mm;
    padding-top: 4mm;
    border-top: 1px solid #ddd;
    font-size: 8pt;
    color: #aaa;
    text-align: center;
  }

  /* Screen only */
  @media screen {
    body { background: #e8e5e0; }
    .page { margin: 20px auto; box-shadow: 0 4px 40px rgba(0,0,0,.2); }
    .print-btn {
      display: block;
      text-align: center;
      padding: 14px;
      background: <?=$accentRed?>;
      color: #fff;
      font-family: sans-serif;
      font-size: 14px;
      font-weight: 700;
      cursor: pointer;
      border: none;
      width: 100%;
      letter-spacing: .04em;
    }
    .print-btn:hover { background: <?=$accentHover?>; }
  }

  @media print {
    body { background: #fff; padding: 0; }
    .page { width: 100%; margin: 0; padding: 15mm 15mm 18mm 15mm; box-shadow: none; }
    .print-btn { display: none; }
    thead { display: table-header-group; }
    tr { page-break-inside: avoid; }
    th { background: none !important; color: #000 !important; font-weight: 700; border-bottom: 2px solid #000; }
  }
</style>
</head>
<body>

<button class="print-btn" onclick="window.print()">🖨 Als PDF speichern / Drucken</button>

<div class="page">

  <div class="header">
    <div class="header-text">
      <h1>SBO Hildesheim</h1>
      <div class="subtitle">Inhaltsverzeichnis · <?=h2($org)?></div>
    </div>
    <?php if ($logoSrc): ?>
    <div class="header-logo">
      <img src="<?=$logoSrc?>" alt="Logo">
    </div>
    <?php endif; ?>
  </div>

  <div class="meta">
    <span><?=$count?> Stücke sind in der Mappe</span>
    <span>Stand: <?=$date?></span>
  </div>

  <table>
    <thead>
      <tr>
        <th style="width:5mm">#</th>
        <th style="width:90mm">Titel</th>
        <th style="text-align:left">Komponist / Arrangeur</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($pieces as $i => $p): ?>
      <tr>
        <td class="small-col center"><?=$i+1?></td>
        <td class="title"><?=h2($p['title'])?></td>
        <td class="composer">
          <?=h2($p['composer'] ?? '–')?>
          <?php if(!empty($p['arranger'])): ?>
            <span class="arr">Arr. <?=h2($p['arranger'])?></span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$pieces): ?>
      <tr><td colspan="3" style="text-align:center;padding:20px;color:#aaa">Keine Stücke mit Mappe gefunden.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>

  <div class="footer">
    Erstellt am <?=$date?> · KlangVotum · <?=h2($org)?>
  </div>

</div>

</body>
</html>

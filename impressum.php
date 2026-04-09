<?php
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/layout.php';

sv_header('Impressum');
$custom = sv_setting_get('impressum_html', '');
?>

<div class="page-header">
  <div>
    <h2>Impressum</h2>
    <div class="small">Angaben gemäß § 5 TMG</div>
  </div>
</div>

<?php if ($custom !== ''): ?>
<div class="card"><?=$custom?></div>
<?php else: ?>
<div class="card" style="margin-bottom:12px">
  <h3>Verantwortlich</h3>
  <p><strong>Tobias Kropp</strong><br>
  Wanneweg 2<br>
  31162 Bad Salzdetfurth<br>
  Deutschland</p>
  <p><strong>E-Mail:</strong> <a href="mailto:kropp.tobias@gmail.com">kropp.tobias@gmail.com</a></p>
</div>

<div class="card" style="margin-bottom:12px">
  <h3>Haftungsausschluss</h3>
  <p>Diese Webanwendung dient ausschließlich der internen Abstimmung des Orchesters SBO der Musikschule Hildesheim
  und ist nicht für die Öffentlichkeit bestimmt. Der Zugang ist auf registrierte Nutzer beschränkt.</p>
  <p>Trotz sorgfältiger Kontrolle übernehmen wir keine Haftung für die Inhalte externer Links (z.&nbsp;B. YouTube-Links).
  Für den Inhalt der verlinkten Seiten sind ausschließlich deren Betreiber verantwortlich.</p>
</div>

<div class="card">
  <h3>Urheberrecht</h3>
  <p>Die durch den Seitenbetreiber erstellten Inhalte und Werke auf dieser Seite unterliegen dem deutschen Urheberrecht.
  Die Vervielfältigung, Bearbeitung, Verbreitung und jede Art der Verwertung außerhalb der Grenzen des Urheberrechtes
  bedürfen der schriftlichen Zustimmung des jeweiligen Autors.</p>
</div>
<?php endif; ?>

<?php sv_footer(); ?>

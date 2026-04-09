<?php
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/layout.php';

sv_header('Datenschutzerklärung');
$custom = sv_setting_get('datenschutz_html', '');
?>

<div class="page-header">
  <div>
    <h2>Datenschutzerklärung</h2>
    <div class="small">Gemäß DSGVO (EU) 2016/679</div>
  </div>
</div>

<?php if ($custom !== ''): ?>
<div class="card"><?=$custom?></div>
<?php else: ?>
<div class="card" style="margin-bottom:12px">
  <h3>1. Verantwortlicher</h3>
  <p><strong>Tobias Kropp</strong><br>
  E-Mail: <a href="mailto:kropp.tobias@gmail.com">kropp.tobias@gmail.com</a></p>
</div>

<div class="card" style="margin-bottom:12px">
  <h3>2. Zweck der Anwendung</h3>
  <p>KlangVotum ist ein privates Tool für die interne Abstimmung über Musikstücke zur Vorbereitung von Konzerten.
  Die Nutzung ist auf registrierte und autorisierte Personen beschränkt.</p>
</div>

<div class="card" style="margin-bottom:12px">
  <h3>3. Gespeicherte Daten</h3>
  <table>
    <thead>
      <tr><th>Datenkategorie</th><th>Zweck</th><th>Rechtsgrundlage</th></tr>
    </thead>
    <tbody>
      <tr><td>Benutzername, Anzeigename</td><td>Anmeldung und Identifikation</td><td>Art. 6 Abs. 1 lit. b DSGVO</td></tr>
      <tr><td>Passwort (bcrypt-Hash)</td><td>Authentifizierung</td><td>Art. 6 Abs. 1 lit. b DSGVO</td></tr>
      <tr><td>Stimmen und Notizen</td><td>Durchführung der Abstimmung</td><td>Art. 6 Abs. 1 lit. b DSGVO</td></tr>
      <tr><td>IP-Adresse, letzte Aktivität</td><td>Sicherheit, Missbrauchsschutz</td><td>Art. 6 Abs. 1 lit. f DSGVO</td></tr>
      <tr><td>Login-Protokoll</td><td>Sicherheit, Nachvollziehbarkeit</td><td>Art. 6 Abs. 1 lit. f DSGVO</td></tr>
    </tbody>
  </table>
  <div class="small" style="margin-top:10px">Passwörter werden ausschließlich als bcrypt-Hash gespeichert – nicht im Klartext.</div>
</div>

<div class="card" style="margin-bottom:12px">
  <h3>4. Keine Weitergabe an Dritte</h3>
  <p>Deine Daten werden nicht an Dritte weitergegeben, verkauft oder für Werbezwecke genutzt.
  Sie verbleiben ausschließlich auf dem Webserver dieser Anwendung.</p>
</div>

<div class="card" style="margin-bottom:12px">
  <h3>5. Externe Inhalte (YouTube)</h3>
  <p>Die Anwendung enthält Links zu YouTube-Videos. Diese werden nur als externe Verweise angezeigt –
  YouTube-Inhalte werden nicht eingebettet. Beim Klick verlässt du diese Anwendung;
  es gelten die Datenschutzbestimmungen von YouTube (Google LLC).</p>
</div>

<div class="card" style="margin-bottom:12px">
  <h3>6. Speicherdauer</h3>
  <p>Deine Daten werden für die Dauer der Nutzung gespeichert.
  Nach Abschluss der Abstimmung oder auf Anfrage werden deine Daten gelöscht.
  Login-Protokolle werden nach spätestens 2 Tagen automatisch gelöscht.</p>
</div>

<div class="card" style="margin-bottom:12px">
  <h3>7. Deine Rechte</h3>
  <p>Du hast gemäß DSGVO das Recht auf Auskunft (Art. 15), Berichtigung (Art. 16), Löschung (Art. 17),
  Einschränkung (Art. 18), Widerspruch (Art. 21) sowie das Recht auf Beschwerde bei der zuständigen Datenschutzbehörde.</p>
  <p>Zur Ausübung deiner Rechte: <a href="mailto:kropp.tobias@gmail.com">kropp.tobias@gmail.com</a></p>
</div>

<div class="card">
  <h3>8. Cookies und Sessions</h3>
  <p>Diese Anwendung verwendet ausschließlich einen technisch notwendigen Session-Cookie zur Aufrechterhaltung deiner Anmeldung.
  Dieser Cookie enthält keine personenbezogenen Daten und wird beim Schließen des Browsers bzw. nach 60 Minuten Inaktivität gelöscht.
  Es werden keine Tracking- oder Werbe-Cookies eingesetzt.</p>
</div>
<?php endif; ?>

<?php sv_footer(); ?>

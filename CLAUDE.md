# KlangVotum v2 — Projektkontext

## Tech-Stack & Architektur

- **PHP 8.2** — Kein Framework, reine PHP-Dateien
- **MariaDB** — PDO-Singleton via `sv_pdo()` in `lib/db.php`
- **CSS** — Eine Datei `assets/app.css`, CSS Custom Properties, kein Preprocessor
- **Hosting** — Shared Hosting AllInkl, FTP-Deployment
- **`configlive.php`** — DB-Zugangsdaten, darf NIEMALS ins Git oder überschrieben werden
- **`configv2.php`** — Lokale Entwicklungskonfiguration (in `.gitignore`)

## Kernkonventionen

### PHP-Funktionen
- Alle eigenen Hilfsfunktionen haben das Prefix `sv_*`
- `sv_pdo()` — PDO-Singleton
- `sv_ensure_schema(PDO)` — Auto-Migration, erstellt/ändert Tabellen bei Bedarf
- `sv_setting_get(key, default)` — Liest aus `app_settings` mit statischem Cache (funktioniert, weil POST-Handler immer redirecten)
- `sv_setting_set(key, value)` — Schreibt in `app_settings`
- `sv_setting_delete(key)` — Löscht aus `app_settings`
- `sv_color_darken(hex, factor)` — Hex-Farbe abdunkeln
- `sv_color_mix(hex, base, amount)` — Zwei Farben mischen
- `sv_color_variants(hex)` — Gibt `[light, mid]` Varianten zurück (10% bzw. 35% mit Weiß gemischt)
- `sv_color_contrast(hex)` — WCAG-Luminanz, gibt `#ffffff` oder `#1a1a18` zurück
- `sv_diff_style(float $d)` — Gibt CSS-Inline-Style für Schwierigkeitsgrad-Badge zurück
- `sv_diff_pill(mixed $d)` — Gibt fertiges `<span class="badge">` HTML für Schwierigkeitsgrad zurück
- `sv_log(user_id, action, details)` — Chronik/Audit-Log
- `sv_flash_set/get` — Flash-Messages über Session
- `sv_csrf_token() / sv_csrf_check()` — CSRF-Schutz
- `h()` — Alias für `htmlspecialchars()`

### Datenbankstruktur
- `app_settings` — Key-Value-Tabelle für Runtime-Konfiguration (allgemeine App-Einstellungen)
- `settings` — separate Key-Value-Tabelle NUR für die Kartenanzeige (`admin/kartenanzeige.php`, `index.php`) — nicht verwechseln mit `app_settings`
- Soft-Delete-Pattern: `deleted_at` Spalte statt echtem Löschen (Konzerte, Abstimmungstitel, Bibliothek)
- `sv_ensure_schema()` in `lib/db.php` erstellt **alle** Tabellen automatisch beim ersten Aufruf — eine frische Installation braucht keinen manuellen SQL-Import mehr (Stand 2026-07-31; vorher fehlten `users`/`songs`/`votes`/`vote_notes`/`audit_log`/`settings`, deren Struktur wurde per `SHOW CREATE TABLE` aus der Live-DB rekonstruiert und 1:1 übernommen)
- Zentrale Tabellen: `users`, `songs`, `votes`, `vote_notes`, `pieces`, `vote_history`, `concerts`, `concert_pieces`, `concert_plans`, `concert_plan_items`, `piece_loans`, `piece_suggestions`, `tags`/`piece_tags`/`song_tags`, `app_settings`, `settings`, `audit_log`
- `votes`/`vote_notes` haben Foreign Keys auf `users.id` und `songs.id` (ON DELETE CASCADE) — deshalb werden sie in `sv_ensure_schema()` erst NACH den Basis-Tabellen `users`/`songs` angelegt

### Genre-System (Tags)
- **Technisch** 3 Tabellen: `tags`, `piece_tags`, `song_tags` (Many-to-Many)
- **UI-Label** ist "Genre" (nicht "Tags"), technische Variablennamen bleiben `tag`/`tags`
- Genre-Widget: Dropdown + Chips (Auswahl per `<select>`, gewählte als entfernbare Badges)
- Neues Genre inline anlegbar (Textfeld + Button im Widget)
- Genre global löschbar über API (`api/tag.php`) — nur wenn nirgends vergeben
- Alte `genre` VARCHAR-Spalte existiert noch in DB, wird aber nicht mehr genutzt
- Migration: `sv_ensure_schema()` liest einmalig bestehende Genre-Texte und splittet sie in Tags
- Helper-Funktionen: `sv_all_tags()`, `sv_tags_for_piece/song/pieces/songs()`, `sv_sync_tags()`, `sv_tag_badges()`, `sv_tag_widget()`
- CSV Import/Export behält Spaltenname `genre` für Kompatibilität, trennt mehrere Genres mit ` / `
- Sync: Wenn Piece→Song oder Song→Piece übertragen wird, werden Genres mitkopiert

### Rollen
- `admin` — Vollzugriff, immer "Admin" (nicht umbenennbar)
- `leitung` — Erweiterte Rechte (Label konfigurierbar, z.B. "Dirigent")
- `user` — Normaler Nutzer (Label konfigurierbar, z.B. "O-Rat", "Mitglied")
- Labels aus `sv_setting_get('user_role_label')` und `sv_setting_get('leitung_role_label')`

## Farbsystem (WICHTIG)

### Zwei konfigurierbare Farben
Admins stellen in `admin/einstellungen.php` nur **zwei Farben** ein:
1. **Primärfarbe** (`color_primary`, Default: `#c1090f`) — Buttons, Links, Topbar-Akzente
2. **Sekundärfarbe** (`color_secondary`, Default: `#7a8c0a`) — Navigation, Bestätigungen, Checkboxen

### Automatisch berechnete Varianten
In `lib/layout.php` werden aus den 2 Farben 5+1 CSS-Variablen erzeugt:
- `--accent` = Primärfarbe
- `--accent-hover` = Primärfarbe 15% dunkler (`sv_color_darken`)
- `--green` = Sekundärfarbe
- `--green-light` = Sekundärfarbe 10% + 90% Weiß (`sv_color_variants`)
- `--green-mid` = Sekundärfarbe 35% + 65% Weiß (`sv_color_variants`)
- `--green-on` = Auto-Kontrast-Textfarbe (Weiß oder Schwarz je nach Sekundärfarbe-Helligkeit)

### Feste Farben (NIEMALS dynamisch)
- `--red: #C1090F` — Alles was mit Löschen/Gefahr zu tun hat bleibt IMMER rot
- `--red-soft: #fdecea` — Heller Rot-Hintergrund
- `--score: #7a8c0a` — Score-Anzeige immer im Original-Grün
- `--score-light: #f2f5e4` — Score-Hintergrund
- `--score-mid: #c8d4a5` — Score-Rahmen

### Schwierigkeitsgrad-Badges (sv_diff_pill / sv_diff_style)
- 12-Stufen-Farbskala von 0.5 (grau) über 3.0 (kräftig grün) bis 6.0 (rot-rosa)
- Zentral in `lib/db.php` definiert, alle Seiten nutzen `sv_diff_pill()`
- JS-Spiegelung in `admin/bibliothek.php` als `diffStyle()` für Detail-Panel
- **WICHTIG: String-Keys im PHP-Array verwenden!** PHP castet Float-Keys zu Int, d.h. `3.0 =>` und `3.5 =>` werden beide zu Key `3` — Werte überschreiben sich. Immer `'3.0' =>` schreiben.
- Rundung auf 0.5er-Schritte via `number_format()` → String-Lookup

### Duplikatprüfung Bibliothek
- Beim **Anlegen**: Prüfung gegen `pieces` UND `songs` (verhindert Doppel-Einträge)
- Beim **Bearbeiten**: Prüfung nur gegen `pieces` (Songs-Prüfung übersprungen, da das Stück bereits verknüpft sein kann)

### Suchtext bleibt nach Aktionen erhalten (Bibliothek + Abstimmungstitel)
- Die Live-Suche (`bib-live-q`/`song-live-q`) ist rein clientseitig, filtert nur die bereits geladene Tabelle — kein Seiten-Reload beim Tippen
- Jedes Formular auf der Seite trägt ein verstecktes `_q`-Feld; ein globaler `document.addEventListener('submit', ..., true)` überschreibt dessen Wert unmittelbar vor dem Absenden mit dem AKTUELLEN Inhalt des Suchfelds (nicht dem beim Seitenaufbau gerenderten `$_GET['q']`, der könnte veraltet sein)
- Der PHP-Redirect am Ende jedes POST-Handlers hängt `?q=...` aus `$_POST['_q']` an — dadurch bleibt der Suchtext nach Bearbeiten/Löschen/Verleihen etc. erhalten, weil die Seite mit demselben `q`-Parameter neu lädt
- Verschwindet bewusst trotzdem, wenn man die Seite über einen normalen Link verlässt (z. B. zur Bibliothek wechselt) und zurückkommt — dort gibt's keinen `q`-Parameter in der URL
- **Beim Ergänzen neuer Formulare auf diesen Seiten**: `<input type="hidden" name="_q" value="<?=h($_GET['q'] ?? '')?>">` nicht vergessen, sonst geht der Filter bei dieser einen Aktion wieder verloren

### Geteilte Dialoge (Performance-Muster — mehrere Seiten)
**Grundregel: niemals einen Dialog pro Tabellenzeile rendern.** Stattdessen ein geteilter Dialog pro Typ, per JS aus einem JSON-Objekt befüllt. Besonders teuer sind Formulare mit `sv_tag_widget()`, weil das die komplette Tag-Liste in ZWEI Dropdowns rendert. Umgesetzt in:
- `admin/bibliothek.php` → `BIB_FORM_DATA`, `openPieceDialog/openLoanDialog/openSoftdelDialog`
- `admin/abstimmungstitel.php` → `SONG_FORM_DATA`, `openSongDialog/openSongSoftdelDialog` (Besonderheit: `_resetYtRequired()` setzt die YouTube-Pflicht zurück, sonst leckt ein zuvor gesetztes `yt_optional` in den nächsten Titel)
- `admin/concerts.php` → `CONCERT_FORM_DATA`, `openConcertDialog/openConcertSoftdelDialog` (das ausgewählte Konzert wird zusätzlich in die Daten aufgenommen, weil es bei aktiver Suche außerhalb der Liste liegen kann)

Gemeinsames Vorgehen: Trigger-Buttons behalten ihre alten IDs (`data-open-dialog="dialog-edit-<id>"`), der Klick-Handler leitet sie per Regex auf den geteilten Dialog um. Formular-Helper werden mit leeren Daten aufgerufen; beim Öffnen füllt JS die Felder, baut Genre-Chips über `_svAddChip`/`svGenreRefresh` neu auf und setzt Duplikat-Hinweis + Submit-Sperre zurück. **Beim Ergänzen neuer Formularfelder: sowohl das PHP-Daten-Array als auch die JS-Füllfunktion nachziehen.**

### Bibliothek Performance (Details)
- `admin/bibliothek.php` rendert **einen** geteilten Bearbeiten/Vorschlag-Dialog (`dialog-piece-form`), einen Verleihen- (`dialog-loan`) und einen Soft-Delete-Dialog (`dialog-softdel`) — NICHT einen pro Zeile (früher 251× → extrem langsames DOM)
- Stück-Daten liegen als JSON in `BIB_FORM_DATA` (per `json_encode` mit HEX-Flags); `openPieceDialog(id)` / `openLoanDialog(id)` / `openSoftdelDialog(id)` befüllen die Dialoge per JS
- Die Trigger-Buttons behalten ihre alten IDs (`data-open-dialog="dialog-edit-<id>"` etc.) — der Klick-Handler leitet sie per Regex auf die geteilten Dialoge um. Beim Erweitern: neue Stück-Felder in `$bibFormData` UND in `openPieceDialog()` ergänzen
- Verstecktes `notes`-Feld im Formular bewahrt `pieces.notes` beim Speichern (vorher wurde notes bei jedem Edit geleert)

### Trennungsregel
- `var(--accent)` / `var(--accent-hover)` — Für Branding (Buttons, Links, aktive Elemente)
- `var(--green)` / `var(--green-light)` / `var(--green-mid)` — Für Navigation, Badges, Checkboxen, Fokus
- `var(--red)` — NUR für Löschen, Danger, Fehler (nie für Branding!)
- `var(--score)` / `var(--score-light)` / `var(--score-mid)` — NUR für Score-Anzeigen (fest, nicht dynamisch)

## Software-Einstellungen (`admin/einstellungen.php`)

### Ein Formular für alles
- `enctype="multipart/form-data"` (wegen Logo-Upload)
- Ein "Einstellungen speichern"-Button für alles
- Leere Felder werden als leerer String gespeichert (nicht ignoriert!)

### Sektionen in der Card
1. **Anwendung** — App-Name, Organisations-Name
2. **Rollenbezeichnungen** — Mitglieder-Label, Leitungs-Label
3. **Vereinsfarben** — 2 Farbpicker mit Live-Vorschau
4. **Logo** — Galerie mit Radio-Buttons, Upload, Download, Löschen, Login-Breite

### Logo-System
- Logos werden in `assets/logos/` gespeichert (Galerie)
- Legacy-Logos aus `assets/logo.svg` und `assets/logo_custom.*` werden auch angezeigt
- "Kein Logo" speichert `__none__` als expliziten Wert (nicht löschen, sonst greift Default)
- Wenn kein Logo: Header zeigt nur App-Name/Org-Name, Login zeigt App-Name in Akzentfarbe
- Logo-Breite auf Login-Seite einstellbar (60-400px, Setting `logo_login_width`)
- Nur Logos in `assets/logos/` sind löschbar (Legacy-Logos nicht)

### Impressum & Datenschutz
- Editierbare HTML-Textareas
- Settings: `impressum_html`, `datenschutz_html`
- Default-Texte sind in `einstellungen.php` als PHP-Strings hinterlegt
- `impressum.php` und `datenschutz.php` prüfen ob Setting existiert, sonst Default

## Layout-System (`lib/layout.php`)

- `sv_header($title, $user)` — Erzeugt HTML-Head, Topbar, Navigation
- `sv_footer()` — Footer mit dynamischem App-Namen
- CSS-Variablen werden als Inline-`<style>` in den Head injiziert
- Navigation: `.navbtn` Klasse, `.navbtn.active` für aktive Seite
- Aktiver Nav-Button nutzt `var(--green-on)` für Auto-Kontrast-Text

## Print/PDF-Export

- Eigenständiges HTML (nicht das Layout-System)
- Jede Print-Seite lädt `color_primary` selbst: `$accentRed = sv_setting_get('color_primary', '#c1090f')`
- Hover wird lokal berechnet: `$accentHover = sv_color_darken($accentRed, 0.15)`
- `@media print` Override für `th` Tags (weiße Schrift auf Akzentfarbe)
- Print-Button: "Drucken / Als PDF speichern"
- Betrifft: `concerts.php`, `planer.php`, `bibliothek_inhaltsverzeichnis.php`

## Admin-Bereich

### Vorschläge (`admin/vorschlaege.php`)
- "Erledigte löschen"-Button (Admin only) — löscht alle nicht-offenen Vorschläge

### Ausleihen (`admin/ausleihen.php`)
- Pro-Zeile Lösch-Button (Admin only)

### Benutzerverwaltung (`admin/users.php`)
- Neben dem "Neuer Benutzer"-Formular steht eine Referenztabelle "Wer kann was?" — zeigt pro Rolle (O-Rat/Leitung/Admin-Label) welche Bereiche zugänglich sind
- **Wichtig beim Ändern von Rechten im Code**: Die Tabelle muss manuell nachgezogen werden, sie liest nichts dynamisch aus. Rechte-Modell zur Erinnerung:
  - Rolle (`user`/`leitung`/`admin`) steuert NUR grobe Seitenzugriffe: `sv_require_leitung()` blockt `user`-Rolle komplett aus (planer.php, progress.php) — unabhängig von `has_chronik`/`has_noten`
  - `has_chronik`/`has_noten`-Flags sind rollenunabhängig und steuern nur *Bearbeiten*-Rechte innerhalb von Seiten, die man schon betreten darf (`sv_can_edit_chronik()`, `sv_can_edit_noten()`) — Admin hat immer beides, unabhängig von den Flags
  - Konkret: Ein `user` mit `has_chronik=1` sieht die Chronik nur lesend UND kommt gar nicht erst auf `planer.php` (403) — das Flag allein reicht nicht für den "In Chronik übernehmen"-Button

### Bibliothek-Merge (`admin/bibliothek_merge.php`)
- Nutzt `$accentRed` für Diff-Pfeile (PHP + JavaScript)

### Konzertplaner (`admin/planer.php`)
- Plant Konzertprogramme aus Abstimmungstiteln (`songs`) und Bibliothek (`pieces`)
- Mehrere Pläne mit Varianten (A/B/…), duplizierbar
- Zwei-Spalten-Layout: links Quell-Tabellen (Suche, Score, Genre, Dauer), rechts der Plan
- AJAX-basiert: add_item, remove_item, reorder (Drag & Drop), update_item, park_item, unpark_item, remove_parked
- **Item-Typen** (`concert_plan_items.item_type` ENUM):
  - `piece` — normales Stück (aus songs oder pieces, via `source`-Spalte)
  - `block` — freier Textblock mit Label + Dauer (z.B. Pause, Ansprache)
  - `halftime` — Separator "── Halbzeit ──", max. 1 pro Plan
  - `zugabe` — Separator "── Zugaben ──", max. 1 pro Plan, Stücke danach zählen nicht zur Gesamtzeit
  - `parked` — in die Ablage verschoben (position=0, unterhalb des Plans)
- **WICHTIG**: Beim Erweitern des ENUMs immer alle bestehenden Werte beibehalten + Migration in `sv_ensure_schema()` ergänzen. Ungültige ENUM-Werte werden ohne Strict-Mode still als `''` gespeichert → kaputte Renderings
- Zeitsummen live: 1. Hälfte / 2. Hälfte / Gesamt / Zugaben / Stückzahl
- Parken statt Löschen: Stücke aus dem Plan landen in der Ablage statt verloren zu gehen
- Bemerkungen: freies Textfeld pro Plan (`concert_plans.notes`), Auto-Save mit Debounce on input/blur, wird beim Duplizieren mitkopiert und im Print-Export ausgegeben
- Print-Export (`?export=print`): eigenständiges HTML im gleichen Stil wie concerts/bibliothek
- **In Chronik übernehmen** (Button "🏛 In Chronik"): Dialog fragt Name/Datum/Ort ab, Notizen editierbar vorbefüllt; POST `to_chronik` legt per Transaktion einen `concerts`-Eintrag an und kopiert alle Plan-Items (auch Pause/Zugaben/Blöcke) nach `concert_pieces`. Songs werden über `songs.piece_id` auf Bibliotheks-Pieces aufgelöst; ohne Verknüpfung (oder bei doppeltem Piece) wird der Titel als freier Eintrag (`piece_id NULL` + `label`) gespeichert. `concert_plans.chronik_concert_id` merkt sich die Übernahme (Badge im Planer + Warnung vor Doppel-Übernahme)
- Button + Dialog bewusst **Admin-only** (`sv_is_admin()`, NICHT `sv_can_edit_chronik()`), zusätzlich serverseitig im POST-Handler `to_chronik` erzwungen — planer.php selbst ist nur über `sv_require_leitung()` geschützt (alle Leitungs-Rollen kommen auf die Seite), aber die Chronik-Übernahme ist strenger als das Chronik-Zusatzrecht: eine Leitungsperson mit `has_chronik`-Flag bearbeitet weiterhin ganz normal die Chronik selbst (`concerts.php`, unverändert `sv_can_edit_chronik()`), sieht aber den Übernahme-Button im Planer nicht
- Dafür wurde `concert_pieces` erweitert: `piece_id` ist NULL-fähig, `item_type` ENUM('piece','block','halftime','zugabe'), `label`, `duration_override`. Chronik-Programm (Detail + beide HTML-Exporte) rendert Separatoren/Blöcke; Entfernen/Sortieren läuft über `concert_pieces.id` (`cpid`), nicht mehr über `piece_id`. CSV-Export enthält weiterhin nur echte Stücke (INNER JOIN)

## Dateistruktur

```
/                       — Öffentliche Seiten (login, index, ergebnisse, etc.)
/admin/                 — Admin-Bereich
/lib/db.php             — DB-Verbindung, Schema, Settings-Helpers, Farb-Funktionen
/lib/auth.php           — Login, Rollen, CSRF, Session
/lib/layout.php         — Header/Footer, CSS-Injection
/lib/backup_helper.php  — Backup-Funktionen
/assets/app.css         — Einzige CSS-Datei
/assets/logo.svg        — Standard-Logo
/assets/logos/           — Hochgeladene Logo-Galerie
/backups/               — DB- und Code-Backups (in .gitignore)
/api/                   — AJAX-Endpoints (vote, note, check_duplicate)
```

## Stil-Präferenzen des Nutzers

- Kompakte, funktionale UI
- Keine unnötigen Abstraktionen
- Print-Exports sollen konsistent aussehen (gleicher Button-Stil, gleiche Farben)
- Alles was Löschen/Gefahr ist: IMMER rot, unabhängig von Branding
- Einstellungen sollen "idiotensicher" sein — lieber 2 Farben mit Auto-Berechnung als 5 manuelle Picker
- Leere Felder = leer lassen, nicht auf Default zurückfallen
- Kein Logo = wirklich kein Logo (App-Name stattdessen)

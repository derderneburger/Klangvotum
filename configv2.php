<?php
// SongVote Konfiguration
// TODO: Datenbankdaten eintragen
return [
  'db' => [
    'host' => 'localhost',
    'name' => 'd04687d6',
    'user' => 'd04687d6',
    'pass' => '1Yo(.U1abM5C.F0Lb1gc',
    'charset' => 'utf8mb4',
  ],
  // Basis-URL optional (leer lassen, wird automatisch ermittelt)
  'base_url' => 'https://sbov2.kroppbox.de',
  'session_name' => 'klangvotum_v2',
  'dev_mode' => true,
  

  // Branding (optional)
  'branding' => [
    'app_name' => 'KlangVotum',
    'org_name' => 'Musikschule Hildesheim',
    // Put your logo file into /assets/logo.svg (or .png) and set the path here:
    'logo_path' => 'assets/logo.svg',
  ],
];

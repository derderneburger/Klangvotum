<?php
require_once __DIR__ . '/lib/auth.php';
sv_logout();
header('Location: login.php');

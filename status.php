<?php
// Serves the plugin's live balance snapshot for the settings page.
// fppd writes /dev/shm (RAM, no SD wear, shared across mount namespaces; Apache
// runs under systemd PrivateTmp so a /tmp file would be invisible to it).
@header('Content-Type: application/json');

$out = array(
    'enabled' => false, 'blocking' => false, 'remaining' => 0, 'consumed' => 0,
    'secondsPerCredit' => 3600, 'countMode' => 'running', 'playing' => false,
    'hasPassword' => false, 'live' => false,
);

$f = '/dev/shm/credits_status.json';
if (is_file($f) && (time() - filemtime($f) < 8)) {
    $j = json_decode(@file_get_contents($f), true);
    if (is_array($j)) { $out = array_merge($out, $j); $out['live'] = true; }
}

// Whether an admin password is configured (read straight from the settings file).
global $settings;
$dir = isset($settings['configDirectory']) ? $settings['configDirectory'] : '/home/fpp/media/config';
$cfg = @parse_ini_file($dir . '/plugin.credits');
$out['hasPassword'] = (is_array($cfg) && !empty($cfg['admin_password_hash']));

echo json_encode($out);

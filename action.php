<?php
/*
 * Password-gated admin actions for the credits plugin.
 *
 * All writes to config/plugin.credits go through here so they can be gated
 * behind the admin password. (Note: this gate is enforced by the plugin UI.
 * Anyone with full FPP admin / SSH access can edit the settings file directly,
 * so for real-world enforcement also protect FPP itself - UI password / network.
 * The credit *balance* itself lives in a file owned by fppd, not here.)
 *
 * Actions (POST):
 *   login    pw=...                 -> unlock this browser session
 *   logout                          -> lock
 *   setpw    pw=... [oldpw=...]     -> set/change the admin password
 *   set      enabled, seconds_per_credit, count_mode, blank_channels (any subset)
 *   recharge value=N                -> set the device's balance to N credits
 */
@header('Content-Type: application/json');
if (session_status() === PHP_SESSION_NONE) @session_start();

global $settings;
$dir = isset($settings['configDirectory']) ? $settings['configDirectory'] : '/home/fpp/media/config';
$cfgPath = $dir . '/plugin.credits';

function cfg_read($path) {
    $c = @parse_ini_file($path);
    return is_array($c) ? $c : array();
}
function cfg_write($path, $cfg) {
    $lines = '';
    foreach ($cfg as $k => $v) {
        $v = str_replace('"', '', (string)$v);   // C++ trims surrounding quotes
        $lines .= $k . ' = "' . $v . "\"\n";
    }
    return @file_put_contents($path, $lines) !== false;
}
function out($ok, $extra = array()) { echo json_encode(array_merge(array('ok' => $ok), $extra)); exit; }

$cfg = cfg_read($cfgPath);
$hasPw = !empty($cfg['admin_password_hash']);
$unlocked = (isset($_SESSION['credits_admin']) && $_SESSION['credits_admin'] === true) || !$hasPw;
$action = isset($_POST['action']) ? $_POST['action'] : (isset($_GET['action']) ? $_GET['action'] : '');

// Lightweight state probe for the settings page. The full-page render can't read
// the session (FPP prints HTML before our session_start), so the UI asks here
// instead - this nopage=1 request CAN read the session.
if ($action === 'check') out(true, array('unlocked' => $unlocked, 'hasPassword' => $hasPw));

if ($action === 'login') {
    $pw = isset($_POST['pw']) ? $_POST['pw'] : '';
    if ($hasPw && password_verify($pw, $cfg['admin_password_hash'])) {
        $_SESSION['credits_admin'] = true;
        out(true, array('unlocked' => true));
    }
    out(false, array('error' => 'Incorrect password'));
}

if ($action === 'logout') {
    unset($_SESSION['credits_admin']);
    out(true, array('unlocked' => false));
}

if ($action === 'setpw') {
    $new = isset($_POST['pw']) ? $_POST['pw'] : '';
    if (strlen($new) < 4) out(false, array('error' => 'Password must be at least 4 characters'));
    // If a password already exists, require being unlocked OR the old password.
    if ($hasPw && !$unlocked) {
        $old = isset($_POST['oldpw']) ? $_POST['oldpw'] : '';
        if (!password_verify($old, $cfg['admin_password_hash']))
            out(false, array('error' => 'Current password is incorrect'));
    }
    $cfg['admin_password_hash'] = password_hash($new, PASSWORD_DEFAULT);
    if (!cfg_write($cfgPath, $cfg)) out(false, array('error' => 'Could not write settings file'));
    $_SESSION['credits_admin'] = true;
    out(true, array('unlocked' => true));
}

// Everything below requires admin (or first-run with no password yet).
if ($hasPw && !$unlocked) out(false, array('error' => 'Not authorized', 'locked' => true));

if ($action === 'set') {
    $allowed = array('enabled', 'seconds_per_credit', 'count_mode', 'blank_channels');
    foreach ($allowed as $k) {
        if (!isset($_POST[$k])) continue;
        $v = $_POST[$k];
        if ($k === 'enabled') $v = ($v === '1' || $v === 'true' || $v === 'on') ? '1' : '0';
        elseif ($k === 'count_mode') $v = ($v === 'playing') ? 'playing' : 'running';
        else $v = (string)max(0, (int)$v);
        $cfg[$k] = $v;
    }
    if (!cfg_write($cfgPath, $cfg)) out(false, array('error' => 'Could not write settings file'));
    out(true);
}

if ($action === 'recharge') {
    $value = isset($_POST['value']) ? (int)$_POST['value'] : -1;
    if ($value < 0) out(false, array('error' => 'Invalid credit amount'));
    $cfg['recharge_value'] = (string)$value;
    $cfg['recharge_token'] = uniqid('', true);   // fppd applies each new token once
    if (!cfg_write($cfgPath, $cfg)) out(false, array('error' => 'Could not write settings file'));
    out(true, array('value' => $value));
}

out(false, array('error' => 'Unknown action'));

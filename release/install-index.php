<?php

declare(strict_types=1);

// ConsultDesk web installer: https://<booking site>/install. Works only until config.php exists.

use ConsultDesk\Install\Installer;
use ConsultDesk\Install\InstallFailed;

$app = getenv('CONSULTDESK_APP') ?: '';
for ($dir = __DIR__, $i = 0; $app === '' && $i < 4; $i++) {
    $dir = dirname($dir);
    if (is_file($dir . '/consultdesk-app/http.php')) {
        $app = $dir . '/consultdesk-app';
    }
}

header('Cache-Control: no-store');
header('X-Frame-Options: DENY');

$e = static fn(?string $text): string => htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$page = static function (string $title, string $body) use ($e): never {
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="robots" content="noindex"><title>' . $e($title) . ' · ConsultDesk</title><link rel="icon" href="/favicon.svg">'
        . '<style>
:root{--brand:#2f4a7a;--accent:#a3405a;--ink:#1d2330;--ink2:#545c6b;--rule:#d9dce3;--bg:#f6f4ef;--card:#fff;--bad:#a12a2a;--good:#2c6e49}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.55 system-ui,-apple-system,Segoe UI,Roboto,sans-serif}
main{max-width:44rem;margin:0 auto;padding:2.5rem 1rem 4rem}h1{font:600 2rem/1.15 Georgia,serif;margin:.75rem 0 1rem}
h2{font-size:1.05rem;margin:2rem 0 .5rem}.brand{display:flex;gap:.6rem;align-items:center;color:var(--ink2);font-size:.8rem;font-weight:700;letter-spacing:.12em;text-transform:uppercase}
.card{background:var(--card);border:1px solid var(--rule);border-radius:16px;padding:1.5rem;margin-top:1rem}
label{display:block;font-weight:600;margin:1rem 0 .3rem}.hint{color:var(--ink2);font-size:.9rem;margin:.15rem 0 .4rem}
input,select,textarea{width:100%;padding:.6rem .7rem;border:1px solid var(--rule);border-radius:10px;font:inherit;background:#fff}
.row{display:grid;grid-template-columns:repeat(auto-fit,minmax(12rem,1fr));gap:0 1rem}.err{color:var(--bad);font-size:.9rem;margin:.3rem 0 0}
button{margin-top:1.5rem;background:var(--brand);color:#fff;border:0;border-radius:10px;padding:.8rem 1.3rem;font:600 1rem system-ui;cursor:pointer}
.checks{list-style:none;padding:0;margin:0}.checks li{padding:.35rem 0}.ok{color:var(--good)}.no{color:var(--bad)}
code,.mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.92em;word-break:break-all}.box{background:#f1f2f6;border-radius:10px;padding:.75rem 1rem}
.alert{border-left:4px solid var(--bad);background:#fbeeee;padding:.75rem 1rem;border-radius:8px}.done{border-left-color:var(--good);background:#edf6f0}
</style></head><body><main><p class="brand"><img src="/favicon.svg" width="26" height="26" alt="">ConsultDesk</p>'
        . $body . '</main></body></html>';
    exit;
};

if ($app === '' || !is_file($app . '/vendor/autoload.php')) {
    $page('Install', '<h1>Almost there</h1><div class="alert">The <code>consultdesk-app</code> folder wasn’t found. Upload it next to your web folder (for example next to <code>public_html</code>), not inside it, then reload this page.</div>');
}
require $app . '/vendor/autoload.php';
$installer = new Installer($app);

if ($installer->installed()) {
    $page('Installed', '<h1>ConsultDesk is installed</h1><p>This page is switched off now. Open your admin area at the secret address you chose, or the <a href="/">booking site</a>.</p>');
}

$https = ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
session_name('cd_install');
session_set_cookie_params(['lifetime' => 0, 'path' => '/install', 'secure' => $https, 'httponly' => true, 'samesite' => 'Strict']);
session_start();
$_SESSION['csrf'] ??= bin2hex(random_bytes(16));
$_SESSION['admin_path'] ??= Installer::suggestAdminPath();

$checks = $installer->requirements();
if (!$installer->requirementsMet()) {
    $list = '';
    foreach ($checks as $check) {
        $list .= '<li class="' . ($check['ok'] ? 'ok' : 'no') . '">' . ($check['ok'] ? '✓ ' : '✗ ') . $e($check['label'])
            . ($check['ok'] ? '' : '<div class="hint">' . $e($check['note']) . '</div>') . '</li>';
    }
    $page('Install', '<h1>Before installing</h1><p>This server needs a few changes first:</p><div class="card"><ul class="checks">' . $list . '</ul></div><p>Fix these in hPanel, then reload this page.</p>');
}
$installer->ensureSetupCode();

$host = preg_replace('/[^a-z0-9.:-]/i', '', (string) ($_SERVER['HTTP_HOST'] ?? ''));
$form = [
    'app_url' => 'https://' . $host,
    'admin_path' => $_SESSION['admin_path'],
    'booking_prefix' => '',
    'embed_sites' => '',
    'db_host' => 'localhost', 'db_port' => '3306', 'db_name' => '', 'db_user' => '', 'db_password' => '',
    'smtp_host' => 'smtp.hostinger.com', 'smtp_port' => '465', 'smtp_encryption' => 'ssl', 'smtp_user' => '', 'smtp_password' => '',
    'mail_from' => '', 'mail_from_name' => 'Bookings',
];
$errors = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    foreach ($form as $key => $default) {
        $form[$key] = is_string($_POST[$key] ?? null) ? (string) $_POST[$key] : $default;
    }
    if (!hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
        $errors['install'] = 'This form expired. Please try again.';
    } elseif (!$installer->codeMatches((string) ($_POST['setup_code'] ?? ''))) {
        sleep(2); // guessing stays slow
        $errors['setup_code'] = 'That isn’t the code in consultdesk-app/install-code.txt.';
    } else {
        try {
            $next = $installer->install($form);
            try {
                $installer->allowEmbedding(dirname(__DIR__) . '/.htaccess', $form['embed_sites']);
            } catch (InstallFailed $embed) {
                $next['embed_note'] = $embed->errors['embed_sites'] ?? '';
            }
            session_destroy();
            $cron = 'php ' . $app . '/bin/cron.php';
            $page('Installed', '<h1>Installed</h1><div class="alert done">ConsultDesk is set up and this installer has switched itself off.</div>'
                . '<h2>1. Create your owner account</h2><p>Open your admin area and create the owner (you can bookmark this address; keep it to yourself):</p>'
                . '<p class="box"><a class="mono" href="' . $e($next['admin_url']) . '">' . $e($next['admin_url']) . '</a></p>'
                . '<h2>2. Add the cron job</h2><p>In hPanel → Advanced → Cron Jobs, add a job that runs <strong>every minute</strong> with this command:</p>'
                . '<p class="box mono">' . $e($cron) . '</p>'
                . '<p class="hint">If the command doesn’t run on your plan, use this web address with a "fetch URL" cron instead (keep it private):</p>'
                . '<p class="box mono">' . $e($next['cron_url']) . '</p>'
                . (($next['embed_note'] ?? '') !== '' ? '<p class="err">' . $e($next['embed_note']) . '</p>' : '')
                . '<h2>3. Then</h2><p>Follow the rest of the deployment guide: Razorpay webhook, Telegram, and a first test booking.</p>');
        } catch (InstallFailed $failed) {
            $errors = $failed->errors;
        }
    }
}

$field = static function (string $name, string $label, string $hint = '', string $type = 'text', string $extra = '') use ($form, $errors, $e): string {
    return '<label for="' . $name . '">' . $e($label) . '</label>' . ($hint !== '' ? '<p class="hint">' . $hint . '</p>' : '')
        . '<input id="' . $name . '" name="' . $name . '" type="' . $type . '" value="' . ($type === 'password' ? '' : $e($form[$name] ?? '')) . '" ' . $extra . '>'
        . (isset($errors[$name]) ? '<p class="err">' . $e($errors[$name]) . '</p>' : '');
};
$codePath = 'consultdesk-app/install-code.txt';
$enc = $form['smtp_encryption'];

$page('Install', '<h1>Install ConsultDesk</h1><p>About five minutes. Have hPanel open in another tab for the database and email details.</p>'
    . (isset($errors['install']) ? '<div class="alert">' . $e($errors['install']) . '</div>' : '')
    . '<form method="post" class="card" autocomplete="off"><input type="hidden" name="csrf" value="' . $e($_SESSION['csrf']) . '">'
    . '<h2>Setup code</h2><p class="hint">To prove you control this server: in hPanel → File Manager open <code>' . $e($codePath) . '</code> and copy the code inside.</p>'
    . $field('setup_code', 'Setup code', '', 'text', 'required autocapitalize="characters" placeholder="ABCD-EFGH-JKLM"')
    . '<h2>Your site</h2>'
    . $field('app_url', 'Booking site address', 'The https:// address of this site, without a path.', 'url', 'required')
    . $field('admin_path', 'Secret admin path', 'Your admin area will be at this address + /<em>path</em>. Keep the suggested one or choose your own (8+ characters, hard to guess).', 'text', 'required pattern="[a-z0-9][a-z0-9-]{7,63}"')
    . $field('booking_prefix', 'Booking codes start with (optional)', 'e.g. VRL for VRL-7F3K. You can change it later under Bookings.')
    . '<label for="embed_sites">Websites that will embed the booking pages (optional)</label><p class="hint">For the “Book a session” button on another site, e.g. https://atultiwari.com — one per line.</p>'
    . '<textarea id="embed_sites" name="embed_sites" rows="2">' . $e($form['embed_sites']) . '</textarea>'
    . (isset($errors['embed_sites']) ? '<p class="err">' . $e($errors['embed_sites']) . '</p>' : '')
    . '<h2>Database</h2><p class="hint">hPanel → Databases → MySQL Databases: create a database and user, then copy the names here (they start with u…_).</p>'
    . '<div class="row">' . $field('db_name', 'Database name', '', 'text', 'required') . $field('db_user', 'Database user', '', 'text', 'required') . '</div>'
    . $field('db_password', 'Database password', '', 'password', 'required autocomplete="new-password"')
    . '<div class="row">' . $field('db_host', 'Host', '', 'text') . $field('db_port', 'Port', '', 'text') . '</div>'
    . '<h2>Email</h2><p class="hint">hPanel → Emails: create a mailbox such as bookings@yourdomain, then use its address and password here.</p>'
    . '<div class="row">' . $field('mail_from', 'Send emails from', '', 'email', 'required') . $field('mail_from_name', 'Sender name') . '</div>'
    . '<div class="row">' . $field('smtp_user', 'Mailbox (login)', '', 'email', 'required') . $field('smtp_password', 'Mailbox password', '', 'password', 'required autocomplete="new-password"') . '</div>'
    . '<div class="row">' . $field('smtp_host', 'SMTP server') . $field('smtp_port', 'Port')
    . '<div><label for="smtp_encryption">Encryption</label><select id="smtp_encryption" name="smtp_encryption">'
    . '<option value="ssl"' . ($enc === 'ssl' ? ' selected' : '') . '>SSL (port 465)</option><option value="tls"' . ($enc === 'tls' ? ' selected' : '') . '>TLS (port 587)</option></select></div></div>'
    . '<button type="submit">Install</button></form>');

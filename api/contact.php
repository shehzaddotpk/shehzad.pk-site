<?php
// Shehzad.pk contact endpoint (PHP 8+, cPanel). No database.
// Layers: origin check -> honeypot -> minimum fill time -> per-IP rate limit -> validation -> Turnstile (if configured) -> mail().
// Settings live in api/config.php (not in the repo, not web-readable). See config.sample.php.
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$cfg = ['to' => 'write2me@shehzad.pk', 'from' => 'no-reply@shehzad.pk', 'turnstile_secret' => '', 'max_per_hour' => 5];
if (is_file(__DIR__ . '/config.php')) { $cfg = array_merge($cfg, (array) include __DIR__ . '/config.php'); }

function out(int $code, array $body): void { http_response_code($code); echo json_encode($body); exit; }
function clean(mixed $v, int $max): string {
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string) $v) ?? '';
    return mb_substr(trim($s), 0, $max);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { header('Allow: POST'); out(405, ['ok' => false, 'error' => 'method']); }

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '') {
    $host = parse_url($origin, PHP_URL_HOST) ?: '';
    if (!in_array($host, ['www.shehzad.pk', 'shehzad.pk', 'localhost'], true)) out(403, ['ok' => false, 'error' => 'origin']);
}

$b = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($b)) out(400, ['ok' => false, 'error' => 'bad_json']);

// 1. Honeypot + minimum fill time (client supplies startedAt in ms) — pretend success so bots don't retry.
$started = (int) ($b['startedAt'] ?? 0);
$age = (int) (microtime(true) * 1000) - $started;
if (!empty($b['website']) || $started <= 0 || $age < 3000 || $age > 86400000) out(200, ['ok' => true]);

// 2. Rate limit per IP (file based)
$ip = $_SERVER['REMOTE_ADDR'] ?? '0';
$dir = __DIR__ . '/.rl';
if (!is_dir($dir)) { @mkdir($dir, 0700, true); @file_put_contents($dir . '/.htaccess', "Require all denied\n"); }
$f = $dir . '/' . date('YmdH') . '-' . hash('sha256', $ip . 'shehzadpk');
$n = is_file($f) ? (int) file_get_contents($f) : 0;
if ($n >= (int) $cfg['max_per_hour']) out(429, ['ok' => false, 'error' => 'rate_limited']);
@file_put_contents($f, (string) ($n + 1), LOCK_EX);
foreach (glob($dir . '/*') ?: [] as $old) { if (is_file($old) && filemtime($old) < time() - 7200) @unlink($old); }

// 3. Validation
$name = clean($b['name'] ?? '', 80); $email = clean($b['email'] ?? '', 160);
$subject = clean($b['subject'] ?? '', 120); $message = clean($b['message'] ?? '', 5000);
if (mb_strlen($name) < 2 || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($subject) < 3 || mb_strlen($message) < 10) out(422, ['ok' => false, 'error' => 'invalid']);

// 4. Cloudflare Turnstile (enforced once a secret is configured)
if ($cfg['turnstile_secret'] !== '') {
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => 'Content-Type: application/x-www-form-urlencoded', 'timeout' => 8,
        'content' => http_build_query(['secret' => $cfg['turnstile_secret'], 'response' => (string) ($b['turnstileToken'] ?? ''), 'remoteip' => $ip])]]);
    $r = json_decode((string) @file_get_contents('https://challenges.cloudflare.com/turnstile/v0/siteverify', false, $ctx), true);
    if (empty($r['success'])) out(403, ['ok' => false, 'error' => 'captcha']);
}

// 5. Send with the host mailer. Header values are stripped of CR/LF; the visitor address is only used in Reply-To.
$strip = fn(string $s): string => str_replace(["\r", "\n"], ' ', $s);
$headers = "From: Shehzad.pk <{$cfg['from']}>\r\nReply-To: " . $strip($email) . "\r\nContent-Type: text/plain; charset=UTF-8\r\nX-Mailer: shehzad.pk-contact";
$body = "From: " . $strip($name) . " <" . $strip($email) . ">\nSource: Shehzad.pk contact form\nIP: {$ip}\n\n{$message}\n";
$ok = @mail($cfg['to'], '=?UTF-8?B?' . base64_encode('[Shehzad.pk] ' . $strip($subject)) . '?=', $body, $headers, '-f' . $cfg['from']);
out($ok ? 200 : 502, ['ok' => $ok, 'error' => $ok ? null : 'send']);

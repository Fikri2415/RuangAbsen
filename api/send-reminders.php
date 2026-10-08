<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('ABSEN_API_BOOTSTRAPPED', true);
require __DIR__ . '/config.php';

date_default_timezone_set('Asia/Jakarta');

$configPath = __DIR__ . '/reminder-config.php';
if (!is_file($configPath)) {
    fwrite(STDERR, "Konfigurasi email belum ada. Salin api/reminder-config.example.php menjadi api/reminder-config.php dan isi pengaturan SMTP di server.\n");
    exit(1);
}

$mailConfig = require $configPath;
$requiredConfig = ['host', 'port', 'username', 'password', 'encryption', 'from_email', 'from_name', 'site_url'];
if (!is_array($mailConfig)) {
    fwrite(STDERR, "Format api/reminder-config.php tidak valid.\n");
    exit(1);
}
foreach ($requiredConfig as $key) {
    if (!isset($mailConfig[$key])
        || (!is_string($mailConfig[$key]) && !is_int($mailConfig[$key]))
        || trim((string) $mailConfig[$key]) === '') {
        fwrite(STDERR, "Pengaturan SMTP belum lengkap: {$key}.\n");
        exit(1);
    }
}
if (!filter_var($mailConfig['from_email'], FILTER_VALIDATE_EMAIL) || !filter_var($mailConfig['site_url'], FILTER_VALIDATE_URL)) {
    fwrite(STDERR, "Alamat pengirim email atau URL situs tidak valid.\n");
    exit(1);
}
if (str_contains((string) $mailConfig['host'], 'example.com')
    || str_starts_with((string) $mailConfig['password'], 'GANTI_')
    || (int) $mailConfig['port'] < 1
    || (int) $mailConfig['port'] > 65535
    || parse_url((string) $mailConfig['site_url'], PHP_URL_SCHEME) !== 'https') {
    fwrite(STDERR, "Ganti nilai contoh SMTP dan pastikan URL situs memakai HTTPS.\n");
    exit(1);
}
if (!in_array($mailConfig['encryption'], ['tls', 'ssl'], true)) {
    fwrite(STDERR, "Enkripsi SMTP harus bernilai tls atau ssl.\n");
    exit(1);
}

$autoloadPath = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($autoloadPath)) {
    fwrite(STDERR, "PHPMailer belum terpasang. Jalankan composer install di folder aplikasi.\n");
    exit(1);
}
require $autoloadPath;

$db->exec("SET time_zone = '+07:00'");
$dayOfWeek = (int) $db->query('SELECT DAYOFWEEK(CURRENT_DATE())')->fetchColumn();
if ($dayOfWeek === 1 || $dayOfWeek === 7) {
    exit(0);
}

$now = new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta'));
$minutes = ((int) $now->format('H')) * 60 + (int) $now->format('i');
$reminderType = null;
if ($minutes >= 15 * 60 + 45 && $minutes < 16 * 60) {
    $reminderType = 'checkout';
} elseif ($minutes >= 16 * 60 + 15 && $minutes < 16 * 60 + 30) {
    $reminderType = 'missed';
}
if ($reminderType === null) {
    exit(0);
}

$db->exec(
    'DELETE FROM attendance_reminder_log
     WHERE sent_at IS NULL AND created_at < DATE_SUB(CURRENT_TIMESTAMP(), INTERVAL 10 MINUTE)'
);

if ($reminderType === 'checkout') {
    $statement = $db->prepare(
        "SELECT u.id, u.email, u.name
         FROM attendances AS a
         INNER JOIN users AS u ON u.id = a.user_id
         WHERE a.work_date = CURRENT_DATE()
           AND a.check_in IS NOT NULL AND a.check_out IS NULL
           AND a.attendance_status = 'present'"
    );
    $subject = 'Pengingat: jangan lupa absen pulang';
    $messageFor = static fn (string $name, string $url): string =>
        "Halo {$name},\n\nKegiatan magang hampir selesai. Kamu sudah absen masuk, tetapi belum mencatat absen pulang hari ini.\n\nSilakan buka {$url} untuk melengkapi absensi.\n\nRuang Absen Kesbangpol Kota Bandung";
} else {
    $statement = $db->prepare(
        "SELECT u.id, u.email, u.name
         FROM users AS u
         LEFT JOIN attendances AS a
           ON a.user_id = u.id AND a.work_date = CURRENT_DATE()
         WHERE a.id IS NULL
            OR (a.check_in IS NOT NULL AND a.check_out IS NULL AND a.attendance_status = 'present')"
    );
    $subject = 'Pengingat: absensi hari ini belum lengkap';
    $messageFor = static fn (string $name, string $url): string =>
        "Halo {$name},\n\nCatatan absensi hari ini belum lengkap. Jika kamu belum mencatat kehadiran, silakan absen atau isi keterangan. Jika sudah absen masuk, jangan lupa absen pulang.\n\nBuka {$url} untuk memeriksa absensi.\n\nRuang Absen Kesbangpol Kota Bandung";
}
$statement->execute();
$recipients = $statement->fetchAll(PDO::FETCH_ASSOC);

$failed = 0;
foreach ($recipients as $recipient) {
    $reserve = $db->prepare(
        'INSERT IGNORE INTO attendance_reminder_log (user_id, work_date, reminder_type)
         VALUES (?, CURRENT_DATE(), ?)'
    );
    $reserve->execute([(int) $recipient['id'], $reminderType]);
    if ($reserve->rowCount() !== 1) {
        continue;
    }

    try {
        $mailer = new PHPMailer\PHPMailer\PHPMailer(true);
        $mailer->isSMTP();
        $mailer->Host = (string) $mailConfig['host'];
        $mailer->SMTPAuth = true;
        $mailer->Username = (string) $mailConfig['username'];
        $mailer->Password = (string) $mailConfig['password'];
        $mailer->SMTPSecure = $mailConfig['encryption'] === 'ssl'
            ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
            : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mailer->Port = (int) $mailConfig['port'];
        $mailer->Timeout = 15;
        $mailer->CharSet = PHPMailer\PHPMailer\PHPMailer::CHARSET_UTF8;
        $mailer->setFrom((string) $mailConfig['from_email'], (string) $mailConfig['from_name']);
        $mailer->addAddress((string) $recipient['email'], (string) $recipient['name']);
        $mailer->Subject = $subject;
        $mailer->Body = $messageFor((string) $recipient['name'], rtrim((string) $mailConfig['site_url'], '/') . '/index.html');
        $mailer->send();

        $sent = $db->prepare(
            'UPDATE attendance_reminder_log
             SET sent_at = CURRENT_TIMESTAMP()
             WHERE user_id = ? AND work_date = CURRENT_DATE() AND reminder_type = ?'
        );
        $sent->execute([(int) $recipient['id'], $reminderType]);
    } catch (Throwable $error) {
        error_log('Ruang Absen email reminder failed for user ID ' . (int) $recipient['id'] . ': ' . get_class($error));
        $removeReservation = $db->prepare(
            'DELETE FROM attendance_reminder_log
             WHERE user_id = ? AND work_date = CURRENT_DATE() AND reminder_type = ? AND sent_at IS NULL'
        );
        $removeReservation->execute([(int) $recipient['id'], $reminderType]);
        $failed += 1;
    }
}

if ($failed > 0) {
    fwrite(STDERR, "{$failed} email reminder(s) gagal dikirim. Periksa konfigurasi SMTP dan error log PHP.\n");
    exit(1);
}

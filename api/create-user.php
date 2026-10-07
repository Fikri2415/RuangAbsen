<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('ABSEN_API_BOOTSTRAPPED', true);
require __DIR__ . '/config.php';

function ask(string $prompt): string
{
    fwrite(STDOUT, $prompt);
    $value = fgets(STDIN);
    return $value === false ? '' : rtrim($value, "\r\n");
}

if (DB_NAME === 'ISI_NAMA_DATABASE' || DB_USER === 'ISI_USER_DATABASE' || DB_PASSWORD === 'ISI_PASSWORD_DATABASE') {
    fwrite(STDERR, "Konfigurasi database belum diisi pada api/config.php.\n");
    exit(1);
}

$email = strtolower(trim(ask('Email peserta: ')));
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
    fwrite(STDERR, "Format email tidak valid.\n");
    exit(1);
}

$name = trim(ask('Nama lengkap: '));
$identity = trim(ask('NIM/NIS (boleh kosong): '));
$school = trim(ask('Sekolah/kampus (boleh kosong): '));
$password = ask('Kata sandi sementara (minimal 12 karakter): ');
if ($name === '' || mb_strlen($name) > 80 || mb_strlen($identity) > 40 || mb_strlen($school) > 100 || strlen($password) < 12) {
    fwrite(STDERR, "Data tidak valid. Nama wajib diisi dan kata sandi harus minimal 12 karakter.\n");
    exit(1);
}

$db = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER,
    DB_PASSWORD,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);
$statement = $db->prepare(
    'INSERT INTO users (email, password_hash, name, identity_number, school)
     VALUES (?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), name = VALUES(name),
                             identity_number = VALUES(identity_number), school = VALUES(school)'
);
$passwordHash = password_hash($password, PASSWORD_DEFAULT);
$statement->execute([$email, $passwordHash, $name, $identity, $school]);

fwrite(STDOUT, "Akun peserta tersimpan. Sampaikan email dan kata sandi sementara secara privat.\n");

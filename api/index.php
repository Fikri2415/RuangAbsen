<?php
declare(strict_types=1);

define('ABSEN_API_BOOTSTRAPPED', true);
require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

$isHttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && $_SERVER['HTTPS'] !== 'off';
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

function respond(array $body, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function requireCsrf(): void
{
    $provided = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $expected = $_SESSION['csrf_token'] ?? '';
    if (!is_string($provided) || !is_string($expected) || $expected === '' || !hash_equals($expected, $provided)) {
        respond(['error' => 'Sesi keamanan sudah berubah. Muat ulang halaman lalu coba lagi.'], 403);
    }
}

function requireUserId(): int
{
    $userId = $_SESSION['user_id'] ?? null;
    if (!is_int($userId) || $userId < 1) {
        respond(['error' => 'Silakan masuk terlebih dahulu.'], 401);
    }
    return $userId;
}

function readJsonBody(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || strlen($raw) > 16384) {
        respond(['error' => 'Ukuran permintaan tidak valid.'], 413);
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        respond(['error' => 'Format permintaan tidak valid.'], 400);
    }
    return $data;
}

function fetchRecords(PDO $db): array
{
    $result = $db->query(
        "SELECT a.user_id AS userId, CAST(a.work_date AS CHAR) AS workDate,
                u.name, u.identity_number AS identityNumber, a.check_in AS checkIn, a.check_out AS checkOut,
                a.attendance_status AS attendanceStatus, a.attendance_note AS attendanceNote
         FROM attendances AS a
         INNER JOIN users AS u ON u.id = a.user_id
         ORDER BY a.work_date DESC, u.name ASC"
    );
    return array_map(static function (array $row): array {
        return [
            'userId' => (int) $row['userId'],
            'date' => $row['workDate'],
            'name' => $row['name'],
            'identity' => $row['identityNumber'],
            'checkIn' => $row['checkIn'],
            'checkOut' => $row['checkOut'],
            'attendanceStatus' => $row['attendanceStatus'],
            'attendanceNote' => $row['attendanceNote'],
        ];
    }, $result->fetchAll());
}

function fetchDivisions(PDO $db): array
{
    $result = $db->query('SELECT id, name FROM divisions ORDER BY name ASC');
    return array_map(static fn (array $row): array => [
        'id' => (int) $row['id'],
        'name' => $row['name'],
    ], $result->fetchAll());
}

try {
    if (DB_NAME === 'ISI_NAMA_DATABASE' || DB_USER === 'ISI_USER_DATABASE' || DB_PASSWORD === 'ISI_PASSWORD_DATABASE') {
        respond(['error' => 'Konfigurasi database belum diisi pada api/config.php.'], 503);
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
    $db->exec("SET time_zone = '+07:00'");

    $action = $_GET['action'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET' && $action === 'session') {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        $userId = $_SESSION['user_id'] ?? null;
        if (!is_int($userId) || $userId < 1) {
            respond(['csrf' => $_SESSION['csrf_token'], 'user' => null, 'profile' => null, 'records' => [], 'divisions' => []]);
        }
        $statement = $db->prepare('SELECT id, email, name, identity_number, school FROM users WHERE id = ?');
        $statement->execute([$userId]);
        $user = $statement->fetch();
        if (!$user) {
            $_SESSION = [];
            session_regenerate_id(true);
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            respond(['csrf' => $_SESSION['csrf_token'], 'user' => null, 'profile' => null, 'records' => [], 'divisions' => []]);
        }
        $profile = [
            'name' => $user['name'],
            'identity' => $user['identity_number'],
            'school' => $user['school'],
        ];
        respond([
            'csrf' => $_SESSION['csrf_token'],
            'user' => ['id' => (int) $user['id'], 'email' => $user['email']],
            'profile' => $profile,
            'records' => fetchRecords($db),
            'divisions' => fetchDivisions($db),
        ]);
    }

    if ($method === 'GET' && $action === 'history') {
        requireUserId();
        respond(['records' => fetchRecords($db)]);
    }

    if ($method === 'GET' && $action === 'divisions') {
        requireUserId();
        respond(['divisions' => fetchDivisions($db)]);
    }

    if ($method !== 'POST') {
        header('Allow: GET, POST');
        respond(['error' => 'Metode permintaan tidak didukung.'], 405);
    }

    requireCsrf();
    $data = readJsonBody();

    if ($action === 'login') {
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        $password = (string) ($data['password'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254 || $password === '' || strlen($password) > 1024) {
            respond(['error' => 'Email atau kata sandi tidak valid.'], 422);
        }
        $statement = $db->prepare('SELECT id, email, password_hash, name, identity_number, school FROM users WHERE email = ?');
        $statement->execute([$email]);
        $user = $statement->fetch();
        if ($user && $user['password_hash'] === null) {
            respond(['error' => 'Akun sudah disiapkan, tetapi belum diaktifkan. Minta pengelola membuatkan kata sandi.'], 403);
        }
        if (!$user || !is_string($user['password_hash']) || !password_verify($password, $user['password_hash'])) {
            usleep(350000);
            respond(['error' => 'Email atau kata sandi salah. Jika belum memiliki akun, hubungi pengelola.'], 401);
        }
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        respond([
            'csrf' => $_SESSION['csrf_token'],
            'user' => ['id' => (int) $user['id'], 'email' => $user['email']],
            'profile' => [
                'name' => $user['name'],
                'identity' => $user['identity_number'],
                'school' => $user['school'],
            ],
            'records' => fetchRecords($db),
            'divisions' => fetchDivisions($db),
        ]);
    }

    if ($action === 'logout') {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        respond(['csrf' => $_SESSION['csrf_token'], 'user' => null, 'profile' => null, 'records' => [], 'divisions' => []]);
    }

    $userId = requireUserId();

    if ($action === 'profile') {
        $name = trim((string) ($data['name'] ?? ''));
        $identity = trim((string) ($data['identity'] ?? ''));
        $school = trim((string) ($data['school'] ?? ''));
        if ($name === '' || mb_strlen($name) > 80 || mb_strlen($identity) > 40 || mb_strlen($school) > 100) {
            respond(['error' => 'Periksa nama dan data profil. Batas nama 80 karakter, NIM/NIS 40, dan sekolah 100.'], 422);
        }
        $statement = $db->prepare('UPDATE users SET name = ?, identity_number = ?, school = ? WHERE id = ?');
        $statement->execute([$name, $identity, $school, $userId]);
        respond(['profile' => ['name' => $name, 'identity' => $identity, 'school' => $school]]);
    }

    if ($action === 'division') {
        $operation = $data['operation'] ?? '';
        if (!is_string($operation) || !in_array($operation, ['create', 'update', 'delete'], true)) {
            respond(['error' => 'Aksi pengelolaan divisi tidak valid.'], 422);
        }

        if ($operation === 'delete' || $operation === 'update') {
            $id = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) {
                respond(['error' => 'Pilih divisi yang valid.'], 422);
            }
        }

        if ($operation === 'delete') {
            $statement = $db->prepare('DELETE FROM divisions WHERE id = ?');
            $statement->execute([$id]);
            if ($statement->rowCount() !== 1) {
                respond(['error' => 'Divisi tidak ditemukan atau sudah dihapus.'], 404);
            }
            respond(['divisions' => fetchDivisions($db)]);
        }

        $name = $data['name'] ?? null;
        if (!is_string($name)) {
            respond(['error' => 'Masukkan nama divisi.'], 422);
        }
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 80) {
            respond(['error' => 'Nama divisi wajib diisi dan maksimal 80 karakter.'], 422);
        }

        try {
            if ($operation === 'create') {
                $statement = $db->prepare('INSERT INTO divisions (name, created_by) VALUES (?, ?)');
                $statement->execute([$name, $userId]);
            } else {
                $statement = $db->prepare('UPDATE divisions SET name = ? WHERE id = ?');
                $statement->execute([$name, $id]);
                if ($statement->rowCount() === 0) {
                    $exists = $db->prepare('SELECT 1 FROM divisions WHERE id = ?');
                    $exists->execute([$id]);
                    if (!$exists->fetchColumn()) {
                        respond(['error' => 'Divisi tidak ditemukan.'], 404);
                    }
                }
            }
        } catch (PDOException $error) {
            if ((int) ($error->errorInfo[1] ?? 0) === 1062) {
                respond(['error' => 'Nama divisi tersebut sudah terdaftar.'], 409);
            }
            throw $error;
        }
        respond(['divisions' => fetchDivisions($db)]);
    }

    if ($action === 'check-in') {
        try {
            $statement = $db->prepare("INSERT INTO attendances (user_id, work_date, check_in, attendance_status) VALUES (?, CURRENT_DATE(), CURRENT_TIMESTAMP(), 'present')");
            $statement->execute([$userId]);
        } catch (PDOException $error) {
            if ((int) ($error->errorInfo[1] ?? 0) === 1062) {
                respond(['error' => 'Absensi kamu sudah tercatat hari ini.'], 409);
            }
            throw $error;
        }
        respond(['records' => fetchRecords($db)]);
    }

    if ($action === 'report-absence') {
        $status = $data['status'] ?? '';
        $rawNote = $data['note'] ?? '';
        if (!is_string($status) || !in_array($status, ['absent', 'sick'], true) || !is_string($rawNote)) {
            respond(['error' => 'Pilih status Tidak hadir atau Sakit.'], 422);
        }
        $note = trim($rawNote);
        if (mb_strlen($note) > 255) {
            respond(['error' => 'Pilih status Tidak hadir atau Sakit. Keterangan maksimal 255 karakter.'], 422);
        }
        try {
            $statement = $db->prepare(
                'INSERT INTO attendances (user_id, work_date, check_in, attendance_status, attendance_note)
                 VALUES (?, CURRENT_DATE(), NULL, ?, ?)'
            );
            $statement->execute([$userId, $status, $note]);
        } catch (PDOException $error) {
            if ((int) ($error->errorInfo[1] ?? 0) === 1062) {
                respond(['error' => 'Absensi atau keterangan kamu sudah tercatat hari ini.'], 409);
            }
            throw $error;
        }
        respond(['records' => fetchRecords($db)]);
    }

    if ($action === 'check-out') {
        $statement = $db->prepare(
            'UPDATE attendances
             SET check_out = CURRENT_TIMESTAMP()
             WHERE user_id = ? AND work_date = CURRENT_DATE()
               AND check_in IS NOT NULL AND check_out IS NULL'
        );
        $statement->execute([$userId]);
        if ($statement->rowCount() !== 1) {
            respond(['error' => 'Absen masuk dulu atau absensi pulang sudah tercatat.'], 409);
        }
        respond(['records' => fetchRecords($db)]);
    }

    respond(['error' => 'Aksi tidak ditemukan.'], 404);
} catch (Throwable $error) {
    error_log('Ruang Absen API: ' . $error->getMessage());
    if ($error instanceof PDOException) {
        $sqlState = (string) $error->getCode();
        $driverCode = (int) ($error->errorInfo[1] ?? 0);
        $message = strtolower($error->getMessage());

        if (str_contains($message, 'could not find driver')) {
            respond(['error' => 'PHP hosting belum mengaktifkan driver PDO_MYSQL. Aktifkan ekstensi PDO MySQL di panel hosting.'], 503);
        }
        if ($driverCode === 1044 || $driverCode === 1045) {
            respond(['error' => 'MySQL menolak akses. Periksa host, nama database, user, password, dan hak akses user database di api/config.php.'], 503);
        }
        if ($driverCode === 1049) {
            respond(['error' => 'Database belum ditemukan. Buat database di panel hosting lalu impor database.sql ke database tersebut.'], 503);
        }
        if (in_array($driverCode, [2002, 2003, 2005], true)) {
            respond(['error' => 'Server MySQL tidak dapat dijangkau. Periksa DB_HOST di api/config.php dan pastikan host database sesuai panel hosting.'], 503);
        }
        if ($driverCode === 1146 || $sqlState === '42S02') {
            respond(['error' => 'Tabel database belum ditemukan. Untuk database baru, impor database.sql; untuk database lama, jalankan migrasi database yang diperlukan.'], 503);
        }
        if ($driverCode === 1054 || $sqlState === '42S22') {
            respond(['error' => 'Struktur tabel MySQL belum sesuai. Impor database.sql untuk database baru atau jalankan database-migration-absence.sql untuk database lama.'], 503);
        }
    }

    respond(['error' => 'Server gagal memproses permintaan. Lihat error log PHP di panel hosting untuk detail.'], 500);
}

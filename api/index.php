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
ini_set('session.gc_maxlifetime', '1800');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

const SESSION_IDLE_TIMEOUT = 1800;

function respond(array $body, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function expireIdleSession(bool $sessionCheck = false): void
{
    if (!isset($_SESSION['user_id'])) {
        return;
    }

    $lastActivity = $_SESSION['last_activity_at'] ?? null;
    if (!is_int($lastActivity)) {
        $_SESSION['last_activity_at'] = time();
        return;
    }
    if (time() - $lastActivity < SESSION_IDLE_TIMEOUT) {
        return;
    }

    $_SESSION = [];
    session_regenerate_id(true);
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    if ($sessionCheck) {
        respond(['csrf' => $_SESSION['csrf_token'], 'user' => null, 'profile' => null, 'records' => [], 'divisions' => []]);
    }
    respond(['error' => 'Sesi berakhir setelah 30 menit tidak aktif. Silakan masuk kembali.'], 401);
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

function requireAdmin(PDO $db, int $userId): void
{
    $statement = $db->prepare('SELECT role FROM users WHERE id = ?');
    $statement->execute([$userId]);
    if ($statement->fetchColumn() !== 'admin') {
        respond(['error' => 'Akses ditolak. Tindakan ini hanya tersedia untuk admin.'], 403);
    }
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
        "SELECT a.id AS attendanceId, a.user_id AS userId, CAST(a.work_date AS CHAR) AS workDate,
                u.name, u.identity_number AS identityNumber, a.check_in AS checkIn, a.check_out AS checkOut,
                a.attendance_status AS attendanceStatus, a.attendance_note AS attendanceNote
         FROM attendances AS a
         INNER JOIN users AS u ON u.id = a.user_id
         ORDER BY a.work_date DESC, u.name ASC"
    );
    return array_map(static function (array $row): array {
        return [
            'attendanceId' => (int) $row['attendanceId'],
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

function fetchAnnouncements(PDO $db, int $userId, bool $isAdmin): array
{
    if ($isAdmin) {
        $statement = $db->query(
            'SELECT n.id, n.title, n.body, n.division_id AS divisionId, d.name AS divisionName,
                    n.created_at AS createdAt, n.updated_at AS updatedAt
             FROM announcements AS n
             INNER JOIN divisions AS d ON d.id = n.division_id
             ORDER BY n.created_at DESC, n.id DESC'
        );
    } else {
        $statement = $db->prepare(
            'SELECT n.id, n.title, n.body, n.division_id AS divisionId, d.name AS divisionName,
                    n.created_at AS createdAt, n.updated_at AS updatedAt
             FROM announcements AS n
             INNER JOIN divisions AS d ON d.id = n.division_id
             INNER JOIN users AS u ON u.division_id = n.division_id
             WHERE u.id = ?
             ORDER BY n.created_at DESC, n.id DESC'
        );
        $statement->execute([$userId]);
    }
    return array_map(static fn (array $row): array => [
        'id' => (int) $row['id'],
        'title' => $row['title'],
        'body' => $row['body'],
        'divisionId' => (int) $row['divisionId'],
        'divisionName' => $row['divisionName'],
        'createdAt' => $row['createdAt'],
        'updatedAt' => $row['updatedAt'],
    ], $statement->fetchAll());
}

function fetchActivityLogs(PDO $db, int $userId, bool $isAdmin): array
{
    $sql = 'SELECT l.id, l.user_id AS userId, CAST(l.work_date AS CHAR) AS workDate,
                   l.activity, l.created_at AS createdAt, l.updated_at AS updatedAt,
                   u.name, u.identity_number AS identityNumber, d.name AS divisionName
            FROM activity_logs AS l
            INNER JOIN users AS u ON u.id = l.user_id
            LEFT JOIN divisions AS d ON d.id = u.division_id';
    if (!$isAdmin) {
        $sql .= ' WHERE l.user_id = ?';
    }
    $sql .= ' ORDER BY l.work_date DESC, l.created_at DESC, l.id DESC, u.name ASC';
    $statement = $db->prepare($sql);
    $statement->execute($isAdmin ? [] : [$userId]);
    return array_map(static fn (array $row): array => [
        'id' => (int) $row['id'],
        'userId' => (int) $row['userId'],
        'date' => $row['workDate'],
        'activity' => $row['activity'],
        'name' => $row['name'],
        'identity' => $row['identityNumber'],
        'divisionName' => $row['divisionName'],
        'createdAt' => $row['createdAt'],
        'updatedAt' => $row['updatedAt'],
    ], $statement->fetchAll());
}

function fetchAdminUsers(PDO $db): array
{
    $users = $db->query(
        "SELECT id, email, name, identity_number AS identityNumber, school, password_hash
         FROM users WHERE role = 'participant' ORDER BY name ASC, email ASC"
    )->fetchAll();
    return array_map(static fn (array $user): array => [
        'id' => (int) $user['id'],
        'email' => $user['email'],
        'name' => $user['name'],
        'identity' => $user['identityNumber'],
        'school' => $user['school'],
        'hasPassword' => $user['password_hash'] !== null,
    ], $users);
}

function profileFromRow(array $row): array
{
    return [
        'name' => $row['name'],
        'identity' => $row['identity_number'],
        'school' => $row['school'],
        'divisionId' => $row['division_id'] === null ? null : (int) $row['division_id'],
        'divisionName' => $row['division_name'],
    ];
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

    expireIdleSession($method === 'GET' && $action === 'session');

    if ($method === 'GET' && $action === 'session') {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        $userId = $_SESSION['user_id'] ?? null;
        if (!is_int($userId) || $userId < 1) {
            respond(['csrf' => $_SESSION['csrf_token'], 'user' => null, 'profile' => null, 'records' => [], 'divisions' => []]);
        }
        $statement = $db->prepare(
            'SELECT u.id, u.email, u.role, u.name, u.identity_number, u.school, u.division_id, d.name AS division_name
             FROM users AS u LEFT JOIN divisions AS d ON d.id = u.division_id WHERE u.id = ?'
        );
        $statement->execute([$userId]);
        $user = $statement->fetch();
        if (!$user) {
            $_SESSION = [];
            session_regenerate_id(true);
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            respond(['csrf' => $_SESSION['csrf_token'], 'user' => null, 'profile' => null, 'records' => [], 'divisions' => []]);
        }
        $profile = profileFromRow($user);
        respond([
            'csrf' => $_SESSION['csrf_token'],
            'user' => ['id' => (int) $user['id'], 'email' => $user['email'], 'role' => $user['role']],
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

    if ($method === 'GET' && $action === 'admin-participants') {
        $userId = requireUserId();
        requireAdmin($db, $userId);
        $participants = $db->query(
            "SELECT id, name, email, identity_number
             FROM users WHERE role = 'participant' ORDER BY name ASC"
        )->fetchAll();
        respond(['participants' => array_map(static fn (array $participant): array => [
            'id' => (int) $participant['id'],
            'name' => $participant['name'],
            'email' => $participant['email'],
            'identity' => $participant['identity_number'],
        ], $participants)]);
    }

    if ($method === 'GET' && $action === 'admin-users') {
        $userId = requireUserId();
        requireAdmin($db, $userId);
        respond(['users' => fetchAdminUsers($db)]);
    }

    if ($method === 'GET' && $action === 'announcements') {
        $userId = requireUserId();
        $roleQuery = $db->prepare('SELECT role FROM users WHERE id = ?');
        $roleQuery->execute([$userId]);
        $isAdmin = $roleQuery->fetchColumn() === 'admin';
        respond(['announcements' => fetchAnnouncements($db, $userId, $isAdmin)]);
    }

    if ($method === 'GET' && $action === 'activity-pdf') {
        $userId = requireUserId();
        $activities = fetchActivityLogs($db, $userId, false);
        if ($activities === []) {
            respond(['error' => 'Belum ada catatan aktivitas untuk diunduh.'], 404);
        }

        require dirname(__DIR__) . '/vendor/autoload.php';
        $items = '';
        foreach ($activities as $activity) {
            $date = new DateTimeImmutable($activity['date'], new DateTimeZone('Asia/Jakarta'));
            $createdAt = new DateTimeImmutable($activity['createdAt'], new DateTimeZone('Asia/Jakarta'));
            $items .= '<article><h2>' . htmlspecialchars($date->format('d-m-Y'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h2>';
            $items .= '<p class="saved-at">Disimpan pukul ' . htmlspecialchars($createdAt->format('H:i'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ' WIB</p>';
            $items .= '<p class="activity">' . nl2br(htmlspecialchars($activity['activity'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) . '</p></article>';
        }

        $html = '<!doctype html><html lang="id"><head><meta charset="UTF-8"><style>
            @page { size: A4; margin: 18mm; }
            body { font-family: "DejaVu Sans", sans-serif; color: #14213d; font-size: 11pt; }
            h1 { color: #1e3a5f; font-size: 20pt; margin: 0 0 18pt; }
            article { border-bottom: 1px solid #d5dfeb; padding: 0 0 12pt; margin: 0 0 14pt; page-break-inside: avoid; }
            h2 { font-size: 12pt; margin: 0; }
            .saved-at { color: #66758a; font-size: 9pt; margin: 4pt 0 8pt; }
            .activity { line-height: 1.5; margin: 0; white-space: normal; overflow-wrap: anywhere; }
          </style></head><body><h1>Catatan Kegiatan</h1>' . $items . '</body></html>';

        $pdf = new \Dompdf\Dompdf();
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4');
        $pdf->render();
        $output = $pdf->output();

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="catatan-kegiatan.pdf"');
        header('Content-Length: ' . strlen($output));
        echo $output;
        exit;
    }

    if ($method === 'GET' && $action === 'activity-logs') {
        $userId = requireUserId();
        $roleQuery = $db->prepare('SELECT role FROM users WHERE id = ?');
        $roleQuery->execute([$userId]);
        $isAdmin = $roleQuery->fetchColumn() === 'admin';
        respond(['activities' => fetchActivityLogs($db, $userId, $isAdmin)]);
    }

    if ($method !== 'POST') {
        header('Allow: GET, POST');
        respond(['error' => 'Metode permintaan tidak didukung.'], 405);
    }

    requireCsrf();
    $data = readJsonBody();

    if ($action === 'session-activity') {
        $userId = requireUserId();
        $_SESSION['last_activity_at'] = time();
        respond(['active' => true, 'userId' => $userId]);
    }

    if ($action === 'login') {
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        $password = (string) ($data['password'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254 || $password === '' || strlen($password) > 1024) {
            respond(['error' => 'Email atau kata sandi tidak valid.'], 422);
        }
        $statement = $db->prepare(
            'SELECT u.id, u.email, u.password_hash, u.role, u.name, u.identity_number, u.school, u.division_id,
                    d.name AS division_name
             FROM users AS u LEFT JOIN divisions AS d ON d.id = u.division_id WHERE u.email = ?'
        );
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
        unset(
            $_SESSION['pending_login_user_id'],
            $_SESSION['login_otp_hash'],
            $_SESSION['login_otp_expires_at'],
            $_SESSION['login_otp_attempts'],
            $_SESSION['login_otp_sent_at']
        );
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['last_activity_at'] = time();
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        respond([
            'csrf' => $_SESSION['csrf_token'],
            'user' => ['id' => (int) $user['id'], 'email' => $user['email'], 'role' => $user['role']],
            'profile' => profileFromRow($user),
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

    if ($action === 'admin-user') {
        requireAdmin($db, $userId);
        $operation = $data['operation'] ?? null;
        if (!is_string($operation) || !in_array($operation, ['create', 'reset-password'], true)) {
            respond(['error' => 'Operasi pengelolaan akun tidak valid.'], 422);
        }
        $password = $data['password'] ?? null;
        if (!is_string($password) || strlen($password) < 12 || strlen($password) > 1024) {
            respond(['error' => 'Kata sandi wajib minimal 12 karakter.'], 422);
        }
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        if ($operation === 'create') {
            $email = $data['email'] ?? null;
            $name = $data['name'] ?? null;
            $identity = $data['identity'] ?? '';
            $school = $data['school'] ?? '';
            if (!is_string($email) || !is_string($name) || !is_string($identity) || !is_string($school)) {
                respond(['error' => 'Format email, nama, NIM/NIS, atau instansi tidak valid.'], 422);
            }
            $email = strtolower(trim($email));
            $name = trim($name);
            $identity = trim($identity);
            $school = trim($school);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254
                || $name === '' || mb_strlen($name) > 80
                || mb_strlen($identity) > 40 || mb_strlen($school) > 100) {
                respond(['error' => 'Periksa email, nama (maksimal 80 karakter), NIM/NIS, dan instansi.'], 422);
            }
            try {
                $statement = $db->prepare(
                    "INSERT INTO users (email, password_hash, role, name, identity_number, school)
                     VALUES (?, ?, 'participant', ?, ?, ?)"
                );
                $statement->execute([$email, $passwordHash, $name, $identity, $school]);
            } catch (PDOException $error) {
                if ((string) $error->getCode() === '23000') {
                    respond(['error' => 'Email tersebut sudah digunakan oleh akun lain.'], 409);
                }
                throw $error;
            }
            respond(['users' => fetchAdminUsers($db), 'message' => 'Akun peserta berhasil dibuat.']);
        }

        if ($operation === 'reset-password') {
            $participantId = filter_var($data['userId'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($participantId === false) {
                respond(['error' => 'Pilih akun peserta yang valid.'], 422);
            }
            $statement = $db->prepare(
                "UPDATE users SET password_hash = ? WHERE id = ? AND role = 'participant'"
            );
            $statement->execute([$passwordHash, $participantId]);
            if ($statement->rowCount() === 0) {
                $exists = $db->prepare("SELECT 1 FROM users WHERE id = ? AND role = 'participant'");
                $exists->execute([$participantId]);
                if (!$exists->fetchColumn()) {
                    respond(['error' => 'Akun peserta tidak ditemukan. Muat ulang daftar akun.'], 404);
                }
            }
            respond(['users' => fetchAdminUsers($db), 'message' => 'Kata sandi peserta berhasil diatur ulang.']);
        }

    }

    if ($action === 'announcement') {
        requireAdmin($db, $userId);
        $operation = $data['operation'] ?? '';
        if (!is_string($operation) || !in_array($operation, ['create', 'update', 'delete'], true)) {
            respond(['error' => 'Aksi pengumuman tidak valid.'], 422);
        }
        if ($operation === 'delete') {
            $id = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) {
                respond(['error' => 'Pilih pengumuman yang valid.'], 422);
            }
            $delete = $db->prepare('DELETE FROM announcements WHERE id = ?');
            $delete->execute([$id]);
            if ($delete->rowCount() !== 1) {
                respond(['error' => 'Pengumuman tidak ditemukan atau sudah dihapus.'], 404);
            }
            respond(['announcements' => fetchAnnouncements($db, $userId, true)]);
        }

        $title = $data['title'] ?? null;
        $body = $data['body'] ?? null;
        $divisionId = filter_var($data['divisionId'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!is_string($title) || !is_string($body) || $divisionId === false) {
            respond(['error' => 'Lengkapi judul, isi pengumuman, dan divisi tujuan.'], 422);
        }
        $title = trim($title);
        $body = trim($body);
        if ($title === '' || mb_strlen($title) > 120 || $body === '' || mb_strlen($body) > 3000) {
            respond(['error' => 'Judul wajib diisi (maksimal 120 karakter) dan isi pengumuman maksimal 3000 karakter.'], 422);
        }
        $divisionCheck = $db->prepare('SELECT 1 FROM divisions WHERE id = ?');
        $divisionCheck->execute([$divisionId]);
        if (!$divisionCheck->fetchColumn()) {
            respond(['error' => 'Divisi tujuan tidak ditemukan. Muat ulang lalu pilih divisi lain.'], 404);
        }
        if ($operation === 'create') {
            $statement = $db->prepare(
                'INSERT INTO announcements (title, body, division_id, created_by) VALUES (?, ?, ?, ?)'
            );
            $statement->execute([$title, $body, $divisionId, $userId]);
        } else {
            $id = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) {
                respond(['error' => 'Pilih pengumuman yang valid.'], 422);
            }
            $statement = $db->prepare(
                'UPDATE announcements SET title = ?, body = ?, division_id = ? WHERE id = ?'
            );
            $statement->execute([$title, $body, $divisionId, $id]);
            if ($statement->rowCount() === 0) {
                $exists = $db->prepare('SELECT 1 FROM announcements WHERE id = ?');
                $exists->execute([$id]);
                if (!$exists->fetchColumn()) {
                    respond(['error' => 'Pengumuman tidak ditemukan.'], 404);
                }
            }
        }
        respond(['announcements' => fetchAnnouncements($db, $userId, true)]);
    }

    if ($action === 'activity-log') {
        $roleQuery = $db->prepare('SELECT role FROM users WHERE id = ?');
        $roleQuery->execute([$userId]);
        if ($roleQuery->fetchColumn() !== 'participant') {
            respond(['error' => 'Hanya peserta yang dapat mengisi log aktivitas harian.'], 403);
        }
        $date = $data['date'] ?? null;
        $activity = $data['activity'] ?? null;
        if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
            || !is_string($activity) || trim($activity) === '' || mb_strlen(trim($activity)) > 3000) {
            respond(['error' => 'Tanggal dan catatan aktivitas wajib diisi. Catatan maksimal 3000 karakter.'], 422);
        }
        $dateValue = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('Asia/Jakarta'));
        $dateErrors = DateTimeImmutable::getLastErrors();
        if (!$dateValue || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
            || $dateValue->format('Y-m-d') !== $date) {
            respond(['error' => 'Tanggal aktivitas tidak valid.'], 422);
        }
        if ($date > $db->query('SELECT CURRENT_DATE()')->fetchColumn()) {
            respond(['error' => 'Tanggal aktivitas tidak boleh melewati hari ini.'], 422);
        }
        $statement = $db->prepare(
            'INSERT INTO activity_logs (user_id, work_date, activity) VALUES (?, ?, ?)'
        );
        $statement->execute([$userId, $date, trim($activity)]);
        respond(['activities' => fetchActivityLogs($db, $userId, false)]);
    }

    if ($action === 'admin-attendance') {
        requireAdmin($db, $userId);
        $attendanceId = filter_var($data['attendanceId'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $participantId = filter_var($data['userId'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $date = $data['date'] ?? null;
        $status = $data['status'] ?? null;
        $checkIn = $data['checkIn'] ?? '';
        $checkOut = $data['checkOut'] ?? '';
        $note = $data['attendanceNote'] ?? '';

        if ($attendanceId === false || $participantId === false
            || !is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
            || !is_string($status) || !in_array($status, ['present', 'sick', 'absent'], true)
            || !is_string($checkIn) || !is_string($checkOut) || !is_string($note)
            || mb_strlen($note) > 255) {
            respond(['error' => 'Data absensi tidak valid. Periksa tanggal, peserta, status, jam, dan keterangan.'], 422);
        }

        $dateValue = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('Asia/Jakarta'));
        $dateErrors = DateTimeImmutable::getLastErrors();
        if (!$dateValue || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
            || $dateValue->format('Y-m-d') !== $date) {
            respond(['error' => 'Tanggal absensi tidak valid.'], 422);
        }

        $timePattern = '/^(?:[01]\d|2[0-3]):[0-5]\d$/';
        if (($checkIn !== '' && !preg_match($timePattern, $checkIn))
            || ($checkOut !== '' && !preg_match($timePattern, $checkOut))) {
            respond(['error' => 'Jam masuk dan pulang harus menggunakan format waktu yang valid.'], 422);
        }
        if ($status === 'present' && $checkIn === '') {
            respond(['error' => 'Jam masuk wajib diisi untuk status Hadir.'], 422);
        }
        if ($status === 'present' && $checkOut !== '' && $checkOut < $checkIn) {
            respond(['error' => 'Jam pulang tidak boleh lebih awal dari jam masuk.'], 422);
        }
        if ($status !== 'present') {
            $checkIn = '';
            $checkOut = '';
        }

        $participantCheck = $db->prepare("SELECT 1 FROM users WHERE id = ? AND role = 'participant'");
        $participantCheck->execute([$participantId]);
        if (!$participantCheck->fetchColumn()) {
            respond(['error' => 'Akun peserta yang dipilih tidak ditemukan.'], 404);
        }
        $recordCheck = $db->prepare('SELECT 1 FROM attendances WHERE id = ?');
        $recordCheck->execute([$attendanceId]);
        if (!$recordCheck->fetchColumn()) {
            respond(['error' => 'Catatan absensi tidak ditemukan. Muat ulang riwayat lalu coba lagi.'], 404);
        }

        try {
            $update = $db->prepare(
                'UPDATE attendances
                 SET user_id = ?, work_date = ?, check_in = ?, check_out = ?,
                     attendance_status = ?, attendance_note = ?
                 WHERE id = ?'
            );
            $update->execute([
                $participantId,
                $date,
                $checkIn === '' ? null : $date . ' ' . $checkIn . ':00',
                $checkOut === '' ? null : $date . ' ' . $checkOut . ':00',
                $status,
                trim($note),
                $attendanceId,
            ]);
        } catch (PDOException $error) {
            if ((int) ($error->errorInfo[1] ?? 0) === 1062) {
                respond(['error' => 'Peserta tersebut sudah memiliki catatan absensi pada tanggal yang dipilih.'], 409);
            }
            throw $error;
        }
        respond(['records' => fetchRecords($db)]);
    }

    if ($action === 'admin-delete-attendance') {
        requireAdmin($db, $userId);
        $attendanceId = filter_var($data['attendanceId'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($attendanceId === false) {
            respond(['error' => 'Catatan absensi yang dipilih tidak valid.'], 422);
        }
        $delete = $db->prepare('DELETE FROM attendances WHERE id = ?');
        $delete->execute([$attendanceId]);
        if ($delete->rowCount() !== 1) {
            respond(['error' => 'Catatan absensi tidak ditemukan atau sudah dihapus. Muat ulang riwayat lalu coba lagi.'], 404);
        }
        respond(['records' => fetchRecords($db)]);
    }

    if ($action === 'profile') {
        $name = trim((string) ($data['name'] ?? ''));
        $identity = trim((string) ($data['identity'] ?? ''));
        $school = trim((string) ($data['school'] ?? ''));
        $rawDivisionId = $data['divisionId'] ?? null;
        if ($rawDivisionId === '' || $rawDivisionId === null) {
            $divisionId = null;
        } else {
            $divisionId = filter_var($rawDivisionId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($divisionId === false) {
                respond(['error' => 'Pilih divisi yang tersedia atau kosongkan pilihan.'], 422);
            }
        }
        if ($name === '' || mb_strlen($name) > 80 || mb_strlen($identity) > 40 || mb_strlen($school) > 100) {
            respond(['error' => 'Periksa nama dan data profil. Batas nama 80 karakter, NIM/NIS 40, dan sekolah 100.'], 422);
        }
        if ($divisionId !== null) {
            $divisionCheck = $db->prepare('SELECT 1 FROM divisions WHERE id = ?');
            $divisionCheck->execute([$divisionId]);
            if (!$divisionCheck->fetchColumn()) {
                respond(['error' => 'Divisi yang dipilih sudah tidak tersedia. Muat ulang halaman lalu pilih divisi lain.'], 422);
            }
        }
        $statement = $db->prepare('UPDATE users SET name = ?, identity_number = ?, school = ?, division_id = ? WHERE id = ?');
        $statement->execute([$name, $identity, $school, $divisionId, $userId]);
        $profileQuery = $db->prepare(
            'SELECT u.name, u.identity_number, u.school, u.division_id, d.name AS division_name
             FROM users AS u LEFT JOIN divisions AS d ON d.id = u.division_id WHERE u.id = ?'
        );
        $profileQuery->execute([$userId]);
        $updatedProfile = $profileQuery->fetch();
        if (!$updatedProfile) {
            respond(['error' => 'Profil tidak ditemukan setelah disimpan.'], 500);
        }
        respond(['profile' => profileFromRow($updatedProfile)]);
    }

    if ($action === 'division') {
        requireAdmin($db, $userId);
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
            $unassign = $db->prepare('UPDATE users SET division_id = NULL WHERE division_id = ?');
            $unassign->execute([$id]);
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
            respond(['error' => 'Struktur database belum sesuai. Untuk database baru, impor database.sql. Untuk database lama, jalankan setiap migrasi yang belum diterapkan, termasuk database-migration-admin-divisions.sql untuk role administrator.'], 503);
        }
    }

    respond(['error' => 'Server gagal memproses permintaan. Lihat error log PHP di panel hosting untuk detail.'], 500);
}

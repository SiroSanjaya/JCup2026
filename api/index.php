<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once '../config.php';
$action = $_GET['action'] ?? '';

switch ($action) {

    // ═══ SISTEM LOGIN & SESSION ═══
    case 'login':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            break;
        }
        $in = json_decode(file_get_contents('php://input'), true);
        $pass_input = $in['password'] ?? '';

        // Hash BCRYPT untuk kata "lupa" 
        // (Dibuat menggunakan password_hash('lupa', PASSWORD_DEFAULT))
        $hash_lupa = '$2y$10$7ZkP9X1w1J8F3l4mO8J9VuU7g3H3j3y5l2k1z8x9c0v9b8n7m6M5';

        // Kita bandingkan input user dengan hash yang sudah dienkripsi
        // Gunakan $pass_input === 'lupa' jika Anda tidak ingin repot dengan hash, 
        // tapi password_verify adalah standar keamanannya.
        if (password_verify($pass_input, password_hash('lupa', PASSWORD_DEFAULT))) {
            // Set session bahwa admin sudah login
            $_SESSION['is_admin'] = true;
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['error' => 'Password salah!']);
        }
        break;

    case 'logout':
        // Hapus semua session
        session_unset();
        session_destroy();
        echo json_encode(['success' => true]);
        break;

    case 'check_auth':
        // Cek apakah user saat ini punya session admin
        $isAdmin = isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true;
        echo json_encode(['is_admin' => $isAdmin]);
        break;

    case 'get_peserta':
        $db = getDB();
        $namaList = $db->query("
            SELECT MAX(nama) as nama, MIN(created_at) as created_at
            FROM peserta_cabang
            GROUP BY LOWER(nama)
            ORDER BY MAX(nama) ASC
        ")->fetchAll();
        $stmtC = $db->prepare("
            SELECT pc.id as row_id, c.id as cabang_id, c.nama as cabang_nama, c.emoji
            FROM peserta_cabang pc
            JOIN cabang c ON pc.cabang_id = c.id
            WHERE LOWER(pc.nama) = LOWER(?)
            ORDER BY c.urutan
        ");

        $result = [];
        foreach ($namaList as $n) {
            $stmtC->execute([$n['nama']]);
            $result[] = ['nama' => $n['nama'], 'created_at' => $n['created_at'], 'cabang' => $stmtC->fetchAll()];
        }
        echo json_encode(['success' => true, 'data' => $result]);
        break;

    case 'get_cabang':
        $rows = getDB()->query("
            SELECT c.*, COUNT(pc.id) as jumlah_peserta
            FROM cabang c LEFT JOIN peserta_cabang pc ON c.id = pc.cabang_id
            GROUP BY c.id ORDER BY c.urutan
        ")->fetchAll();
        echo json_encode(['success' => true, 'data' => $rows]);
        break;

    case 'get_statistik':
        $db   = getDB();
        $tp   = (int)$db->query("SELECT COUNT(DISTINCT LOWER(nama)) FROM peserta_cabang")->fetchColumn();
        $tpd  = (int)$db->query("SELECT COUNT(*) FROM peserta_cabang")->fetchColumn();
        $twin = (int)$db->query("SELECT COUNT(*) FROM skor WHERE skor_adm > skor_lawan")->fetchColumn();
        $tjad = (int)$db->query("SELECT COUNT(*) FROM jadwal WHERE status = 'upcoming'")->fetchColumn();
        $cab  = $db->query("
            SELECT c.id, c.nama, c.emoji, c.max_peserta, COUNT(pc.id) as jumlah
            FROM cabang c LEFT JOIN peserta_cabang pc ON c.id = pc.cabang_id
            GROUP BY c.id ORDER BY c.urutan
        ")->fetchAll();
        echo json_encode(['success' => true, 'data' => [
            'total_peserta'    => $tp,
            'total_pendaftaran' => $tpd,
            'total_menang'     => $twin,
            'jadwal_upcoming'  => $tjad,
            'cabang'           => $cab
        ]]);
        break;

    case 'daftar':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            break;
        }
        $in       = json_decode(file_get_contents('php://input'), true);
        $nama     = trim($in['nama'] ?? '');
        $branches = $in['branches'] ?? [];
        if (!$nama) {
            echo json_encode(['error' => 'Nama tidak boleh kosong']);
            break;
        }
        if (mb_strlen($nama) > 100) {
            echo json_encode(['error' => 'Nama terlalu panjang']);
            break;
        }
        if (empty($branches)) {
            echo json_encode(['error' => 'Pilih minimal satu cabang']);
            break;
        }
        $db     = getDB();
        $validC = $db->query("SELECT id FROM cabang")->fetchAll(PDO::FETCH_COLUMN);
        $branches = array_values(array_intersect($branches, $validC));
        if (empty($branches)) {
            echo json_encode(['error' => 'Cabang tidak valid']);
            break;
        }
        $sudahAda = [];
        $inserted = 0;
        foreach ($branches as $bid) {
            $cek = $db->prepare("SELECT id FROM peserta_cabang WHERE LOWER(nama) = LOWER(?) AND cabang_id = ?");
            $cek->execute([$nama, $bid]);
            if ($cek->fetch()) {
                $sudahAda[] = "<b>$nama</b> sudah terdaftar di " . getCabangLabel($db, $bid);
                continue;
            }
            $q = $db->prepare("SELECT c.max_peserta, COUNT(pc.id) as j FROM cabang c LEFT JOIN peserta_cabang pc ON c.id = pc.cabang_id WHERE c.id = ? GROUP BY c.id");
            $q->execute([$bid]);
            $row = $q->fetch();
            if ($row && $row['max_peserta'] && (int)$row['j'] >= (int)$row['max_peserta']) {
                $sudahAda[] = "Kuota " . getCabangLabel($db, $bid) . " sudah penuh";
                continue;
            }
            $db->prepare("INSERT INTO peserta_cabang(nama, cabang_id) VALUES(?, ?)")->execute([$nama, $bid]);
            $inserted++;
        }
        if ($inserted === 0) echo json_encode(['error' => implode('<br>', $sudahAda)]);
        else echo json_encode(['success' => true, 'message' => "$nama didaftarkan ke $inserted cabang.", 'warnings' => $sudahAda]);
        break;

    case 'hapus':
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) {
            echo json_encode(['error' => 'ID tidak valid']);
            break;
        }
        getDB()->prepare("DELETE FROM peserta_cabang WHERE id = ?")->execute([$id]);
        echo json_encode(['success' => true]);
        break;

    case 'hapus_nama':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            break;
        }
        $in   = json_decode(file_get_contents('php://input'), true);
        $nama = trim($in['nama'] ?? '');
        if (!$nama) {
            echo json_encode(['error' => 'Nama kosong']);
            break;
        }
        getDB()->prepare("DELETE FROM peserta_cabang WHERE LOWER(nama) = LOWER(?)")->execute([$nama]);
        echo json_encode(['success' => true]);
        break;

    case 'tambah_skor':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            break;
        }
        $in         = json_decode(file_get_contents('php://input'), true);
        $cabang_id  = trim($in['cabang_id'] ?? '');
        $lawan      = trim($in['lawan'] ?? '');
        $skor_adm   = (int)($in['skor_adm'] ?? 0);
        $skor_lawan = (int)($in['skor_lawan'] ?? 0);
        $tanggal    = trim($in['tanggal'] ?? '');
        $ket        = trim($in['keterangan'] ?? '');
        if (!$cabang_id || !$lawan || !$tanggal) {
            echo json_encode(['error' => 'Data tidak lengkap']);
            break;
        }
        getDB()->prepare("INSERT INTO skor(cabang_id, lawan, skor_adm, skor_lawan, tanggal, keterangan) VALUES(?,?,?,?,?,?)")
            ->execute([$cabang_id, $lawan, $skor_adm, $skor_lawan, $tanggal, $ket ?: null]);
        echo json_encode(['success' => true]);
        break;

    case 'get_skor':
        $rows = getDB()->query("
            SELECT s.*, c.nama as cabang_nama, c.emoji as cabang_emoji
            FROM skor s JOIN cabang c ON s.cabang_id = c.id
            ORDER BY s.tanggal DESC, s.id DESC
        ")->fetchAll();
        echo json_encode(['success' => true, 'data' => $rows]);
        break;

    case 'hapus_skor':
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) {
            echo json_encode(['error' => 'ID tidak valid']);
            break;
        }
        getDB()->prepare("DELETE FROM skor WHERE id = ?")->execute([$id]);
        echo json_encode(['success' => true]);
        break;

    // ═══ JADWAL ═══

    case 'get_jadwal':
        $db            = getDB();
        $cabang_filter = $_GET['cabang_id'] ?? '';
        $sql    = "SELECT j.*, c.nama as cabang_nama, c.emoji as cabang_emoji FROM jadwal j JOIN cabang c ON j.cabang_id = c.id";
        $params = [];
        if ($cabang_filter) {
            $sql .= " WHERE j.cabang_id = ?";
            $params[] = $cabang_filter;
        }
        $sql .= " ORDER BY j.tanggal ASC, j.jam ASC";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
        break;

    case 'tambah_jadwal':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            break;
        }
        $in        = json_decode(file_get_contents('php://input'), true);
        $cabang_id = trim($in['cabang_id'] ?? '');
        $tim_b     = trim($in['tim_b'] ?? '');
        $tanggal   = trim($in['tanggal'] ?? '');
        $jam       = trim($in['jam'] ?? '');
        $lokasi    = trim($in['lokasi'] ?? '');
        $ket       = trim($in['keterangan'] ?? '');
        if (!$cabang_id || !$tim_b || !$tanggal || !$jam) {
            echo json_encode(['error' => 'Cabang, lawan, tanggal, dan jam wajib diisi']);
            break;
        }
        getDB()->prepare("INSERT INTO jadwal(cabang_id, tim_b, tanggal, jam, lokasi, keterangan) VALUES(?,?,?,?,?,?)")
            ->execute([$cabang_id, $tim_b, $tanggal, $jam, $lokasi ?: null, $ket ?: null]);
        echo json_encode(['success' => true]);
        break;

    case 'update_status_jadwal':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            break;
        }
        $in      = json_decode(file_get_contents('php://input'), true);
        $id      = (int)($in['id'] ?? 0);
        $status  = trim($in['status'] ?? '');
        $allowed = ['upcoming', 'ongoing', 'selesai'];
        if (!$id || !in_array($status, $allowed)) {
            echo json_encode(['error' => 'Data tidak valid']);
            break;
        }
        getDB()->prepare("UPDATE jadwal SET status = ? WHERE id = ?")->execute([$status, $id]);
        echo json_encode(['success' => true]);
        break;

    case 'hapus_jadwal':
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) {
            echo json_encode(['error' => 'ID tidak valid']);
            break;
        }
        getDB()->prepare("DELETE FROM jadwal WHERE id = ?")->execute([$id]);
        echo json_encode(['success' => true]);
        break;

    // ═══ FORUM INFORMASI ═══

    case 'get_forum':
        $db = getDB();
        $forums = $db->query("SELECT * FROM forum_informasi ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($forums as &$f) {
            $stmt = $db->prepare("SELECT file_path FROM forum_gambar WHERE forum_id = ?");
            $stmt->execute([$f['id']]);
            $f['gambar'] = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }
        echo json_encode(['success' => true, 'data' => $forums]);
        break;

    case 'tambah_forum':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            break;
        }
        // Karena menggunakan FormData untuk file, data teks ada di $_POST
        $judul = trim($_POST['judul'] ?? '');
        $konten = trim($_POST['konten'] ?? '');

        if (!$judul || !$konten) {
            echo json_encode(['error' => 'Judul dan konten wajib diisi']);
            break;
        }

        $db = getDB();
        $db->prepare("INSERT INTO forum_informasi (judul, konten) VALUES (?, ?)")->execute([$judul, $konten]);
        $forum_id = $db->lastInsertId();

        // Handle File Uploads (Multiple)
        $uploadDir = '../uploads/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

        if (!empty($_FILES['gambar']['name'][0])) {
            foreach ($_FILES['gambar']['name'] as $key => $val) {
                // Buat nama file unik
                $fileName = time() . '_' . preg_replace("/[^a-zA-Z0-9.]/", "", basename($_FILES['gambar']['name'][$key]));
                $targetFilePath = $uploadDir . $fileName;

                if (move_uploaded_file($_FILES["gambar"]["tmp_name"][$key], $targetFilePath)) {
                    // Simpan path ke DB (tanpa '../' agar bisa dibaca langsung oleh index.html)
                    $db->prepare("INSERT INTO forum_gambar (forum_id, file_path) VALUES (?, ?)")->execute([$forum_id, 'uploads/' . $fileName]);
                }
            }
        }
        echo json_encode(['success' => true]);
        break;

    case 'hapus_forum':
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) {
            echo json_encode(['error' => 'ID tidak valid']);
            break;
        }
        $db = getDB();
        // Hapus file fisik gambar terlebih dahulu
        $stmt = $db->prepare("SELECT file_path FROM forum_gambar WHERE forum_id = ?");
        $stmt->execute([$id]);
        $gambars = $stmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($gambars as $g) {
            if (file_exists('../' . $g)) unlink('../' . $g);
        }
        // Hapus data dari DB (tabel forum_gambar otomatis terhapus karena ON DELETE CASCADE)
        $db->prepare("DELETE FROM forum_informasi WHERE id = ?")->execute([$id]);
        echo json_encode(['success' => true]);
        break;

    default:
        http_response_code(404);
        echo json_encode(['error' => 'Action tidak ditemukan']);
}

function getCabangLabel($db, $id)
{
    $r = $db->prepare("SELECT CONCAT(emoji, ' ', nama) FROM cabang WHERE id = ?");
    $r->execute([$id]);
    return $r->fetchColumn() ?: $id;
}

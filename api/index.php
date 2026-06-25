<?php
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

    // ── Semua peserta: 1 nama = 1 row, cabang sebagai array objek ──
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
      JOIN cabang c ON pc.cabang_id=c.id
      WHERE LOWER(pc.nama)=LOWER(?)
      ORDER BY c.urutan
    ");
        $result = [];
        foreach ($namaList as $n) {
            $stmtC->execute([$n['nama']]);
            $result[] = [
                'nama'       => $n['nama'],
                'created_at' => $n['created_at'],
                'cabang'     => $stmtC->fetchAll()
            ];
        }
        echo json_encode(['success' => true, 'data' => $result]);
        break;

    // ── Daftar cabang + jumlah peserta ──
    case 'get_cabang':
        $db = getDB();
        $rows = $db->query("
      SELECT c.*, COUNT(pc.id) as jumlah_peserta
      FROM cabang c LEFT JOIN peserta_cabang pc ON c.id=pc.cabang_id
      GROUP BY c.id ORDER BY c.urutan
    ")->fetchAll();
        echo json_encode(['success' => true, 'data' => $rows]);
        break;

    // ── Statistik dashboard ──
    case 'get_statistik':
        $db = getDB();
        $tp  = (int)$db->query("SELECT COUNT(DISTINCT LOWER(nama)) FROM peserta_cabang")->fetchColumn();
        $tpd = (int)$db->query("SELECT COUNT(*) FROM peserta_cabang")->fetchColumn();
        $twin = (int)$db->query("SELECT COUNT(*) FROM skor WHERE skor_adm>skor_lawan")->fetchColumn();
        $cab = $db->query("
      SELECT c.id,c.nama,c.emoji,c.max_peserta,COUNT(pc.id) as jumlah
      FROM cabang c LEFT JOIN peserta_cabang pc ON c.id=pc.cabang_id
      GROUP BY c.id ORDER BY c.urutan
    ")->fetchAll();
        echo json_encode(['success' => true, 'data' => [
            'total_peserta' => $tp,
            'total_pendaftaran' => $tpd,
            'total_menang' => $twin,
            'cabang' => $cab
        ]]);
        break;

    // ── Daftar peserta baru ──
    case 'daftar':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            break;
        }
        $in      = json_decode(file_get_contents('php://input'), true);
        $nama    = trim($in['nama'] ?? '');
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

        $db = getDB();
        $validC = $db->query("SELECT id FROM cabang")->fetchAll(PDO::FETCH_COLUMN);
        $branches = array_values(array_intersect($branches, $validC));
        if (empty($branches)) {
            echo json_encode(['error' => 'Cabang tidak valid']);
            break;
        }

        $sudahAda = [];
        $inserted = 0;
        foreach ($branches as $bid) {
            // cek duplikat nama+cabang (case-insensitive)
            $cek = $db->prepare("SELECT id FROM peserta_cabang WHERE LOWER(nama)=LOWER(?) AND cabang_id=?");
            $cek->execute([$nama, $bid]);
            if ($cek->fetch()) {
                $cn = getCabangLabel($db, $bid);
                $sudahAda[] = "<b>$nama</b> sudah terdaftar di $cn";
                continue;
            }
            // cek kuota
            $q = $db->prepare("SELECT c.max_peserta,COUNT(pc.id) as j FROM cabang c LEFT JOIN peserta_cabang pc ON c.id=pc.cabang_id WHERE c.id=? GROUP BY c.id");
            $q->execute([$bid]);
            $row = $q->fetch();
            if ($row && $row['max_peserta'] && (int)$row['j'] >= (int)$row['max_peserta']) {
                $cn = getCabangLabel($db, $bid);
                $sudahAda[] = "Kuota $cn sudah penuh";
                continue;
            }
            $db->prepare("INSERT INTO peserta_cabang(nama,cabang_id) VALUES(?,?)")->execute([$nama, $bid]);
            $inserted++;
        }

        if ($inserted === 0) {
            echo json_encode(['error' => implode('<br>', $sudahAda)]);
        } else {
            echo json_encode(['success' => true, 'message' => "$nama didaftarkan ke $inserted cabang.", 'warnings' => $sudahAda]);
        }
        break;

    // ── Hapus 1 baris peserta_cabang (hapus dari 1 cabang) ──
    case 'hapus':
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) {
            echo json_encode(['error' => 'ID tidak valid']);
            break;
        }
        getDB()->prepare("DELETE FROM peserta_cabang WHERE id=?")->execute([$id]);
        echo json_encode(['success' => true]);
        break;

    // ── Hapus semua cabang milik satu nama ──
    case 'hapus_nama':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            break;
        }
        $in  = json_decode(file_get_contents('php://input'), true);
        $nama = trim($in['nama'] ?? '');
        if (!$nama) {
            echo json_encode(['error' => 'Nama kosong']);
            break;
        }
        getDB()->prepare("DELETE FROM peserta_cabang WHERE LOWER(nama)=LOWER(?)")->execute([$nama]);
        echo json_encode(['success' => true]);
        break;

    // ── Tambah skor ──
    case 'tambah_skor':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            break;
        }
        $in        = json_decode(file_get_contents('php://input'), true);
        $cabang_id = trim($in['cabang_id'] ?? '');
        $lawan     = trim($in['lawan'] ?? '');
        $skor_adm  = (int)($in['skor_adm'] ?? 0);
        $skor_lawan = (int)($in['skor_lawan'] ?? 0);
        $tanggal   = trim($in['tanggal'] ?? '');
        $ket       = trim($in['keterangan'] ?? '');
        if (!$cabang_id || !$lawan || !$tanggal) {
            echo json_encode(['error' => 'Data tidak lengkap']);
            break;
        }
        getDB()->prepare("INSERT INTO skor(cabang_id,lawan,skor_adm,skor_lawan,tanggal,keterangan) VALUES(?,?,?,?,?,?)")
            ->execute([$cabang_id, $lawan, $skor_adm, $skor_lawan, $tanggal, $ket ?: null]);
        echo json_encode(['success' => true]);
        break;

    // ── Get skor ──
    case 'get_skor':
        $rows = getDB()->query("
      SELECT s.*, c.nama as cabang_nama, c.emoji as cabang_emoji
      FROM skor s JOIN cabang c ON s.cabang_id=c.id
      ORDER BY s.tanggal DESC, s.id DESC
    ")->fetchAll();
        echo json_encode(['success' => true, 'data' => $rows]);
        break;

    // ── Hapus skor ──
    case 'hapus_skor':
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) {
            echo json_encode(['error' => 'ID tidak valid']);
            break;
        }
        getDB()->prepare("DELETE FROM skor WHERE id=?")->execute([$id]);
        echo json_encode(['success' => true]);
        break;

    default:
        http_response_code(404);
        echo json_encode(['error' => 'Action tidak ditemukan']);
}

function getCabangLabel($db, $id)
{
    $r = $db->prepare("SELECT CONCAT(emoji,' ',nama) FROM cabang WHERE id=?");
    $r->execute([$id]);
    return $r->fetchColumn() ?: $id;
}

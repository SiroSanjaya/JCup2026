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

    case 'get_peserta':
        $db = getDB();
        $rows = $db->query("SELECT p.id,p.nama,p.created_at,
      GROUP_CONCAT(pc.cabang_id ORDER BY c.urutan SEPARATOR ',') as cids,
      GROUP_CONCAT(c.nama       ORDER BY c.urutan SEPARATOR '||') as cnames,
      GROUP_CONCAT(c.emoji      ORDER BY c.urutan SEPARATOR ',') as cemojis
      FROM peserta p
      LEFT JOIN peserta_cabang pc ON p.id=pc.peserta_id
      LEFT JOIN cabang c ON pc.cabang_id=c.id
      GROUP BY p.id,p.nama,p.created_at ORDER BY p.nama ASC")->fetchAll();
        foreach ($rows as &$r) {
            $r['cabang'] = $r['cids'] ? array_map(null, explode(',', $r['cids']), explode('||', $r['cnames']), explode(',', $r['cemojis'])) : [];
            unset($r['cids'], $r['cnames'], $r['cemojis']);
        }
        echo json_encode(['success' => true, 'data' => $rows]);
        break;

    case 'get_cabang':
        $db = getDB();
        $rows = $db->query("SELECT c.*,COUNT(pc.peserta_id) as jumlah_peserta
      FROM cabang c LEFT JOIN peserta_cabang pc ON c.id=pc.cabang_id
      GROUP BY c.id ORDER BY c.urutan")->fetchAll();
        echo json_encode(['success' => true, 'data' => $rows]);
        break;

    case 'get_statistik':
        $db = getDB();
        $tp  = (int)$db->query("SELECT COUNT(*) FROM peserta")->fetchColumn();
        $tpd = (int)$db->query("SELECT COUNT(*) FROM peserta_cabang")->fetchColumn();
        $twin = (int)$db->query("SELECT COUNT(*) FROM skor WHERE skor_adm>skor_lawan")->fetchColumn();
        $cab = $db->query("SELECT c.id,c.nama,c.emoji,c.max_peserta,COUNT(pc.peserta_id) as jumlah
      FROM cabang c LEFT JOIN peserta_cabang pc ON c.id=pc.cabang_id
      GROUP BY c.id ORDER BY c.urutan")->fetchAll();
        echo json_encode(['success' => true, 'data' => [
            'total_peserta' => $tp,
            'total_pendaftaran' => $tpd,
            'total_menang' => $twin,
            'cabang' => $cab
        ]]);
        break;

    case 'daftar':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            break;
        }
        $in = json_decode(file_get_contents('php://input'), true);
        $nama = trim($in['nama'] ?? '');
        $branches = $in['branches'] ?? [];
        if (!$nama) {
            echo json_encode(['error' => 'Nama tidak boleh kosong']);
            break;
        }
        if (empty($branches)) {
            echo json_encode(['error' => 'Pilih minimal satu cabang']);
            break;
        }
        if (mb_strlen($nama) > 100) {
            echo json_encode(['error' => 'Nama terlalu panjang']);
            break;
        }
        $db = getDB();
        // $cek=$db->prepare("SELECT id FROM peserta WHERE LOWER(nama)=LOWER(?)");
        // $cek->execute([$nama]);
        // if($cek->fetch()){echo json_encode(['error'=>"$nama sudah terdaftar"]);break;}
        $validC = $db->query("SELECT id FROM cabang")->fetchAll(PDO::FETCH_COLUMN);
        $branches = array_values(array_intersect($branches, $validC));
        if (empty($branches)) {
            echo json_encode(['error' => 'Cabang tidak valid']);
            break;
        }
        foreach ($branches as $bid) {
            $q = $db->prepare("SELECT c.max_peserta,COUNT(pc.id) as j FROM cabang c LEFT JOIN peserta_cabang pc ON c.id=pc.cabang_id WHERE c.id=? GROUP BY c.id");
            $q->execute([$bid]);
            $row = $q->fetch();
            if ($row && $row['max_peserta'] && $row['j'] >= $row['max_peserta']) {
                echo json_encode(['error' => "Kuota cabang $bid penuh"]);
                return;
            }
        }
        $db->beginTransaction();
        try {
            $ins = $db->prepare("INSERT INTO peserta(nama) VALUES(?)");
            $ins->execute([$nama]);
            $pid = $db->lastInsertId();
            $insC = $db->prepare("INSERT INTO peserta_cabang(peserta_id,cabang_id) VALUES(?,?)");
            foreach ($branches as $bid) $insC->execute([$pid, $bid]);
            $db->commit();
            echo json_encode(['success' => true, 'message' => "$nama didaftarkan", 'id' => $pid]);
        } catch (Exception $e) {
            $db->rollBack();
            echo json_encode(['error' => 'Gagal: ' . $e->getMessage()]);
        }
        break;

    case 'hapus':
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) {
            echo json_encode(['error' => 'ID tidak valid']);
            break;
        }
        getDB()->prepare("DELETE FROM peserta WHERE id=?")->execute([$id]);
        echo json_encode(['success' => true]);
        break;

    case 'tambah_skor':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            break;
        }
        $in = json_decode(file_get_contents('php://input'), true);
        $cabang_id = trim($in['cabang_id'] ?? '');
        $lawan = trim($in['lawan'] ?? '');
        $skor_adm = (int)($in['skor_adm'] ?? 0);
        $skor_lawan = (int)($in['skor_lawan'] ?? 0);
        $tanggal = trim($in['tanggal'] ?? '');
        $ket = trim($in['keterangan'] ?? '');
        if (!$cabang_id || !$lawan || !$tanggal) {
            echo json_encode(['error' => 'Data tidak lengkap']);
            break;
        }
        $db = getDB();
        $db->prepare("INSERT INTO skor(cabang_id,lawan,skor_adm,skor_lawan,tanggal,keterangan) VALUES(?,?,?,?,?,?)")
            ->execute([$cabang_id, $lawan, $skor_adm, $skor_lawan, $tanggal, $ket ?: null]);
        echo json_encode(['success' => true]);
        break;

    case 'get_skor':
        $db = getDB();
        $rows = $db->query("SELECT s.*,c.nama as cabang_nama,c.emoji as cabang_emoji
      FROM skor s JOIN cabang c ON s.cabang_id=c.id
      ORDER BY s.tanggal DESC,s.id DESC")->fetchAll();
        echo json_encode(['success' => true, 'data' => $rows]);
        break;

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

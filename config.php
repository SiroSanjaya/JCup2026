<?php
// =============================================
// Konfigurasi Database Railway
// =============================================

define('DB_HOST', 'reseau.proxy.rlwy.net');
define('DB_PORT', '41108');
define('DB_USER', 'root');
define('DB_PASS', 'JwoZALOXipGOZSnNZLVLtGeeIHjxpKNt'); // ganti kalau nanti regenerate
define('DB_NAME', 'railway');

function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
                ]
            );
        } catch (PDOException $e) {
            http_response_code(500);
            die(json_encode([
                'error' => 'Koneksi database gagal: ' . $e->getMessage()
            ]));
        }
    }
    return $pdo;
}

<?php
namespace Controllers;

use Helpers\AuthHelper;
use Models\IndikatorKinerjaModel;
use Models\SasaranModel;
use Models\TahunPerjakinModel;
use Models\TujuanKinerjaModel;

class PerjanjianKinerjaController {

    public function __construct() {
        AuthHelper::requireRole('superadmin');
    }

    public function index($f3) {
        $tahunRaw = $f3->get('GET.tahun');
        $tahun    = ($tahunRaw === null) ? '' : (string)$tahunRaw;
        $topikId  = intval($f3->get('GET.topik_id')); // 0 = semua topik

        $db = $f3->get('DB');
        // Auto-default ke topik tahun berjalan jika belum ada filter
        if ($topikId === 0) {
            $currentYear = date('Y');
            $defaultTopik = $db->exec('SELECT id FROM topik_perjakin WHERE tahun = ? ORDER BY id ASC LIMIT 1', $currentYear);
            if (!empty($defaultTopik)) $topikId = (int)$defaultTopik[0]['id'];
        }

        $indikatorModel = new IndikatorKinerjaModel();
        $rawIndikators = $indikatorModel->getAllByTahun($tahun, $topikId);

        // Group indikator by Tujuan (matching the UI dropdown selection)
        $indikatorGrouped = [];
        foreach ($rawIndikators as $ind) {
            $tujuanKey = $ind['tujuan_kode'] . ' - ' . $ind['tujuan_nama'];
            $indikatorGrouped[$tujuanKey][] = $ind;
        }

        // Ambil data users role PIC dari sil2020
        $db = $f3->get('DB');
        $dbSil = $f3->get('DB_SIL');
        if ($dbSil) {
            $picsRaw = $dbSil->exec("SELECT id_user, login, nama_user FROM tb_arsipuser WHERE si_perjakin = 'pic' AND login IS NOT NULL AND login != '' ORDER BY nama_user ASC");
            $pics = array_map(function($r) {
                $namaLengkap = $r['nama_user'] ?: $r['login'];
                // Hilangkan single quote agar tidak merusak parameter @click AlpineJS
                $namaLengkap = str_replace("'", "`", $namaLengkap);
                return ['id' => 0, 'login_sil' => $r['login'], 'nama_lengkap' => $namaLengkap];
            }, $picsRaw);
        } else {
            $pics = [];
        }

        // Ambil daftar tahun dinamis dari tabel indikator
        $tahunListRaw = $db->exec('SELECT DISTINCT tahun FROM indikator WHERE tahun IS NOT NULL ORDER BY tahun DESC');
        $tahunList = [];
        foreach ($tahunListRaw as $row) {
            if (!empty($row['tahun'])) $tahunList[] = $row['tahun'];
        }
        $currentYear = date('Y');
        if (!in_array($currentYear, $tahunList)) {
            $tahunList[] = $currentYear;
            rsort($tahunList);
        }

        // --- Data for Modal Tambah Indikator (Cascading Dropdown) ---
        $topikModel = new TahunPerjakinModel();
        $topiks = $topikModel->getAll();

        $tujuanModel = new TujuanKinerjaModel();
        $db = $f3->get('DB');
        $tujuans = $db->exec("SELECT id, topik_id, kode, nama FROM tujuan ORDER BY id ASC");

        $sasaranModel = new SasaranModel();
        $sasarans = $db->exec("SELECT id, tujuan_id, kode, nama FROM sasaran ORDER BY id ASC");
        // -------------------------------------------------------------

        if (!$f3->exists('SESSION.csrf')) {
            $f3->set('SESSION.csrf', bin2hex(random_bytes(32)));
        }

        // Konsumsi flash message
        if ($f3->exists('SESSION.successMsg')) {
            $f3->set('successMsg', $f3->get('SESSION.successMsg'));
            $f3->clear('SESSION.successMsg');
        }
        if ($f3->exists('SESSION.errorMsg')) {
            $f3->set('errorMsg', $f3->get('SESSION.errorMsg'));
            $f3->clear('SESSION.errorMsg');
        }

        $f3->set('csrf', $f3->get('SESSION.csrf'));
        $f3->set('topiks', $topiks);
        $f3->set('tujuansArr', $tujuans);
        $f3->set('sasaransArr', $sasarans);
        
        $f3->set('indikatorGrouped', $indikatorGrouped);
        $f3->set('pics', $pics);
        $f3->set('tahunList', $tahunList);
        $f3->set('selectedTahun', $tahun);
        $f3->set('selectedTopikId', $topikId);
        $f3->set('title', 'Indikator Kinerja');
        $f3->set('content', 'indikator_kinerja.html');
        echo \Template::instance()->render('layouts/main.html');
    }

    public function tujuan($f3) {
        // null  = URL tidak punya param ?tahun  → default semua tahun ('')
        // ''    = user pilih "Semua Tahun"
        // '2026'= tahun spesifik
        $tahunRaw = $f3->get('GET.tahun');
        $tahun    = ($tahunRaw === null) ? '' : (string)$tahunRaw;
        $topikId  = intval($f3->get('GET.topik_id'));   // 0 = semua topik

        $tujuanModel = new TujuanKinerjaModel();
        $db          = \Base::instance()->get('DB');

        // Auto-default ke topik tahun berjalan jika belum ada filter
        if ($topikId === 0) {
            $currentYear = date('Y');
            $defaultTopik = $db->exec('SELECT id FROM topik_perjakin WHERE tahun = ? ORDER BY id ASC LIMIT 1', $currentYear);
            if (!empty($defaultTopik)) $topikId = (int)$defaultTopik[0]['id'];
        }

        // Daftar semua topik
        if ($tahun !== '') {
            $topiks = $db->exec('SELECT id, judul, tahun FROM topik_perjakin WHERE tahun=? ORDER BY tahun DESC, id DESC', $tahun);
        } else {
            $topiks = $db->exec('SELECT id, judul, tahun FROM topik_perjakin ORDER BY tahun DESC, id DESC');
        }
        if (!is_array($topiks)) $topiks = [];

        // Validasi: jika Topik ID dipilih tapi tidak ada di filter topik tahun ini, reset ke Semua Topik (0)
        if ($topikId > 0 && !in_array($topikId, array_column($topiks, 'id'))) {
            $topikId = 0;
        }

        // Query tujuan sesuai kombinasi filter (dengan JOIN topik untuk nama topik)
        $joinSql = 'SELECT t.*, tp.judul AS topik_judul, 
                    (SELECT COUNT(*) FROM indikator i JOIN sasaran s ON i.sasaran_id = s.id WHERE s.tujuan_id = t.id) as indikator_count
                    FROM tujuan t 
                    LEFT JOIN topik_perjakin tp ON t.topik_id = tp.id';
        if ($topikId > 0 && $tahun !== '') {
            $tujuans = $db->exec($joinSql . ' WHERE t.tahun=? AND t.topik_id=? ORDER BY t.id ASC', [$tahun, $topikId]);
        } elseif ($topikId > 0) {
            $tujuans = $db->exec($joinSql . ' WHERE t.topik_id=? ORDER BY t.id ASC', $topikId);
        } elseif ($tahun !== '') {
            $tujuans = $db->exec($joinSql . ' WHERE t.tahun=? ORDER BY t.id ASC', $tahun);
        } else {
            $tujuans = $db->exec($joinSql . ' ORDER BY t.id ASC');
        }
        if (!is_array($tujuans)) $tujuans = [];

        // Judul topik terpilih
        $selectedTopik = null;
        foreach ($topiks as $t) {
            if ($t['id'] == $topikId) { $selectedTopik = $t; break; }
        }

        // Ambil daftar tahun dinamis dari tabel tujuan (urut terbaru ke terlama)
        $tahunListRaw = $db->exec('SELECT DISTINCT tahun FROM tujuan WHERE tahun IS NOT NULL ORDER BY tahun DESC');
        $tahunList = [];
        foreach ($tahunListRaw as $row) {
            if (!empty($row['tahun'])) {
                $tahunList[] = $row['tahun'];
            }
        }
        // Pastikan tahun curent masuk walau tidak ada di DB
        $currentYear = date('Y');
        if (!in_array($currentYear, $tahunList)) {
            $tahunList[] = $currentYear;
            rsort($tahunList); // Urutkan lagi dari besar ke kecil
        }

        // CSRF
        if (!$f3->exists('SESSION.csrf')) {
            $f3->set('SESSION.csrf', bin2hex(random_bytes(32)));
        }
        
        // Konsumsi flash message
        if ($f3->exists('SESSION.successMsg')) {
            $f3->set('successMsg', $f3->get('SESSION.successMsg'));
            $f3->clear('SESSION.successMsg');
        }
        if ($f3->exists('SESSION.errorMsg')) {
            $f3->set('errorMsg', $f3->get('SESSION.errorMsg'));
            $f3->clear('SESSION.errorMsg');
        }

        // Build lookup map id => judul dari SEMUA topik (tidak terfilter tahun)
        $allTopiks = $db->exec('SELECT id, judul FROM topik_perjakin ORDER BY id ASC');
        $topikMap = [];
        foreach ($allTopiks as $t) { $topikMap[$t['id']] = $t['judul']; }

        $f3->set('tujuans',         $tujuans);
        $f3->set('topiks',          $topiks);
        $f3->set('tahunList',       $tahunList);
        $f3->set('topikMap',        $topikMap);
        $f3->set('selectedTopikId', (int)$topikId);
        $f3->set('selectedTopik',   $selectedTopik);
        $f3->set('selectedTahun', $tahun);
        $f3->set('csrf',          $f3->get('SESSION.csrf'));
        $f3->set('title',         'Tujuan dan Sasaran');
        $f3->set('content',       'tujuan_sasaran.html');
        echo \Template::instance()->render('layouts/main.html');
    }



    public function storeTujuan($f3) {
        $isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH'])
                  && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

        // CSRF Protection
        if ($f3->get('POST.csrf_token') !== $f3->get('SESSION.csrf')) {
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['status' => 'error', 'message' => 'Invalid CSRF token']);
                return;
            }
            $f3->error(400, 'Invalid CSRF token');
            return;
        }

        $post = $f3->get('POST');

        if (empty($post['kode']) || empty($post['nama'])) {
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['status' => 'error', 'message' => 'Kode dan Nama Tujuan wajib diisi.']);
                return;
            }
            $f3->set('SESSION.errorMsg', 'Kode dan Nama Tujuan wajib diisi.');
            $f3->reroute('/Tujuan-Sasaran');
            return;
        }

        // Ambil tahun dari topik yang dipilih, fallback ke tahun sekarang
        $tahunData = date('Y');
        if (!empty($post['topik_id'])) {
            $db = $f3->get('DB');
            $topikRow = $db->exec('SELECT tahun FROM topik_perjakin WHERE id=? LIMIT 1', (int)$post['topik_id']);
            if (!empty($topikRow) && !empty($topikRow[0]['tahun'])) {
                $tahunData = $topikRow[0]['tahun'];
            }
        }

        $data = [
            'kode'     => $post['kode'],
            'nama'     => $post['nama'],
            'tahun'    => $tahunData,
            'topik_id' => !empty($post['topik_id']) ? (int)$post['topik_id'] : null,
        ];

        $tujuanModel = new TujuanKinerjaModel();
        $result = $tujuanModel->create($data);

        if ($isAjax) {
            header('Content-Type: application/json');
            if ($result) {
                echo json_encode(['status' => 'success', 'message' => 'Tujuan berhasil ditambahkan.']);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Gagal menyimpan data tujuan.']);
            }
            return;
        }

        $f3->set('SESSION.successMsg', 'Tujuan berhasil ditambahkan.');
        $f3->reroute('/Tujuan-Sasaran');
    }

    public function updateTujuan($f3, $params) {
        $id = $params['id'];
        header('Content-Type: application/json');

        if ($f3->get('POST.csrf_token') !== $f3->get('SESSION.csrf')) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid CSRF token']);
            return;
        }

        $kode     = trim($f3->get('POST.kode')     ?? '');
        $nama     = trim($f3->get('POST.nama')     ?? '');
        $topikId  = $f3->get('POST.topik_id');

        if (empty($kode) || empty($nama)) {
            echo json_encode(['status' => 'error', 'message' => 'Kode dan Nama wajib diisi.']);
            return;
        }

        $tujuanModel = new TujuanKinerjaModel();
        $result = $tujuanModel->updateRecord($id, [
            'kode'     => $kode,
            'nama'     => $nama,
            'topik_id' => !empty($topikId) ? (int)$topikId : null,
        ]);

        if ($result !== false) {
            echo json_encode(['status' => 'success', 'message' => 'Tujuan berhasil diperbarui.']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Gagal memperbarui tujuan.']);
        }
    }

    public function deleteTujuan($f3, $params) {
        $id = $params['id'];
        header('Content-Type: application/json');

        if ($f3->get('POST.csrf_token') !== $f3->get('SESSION.csrf')) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid CSRF token']);
            return;
        }

        $tujuanModel = new TujuanKinerjaModel();
        $result = $tujuanModel->deleteRecord($id);

        if ($result !== false) {
            echo json_encode(['status' => 'success', 'message' => 'Tujuan berhasil dihapus.']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Gagal menghapus tujuan.']);
        }
    }



    public function store($f3) {
        $isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH'])
                  && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

        // CSRF Protection
        if ($f3->get('POST.csrf_token') !== $f3->get('SESSION.csrf')) {
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['status' => 'error', 'message' => 'Invalid CSRF token']);
                return;
            }
            $f3->error(400, 'Invalid CSRF token');
            return;
        }

        $post = $f3->get('POST');

        // Validation target is not empty
        if (empty($post['target_tahunan'])) {
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['status' => 'error', 'message' => 'Target tahunan wajib diisi.']);
                return;
            }
            $f3->set('SESSION.errorMsg', 'Target tahunan wajib diisi.');
            $f3->reroute('/master');
            return;
        }

        // Logic to handle destination Sasaran given only Tujuan ID from modal
        $tujuan_id = $post['tujuan_id'];
        if (empty($tujuan_id)) {
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['status' => 'error', 'message' => 'Tujuan / Sasaran wajib dipilih.']);
                return;
            }
            $f3->set('SESSION.errorMsg', 'Tujuan / Sasaran wajib dipilih.');
            $f3->reroute('/Indikator-Kinerja');
            return;
        }

        // Find existing Sasaran for this Tujuan, or create a default one
        $db = $f3->get('DB');
        $sasaran = $db->exec("SELECT id FROM sasaran WHERE tujuan_id = ? LIMIT 1", $tujuan_id);
        
        if (!empty($sasaran)) {
            $sasaran_id = $sasaran[0]['id'];
        } else {
            // Auto-create a default Sasaran to satisfy DB constraint
            $tujuan = $db->exec("SELECT tahun FROM tujuan WHERE id = ?", $tujuan_id);
            $tahun = !empty($tujuan) ? $tujuan[0]['tahun'] : date('Y');
            
            $sasaranModel = new SasaranModel();
            try {
                $sasaranModel->create([
                    'tujuan_id' => $tujuan_id,
                    'kode' => 'S-TJ-' . $tujuan_id . '-' . time(), // Make it unique
                    'nama' => 'Sasaran Otomatis',
                    'tahun' => $tahun
                ]);
                $sasaran_id = $db->lastInsertId();
                if (!$sasaran_id) {
                    // Method create di F3 Mapper juga melakukan populate ke field mapper nya
                    $sasaran_id = $sasaranModel->get('id');
                }
            } catch (\PDOException $e) {
                if ($isAjax) {
                    header('Content-Type: application/json');
                    echo json_encode(['status' => 'error', 'message' => 'Gagal membuat relasi sasaran: ' . $e->getMessage()]);
                    return;
                }
                $f3->set('SESSION.errorMsg', 'Gagal membuat relasi sasaran: ' . $e->getMessage());
                $f3->reroute('/Indikator-Kinerja');
                return;
            }
        }

        if (empty($sasaran_id)) {
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['status' => 'error', 'message' => 'Gagal mendapatkan data sasaran_id.']);
                return;
            }
            $f3->set('SESSION.errorMsg', 'Gagal mendapatkan data sasaran_id.');
            $f3->reroute('/Indikator-Kinerja');
            return;
        }

        $data = [
            'sasaran_id' => $sasaran_id,
            'nama' => $post['nama'],
            'satuan' => $post['satuan'],
            'jenis_indikator' => 'maximize', // default
            'target_tahunan' => $post['target_tahunan'], // string allowed
            'pic_user_id' => !empty($post['pic_user_id']) ? (int)$post['pic_user_id'] : null,
            'pic_login' => !empty($post['pic_login']) ? $post['pic_login'] : null,
            'tahun' => date('Y')
        ];

        try {
            $indikatorModel = new IndikatorKinerjaModel();
            $id = $indikatorModel->create($data);
            
            if (!$id) {
                throw new \Exception("Gagal menyimpan data ke database (Model return false).");
            }
        } catch (\Exception $e) {
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['status' => 'error', 'message' => 'Gagal menyimpan: ' . $e->getMessage()]);
                return;
            }
            $f3->set('SESSION.errorMsg', 'Gagal menyimpan: ' . $e->getMessage());
            $f3->reroute('/Indikator-Kinerja');
            return;
        }

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'success', 'message' => 'Indikator Kinerja berhasil ditambahkan.']);
            return;
        }

        $f3->set('SESSION.successMsg', 'Indikator berhasil ditambahkan.');
        $f3->reroute('/Indikator-Kinerja');
    }

    public function update($f3, $params) {
        $id = $params['id'];
        $isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH'])
                  && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
        
        // CSRF Protection
        if ($f3->get('POST.csrf_token') !== $f3->get('SESSION.csrf')) {
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['status' => 'error', 'message' => 'Invalid CSRF token']);
                return;
            }
            $f3->error(400, 'Invalid CSRF token');
            return;
        }

        $post = $f3->get('POST');

        // Validation target is not empty
        if (empty($post['target_tahunan'])) {
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['status' => 'error', 'message' => 'Target tahunan wajib diisi.']);
                return;
            }
            $f3->set('SESSION.errorMsg', 'Target tahunan wajib diisi.');
            $f3->reroute('/Indikator-Kinerja');
            return;
        }

        // Handle destination Sasaran given only Tujuan ID from modal
        $tujuan_id = $post['tujuan_id'];
        if (empty($tujuan_id)) {
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['status' => 'error', 'message' => 'Tujuan / Sasaran wajib dipilih.']);
                return;
            }
            $f3->set('SESSION.errorMsg', 'Tujuan / Sasaran wajib dipilih.');
            $f3->reroute('/Indikator-Kinerja');
            return;
        }

        $db = $f3->get('DB');
        $sasaran = $db->exec("SELECT id FROM sasaran WHERE tujuan_id = ? LIMIT 1", $tujuan_id);
        
        if (!empty($sasaran)) {
            $sasaran_id = $sasaran[0]['id'];
        } else {
            // Auto-create a default Sasaran if doesn't exist for this Tujuan
            $tujuan = $db->exec("SELECT tahun FROM tujuan WHERE id = ?", $tujuan_id);
            $tahun = !empty($tujuan) ? $tujuan[0]['tahun'] : date('Y');
            
            $sasaranModel = new SasaranModel();
            try {
                $sasaranModel->create([
                    'tujuan_id' => $tujuan_id,
                    'kode' => 'S-TJ-' . $tujuan_id . '-' . time(), // Unik untuk update
                    'nama' => 'Sasaran Otomatis',
                    'tahun' => $tahun
                ]);
                $sasaran_id = $db->lastInsertId();
                if (!$sasaran_id) {
                    $sasaran_id = $sasaranModel->get('id');
                }
            } catch (\PDOException $e) {
                if ($isAjax) {
                    header('Content-Type: application/json');
                    echo json_encode(['status' => 'error', 'message' => 'Gagal membuat relasi sasaran: ' . $e->getMessage()]);
                    return;
                }
                $f3->set('SESSION.errorMsg', 'Gagal membuat relasi sasaran: ' . $e->getMessage());
                $f3->reroute('/master');
                return;
            }
        }

        if (empty($sasaran_id)) {
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['status' => 'error', 'message' => 'Gagal mendapatkan data sasaran_id saat update.']);
                return;
            }
            $f3->set('SESSION.errorMsg', 'Gagal mendapatkan data sasaran_id saat update.');
            $f3->reroute('/Indikator-Kinerja');
            return;
        }

        $data = [
            'sasaran_id' => $sasaran_id,
            'nama' => $post['nama'],
            'satuan' => $post['satuan'],
            'target_tahunan' => $post['target_tahunan'],
            'pic_user_id' => !empty($post['pic_user_id']) ? (int)$post['pic_user_id'] : null,
            'pic_login' => !empty($post['pic_login']) ? $post['pic_login'] : null
        ];

        $indikatorModel = new IndikatorKinerjaModel();
        $indikatorModel->updateRecord($id, $data);

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'success', 'message' => 'Indikator berhasil diperbarui.']);
            return;
        }

        $f3->set('SESSION.successMsg', 'Indikator berhasil diperbarui.');
        $f3->reroute('/Indikator-Kinerja');
    }


    public function delete($f3, $params) {
        $isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH'])
                  && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

        // CSRF Protection
        if ($f3->get('POST.csrf_token') !== $f3->get('SESSION.csrf')) {
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['status' => 'error', 'message' => 'Invalid CSRF token']);
                return;
            }
            $f3->error(400, 'Invalid CSRF token');
            return;
        }

        $id = $params['id'];
        $indikatorModel = new IndikatorKinerjaModel();
        try {
            if ($indikatorModel->delete($id)) {
                if ($isAjax) {
                    header('Content-Type: application/json');
                    echo json_encode(['status' => 'success', 'message' => 'Indikator berhasil dihapus.']);
                    return;
                }
                $f3->set('SESSION.successMsg', 'Indikator berhasil dihapus.');
            } else {
                if ($isAjax) {
                    header('Content-Type: application/json');
                    echo json_encode(['status' => 'error', 'message' => 'Gagal menghapus indikator. Data tidak ditemukan.']);
                    return;
                }
                $f3->set('SESSION.errorMsg', 'Gagal menghapus indikator.');
            }
        } catch (\PDOException $e) {
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['status' => 'error', 'message' => 'Gagal: ' . $e->getMessage()]);
                return;
            }
            $f3->set('SESSION.errorMsg', 'Terjadi kesalahan sistem: Indikator sedang digunakan atau tidak dapat dihapus.');
        }

        $f3->reroute('/Indikator-Kinerja');
    }

    public function assignPIC($f3) {
        $f3->set('UI', ''); // Disable views rendering for JSON
        header('Content-Type: application/json');

        // Note: For AJAX, ideally CSRF checks should be done via headers, 
        // but since we are sending JSON post, we verify it through POST payload.
        if ($f3->get('POST.csrf_token') !== $f3->get('SESSION.csrf')) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid CSRF']);
            return;
        }

        $indikator_id = $f3->get('POST.indikator_id');
        $user_id  = intval($f3->get('POST.pic_user_id') ?: 0);
        $pic_login = trim($f3->get('POST.pic_login') ?: '');

        $indikatorModel = new IndikatorKinerjaModel();
        if ($indikatorModel->assignPIC($indikator_id, $user_id, $pic_login)) {
            echo json_encode(['status' => 'success', 'message' => 'PIC berhasil di-assign']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Gagal assign PIC']);
        }
    }
}

<?php
namespace Controllers;

use Helpers\AuthHelper;
use Models\TahunPerjakinModel;

class TahunPerjakinController {

    public function __construct() {
        AuthHelper::requireRole('superadmin');
    }

    public function index($f3) {
        $model = new TahunPerjakinModel();
        $topiks = $model->getAll();

        // Cek apakah setiap topik sudah punya tujuan sasaran
        $db = $f3->get('DB');
        $tujuanCounts = $db->exec('SELECT topik_id, COUNT(*) as jumlah FROM tujuan GROUP BY topik_id');
        $tujuanCountMap = [];
        foreach ($tujuanCounts as $row) {
            $tujuanCountMap[$row['topik_id']] = (int)$row['jumlah'];
        }
        foreach ($topiks as &$t) {
            $t['has_tujuan'] = isset($tujuanCountMap[$t['id']]) && $tujuanCountMap[$t['id']] > 0 ? 1 : 0;
        }
        unset($t);

        if (!$f3->exists('SESSION.csrf')) {
            $f3->set('SESSION.csrf', bin2hex(random_bytes(32)));
        }

        $f3->set('topiks', $topiks);
        $f3->set('csrf', $f3->get('SESSION.csrf'));
        $f3->set('selectedTahun', date('Y'));
        $f3->set('title', 'Tahun Perjakin');
        $f3->set('content', 'tahun_perjakin.html');
        echo \Template::instance()->render('layouts/main.html');
    }

    public function store($f3) {
        header('Content-Type: application/json');
        $f3->set('UI', '');

        if ($f3->get('POST.csrf_token') !== $f3->get('SESSION.csrf')) {
            echo json_encode(['status' => 'error', 'message' => 'Token CSRF tidak valid.']);
            return;
        }

        $judul = trim($f3->get('POST.judul'));
        $tahun = trim($f3->get('POST.tahun'));
        $keterangan = trim($f3->get('POST.keterangan'));

        if (empty($judul) || empty($tahun)) {
            echo json_encode(['status' => 'error', 'message' => 'Judul dan tahun wajib diisi.']);
            return;
        }

        $model = new TahunPerjakinModel();
        $model->create([
            'judul'      => $judul,
            'tahun'      => $tahun,
            'keterangan' => $keterangan ?: null,
        ]);

        echo json_encode(['status' => 'success', 'message' => 'Topik berhasil ditambahkan.']);
    }

    public function update($f3, $params) {
        header('Content-Type: application/json');
        $f3->set('UI', '');

        if ($f3->get('POST.csrf_token') !== $f3->get('SESSION.csrf')) {
            echo json_encode(['status' => 'error', 'message' => 'Token CSRF tidak valid.']);
            return;
        }

        $id = (int) $params['id'];
        $judul = trim($f3->get('POST.judul'));
        $tahun = trim($f3->get('POST.tahun'));
        $keterangan = trim($f3->get('POST.keterangan'));

        if (empty($judul) || empty($tahun)) {
            echo json_encode(['status' => 'error', 'message' => 'Judul dan tahun wajib diisi.']);
            return;
        }

        $model = new TahunPerjakinModel();
        $ok = $model->updateRecord($id, [
            'judul'      => $judul,
            'tahun'      => $tahun,
            'keterangan' => $keterangan ?: null,
        ]);

        if ($ok) {
            // Sinkronisasi: otomatis ubah tahun di tabel tujuan yang terkait topik ini
            $db = $f3->get('DB');
            $db->exec('UPDATE tujuan SET tahun = ? WHERE topik_id = ?', [$tahun, $id]);

            echo json_encode(['status' => 'success', 'message' => 'Topik berhasil diperbarui.']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Topik tidak ditemukan.']);
        }
    }

    public function destroy($f3, $params) {
        header('Content-Type: application/json');
        $f3->set('UI', '');

        if ($f3->get('POST.csrf_token') !== $f3->get('SESSION.csrf')) {
            echo json_encode(['status' => 'error', 'message' => 'Token CSRF tidak valid.']);
            return;
        }

        $id = (int) $params['id'];
        $model = new TahunPerjakinModel();
        $ok = $model->deleteRecord($id);

        if ($ok) {
            echo json_encode(['status' => 'success', 'message' => 'Topik berhasil dihapus.']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Topik tidak ditemukan.']);
        }
    }
}

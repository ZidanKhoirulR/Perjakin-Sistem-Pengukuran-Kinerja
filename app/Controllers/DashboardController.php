<?php
namespace Controllers;

use Helpers\AuthHelper;
use Models\IndikatorKinerjaModel;
use Models\SasaranModel;
class DashboardController {

    public function __construct() {
        AuthHelper::requireLogin();
    }

    public function index($f3) {
        $tahunRaw = $f3->get('GET.tahun');
        $tahun    = ($tahunRaw === null || $tahunRaw === '') ? date('Y') : (string)$tahunRaw;
        $topikId  = intval($f3->get('GET.topik_id'));

        $db   = $f3->get('DB');
        $user = AuthHelper::getUser();
        $role = $user['role'];

        // Auto-default ke topik tahun berjalan
        if ($topikId === 0) {
            $currentYear = date('Y');
            $row = $db->exec('SELECT id FROM topik_perjakin WHERE tahun = ? ORDER BY id ASC LIMIT 1', $currentYear);
            if (!empty($row)) $topikId = (int)$row[0]['id'];
        }

        // Load indikator: PIC dan Superadmin melihat semua data yang sama
        $indikatorModel = new IndikatorKinerjaModel();
        $allData = $indikatorModel->getAllByTahun($tahun, $topikId);

        // === SUMMARY CARDS ===
        $summary = [
            'total_indikator'  => 0,
            'indikator_hijau'  => 0,  // tercapai: realisasi_akhir >= target
            'indikator_merah'  => 0,  // tidak tercapai: ada data tapi < target
            'indikator_kuning' => 0,  // belum isi
            'total_sasaran'    => 0,
        ];

        // Hitung total_indikator dan total_sasaran secara GLOBAL (mengikuti data admin)
        if ($topikId > 0) {
            $resInd = $db->exec('SELECT COUNT(*) as total FROM indikator WHERE sasaran_id IN (SELECT id FROM sasaran WHERE tujuan_id IN (SELECT id FROM tujuan WHERE topik_id = ?))', [$topikId]);
            $summary['total_indikator'] = (int)$resInd[0]['total'];

            $resSas = $db->exec('SELECT COUNT(*) as total FROM sasaran WHERE tujuan_id IN (SELECT id FROM tujuan WHERE topik_id = ?)', [$topikId]);
            $summary['total_sasaran'] = (int)$resSas[0]['total'];
        } else {
            $resInd = $db->exec('SELECT COUNT(*) as total FROM indikator');
            $summary['total_indikator'] = (int)$resInd[0]['total'];

            $resSas = $db->exec('SELECT COUNT(*) as total FROM sasaran');
            $summary['total_sasaran'] = (int)$resSas[0]['total'];
        }

        // Reset counter untuk hitung indikator yang aktif (hijau/merah/kuning)
        $indikatorHijau  = 0;
        $indikatorMerah  = 0;
        $indikatorKuning = 0;

        $tabel_rekap = [];

        foreach ($allData as $row) {
            // Normalisasi target_tahunan
            $tRaw = str_replace('%', '', $row['target_tahunan'] ?? '1');
            if (strpos($tRaw, ',') !== false) {
                $tRaw = str_replace('.', '', $tRaw);
                $tRaw = str_replace(',', '.', $tRaw);
            }
            $target = floatval(preg_replace('/[^0-9.]/', '', $tRaw)) ?: 1;

            // Normalisasi realisasi_akhir
            $rRaw = str_replace('%', '', $row['realisasi_akhir'] ?? '');
            if (strpos($rRaw, ',') !== false) {
                $rRaw = str_replace('.', '', $rRaw);
                $rRaw = str_replace(',', '.', $rRaw);
            }
            $realisasi = floatval(preg_replace('/[^0-9.]/', '', $rRaw));

            // Tentukan status berdasarkan realisasi_akhir vs target
            if (!empty($row['realisasi_akhir']) && $realisasi > 0) {
                if ($realisasi >= $target) {
                    $indikatorHijau++;
                    $status = 'hijau';
                } else {
                    $indikatorMerah++;
                    $status = 'merah';
                }
            } else {
                $indikatorKuning++;
                $status = 'gray';
            }

            $tabel_rekap[] = [
                'nama'         => $row['nama'],
                'sasaran'      => ($row['sasaran_kode'] ?? '') . ' - ' . ($row['sasaran_nama'] ?? ''),
                'target'       => $row['target_tahunan'],
                'satuan'       => $row['satuan'] ?? '',
                'realisasi'    => $row['realisasi_akhir'] ?? '-',
                'capaian_akhir'=> $row['capaian_akhir'] ?? null,
                'status'       => $status,
            ];
        }

        $summary['indikator_hijau']  = $indikatorHijau;
        $summary['indikator_merah']  = $indikatorMerah;
        $summary['indikator_kuning'] = $indikatorKuning;


        // Topik list untuk dropdown dashboard
        $topikModel = new \Models\TahunPerjakinModel();
        $topiks = $topikModel->getAll();

        // Tahun list dari topik_perjakin
        $tahunListRaw = $db->exec('SELECT DISTINCT tahun FROM topik_perjakin WHERE tahun IS NOT NULL ORDER BY tahun DESC');
        $tahunList = [];
        foreach ($tahunListRaw as $r) {
            if (!empty($r['tahun'])) $tahunList[] = $r['tahun'];
        }
        $currentYear = date('Y');
        if (!in_array($currentYear, $tahunList)) {
            $tahunList[] = $currentYear;
            rsort($tahunList);
        }

        $selectedTopikLabel = 'Pilih Topik';
        if ($topikId > 0) {
            foreach ($topiks as $tp) {
                if ($tp['id'] == $topikId) {
                    $selectedTopikLabel = $tp['judul'];
                    break;
                }
            }
        }

        $f3->set('summary',          $summary);
        $f3->set('tabel_rekap',      $tabel_rekap);
        $f3->set('topiks',           $topiks);
        $f3->set('tahunList',        $tahunList);
        $f3->set('selectedTahun',    $tahun);
        $f3->set('selectedTopikId',  $topikId);
        $f3->set('selectedTopikLabel', $selectedTopikLabel);

        if ($role === 'pic') {
            $f3->set('title',   'Dashboard Kinerja Saya');
        } else {
            $f3->set('title',   'Dashboard');
        }

        $f3->set('content', 'dashboard.html');

        echo \Template::instance()->render('layouts/main.html');
    }

    private function getLastKendala($row) {
        return 'Belum ada kendala tercatat.';
    }
}

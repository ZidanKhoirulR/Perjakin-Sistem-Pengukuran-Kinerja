<?php
namespace Controllers;

use Helpers\AuthHelper;
use Models\IndikatorKinerjaModel;
use Models\TahunPerjakinModel;
use Mpdf\Mpdf;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

class LaporanController
{

    public function __construct()
    {
        AuthHelper::requireLogin();
    // Superadmin, pic, viewer, dan null_role semuanya boleh akses (view only)
    // Hanya yang tidak login sama sekali yang diblokir
    }

    public function index($f3)
    {
        $tahunRaw = $f3->get('GET.tahun');
        $tahun = ($tahunRaw === null || $tahunRaw === '') ? date('Y') : (string)$tahunRaw;
        $topikId = intval($f3->get('GET.topik_id'));
        $tw = $f3->get('GET.tw') ?: 'semua';

        $db = $f3->get('DB');

        // Auto-default ke topik tahun berjalan jika belum ada filter
        if ($topikId === 0) {
            $currentYear = date('Y');
            $defaultTopik = $db->exec('SELECT id FROM topik_perjakin WHERE tahun = ? ORDER BY id ASC LIMIT 1', $currentYear);
            if (!empty($defaultTopik))
                $topikId = (int)$defaultTopik[0]['id'];
        }

        // Load indikator - semua role termasuk PIC melihat semua data
        $indikatorModel = new IndikatorKinerjaModel();
        $rawIndikators = $indikatorModel->getAllByTahun($tahun, $topikId);

        // Hitung capaian per TW (sama seperti CapaianKinerjaController)
        $indikatorGrouped = [];
        foreach ($rawIndikators as $ind) {
            $tujuanId = $ind['sasaran_tujuan_id'];
            if (!isset($indikatorGrouped[$tujuanId])) {
                $indikatorGrouped[$tujuanId] = [
                    'kode' => $ind['tujuan_kode'],
                    'nama' => $ind['tujuan_nama'],
                    'indikators' => []
                ];
            }
            $indikatorGrouped[$tujuanId]['indikators'][] = $ind;
        }

        // Pre-process: hitung rowspan PIC untuk baris yang PIC-nya sama secara berurutan
        // Flatten seluruh indikator (lintas tujuan) agar bisa melihat kesinambungan antar grup
        $allInds = [];
        foreach ($indikatorGrouped as $tujuanId => $group) {
            foreach ($group['indikators'] as $idx => $ind) {
                $allInds[] = ['tujuanId' => $tujuanId, 'idx' => $idx];
            }
        }
        $total = count($allInds);
        $picRowspans = array_fill(0, $total, 0); // 0 = jangan tampilkan sel PIC (sudah di-merge)
        $i = 0;
        while ($i < $total) {
            $curTujuanId = $allInds[$i]['tujuanId'];
            $curIdx = $allInds[$i]['idx'];
            $curPic = $indikatorGrouped[$curTujuanId]['indikators'][$curIdx]['pic_nama'] ?? '';
            $span = 1;
            while ($i + $span < $total) {
                $nextTujuanId = $allInds[$i + $span]['tujuanId'];
                $nextIdx = $allInds[$i + $span]['idx'];
                $nextPic = $indikatorGrouped[$nextTujuanId]['indikators'][$nextIdx]['pic_nama'] ?? '';
                if ($nextPic === $curPic) {
                    $span++;
                }
                else {
                    break;
                }
            }
            $picRowspans[$i] = $span; // baris pertama dari grup dapat rowspan
            $i += $span;
        }
        // Tulis kembali pic_rowspan & pic_show ke setiap indikator
        foreach ($allInds as $flatIdx => $ref) {
            $indikatorGrouped[$ref['tujuanId']]['indikators'][$ref['idx']]['pic_rowspan'] = $picRowspans[$flatIdx];
            $indikatorGrouped[$ref['tujuanId']]['indikators'][$ref['idx']]['pic_show'] = ($picRowspans[$flatIdx] > 0);
        }

        // Tahun list dari topik_perjakin
        $tahunListRaw = $db->exec('SELECT DISTINCT tahun FROM topik_perjakin WHERE tahun IS NOT NULL ORDER BY tahun DESC');
        $tahunList = [];
        foreach ($tahunListRaw as $row) {
            if (!empty($row['tahun']))
                $tahunList[] = $row['tahun'];
        }
        $currentYear = date('Y');
        if (!in_array($currentYear, $tahunList)) {
            $tahunList[] = $currentYear;
            rsort($tahunList);
        }

        // Topik list
        $topikModel = new TahunPerjakinModel();
        $topiks = $topikModel->getAll();

        $f3->set('topiks', $topiks);
        $f3->set('indikatorGrouped', $indikatorGrouped);
        $f3->set('tahunList', $tahunList);
        $f3->set('selectedTahun', $tahun);
        $f3->set('selectedTopikId', $topikId);
        $f3->set('selectedTw', $tw);
        $activeTw = $tw ?: 'semua';
        $f3->set('activeTw', $activeTw);
        $f3->set('activeTwLabel', $activeTw === 'semua' ? 'Semua Triwulan' : 'Triwulan ' . $activeTw);
        $f3->set('twNumbers', ['1', '2', '3', '4']);
        $base = $f3->get('BASE');
        $f3->set('exportUrl', $base . '/Dokumen-Perkin/export?topik_id=' . $topikId . '&tahun=' . urlencode($tahun) . '&tw=' . urlencode($tw ?: 'semua'));
        $f3->set('title', 'Dokumen Perkin');
        $f3->set('content', 'dokumenperkin.html');
        echo \Template::instance()->render('layouts/main.html');
    }

    public function export($f3)
    {
        $tahunRaw = $f3->get('GET.tahun');
        $tahun = ($tahunRaw === null || $tahunRaw === '') ? date('Y') : (string)$tahunRaw;
        $topikId = intval($f3->get('GET.topik_id'));

        $db = $f3->get('DB');
        if ($topikId === 0) {
            $currentYear = date('Y');
            $defaultTopik = $db->exec('SELECT id FROM topik_perjakin WHERE tahun = ? ORDER BY id ASC LIMIT 1', $currentYear);
            if (!empty($defaultTopik))
                $topikId = (int)$defaultTopik[0]['id'];
        }

        $indikatorModel = new IndikatorKinerjaModel();
        $rawIndikators = $indikatorModel->getAllByTahun($tahun, $topikId);

        $indikatorGrouped = [];
        foreach ($rawIndikators as $ind) {
            $tujuanId = $ind['sasaran_tujuan_id'];
            if (!isset($indikatorGrouped[$tujuanId])) {
                $indikatorGrouped[$tujuanId] = [
                    'kode' => $ind['tujuan_kode'],
                    'nama' => $ind['tujuan_nama'],
                    'indikators' => []
                ];
            }
            $indikatorGrouped[$tujuanId]['indikators'][] = $ind;
        }

        // Nama topik terpilih
        $topikNama = '';
        if ($topikId > 0) {
            $topikRow = $db->exec('SELECT judul FROM topik_perjakin WHERE id = ? LIMIT 1', $topikId);
            if (!empty($topikRow))
                $topikNama = $topikRow[0]['judul'];
        }

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Dokumen Perkin');

        // Judul
        $sheet->setCellValue('A1', 'DOKUMEN PERKIN (PERJANJIAN KINERJA)');
        $sheet->mergeCells('A1:E1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $sheet->setCellValue('A2', 'Tahun: ' . ($tahun ?: 'Semua') . ($topikNama ? ' - ' . $topikNama : ''));
        $sheet->mergeCells('A2:E2');
        $sheet->getStyle('A2')->getFont()->setBold(true);
        $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // Header Tabel
        $sheet->setCellValue('A4', 'No');
        $sheet->setCellValue('B4', 'Kode');
        $sheet->setCellValue('C4', 'Tujuan/Sasaran');
        $sheet->setCellValue('D4', 'Indikator Kinerja');
        $sheet->setCellValue('E4', 'Target');
        $sheet->setCellValue('F4', 'Satuan');
        $sheet->setCellValue('G4', 'PIC');

        $headerStyle = [
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => [
                'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                'startColor' => ['argb' => 'FF1F4E79']
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                ],
            ],
        ];
        $sheet->getStyle('A4:G4')->applyFromArray($headerStyle);
        $sheet->getRowDimension(4)->setRowHeight(25);

        $rowNum = 5;
        $no = 1;
        $dataStyle = [
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                ],
            ],
            'alignment' => [
                'vertical' => Alignment::VERTICAL_TOP,
            ],
        ];

        foreach ($indikatorGrouped as $tujuanGroup) {
            $startRow = $rowNum;
            $count = count($tujuanGroup['indikators']);

            $sheet->setCellValue('A' . $startRow, $no++);
            $sheet->getStyle('A' . $startRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

            $sheet->setCellValue('B' . $startRow, $tujuanGroup['kode']);
            $sheet->getStyle('B' . $startRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

            $sheet->setCellValue('C' . $startRow, $tujuanGroup['nama']);
            $sheet->getStyle('C' . $startRow)->getAlignment()->setWrapText(true);

            if ($count > 1) {
                $sheet->mergeCells('A' . $startRow . ':A' . ($startRow + $count - 1));
                $sheet->mergeCells('B' . $startRow . ':B' . ($startRow + $count - 1));
                $sheet->mergeCells('C' . $startRow . ':C' . ($startRow + $count - 1));

                // For vertically merged cells, ensure they remain centered vertically
                $sheet->getStyle('A' . $startRow . ':C' . ($startRow + $count - 1))->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
            }

            foreach ($tujuanGroup['indikators'] as $ind) {
                $sheet->setCellValue('D' . $rowNum, $ind['nama']);
                $sheet->getStyle('D' . $rowNum)->getAlignment()->setWrapText(true);

                $sheet->setCellValueExplicit('E' . $rowNum, (string)$ind['target_tahunan'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                $sheet->getStyle('E' . $rowNum)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

                $sheet->setCellValue('F' . $rowNum, (string)$ind['satuan']);
                $sheet->getStyle('F' . $rowNum)->getAlignment()->setWrapText(true);
                $sheet->getStyle('F' . $rowNum)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

                $sheet->setCellValue('G' . $rowNum, (string)($ind['pic_nama'] ?: '-'));
                $sheet->getStyle('G' . $rowNum)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

                $sheet->getStyle('A' . $rowNum . ':G' . $rowNum)->applyFromArray($dataStyle);
                $rowNum++;
            }
        }

        // Column widths
        $sheet->getColumnDimension('A')->setWidth(5);
        $sheet->getColumnDimension('B')->setWidth(15);
        $sheet->getColumnDimension('C')->setWidth(35);
        $sheet->getColumnDimension('D')->setWidth(50);
        $sheet->getColumnDimension('E')->setWidth(15);
        $sheet->getColumnDimension('F')->setWidth(35);
        $sheet->getColumnDimension('G')->setWidth(25);

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="Dokumen_Perkin_' . ($tahun ?: 'Semua') . '.xlsx"');
        header('Cache-Control: max-age=0');

        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
    }
}

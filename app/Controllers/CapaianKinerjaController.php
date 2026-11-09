<?php

namespace Controllers;

class CapaianKinerjaController {
    public function index(\Base $f3) {
        // Semua yang login (termasuk null_role) boleh melihat Capaian Kinerja
        \Helpers\AuthHelper::requireLogin();

        $tahunRaw = $f3->get('GET.tahun');
        $tahun    = ($tahunRaw === null || $tahunRaw === '') ? date('Y') : (string)$tahunRaw;
        $topikId  = intval($f3->get('GET.topik_id'));
        $tw       = $f3->get('GET.tw') ?: '1';

        $db = $f3->get('DB');
        // Auto-default ke topik tahun berjalan jika belum ada filter
        if ($topikId === 0) {
            $currentYear = date('Y');
            $defaultTopik = $db->exec('SELECT id FROM topik_perjakin WHERE tahun = ? ORDER BY id ASC LIMIT 1', $currentYear);
            if (!empty($defaultTopik)) $topikId = (int)$defaultTopik[0]['id'];
        }

        $indikatorModel = new \Models\IndikatorKinerjaModel();
        // PIC hanya lihat indikator yang di-assign kepadanya
        $user = \Helpers\AuthHelper::getUser();
        if (\Helpers\AuthHelper::hasRole('pic')) {
            $picLogin  = $user['username'] ?? '';
            $picUserId = (int)($user['id'] ?? 0);
            $rawIndikators = $indikatorModel->getAllByTahunForPic($tahun, $topikId, $picLogin, $picUserId);
        } else {
            $rawIndikators = $indikatorModel->getAllByTahun($tahun, $topikId);
        }

        $indikatorGrouped = [];
        foreach ($rawIndikators as $ind) {
            // Normalisasi target tahunan
            $tRaw = str_replace('%', '', $ind['target_tahunan'] ?? '1');
            if (strpos($tRaw, ',') !== false) {
                $tRaw = str_replace('.', '', $tRaw);
                $tRaw = str_replace(',', '.', $tRaw);
            }
            $target = floatval(preg_replace('/[^0-9.]/', '', $tRaw)) ?: 1;

            // Capaian Akhir & Status
            $raRaw = str_replace('%', '', $ind['realisasi_akhir'] ?? '');
            if (strpos($raRaw, ',') !== false) {
                $raRaw = str_replace('.', '', $raRaw);
                $raRaw = str_replace(',', '.', $raRaw);
            }
            $realAkhir = floatval(preg_replace('/[^0-9.-]/', '', $raRaw));
            
            $status = 'gray';
            if ($realAkhir > 0) {
                $pct = ($realAkhir / $target) * 100;
                if ($pct >= 100) $status = 'hijau';
                elseif ($pct >= 1) $status = 'merah'; // In many contexts, < 100 is merah unless there's a specific kuning range
            }
            $ind['status'] = $status;

            // Hitung capaian per TW
            foreach (['1','2','3','4'] as $t) {
                $rRaw = str_replace('%', '', $ind['tw'.$t] ?? '');
                if (strpos($rRaw, ',') !== false) {
                    $rRaw = str_replace('.', '', $rRaw);
                    $rRaw = str_replace(',', '.', $rRaw);
                }
                $real = floatval(preg_replace('/[^0-9.-]/', '', $rRaw));
                if ($real > 0) {
                    $c = round(($real / $target) * 100, 2);
                    $ind['capaian_tw'.$t] = $c;
                    $ind['capaian_tw'.$t.'_color'] = $c >= 100 ? 'text-green-600' : ($c >= 80 ? 'text-yellow-500' : 'text-red-600');
                } else {
                    $ind['capaian_tw'.$t] = null;
                    $ind['capaian_tw'.$t.'_color'] = 'text-gray-300';
                }
            }

            // Group hanya berdasarkan tujuan (sasaran tidak ditampilkan)
            $tujuanKey = trim($ind['tujuan_kode'] . ' - ' . $ind['tujuan_nama']);
            $indikatorGrouped[$tujuanKey][] = $ind;
        }

        $db = $f3->get('DB');
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

        $topikModel = new \Models\TahunPerjakinModel();
        $topiks = $topikModel->getAll();

        if (!$f3->exists('SESSION.csrf')) {
            $f3->set('SESSION.csrf', bin2hex(random_bytes(32)));
        }

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
        $f3->set('selectedTopikId', $topikId);
        $f3->set('indikatorGrouped', $indikatorGrouped);
        $f3->set('tahunList', $tahunList);
        $f3->set('selectedTahun', $tahun);
        $f3->set('selectedTw', $tw);

        $f3->set('title', 'Capaian Kinerja');
        $f3->set('content', 'capaian_kinerja.html');
        $f3->set('active_menu', 'indeks_perjakin');
        
        echo \Template::instance()->render('layouts/main.html');
    }

    public function update(\Base $f3, $params) {
        // null_role (dan viewer) tidak boleh edit data
        if (!\Helpers\AuthHelper::hasRole('superadmin') && !\Helpers\AuthHelper::hasRole('pic')) {
            $f3->error(403);
            return;
        }

        $id = $params['id'];
        $isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH'])
                  && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
        
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
        
        // Hitung capaian_akhir otomatis
        $realisasiRaw = $post['realisasi_akhir'] ?? '0';
        $realisasiRaw = str_replace('%', '', $realisasiRaw);
        if (strpos($realisasiRaw, ',') !== false) {
            $realisasiRaw = str_replace('.', '', $realisasiRaw);
            $realisasiRaw = str_replace(',', '.', $realisasiRaw);
        }
        $realisasiAkhir = floatval(preg_replace('/[^0-9.\-]/', '', $realisasiRaw));
        
        $targetRaw = $post['target'] ?? '1';
        if (strpos($targetRaw, ',') !== false) {
            $targetRaw = str_replace('.', '', $targetRaw);
            $targetRaw = str_replace(',', '.', $targetRaw);
        }
        $targetVal = floatval(preg_replace('/[^0-9.]/', '', $targetRaw));
        if ($targetVal == 0) $targetVal = 1;
        
        $capaianAkhir = round(($realisasiAkhir / $targetVal) * 100, 2);

        $data = [
            // Realisasi per TW
            'tw1'              => $post['tw1'] ?? null,
            'tw2'              => $post['tw2'] ?? null,
            'tw3'              => $post['tw3'] ?? null,
            'tw4'              => $post['tw4'] ?? null,
            // Keterangan per TW
            'keterangan_tw1'   => $post['keterangan_tw1'] ?? null,
            'keterangan_tw2'   => $post['keterangan_tw2'] ?? null,
            'keterangan_tw3'   => $post['keterangan_tw3'] ?? null,
            'keterangan_tw4'   => $post['keterangan_tw4'] ?? null,
            // Kendala per TW
            'kendala_tw1'      => $post['kendala_tw1'] ?? null,
            'kendala_tw2'      => $post['kendala_tw2'] ?? null,
            'kendala_tw3'      => $post['kendala_tw3'] ?? null,
            'kendala_tw4'      => $post['kendala_tw4'] ?? null,
            // Rekomendasi per TW
            'rekomendasi_tw1'  => $post['rekomendasi_tw1'] ?? null,
            'rekomendasi_tw2'  => $post['rekomendasi_tw2'] ?? null,
            'rekomendasi_tw3'  => $post['rekomendasi_tw3'] ?? null,
            'rekomendasi_tw4'  => $post['rekomendasi_tw4'] ?? null,
            // Akhir Keterangan/Kendala/Rekomendasi
            'keterangan_akhir' => $post['keterangan_akhir'] ?? null,
            'kendala_akhir'    => $post['kendala_akhir'] ?? null,
            'rekomendasi_akhir'=> $post['rekomendasi_akhir'] ?? null,
            // Realisasi & Capaian Akhir
            'realisasi_akhir'  => $post['realisasi_akhir'] ?? null,
            'capaian_akhir'    => $capaianAkhir,
        ];

        $indikatorModel = new \Models\IndikatorKinerjaModel();
        $indikatorModel->updateRecord($id, $data);

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode([
                'status'       => 'success',
                'message'      => 'Data Capaian Kinerja berhasil diperbarui.',
                'capaian_akhir' => $capaianAkhir
            ]);
            return;
        }

        $f3->set('SESSION.successMsg', 'Data Capaian Kinerja berhasil diperbarui.');
        $f3->reroute('/Capaian-Kinerja');
    }

    public function export(\Base $f3) {
        // Semua yang login boleh export
        \Helpers\AuthHelper::requireLogin();

        $tahunRaw = $f3->get('GET.tahun');
        $tahun    = ($tahunRaw === null || $tahunRaw === '') ? date('Y') : (string)$tahunRaw;
        $topikId  = intval($f3->get('GET.topik_id'));
        $tw       = $f3->get('GET.tw') ?: '1';

        $db = $f3->get('DB');

        if ($topikId === 0) {
            $currentYear = date('Y');
            $defaultTopik = $db->exec('SELECT id FROM topik_perjakin WHERE tahun = ? ORDER BY id ASC LIMIT 1', $currentYear);
            if (!empty($defaultTopik)) $topikId = (int)$defaultTopik[0]['id'];
        }

        $indikatorModel = new \Models\IndikatorKinerjaModel();
        $rawIndikators = $indikatorModel->getAllByTahun($tahun, $topikId);

        $indikatorGrouped = [];
        foreach ($rawIndikators as $ind) {
            $tRaw = str_replace('%', '', $ind['target_tahunan'] ?? '1');
            if (strpos($tRaw, ',') !== false) {
                $tRaw = str_replace('.', '', $tRaw);
                $tRaw = str_replace(',', '.', $tRaw);
            }
            $target = floatval(preg_replace('/[^0-9.]/', '', $tRaw)) ?: 1;

            $raRaw = str_replace('%', '', $ind['realisasi_akhir'] ?? '');
            if (strpos($raRaw, ',') !== false) {
                $raRaw = str_replace('.', '', $raRaw);
                $raRaw = str_replace(',', '.', $raRaw);
            }
            $realAkhir = floatval(preg_replace('/[^0-9.-]/', '', $raRaw));
            
            $status = 'gray';
            if ($realAkhir > 0) {
                $pct = ($realAkhir / $target) * 100;
                if ($pct >= 100) $status = 'hijau';
                elseif ($pct >= 1) $status = 'merah';
            }
            $ind['status'] = $status;

            foreach (['1','2','3','4'] as $t) {
                $rRaw = str_replace('%', '', $ind['tw'.$t] ?? '');
                if (strpos($rRaw, ',') !== false) {
                    $rRaw = str_replace('.', '', $rRaw);
                    $rRaw = str_replace(',', '.', $rRaw);
                }
                $real = floatval(preg_replace('/[^0-9.-]/', '', $rRaw));
                if ($real > 0) {
                    $c = round(($real / $target) * 100, 2);
                    $ind['capaian_tw'.$t] = $c;
                } else {
                    $ind['capaian_tw'.$t] = null;
                }
            }

            $tujuanKey = trim($ind['tujuan_kode'] . ' - ' . $ind['tujuan_nama']);
            $indikatorGrouped[$tujuanKey][] = $ind;
        }

        $topikNama = '';
        if ($topikId > 0) {
            $topikRow = $db->exec('SELECT judul FROM topik_perjakin WHERE id = ? LIMIT 1', $topikId);
            if (!empty($topikRow)) $topikNama = $topikRow[0]['judul'];
        }

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        
        $titleTw = $tw === 'akhir' ? 'Capaian Akhir' : 'Triwulan ' . $tw;
        $isAkhir  = ($tw === 'akhir');
        $lastCol  = $isAkhir ? 'J' : 'H';
        $sheet->setTitle(substr('Capaian Kinerja ' . $titleTw, 0, 31));

        // Header Title
        $sheet->setCellValue('A1', 'CAPAIAN KINERJA - ' . strtoupper($titleTw));
        $sheet->mergeCells('A1:' . $lastCol . '1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

        // Table Headers (row 3 karena tidak ada baris subtitle)
        if ($isAkhir) {
            $headers = ['No', 'Indikator Kerja', 'Target', 'Realisasi', 'Capaian %', 'Keterangan', 'Kendala', 'Rekomendasi', 'Realisasi Akhir', 'Capaian Akhir'];
        } else {
            $headers = ['No', 'Indikator Kerja', 'Target', 'Realisasi', 'Capaian %', 'Keterangan', 'Kendala', 'Rekomendasi'];
        }
        $col = 'A';
        foreach ($headers as $header) {
            $sheet->setCellValue($col . '3', $header);
            $col++;
        }

        $headerStyle = [
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => [
                'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                'startColor' => ['argb' => 'FF1F4E79']
            ],
            'alignment' => [
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                ],
            ],
        ];
        $sheet->getStyle('A3:' . $lastCol . '3')->applyFromArray($headerStyle);
        $sheet->getRowDimension(3)->setRowHeight(25);

        $rowNum = 4;
        $dataStyle = [
            'borders' => [
                'allBorders' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                ],
            ],
            'alignment' => [
                'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP,
                'wrapText' => true,
            ],
        ];

        foreach ($indikatorGrouped as $tujuanKey => $indikators) {
            // Tujuan Group Header
            $sheet->setCellValue('A' . $rowNum, $tujuanKey);
            $sheet->mergeCells('A' . $rowNum . ':' . $lastCol . $rowNum);
            $sheet->getStyle('A' . $rowNum . ':' . $lastCol . $rowNum)->applyFromArray([
                'font' => ['bold' => true, 'color' => ['argb' => 'FF1F4E79']],
                'fill' => [
                    'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                    'startColor' => ['argb' => 'FFEBF8FF']
                ],
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    ],
                ]
            ]);
            $rowNum++;

            $no = 1;
            foreach ($indikators as $ind) {
                $sheet->setCellValue('A' . $rowNum, $no++);
                $sheet->getStyle('A' . $rowNum)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
                
                $sheet->setCellValue('B' . $rowNum, $ind['nama']);
                
                $sheet->setCellValue('C' . $rowNum, $ind['target_tahunan'] ?: '-');
                $sheet->getStyle('C' . $rowNum)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

                if ($isAkhir) {
                    $sheet->setCellValue('D' . $rowNum, $ind['realisasi_akhir'] ?: '-');
                    $sheet->setCellValue('E' . $rowNum, $ind['capaian_akhir'] !== null ? $ind['capaian_akhir'] . ' %' : '-');
                    $sheet->setCellValue('F' . $rowNum, $ind['keterangan_akhir'] ?: '-');
                    $sheet->setCellValue('G' . $rowNum, $ind['kendala_akhir'] ?: '-');
                    $sheet->setCellValue('H' . $rowNum, $ind['rekomendasi_akhir'] ?: '-');

                    $sheet->setCellValue('I' . $rowNum, $ind['realisasi_akhir'] ?: '-');
                    $sheet->getStyle('I' . $rowNum)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
                    $sheet->getStyle('I' . $rowNum)->getFont()->setBold(true);
                    if ($ind['status'] === 'hijau') {
                        $sheet->getStyle('I' . $rowNum)->getFont()->getColor()->setARGB('FF16A34A');
                    } elseif ($ind['status'] === 'kuning' || $ind['status'] === 'gray') {
                        $sheet->getStyle('I' . $rowNum)->getFont()->getColor()->setARGB('FFCA8A04');
                    } elseif ($ind['status'] === 'merah') {
                        $sheet->getStyle('I' . $rowNum)->getFont()->getColor()->setARGB('FFDC2626');
                    }

                    $sheet->setCellValue('J' . $rowNum, $ind['capaian_akhir'] !== null ? $ind['capaian_akhir'] . ' %' : '-');
                    $sheet->getStyle('J' . $rowNum)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
                    $sheet->getStyle('J' . $rowNum)->getFont()->setBold(true);
                } else {
                    $sheet->setCellValue('D' . $rowNum, $ind['tw'.$tw] ?: '-');
                    $sheet->setCellValue('E' . $rowNum, $ind['capaian_tw'.$tw] !== null ? $ind['capaian_tw'.$tw] . ' %' : '-');
                    $sheet->setCellValue('F' . $rowNum, $ind['keterangan_tw'.$tw] ?: '-');
                    $sheet->setCellValue('G' . $rowNum, $ind['kendala_tw'.$tw] ?: '-');
                    $sheet->setCellValue('H' . $rowNum, $ind['rekomendasi_tw'.$tw] ?: '-');
                }
                
                $sheet->getStyle('D' . $rowNum)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle('E' . $rowNum)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

                $sheet->getStyle('A' . $rowNum . ':' . $lastCol . $rowNum)->applyFromArray($dataStyle);
                $rowNum++;
            }
        }

        // Set Column Widths
        $sheet->getColumnDimension('A')->setWidth(5);
        $sheet->getColumnDimension('B')->setWidth(40);
        $sheet->getColumnDimension('C')->setWidth(15);
        $sheet->getColumnDimension('D')->setWidth(15);
        $sheet->getColumnDimension('E')->setWidth(15);
        $sheet->getColumnDimension('F')->setWidth(30);
        $sheet->getColumnDimension('G')->setWidth(30);
        $sheet->getColumnDimension('H')->setWidth(30);
        if ($isAkhir) {
            $sheet->getColumnDimension('I')->setWidth(18);
            $sheet->getColumnDimension('J')->setWidth(18);
        }

        $filename = 'Capaian_Kinerja_' . str_replace(' ', '_', $titleTw) . '_' . date('Ymd_His') . '.xlsx';
        
        ob_end_clean(); // Ensure no output buffering interferes with the download
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="' . $filename . '"');
        header('Cache-Control: max-age=0');
        
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
    }
}

<?php

namespace Controllers;

class RekapCapaianController
{
    public function index(\Base $f3)
    {
        if (!\Helpers\AuthHelper::isLoggedIn()) {
            $f3->reroute('/login');
            return;
        }

        $db     = $f3->get('DB');
        $tahun  = ($f3->get('GET.tahun') ?: date('Y'));
        $topikId = intval($f3->get('GET.topik_id'));

        // Default topik
        if ($topikId === 0) {
            $defaultTopik = $db->exec(
                'SELECT id FROM topik_perjakin WHERE tahun = ? ORDER BY id ASC LIMIT 1',
                date('Y')
            );
            if (!empty($defaultTopik)) $topikId = (int)$defaultTopik[0]['id'];
        }

        // Ambil semua indikator beserta data tw1-4
        // Semua role termasuk PIC melihat semua data indikator
        $indikatorModel  = new \Models\IndikatorKinerjaModel();
        $rawIndikators = $indikatorModel->getAllByTahun($tahun, $topikId);

        $indikatorGrouped = [];
        foreach ($rawIndikators as $ind) {
            // Normalisasi target
            $tRaw = str_replace('%', '', $ind['target_tahunan'] ?? '1');
            if (strpos($tRaw, ',') !== false) {
                $tRaw = str_replace('.', '', $tRaw);
                $tRaw = str_replace(',', '.', $tRaw);
            }
            $target = floatval(preg_replace('/[^0-9.]/', '', $tRaw)) ?: 1;

            // Hitung capaian per TW
            foreach (['1', '2', '3', '4'] as $t) {
                $rRaw = str_replace('%', '', $ind['tw' . $t] ?? '');
                if (strpos($rRaw, ',') !== false) {
                    $rRaw = str_replace('.', '', $rRaw);
                    $rRaw = str_replace(',', '.', $rRaw);
                }
                $real = floatval(preg_replace('/[^0-9.-]/', '', $rRaw));
                if ($real > 0) {
                    $c = round(($real / $target) * 100, 2);
                    $ind['capaian_tw' . $t] = $c . ' %';
                    $color = $c >= 100 ? 'text-green-600' : ($c >= 80 ? 'text-yellow-500' : 'text-red-600');
                    $ind['capaian_tw' . $t . '_color'] = $color;
                } else {
                    $ind['capaian_tw' . $t]            = '-';
                    $ind['capaian_tw' . $t . '_color'] = 'text-gray-400';
                }
                // Pastikan realisasi kosong tampil "-"
                $ind['tw' . $t] = $ind['tw' . $t] ?: '-';
            }

            // Hitung capaian akhir
            $raRaw = str_replace('%', '', $ind['realisasi_akhir'] ?? '');
            if (strpos($raRaw, ',') !== false) {
                $raRaw = str_replace('.', '', $raRaw);
                $raRaw = str_replace(',', '.', $raRaw);
            }
            $realAkhir = floatval(preg_replace('/[^0-9.-]/', '', $raRaw));
            if ($realAkhir > 0) {
                $ca = round(($realAkhir / $target) * 100, 2);
                $ind['capaian_akhir_val']   = $ca . ' %';
                $ind['capaian_akhir_color'] = $ca >= 100 ? 'text-green-600' : ($ca >= 80 ? 'text-yellow-500' : 'text-red-600');
            } else {
                $ind['capaian_akhir_val']   = '-';
                $ind['capaian_akhir_color'] = 'text-gray-400';
            }
            $ind['realisasi_akhir'] = $ind['realisasi_akhir'] ?: '-';

            $tujuanKey = trim($ind['tujuan_kode'] . ' - ' . $ind['tujuan_nama']);
            $indikatorGrouped[$tujuanKey][] = $ind;
        }

        // Daftar topik untuk filter
        $topiks = $db->exec(
            'SELECT id, judul, tahun FROM topik_perjakin ORDER BY tahun DESC, id ASC'
        );

        $f3->set('indikatorGrouped', $indikatorGrouped);
        $f3->set('topiks',           $topiks);
        $f3->set('selectedTopikId',  $topikId);
        $f3->set('selectedTahun',    $tahun);
        $f3->set('title',            'Rekap Capaian Kinerja');
        $f3->set('content',          'rekap_capaian.html');
        $f3->set('csrf',             $f3->get('SESSION.csrf'));

        echo \Template::instance()->render('layouts/main.html');
    }
}

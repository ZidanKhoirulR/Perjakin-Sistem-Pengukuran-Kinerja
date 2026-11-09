<?php
namespace Models;

class IndikatorKinerjaModel extends \DB\SQL\Mapper {
    
    public function __construct() {
        parent::__construct(\Base::instance()->get('DB'), 'indikator');
    }

    public function getAllByTahun($tahun = '', $topikId = 0) {
        $f3 = \Base::instance();
        $db = $f3->get('DB');
        $sql = "
            SELECT 
                i.*, 
                s.kode as sasaran_kode, s.nama as sasaran_nama, s.tujuan_id as sasaran_tujuan_id,
                t.kode as tujuan_kode, t.nama as tujuan_nama, t.topik_id as sasaran_topik_id,
                (CASE WHEN (i.tw1 IS NOT NULL AND i.tw1 != '') 
                         OR (i.tw2 IS NOT NULL AND i.tw2 != '')
                         OR (i.tw3 IS NOT NULL AND i.tw3 != '')
                         OR (i.tw4 IS NOT NULL AND i.tw4 != '')
                         OR (i.realisasi_akhir IS NOT NULL AND i.realisasi_akhir != '') 
                       THEN 1 ELSE 0 END) as has_realization
            FROM indikator i
            JOIN sasaran s ON i.sasaran_id = s.id
            JOIN tujuan t ON s.tujuan_id = t.id
        ";
        $conditions = [];
        $params = [];

        if ($tahun !== '') {
            $conditions[] = 'i.tahun = ?';
            $params[] = $tahun;
        }
        if ((int)$topikId > 0) {
            $conditions[] = 't.topik_id = ?';
            $params[] = (int)$topikId;
        }

        if (!empty($conditions)) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }
        $sql .= ' ORDER BY t.id ASC, s.id ASC, i.id ASC';

        $results = empty($params) ? $db->exec($sql) : $db->exec($sql, $params);
        return $this->enrichWithPicNames($results);
    }

    /**
     * Khusus PIC: ambil indikator yang di-assign ke user ini (via pic_login atau pic_user_id)
     */
    public function getAllByTahunForPic($tahun = '', $topikId = 0, $picLogin = '', $picUserId = 0) {
        $f3 = \Base::instance();
        $db = $f3->get('DB');
        $sql = "
            SELECT 
                i.*, 
                s.kode as sasaran_kode, s.nama as sasaran_nama, s.tujuan_id as sasaran_tujuan_id,
                t.kode as tujuan_kode, t.nama as tujuan_nama, t.topik_id as sasaran_topik_id,
                (CASE WHEN (i.tw1 IS NOT NULL AND i.tw1 != '') 
                         OR (i.tw2 IS NOT NULL AND i.tw2 != '')
                         OR (i.tw3 IS NOT NULL AND i.tw3 != '')
                         OR (i.tw4 IS NOT NULL AND i.tw4 != '')
                         OR (i.realisasi_akhir IS NOT NULL AND i.realisasi_akhir != '') 
                       THEN 1 ELSE 0 END) as has_realization
            FROM indikator i
            JOIN sasaran s ON i.sasaran_id = s.id
            JOIN tujuan t ON s.tujuan_id = t.id
        ";
        $conditions = [];
        $params = [];

        // Filter berdasarkan tahun
        if ($tahun !== '') {
            $conditions[] = 'i.tahun = ?';
            $params[] = $tahun;
        }
        // Filter berdasarkan topik
        if ((int)$topikId > 0) {
            $conditions[] = 't.topik_id = ?';
            $params[] = (int)$topikId;
        }
        // Filter PIC: cocokkan via pic_login ATAU pic_user_id
        $picConditions = [];
        if (!empty($picLogin)) {
            $picConditions[] = 'i.pic_login = ?';
            $params[] = $picLogin;
        }
        if ((int)$picUserId > 0) {
            $picConditions[] = 'i.pic_user_id = ?';
            $params[] = (int)$picUserId;
        }
        if (!empty($picConditions)) {
            $conditions[] = '(' . implode(' OR ', $picConditions) . ')';
        }

        if (!empty($conditions)) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }
        $sql .= ' ORDER BY t.id ASC, s.id ASC, i.id ASC';

        $results = empty($params) ? $db->exec($sql) : $db->exec($sql, $params);
        return $this->enrichWithPicNames($results);
    }

    /**
     * Mengambil Nama PIC dari database SILA (Cross-Host) menggunakan Mapping PHP
     */
    private function enrichWithPicNames($results) {
        if (empty($results)) return [];

        $f3 = \Base::instance();
        $dbSil = $f3->get('DB_SIL');
        
        // Kumpulkan login dan ID untuk bulk query
        $logins = [];
        $userIds = [];
        foreach ($results as $row) {
            if (!empty($row['pic_login'])) $logins[] = $row['pic_login'];
            if (!empty($row['pic_user_id'])) $userIds[] = (int)$row['pic_user_id'];
        }
        $logins = array_unique(array_filter($logins));
        $userIds = array_unique(array_filter($userIds));

        // Jika tidak ada PIC atau tidak ada koneksi SILA, pakai placeholder
        if (!$dbSil || (empty($logins) && empty($userIds))) {
            foreach ($results as &$row) {
                $row['pic_nama'] = !empty($row['pic_login']) ? $row['pic_login'] : '-';
            }
            return $results;
        }

        $where = [];
        $params = [];
        if (!empty($logins)) {
            $placeholders = implode(',', array_fill(0, count($logins), '?'));
            $where[] = "login IN ($placeholders)";
            $params = array_merge($params, $logins);
        }
        if (!empty($userIds)) {
            $placeholders = implode(',', array_fill(0, count($userIds), '?'));
            $where[] = "id_user IN ($placeholders)";
            $params = array_merge($params, $userIds);
        }

        try {
            $usersRaw = $dbSil->exec("SELECT id_user, login, nama_user FROM tb_arsipuser WHERE " . implode(' OR ', $where), $params);
            
            $nameByLogin = [];
            $nameById = [];
            foreach ($usersRaw as $u) {
                $name = !empty($u['nama_user']) ? $u['nama_user'] : $u['login'];
                if ($u['login']) $nameByLogin[$u['login']] = $name;
                if ($u['id_user']) $nameById[(int)$u['id_user']] = $name;
            }

            foreach ($results as &$row) {
                $foundName = null;
                if (!empty($row['pic_login']) && isset($nameByLogin[$row['pic_login']])) {
                    $foundName = $nameByLogin[$row['pic_login']];
                } elseif (!empty($row['pic_user_id']) && isset($nameById[(int)$row['pic_user_id']])) {
                    $foundName = $nameById[(int)$row['pic_user_id']];
                }
                $row['pic_nama'] = $foundName ?: (!empty($row['pic_login']) ? $row['pic_login'] : '-');
            }
        } catch (\Exception $e) {
            // Jika query SILA gagal, jangan buat aplikasi crash
            foreach ($results as &$row) {
                $row['pic_nama'] = !empty($row['pic_login']) ? $row['pic_login'] : '(SILA Error)';
            }
        }

        return $results;
    }

    public function getById($id) {
        $this->load(['id=?', $id]);
        return $this->dry() ? [] : $this->cast();
    }

    public function create($data) {
        $this->reset();
        $this->copyfrom($data);
        $this->save();
        return $this->get('_id');
    }

    public function updateRecord($id, $data) {
        $this->load(['id=?', $id]);
        if (!$this->dry()) {
            $this->copyfrom($data);
            $this->save();
            return true;
        }
        return false;
    }

    public function delete($id) {
        $this->load(['id=?', $id]);
        if (!$this->dry()) {
            $this->erase();
            return true;
        }
        return false;
    }

    public function getBySasaran($sasaran_id, $tahun) {
        return $this->find(['sasaran_id=? AND tahun=?', $sasaran_id, $tahun], ['order' => 'nama ASC']);
    }

    public function assignPIC($indikator_id, $user_id, $pic_login = '') {
        $this->load(['id=?', $indikator_id]);
        if (!$this->dry()) {
            $this->pic_user_id = $user_id > 0 ? $user_id : null;
            $this->pic_login   = $pic_login !== '' ? $pic_login : null;
            $this->save();
            return true;
        }
        return false;
    }
}

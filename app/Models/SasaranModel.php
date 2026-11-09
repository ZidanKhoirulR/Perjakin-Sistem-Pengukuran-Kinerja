<?php
namespace Models;

class SasaranModel extends \DB\SQL\Mapper {
    
    public function __construct() {
        parent::__construct(\Base::instance()->get('DB'), 'sasaran');
    }

    public function getAll($tahun) {
        $db = \Base::instance()->get('DB');
        $sql = "
            SELECT 
                s.*, 
                t.kode as tujuan_kode, t.nama as tujuan_nama
            FROM sasaran s
            JOIN tujuan t ON s.tujuan_id = t.id
            WHERE s.tahun = ?
            ORDER BY s.kode ASC
        ";
        return $db->exec($sql, $tahun);
    }

    public function getById($id) {
        $this->load(['id=?', $id]);
        return $this->dry() ? [] : $this->cast();
    }

    public function create($data) {
        $this->copyfrom($data);
        return $this->save();
    }

    public function updateRecord($id, $data) {
        $this->load(['id=?', $id]);
        if (!$this->dry()) {
            $this->copyfrom($data);
            return $this->save();
        }
        return false;
    }

    public function deleteRecord($id) {
        $this->load(['id=?', $id]);
        if (!$this->dry()) {
            return $this->erase();
        }
        return false;
    }
}

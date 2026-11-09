<?php
namespace Models;

class TahunPerjakinModel extends \DB\SQL\Mapper {

    public function __construct() {
        parent::__construct(\Base::instance()->get('DB'), 'topik_perjakin');
    }

    public function getAll($tahun = null) {
        $db = \Base::instance()->get('DB');
        if ($tahun) {
            $sql = "SELECT * FROM topik_perjakin WHERE tahun = ? ORDER BY tahun DESC, id DESC";
            return $db->exec($sql, $tahun);
        }
        $sql = "SELECT * FROM topik_perjakin ORDER BY tahun DESC, id DESC";
        return $db->exec($sql);
    }

    public function getById($id) {
        $this->load(['id=?', $id]);
        return $this->dry() ? null : $this->cast();
    }

    public function create($data) {
        $this->reset();
        $this->copyfrom($data);
        $this->save();
        return $this->id;
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

    public function deleteRecord($id) {
        $this->load(['id=?', $id]);
        if (!$this->dry()) {
            $this->erase();
            return true;
        }
        return false;
    }
}

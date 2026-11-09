<?php
namespace Models;

class TujuanKinerjaModel extends \DB\SQL\Mapper {
    
    public function __construct() {
        parent::__construct(\Base::instance()->get('DB'), 'tujuan');
    }

    public function getAll($tahun) {
        $db = \Base::instance()->get('DB');
        return $db->exec(
            'SELECT * FROM tujuan WHERE tahun=? ORDER BY kode ASC',
            $tahun
        );
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

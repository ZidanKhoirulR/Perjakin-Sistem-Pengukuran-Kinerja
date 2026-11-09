<?php

namespace Controllers;

use Helpers\AuthHelper;

class KelolaUserController
{
    /**
     * GET /Kelola-User
     * Tampilkan daftar semua user dari tb_arsipuser (sil2020).
     * Hanya superadmin yang boleh mengakses.
     */
    public function index($f3)
    {
        if (!AuthHelper::isLoggedIn()) {
            $f3->reroute('/login');
            return;
        }
        if (!AuthHelper::hasRole('superadmin')) {
            $f3->reroute('/dashboard');
            return;
        }

        $dbSil = $f3->get('DB_SIL');
        $users = [];

        if ($dbSil) {
            $users = $dbSil->exec(
                "SELECT id_user, nama_user, login, si_perjakin FROM tb_arsipuser ORDER BY nama_user ASC"
            );
        }

        if (!$f3->exists('SESSION.csrf')) {
            $f3->set('SESSION.csrf', bin2hex(random_bytes(32)));
        }

        $f3->set('csrf',    $f3->get('SESSION.csrf'));
        $f3->set('users',   $users ?: []);
        $f3->set('title',   'Kelola User');
        $f3->set('content', 'kelola_user.html');

        echo \Template::instance()->render('layouts/main.html');
    }

    /**
     * POST /Kelola-User/update-role/@id
     * Update kolom si_perjakin (role) untuk user dengan id_user tertentu.
     * Response JSON.
     */
    public function updateRole($f3, $params)
    {
        header('Content-Type: application/json');

        if (!AuthHelper::isLoggedIn() || !AuthHelper::hasRole('superadmin')) {
            echo json_encode(['status' => 'error', 'message' => 'Akses ditolak.']);
            return;
        }

        $csrfToken = $f3->get('POST.csrf_token');
        if ($csrfToken !== $f3->get('SESSION.csrf')) {
            echo json_encode(['status' => 'error', 'message' => 'CSRF token tidak valid.']);
            return;
        }

        $id   = (int) $params['id'];
        $role = trim($f3->get('POST.role'));

        $allowedRoles = ['superadmin', 'pic', 'viewer', ''];
        if (!in_array($role, $allowedRoles, true)) {
            echo json_encode(['status' => 'error', 'message' => 'Role tidak valid.']);
            return;
        }

        $dbSil = $f3->get('DB_SIL');
        if (!$dbSil) {
            echo json_encode(['status' => 'error', 'message' => 'Koneksi database tidak tersedia.']);
            return;
        }

        try {
            $roleValue = ($role === '') ? null : $role;
            $dbSil->exec(
                "UPDATE tb_arsipuser SET si_perjakin = ? WHERE id_user = ?",
                [$roleValue, $id]
            );

            echo json_encode(['status' => 'success', 'message' => 'Role berhasil diperbarui.']);
        } catch (\Exception $e) {
            error_log('[KelolaUser] updateRole error: ' . $e->getMessage());
            echo json_encode(['status' => 'error', 'message' => 'Gagal memperbarui role.']);
        }
    }
}

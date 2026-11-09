<?php
namespace Helpers;

class AuthHelper {
    
    public static function isLoggedIn() {
        $f3 = \Base::instance();
        return $f3->exists('SESSION.user.id');
    }

    public static function getUser() {
        $f3 = \Base::instance();
        return $f3->get('SESSION.user');
    }

    /**
     * Cek apakah user memiliki salah satu role yang diberikan.
     * null_role tidak dianggap memiliki role manapun selain 'null_role' itu sendiri.
     */
    public static function hasRole($roles) {
        if (!self::isLoggedIn()) return false;
        
        $userRole = self::getUser()['role'] ?? '';
        if (is_array($roles)) {
            return in_array($userRole, $roles);
        }
        return $userRole === $roles;
    }

    /** Apakah user login tapi tidak punya role (null_role) */
    public static function hasNullRole() {
        if (!self::isLoggedIn()) return false;
        return (self::getUser()['role'] ?? '') === 'null_role';
    }

    /** User dengan akses baca (semua role kecuali null_role) */
    public static function hasAnyRole() {
        if (!self::isLoggedIn()) return false;
        $role = self::getUser()['role'] ?? '';
        return in_array($role, ['superadmin', 'pic', 'viewer']);
    }

    public static function requireLogin() {
        if (!self::isLoggedIn()) {
            \Base::instance()->reroute('/login');
        }
    }

    public static function requireRole($roles) {
        self::requireLogin();
        if (!self::hasRole($roles)) {
            \Base::instance()->error(403, 'Akses Ditolak: Anda tidak memiliki izin untuk halaman ini.');
        }
    }

    /**
     * Izinkan akses untuk role tertentu + null_role (view only).
     * Jika user tidak login sama sekali, redirect ke login.
     */
    public static function requireRoleOrNullRole($roles) {
        self::requireLogin();
        if (self::hasNullRole()) return; // null_role diizinkan lanjut
        if (!self::hasRole($roles)) {
            \Base::instance()->error(403, 'Akses Ditolak: Anda tidak memiliki izin untuk halaman ini.');
        }
    }
}

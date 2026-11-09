<?php

namespace Controllers;

use Helpers\AuthHelper;

/**
 * AuthController
 *
 * Menangani semua proses autentikasi Perjakin:
 *  - Alur login Perjakin (send OTP → verify OTP via Fonnte)
 *  - Alur login SILA dua-langkah (kredensial → OTP WA → session)
 *  - Logout
 *
 * Database:
 *  - DB     → database "perjakin" (data aplikasi)
 *  - DB_SIL → database "sil2020"  (tb_arsipuser, tb_logtrace_*, tb_otp_khusus)
 *
 * Konfigurasi WA:
 *  - Ganti FONNTE_TOKEN dengan token dari https://fonnte.com
 */
class AuthController
{
    // =========================================================================
    // KONSTANTA
    // =========================================================================

    /** API Key Wanotif untuk pengiriman OTP via WhatsApp (fallback default) */
    const WANOTIF_API_KEY = '';

    /** Masa berlaku OTP alur Perjakin (detik) */
    const OTP_EXPIRY_SEC = 300; // 5 menit

    /** Nama sistem yang dicatat di kolom sistemto_login */
    const SISTEM_NAME    = 'PERJAKIN';


    // =========================================================================
    // PUBLIC ACTIONS – Route Handlers
    // =========================================================================

    /**
     * GET /login
     * Tampilkan halaman login. Redirect ke dashboard jika sudah login.
     */
    public function showLogin($f3)
    {
        if (AuthHelper::isLoggedIn()) {
            $f3->reroute('/dashboard');
        }

        if (!$f3->exists('SESSION.csrf')) {
            $f3->set('SESSION.csrf', bin2hex(random_bytes(32)));
        }
        $f3->set('csrf', $f3->get('SESSION.csrf'));

        if ($f3->exists('SESSION.errorMsg')) {
            $f3->set('error', $f3->get('SESSION.errorMsg'));
            $f3->clear('SESSION.errorMsg');
        }

        echo \Template::instance()->render('login.html');
    }

    /**
     * POST /login/send-otp  (AJAX)
     * Alur login Perjakin Step 1:
     * Verifikasi username + password → generate OTP → kirim via WA.
     * Response: JSON { success, message, masked_phone, otp_sent }
     */
    public function sendOTP($f3)
    {
        header('Content-Type: application/json');

        $username  = trim($f3->get('POST.username'));
        $password  = $f3->get('POST.password');
        $csrfToken = $f3->get('POST.csrf_token');

        // Validasi CSRF
        if ($csrfToken !== $f3->get('SESSION.csrf')) {
            echo json_encode(['success' => false, 'message' => 'Invalid CSRF Token.']);
            return;
        }

        $dbSil = $f3->get('DB_SIL');
        if (!$dbSil) {
            echo json_encode(['success' => false, 'message' => 'Koneksi database tidak tersedia.']);
            return;
        }

        // Cari user di sil2020
        $sil = new \DB\SQL\Mapper($dbSil, 'tb_arsipuser');
        $sil->load(['login=?', $username]);
        if ($sil->dry()) {
            $sil->load(['LOWER(login)=?', strtolower($username)]);
        }

        if ($sil->dry()) {
            echo json_encode(['success' => false, 'message' => 'Username tidak ditemukan.']);
            return;
        }

        // Validasi password (support MD5 lama & bcrypt baru)
        $hash          = $sil->password;
        $passwordValid = (
            md5($password) === $hash
            || md5($password) === strtolower($hash)
            || strtoupper(md5($password)) === strtoupper($hash)
            || password_verify($password, $hash)
        );

        if (!$passwordValid) {
            echo json_encode(['success' => false, 'message' => 'Password yang Anda masukkan salah.']);
            return;
        }

        // Ambil dan normalisasi nomor HP
        $phone = $this->resolvePhone($sil);

        // Generate OTP
        $otp    = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expiry = time() + self::OTP_EXPIRY_SEC;

        // Tentukan role dari kolom si_perjakin
        $siPerjakin = $sil->si_perjakin;
        $role       = ($siPerjakin !== null && $siPerjakin !== '') ? $siPerjakin : 'null_role';

        // Simpan OTP di session (belum buat session login penuh)
        $f3->set('SESSION.otp_pending', [
            'otp'       => $otp,
            'expiry'    => $expiry,
            'user_data' => [
                'id'         => $sil->id_user,
                'nama'       => $sil->nama_user ?: $sil->login,
                'username'   => $sil->login,
                'role'       => $role,
                'avatar'     => $sil->nama_avatar,
                'unit_kerja' => '',
            ],
            'phone' => $phone,
        ]);

        // Kirim OTP via WhatsApp
        $nama   = $sil->nama_user ?: $sil->login;
        $sent   = false;
        if ($phone) {
            $sent = $this->sendWhatsAppOTP($phone, $otp, $nama);
        } else {
            error_log("[Perjakin OTP] User {$username} tidak punya nomor HP. OTP: {$otp}");
        }

        $masked = $phone ? $this->maskPhone($phone) : null;

        echo json_encode([
            'success'      => true,
            'message'      => $sent
                ? "Kode OTP telah dikirim ke WhatsApp {$masked}."
                : 'Tidak ada nomor HP di akun Anda. OTP dicatat di server log (hubungi admin).',
            'masked_phone' => $masked,
            'otp_sent'     => $sent,
        ]);
    }

    /**
     * POST /login  (AJAX)
     * Alur login Perjakin Step 2:
     * Verifikasi OTP yang diinput → buat session login → redirect dashboard.
     */
    public function doLogin($f3)
    {
        $csrfToken = $f3->get('POST.csrf_token');
        $inputOtp  = trim($f3->get('POST.otp'));

        if ($csrfToken !== $f3->get('SESSION.csrf')) {
            $f3->set('SESSION.errorMsg', 'Invalid CSRF Token. Silakan coba lagi.');
            $f3->reroute('/login');
            return;
        }

        $pending = $f3->get('SESSION.otp_pending');
        if (!$pending) {
            $f3->set('SESSION.errorMsg', 'Sesi habis. Silakan login ulang.');
            $f3->reroute('/login');
            return;
        }

        if (time() > $pending['expiry']) {
            $f3->clear('SESSION.otp_pending');
            $f3->set('SESSION.errorMsg', 'Kode OTP telah kedaluwarsa. Silakan login ulang.');
            $f3->reroute('/login');
            return;
        }

        if ($inputOtp !== $pending['otp']) {
            $f3->set('SESSION.errorMsg', 'Kode OTP salah. Silakan coba lagi.');
            $f3->reroute('/login');
            return;
        }

        // OTP valid → buat session login
        $f3->set('SESSION.user', $pending['user_data']);
        $f3->clear('SESSION.otp_pending');
        $f3->clear('SESSION.errorMsg');
        $f3->set('SESSION.csrf', bin2hex(random_bytes(32)));

        $f3->reroute('/dashboard');
    }

    /**
     * POST /loginsila
     * Alur login dua-langkah ala sistem sil2020:
     *  1. Ambil IP user (dengan filter_var untuk validasi).
     *  2. Rate-limit: blokir IP jika ≥ 40 percobaan gagal hari ini.
     *  3. Reset flag harian (tb_logtrace_daily) jika belum diperbarui hari ini.
     *  4. Validasi kredensial (login + MD5(password) + status=1) di tb_arsipuser.
     *  5a. OTP kosong → generate OTP, kirim WA, simpan ke DB, redirect /login.
     *  5b. OTP diisi  → verifikasi vs id_goonline / otp_khusus → set session → /dashboard.
     *  6. Gagal       → catat ke tb_logtrace_login → set pesan error → redirect /login.
     */
    public function loginsila($f3)
    {
        $ipaddress  = $this->resolveClientIP();
        $loginname  = addslashes(strip_tags(trim((string)$f3->get('POST.userID'))));
        $passwordkey = addslashes(strip_tags(trim((string)$f3->get('POST.password'))));
        $otp_onsign = addslashes(strip_tags(trim((string)$f3->get('POST.otp_onsign'))));
        $photo      = addslashes(strip_tags(trim((string)$f3->get('POST.photo_data'))));

        $dbSil = $f3->get('DB_SIL');
        if (!$dbSil) {
            $f3->set('SESSION.errorMsg', 'Koneksi database ' . $f3->get('db.sil_name') . ' tidak tersedia.');
            $f3->reroute('/login');
            return;
        }

        // ── Langkah 2: Rate-limit per IP ─────────────────────────────────────
        $rowLmt = $dbSil->exec("SELECT COUNT(*) AS jum FROM `tb_logtrace_login` WHERE `ip_login_user`=? AND `waktu_login` LIKE ?", [
            $ipaddress,
            date('Y-m-d') . '%'
        ]);
        $countFailed = !empty($rowLmt) ? (int)$rowLmt[0]['jum'] : 0;

        if ($countFailed >= 40) {
            $f3->set('SESSION.errorMsg', 'Akses Anda sementara diblokir karena terlalu banyak percobaan. Coba lagi besok.');
            $f3->reroute('/login');
            return;
        }

        // ── Langkah 3: Reset flag harian ─────────────────────────────────────
        $tanggalHari = date('Y-m-d');
        $rowCek = $dbSil->exec("SELECT COUNT(*) AS jum FROM `tb_logtrace_daily` WHERE `cek_tanggal_harian`=?", $tanggalHari);
        $countHarian = !empty($rowCek) ? (int)$rowCek[0]['jum'] : 0;
        
        if ($countHarian < 1) {
            // Gunakan ON DUPLICATE KEY UPDATE agar jika id=1 sudah ada, MySQL hanya melakukan update tanpa error 1062
            $dbSil->exec(
                "INSERT INTO `tb_logtrace_daily` (`id_logtrace_daily`, `cek_tanggal_harian`) 
                 VALUES (1, ?) 
                 ON DUPLICATE KEY UPDATE `cek_tanggal_harian`=VALUES(`cek_tanggal_harian`)", 
                $tanggalHari
            );

            // Hapus semua id_goonline untuk mereset OTP di hari yang baru
            $dbSil->exec("UPDATE `tb_arsipuser` SET `id_goonline`=NULL");
        }

        $tanggalSkrg = gmdate('Y-m-d H:i:s', time() + 60 * 60 * 7);

        // ── Langkah 4: Validasi kredensial ───────────────────────────────────
        $userMapper = new \DB\SQL\Mapper($dbSil, 'tb_arsipuser');
        $userMapper->load(['login=? AND password=MD5(?) AND status=1', $loginname, $passwordkey]);

        if ($userMapper->dry()) {
            $this->logFailedLogin($dbSil, $loginname, $passwordkey, $tanggalSkrg, $ipaddress, 'SalahLOGIN');
            $f3->set('SESSION.errorMsg', 'User ID / Password salah!');
            $f3->reroute('/login');
            return;
        }

        // Kredensial valid
        $IDid      = $userMapper->id_user;
        $nama_user = $userMapper->nama_user;
        $no_hp     = $userMapper->no_hp;

            // Jangan set $_SESSION di sini karena ini baru tahap pengiriman OTP,
        // ── Langkah 5a: OTP belum diisi di Form ────────────────────────────────
        if (empty($otp_onsign)) {
            // Cek apakah database user ini sudah pernah dikabari OTP hari ini
            if (empty($userMapper->id_goonline)) {
                $return_otpnum = rand(215487, 987456);

                if (!empty($no_hp)) {
                    $phone   = $this->normalizePhone($no_hp);
                    $message = $this->buildOtpMessage($nama_user, $return_otpnum);
                    $this->SentWANotif($phone, $message);
                }

                $dbSil->exec(
                    "INSERT INTO `tb_logtrace_minta_otp` SET `id_user`=?,`no_otpnya`=?,`row_status_active`='46',`waktu_login`=?,`sistemto_login`=?",
                    [$IDid, $return_otpnum, $tanggalSkrg, self::SISTEM_NAME]
                );
                $dbSil->exec(
                    "UPDATE `tb_arsipuser` SET `id_goonline`=? WHERE `id_user`=?",
                    [$return_otpnum, $IDid]
                );
            }

            // Flag session untuk Frontend UI agar form OTP terbuka
            $f3->set('SESSION.IDid_temp', $IDid);
            $f3->reroute('/login');
            return;
        }

        // ── Langkah 5b: OTP diisi → verifikasi ───────────────────────────────
        $id_goonline = (string)$userMapper->id_goonline;
        $otp_khusus  = (string)$this->CCekMaxIDVerifikasiOTP($f3);
        $otp_onsign_str = (string)$otp_onsign;

        if (($otp_onsign_str === $id_goonline) || ($otp_onsign_str === $otp_khusus)) {
            $this->buildSilaSession($f3, $userMapper, $IDid, $otp_onsign, $otp_khusus);
            // Hapus penanda temporary
            $f3->clear('SESSION.IDid_temp');
            $this->saveLoginPhoto($photo, $IDid);

            $dbSil->exec(
                "INSERT INTO `tb_logtrace_insign` SET `id_user`=?,`no_otpnya`=?,`row_status_active`='46',`waktu_login`=?,`sistemto_login`=?",
                [$IDid, $otp_onsign, $tanggalSkrg, self::SISTEM_NAME]
            );

            $f3->set('SESSION.csrf', bin2hex(random_bytes(32)));
            $f3->reroute('/dashboard');
        } else {
            $this->logFailedLogin($dbSil, $loginname, $passwordkey, $tanggalSkrg, $ipaddress, 'SalahOTP');
            $f3->set('SESSION.errorMsg', 'Kode OTP salah. Silakan coba lagi.');
            $f3->reroute('/login');
        }
    }

    /**
     * GET /logout
     * Hapus session dan redirect ke halaman login.
     */
    public function doLogout($f3)
    {
        $f3->clear('SESSION');
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        $f3->reroute('/login');
    }


    // =========================================================================
    // PRIVATE – Business Logic
    // =========================================================================

    /**
     * Bangun session penuh setelah login sil2020 berhasil (OTP valid).
     */
    private function buildSilaSession($f3, $mapper, int $IDid, string $otpOnsign, ?string $otpKhusus): void
    {
        $siPerjakin = $mapper->si_perjakin ?? null;
        $role       = ($siPerjakin !== null && $siPerjakin !== '') ? $siPerjakin : 'null_role';

        // Session standar AuthHelper
        $f3->set('SESSION.user', [
            'id'         => $IDid,
            'nama'       => $mapper->nama_user ?: $mapper->login,
            'username'   => $mapper->login,
            'role'       => $role,
            'avatar'     => $mapper->nama_avatar ?? null,
            'unit_kerja' => '',
        ]);

        // Session compat SILA
        $f3->set('SESSION.IDid',              $IDid);
        $f3->set('SESSION.IDuser',            $mapper->nama_user);
        $f3->set('SESSION.IDstruktural',      $mapper->id_struktural      ?? null);
        $f3->set('SESSION.IDjeniscontoh',     $mapper->kd_contoh_authen   ?? null);
        $f3->set('SESSION.IDstruktural_tim',  $mapper->id_struktural_tim  ?? null);
        $f3->set('SESSION.IDsicaper',         $mapper->id_sicaper         ?? null);
        $f3->set('SESSION.IDsiinspeksi',      $mapper->id_inspeksi        ?? null);
        $f3->set('SESSION.IDsikalib',         $mapper->si_kalibrasi       ?? null);
        $f3->set('SESSION.IDsertifikasi',     $mapper->si_sertifikasi     ?? null);
        $f3->set('SESSION.IDotp',             $otpKhusus ?? $otpOnsign);
        $f3->set('SESSION.id_avatar_login_f3', $mapper->nama_avatar       ?? null);
        $f3->set('SESSION.IDarsip',           $mapper->id_arsip           ?? null);
        $f3->set('SESSION.IDlph',             $mapper->id_lph             ?? null);
    }

    /**
     * Simpan foto login (base64 PNG) ke folder uploads_login_pics.
     */
    private function saveLoginPhoto(string $photo, int $IDid): void
    {
        if (empty($photo)) return;

        $imgData  = str_replace('data:image/png;base64,', '', $photo);
        $imgData  = str_replace(' ', '+', $imgData);
        $fileData = base64_decode($imgData);

        $uploadDir = __DIR__ . '/../../public/uploads_login_pics/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $fileName = 'login_' . time() . '_' . $IDid . '.png';
        file_put_contents($uploadDir . $fileName, $fileData);
    }

    /**
     * Catat percobaan login gagal ke tb_logtrace_login.
     */
    private function logFailedLogin($dbSil, string $login, string $password, string $waktu, string $ip, string $ket): void
    {
        $dbSil->exec(
            "INSERT INTO `tb_logtrace_login` SET `login_input`=?,`password_input`=?,`waktu_login`=?,`ip_login_user`=?,`ket_logtrace`=?",
            [$login, $password, $waktu, $ip, $ket]
        );
    }

    /**
     * Bangun pesan OTP WA untuk login SILA.
     */
    private function buildOtpMessage(string $namaUser, int $otp): string
    {
        $app     = self::SISTEM_NAME;
        $message = "[ {$app} ] Hello {$namaUser}, \n\n"
            . "Untuk Menghindari Login Hack, Kode OTP : *{$otp}* "
            . "di Sistem Informasi {$app}.\n\n"
            . "OTP dapat digunakan untuk login pada hari yang sama.\n\n"
            . "Apabila ingin reset OTP, Login tanpa memasukan OTP untuk mendapatkan OTP baru.";
        return str_replace('&nbsp;', ' ', $message);
    }


    /** Ambil otp_khusus (master OTP) terbaru dari tb_otp_khusus. */
    private function CCekMaxIDVerifikasiOTP(\Base $f3): ?string
    {
        $dbSil = $f3->get('DB_SIL');
        if (!$dbSil) return null;
        $rows = $dbSil->exec("SELECT otp_khusus FROM tb_otp_khusus ORDER BY id_otp_khusus DESC LIMIT 1");
        return !empty($rows) ? $rows[0]['otp_khusus'] : null;
    }


    // =========================================================================
    // PRIVATE – Utility Helpers (WA, Phone, IP)
    // =========================================================================

    /**
     * Kirim notifikasi WhatsApp via Wanotif API.
     *
     * @param string $noHP     Nomor tujuan format 62xxx
     * @param string $pesanWA  Isi pesan
     */
    private function SentWANotif(string $noHP, string $pesanWA): void
    {
        $apikey = \Base::instance()->get('wa.apikey') ?: self::WANOTIF_API_KEY;
        $url    = 'https://api.wanotif.id/v1/send';

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, [
            'Apikey'  => $apikey,
            'Phone'   => $noHP,
            'Message' => $pesanWA,
        ]);

        $response = curl_exec($ch);
        $err      = curl_error($ch);
        curl_close($ch);

        if ($err) {
            error_log("[Perjakin WA] cURL error: {$err}");
        } else {
            // Catat log ke tb_list_wa jika berhasil atau minimal sudah dikirim ke API
            $f3 = \Base::instance();
            $dbSil = $f3->get('DB_SIL');
            if ($dbSil) {
                $dbSil->exec(
                    "INSERT INTO `tb_list_wa` (`isi_wa_server`, `no_hp_tujuan`, `tanggal_kirim`) VALUES (?, ?, ?)",
                    [$pesanWA, $noHP, date('Y-m-d H:i:s')]
                );
            }
        }
    }

    /**
     * Kirim OTP via WhatsApp menggunakan Wanotif API (alur Perjakin sendOTP).
     * Berbeda dengan SentWANotif – method ini memformat pesan OTP khusus Perjakin
     * dan mengembalikan bool sukses/gagal.
     */
    private function sendWhatsAppOTP(string $phone, string $otp, string $nama): bool
    {
        $message = "Halo, *{$nama}*!\n\n"
            . "Kode OTP Anda untuk login ke *" . self::SISTEM_NAME . "* adalah:\n\n"
            . "*{$otp}*\n\n"
            . "Kode berlaku selama 5 menit.\n"
            . "_Jangan berikan kode ini kepada siapapun._";

        $apikey = \Base::instance()->get('wa.apikey') ?: self::WANOTIF_API_KEY;
        $url    = 'https://api.wanotif.id/v1/send';

        try {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_HEADER, 0);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, [
                'Apikey'  => $apikey,
                'Phone'   => $phone,
                'Message' => $message,
            ]);

            $response  = curl_exec($ch);
            $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError) {
                error_log("[Perjakin OTP] cURL error: {$curlError}");
                return false;
            }

            $result = json_decode($response, true);
            if ($httpCode === 200 && isset($result['status']) && $result['status'] === true) {
                // Catat log ke tb_list_wa
                $f3 = \Base::instance();
                $dbSil = $f3->get('DB_SIL');
                if ($dbSil) {
                    $dbSil->exec(
                        "INSERT INTO `tb_list_wa` (`isi_wa_server`, `no_hp_tujuan`, `tanggal_kirim`) VALUES (?, ?, ?)",
                        [$message, $phone, date('Y-m-d H:i:s')]
                    );
                }
                return true;
            }

            // Jika respons tidak sukses, catat responsenya untuk debug
            error_log("[Perjakin OTP] Wanotif API ({$httpCode}): {$response}");
            return false;

        } catch (\Exception $e) {
            error_log("[Perjakin OTP] Exception: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Cari kolom nomor HP dari mapper tb_arsipuser dan normalisasi ke format 62xxx.
     * Return null jika tidak ditemukan.
     */
    private function resolvePhone($mapper): ?string
    {
        $phone = null;
        foreach (['no_hp', 'phone', 'hp', 'whatsapp', 'no_wa', 'telp', 'telepon', 'handphone'] as $col) {
            try {
                $val = $mapper->$col;
                if ($val !== null && $val !== '') {
                    $phone = preg_replace('/\D/', '', $val);
                    break;
                }
            } catch (\Exception $e) {
                // Kolom tidak ada di tabel, lanjut ke kolom berikutnya
            }
        }

        return $phone ? $this->normalizePhone($phone) : null;
    }

    /**
     * Normalisasi string nomor HP ke format 62xxx (tanpa karakter non-digit).
     */
    private function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/\D/', '', $phone);
        if (substr($phone, 0, 2) === '62') return $phone;
        if (substr($phone, 0, 1) === '0') return '62' . substr($phone, 1);
        return '62' . $phone;
    }

    /**
     * Mask nomor HP untuk tampilan: 628123456789 → 62812***789
     */
    private function maskPhone(string $phone): string
    {
        $len        = strlen($phone);
        if ($len <= 6) return $phone;
        $showStart  = 5;
        $showEnd    = 3;
        $maskLen    = max(0, $len - $showStart - $showEnd);
        return substr($phone, 0, $showStart)
            . str_repeat('*', $maskLen)
            . substr($phone, -$showEnd);
    }

    /**
     * Dapatkan IP client yang valid.
     * Prioritas: HTTP_CLIENT_IP → HTTP_X_FORWARDED_FOR → REMOTE_ADDR.
     */
    private function resolveClientIP(): string
    {
        $keys = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
        foreach ($keys as $key) {
            if (isset($_SERVER[$key]) && filter_var($_SERVER[$key], FILTER_VALIDATE_IP)) {
                return $_SERVER[$key];
            }
        }
        return 'IP tidak dikenali';
    }
}

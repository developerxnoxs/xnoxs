<?php
/**
 * ================================================================
 *  CPANEL MANAGER — Superadmin Panel
 *  cPanel UAPI (v3) — Port 2083 (HTTPS) / 2082 (HTTP)
 *  Fitur: FTP, MySQL, Subdomain, Email Forwarder, Cron, Disk, SSL
 * ================================================================
 */

class CpanelManager {

    private static string $host = '';
    private static int    $port = 2083;
    private static string $user = '';
    private static string $pass = '';

    public static function configure(array $cfg): void {
        self::$host = $cfg['cpanel_host'] ?: CPANEL_HOST;
        self::$port = (int)($cfg['cpanel_port'] ?: CPANEL_PORT);
        self::$user = $cfg['cpanel_user'] ?: CPANEL_USER;
        self::$pass = $cfg['cpanel_pass'] ?: CPANEL_PASS;
    }

    public static function getRuntimeConfig(): array {
        return [
            'cpanel_host' => self::$host ?: CPANEL_HOST,
            'cpanel_port' => self::$port ?: CPANEL_PORT,
            'cpanel_user' => self::$user ?: CPANEL_USER,
            'cpanel_pass' => self::$pass ?: CPANEL_PASS,
        ];
    }

    // ===== cPanel UAPI CALL =====

    private static function uapi(string $module, string $func, array $params = []): array {
        $host = self::$host ?: CPANEL_HOST;
        $port = self::$port ?: CPANEL_PORT;
        $user = self::$user ?: CPANEL_USER;
        $pass = self::$pass ?: CPANEL_PASS;

        $url  = "https://{$host}:{$port}/execute/{$module}/{$func}";
        $body = !empty($params) ? http_build_query($params) : '';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_USERPWD        => "$user:$pass",
            CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'Content-Type: application/x-www-form-urlencoded',
            ],
        ]);
        $res      = curl_exec($ch);
        $err      = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err) return ['errors' => [$err], 'status' => 0];
        if ($httpCode === 403) return ['errors' => ['Autentikasi gagal (403 Forbidden). Periksa kredensial cPanel.'], 'status' => 0];
        if ($httpCode === 401) return ['errors' => ['Tidak diizinkan (401 Unauthorized). Periksa username/password cPanel.'], 'status' => 0];

        $decoded = json_decode($res ?: '{}', true);
        return $decoded ?: ['errors' => ['Respons JSON tidak valid dari cPanel.'], 'status' => 0];
    }

    // ===== cPanel API2 CALL (fallback untuk modul yang tidak tersedia di UAPI) =====

    private static function api2(string $module, string $func, array $params = []): array {
        $host = self::$host ?: CPANEL_HOST;
        $port = self::$port ?: CPANEL_PORT;
        $user = self::$user ?: CPANEL_USER;
        $pass = self::$pass ?: CPANEL_PASS;

        $query = array_merge([
            'cpanel_jsonapi_apiversion' => '2',
            'cpanel_jsonapi_module'     => $module,
            'cpanel_jsonapi_func'       => $func,
        ], $params);

        $url = "https://{$host}:{$port}/json-api/cpanel?" . http_build_query($query);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD        => "$user:$pass",
            CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $res      = curl_exec($ch);
        $err      = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err) return ['errors' => [$err], 'status' => 0];
        if ($httpCode === 403) return ['errors' => ['Autentikasi gagal (403 Forbidden). Periksa kredensial cPanel.'], 'status' => 0];
        if ($httpCode === 401) return ['errors' => ['Tidak diizinkan (401 Unauthorized). Periksa username/password cPanel.'], 'status' => 0];

        $decoded = json_decode($res ?: '{}', true);
        if (!$decoded) return ['errors' => ['Respons JSON tidak valid dari cPanel.'], 'status' => 0];

        // Normalisasi respons API2 ke format mirip UAPI
        $result = $decoded['cpanelresult'] ?? [];
        $event  = $result['event'] ?? [];
        $data   = $result['data'] ?? [];

        // Error level atas
        if (!empty($result['error'])) {
            return ['errors' => [$result['error']], 'status' => 0];
        }

        $eventOk  = ($event['result'] ?? 0) == 1;
        // Cek data[0].result jika ada (beberapa API2 function melaporkan gagal di sini)
        $dataItem = is_array($data) && isset($data[0]) ? $data[0] : null;
        $dataOk   = $dataItem === null || ($dataItem['result'] ?? 1) != 0;
        $dataErr  = ($dataItem !== null && ($dataItem['result'] ?? 1) == 0)
                    ? ($dataItem['reason'] ?? 'Operasi gagal.') : null;

        if ($eventOk && $dataOk) {
            return ['status' => 1, 'data' => $data];
        }
        if ($dataErr) {
            return ['errors' => [$dataErr], 'status' => 0];
        }
        if ($eventOk) {
            return ['status' => 1, 'data' => $data];
        }
        return ['errors' => ['Operasi API2 gagal tanpa pesan error.'], 'status' => 0];
    }

    // ===== HELPER =====

    private static function checkStatus(array $res): array {
        if (!empty($res['errors'])) {
            return ['success' => false, 'error' => implode(', ', (array)$res['errors'])];
        }
        if (($res['status'] ?? 0)) return ['success' => true, 'data' => $res['data'] ?? []];
        return ['success' => false, 'error' => 'Operasi gagal tanpa pesan error.'];
    }

    // ===== TES KONEKSI =====

    public static function testConnection(): array {
        $res = self::uapi('ServerInformation', 'get_information');
        if (!empty($res['errors'])) {
            return ['success' => false, 'message' => implode(', ', $res['errors'])];
        }
        $data = $res['data'] ?? [];
        return [
            'success'  => true,
            'hostname' => $data['hostname'] ?? 'N/A',
            'version'  => $data['version']  ?? 'N/A',
        ];
    }

    // ===== FITUR FTP =====

    public static function ftpList(): array {
        $res = self::uapi('Ftp', 'list_ftp_with_disk');
        if (!empty($res['errors'])) {
            return ['error' => implode(', ', $res['errors'])];
        }
        return $res['data'] ?? [];
    }

    public static function ftpCreate(string $login, string $password, string $homedir = '', int $quota = 0): array {
        $params = ['login' => $login, 'password' => $password, 'quota' => $quota];
        if ($homedir !== '') $params['homedir'] = $homedir;
        $res = self::uapi('Ftp', 'add_ftp', $params);
        if ($res['status'] ?? 0) return ['success' => true];
        $errors = $res['errors'] ?? ['Gagal membuat akun FTP.'];
        return ['success' => false, 'error' => is_array($errors) ? implode(', ', $errors) : $errors];
    }

    public static function ftpDelete(string $login, string $domain): array {
        $res = self::uapi('Ftp', 'delete_ftp', ['login' => $login, 'domain' => $domain]);
        if ($res['status'] ?? 0) return ['success' => true];
        $errors = $res['errors'] ?? ['Gagal menghapus akun FTP.'];
        return ['success' => false, 'error' => is_array($errors) ? implode(', ', $errors) : $errors];
    }

    public static function ftpPasswd(string $login, string $domain, string $newPassword): array {
        $res = self::uapi('Ftp', 'passwd', ['login' => $login, 'domain' => $domain, 'password' => $newPassword]);
        if ($res['status'] ?? 0) return ['success' => true];
        $errors = $res['errors'] ?? ['Gagal mengubah password FTP.'];
        return ['success' => false, 'error' => is_array($errors) ? implode(', ', $errors) : $errors];
    }

    public static function ftpSetQuota(string $login, string $domain, int $quota): array {
        $res = self::uapi('Ftp', 'set_quota', ['login' => $login, 'domain' => $domain, 'quota' => $quota]);
        if ($res['status'] ?? 0) return ['success' => true];
        $errors = $res['errors'] ?? ['Gagal mengatur quota FTP.'];
        return ['success' => false, 'error' => is_array($errors) ? implode(', ', $errors) : $errors];
    }

    // ===== B1. MySQL DATABASE MANAGEMENT =====

    /**
     * Kembalikan prefix cPanel (misal "bintang_").
     * cPanel mewajibkan semua nama DB dan user DB diawali prefix username.
     */
    public static function getDbPrefix(): string {
        $user = self::$user ?: CPANEL_USER;
        return $user ? rtrim($user, '_') . '_' : '';
    }

    /**
     * Tambahkan prefix otomatis jika belum ada.
     * Jika nama sudah diawali prefix, langsung dikembalikan apa adanya.
     */
    public static function addDbPrefix(string $name): string {
        $prefix = self::getDbPrefix();
        if (!$prefix) return $name;
        if (str_starts_with($name, $prefix)) return $name;
        return $prefix . $name;
    }

    public static function dbList(): array {
        $res = self::uapi('Mysql', 'list_databases');
        if (!empty($res['errors'])) return ['error' => implode(', ', $res['errors'])];
        return $res['data'] ?? [];
    }

    public static function dbCreate(string $dbName): array {
        $fullName = self::addDbPrefix($dbName);
        $res = self::uapi('Mysql', 'create_database', ['name' => $fullName]);
        $result = self::checkStatus($res);
        $result['name'] = $fullName;
        return $result;
    }

    public static function dbDelete(string $dbName): array {
        $fullName = self::addDbPrefix($dbName);
        $res = self::uapi('Mysql', 'delete_database', ['name' => $fullName]);
        $result = self::checkStatus($res);
        $result['name'] = $fullName;
        return $result;
    }

    public static function dbUserList(): array {
        $res = self::uapi('Mysql', 'list_users');
        if (!empty($res['errors'])) return ['error' => implode(', ', $res['errors'])];
        return $res['data'] ?? [];
    }

    public static function dbUserCreate(string $username, string $pass): array {
        $fullName = self::addDbPrefix($username);
        $res = self::uapi('Mysql', 'create_user', ['name' => $fullName, 'password' => $pass]);
        $result = self::checkStatus($res);
        $result['name'] = $fullName;
        return $result;
    }

    public static function dbUserGrant(string $dbName, string $username, string $privileges = 'ALL PRIVILEGES'): array {
        $fullDb   = self::addDbPrefix($dbName);
        $fullUser = self::addDbPrefix($username);
        $res = self::uapi('Mysql', 'set_privileges_on_database', [
            'database'   => $fullDb,
            'user'       => $fullUser,
            'privileges' => $privileges,
        ]);
        $result = self::checkStatus($res);
        $result['db']   = $fullDb;
        $result['user'] = $fullUser;
        return $result;
    }

    // ===== B2. SUBDOMAIN MANAGEMENT (API2 — lebih kompatibel dari UAPI) =====

    public static function subdomainList(): array {
        $res = self::api2('SubDomain', 'listsubdomains');
        if (!empty($res['errors'])) return ['error' => implode(', ', $res['errors'])];
        $data = $res['data'] ?? [];
        if (isset($data[0]) && is_array($data[0])) return $data;
        if (!empty($data) && !isset($data[0])) return [$data];
        return $data;
    }

    public static function subdomainCreate(string $subdomain, string $domain, string $dir = ''): array {
        $params = ['domain' => $subdomain, 'rootdomain' => $domain];
        if ($dir !== '') $params['dir'] = $dir;
        $res = self::api2('SubDomain', 'addsubdomain', $params);
        return self::checkStatus($res);
    }

    public static function subdomainDelete(string $subdomain, string $domain): array {
        // Coba UAPI dulu, fallback ke API2 jika gagal
        $res = self::uapi('SubDomain', 'delsubdomain', ['domain' => "{$subdomain}.{$domain}"]);
        if (empty($res['errors']) && ($res['status'] ?? 0)) return ['success' => true];
        $res2 = self::api2('SubDomain', 'delsubdomain', ['domain' => "{$subdomain}.{$domain}"]);
        return self::checkStatus($res2);
    }

    // ===== B3. EMAIL FORWARDER (API2 — lebih kompatibel dari UAPI) =====

    public static function forwarderList(): array {
        $res = self::api2('Email', 'listforwards');
        if (!empty($res['errors'])) return ['error' => implode(', ', $res['errors'])];
        $data = $res['data'] ?? [];
        if (isset($data[0]) && is_array($data[0])) return $data;
        if (!empty($data) && !isset($data[0])) return [$data];
        return $data;
    }

    public static function forwarderCreate(string $email, string $destination): array {
        // Ekstrak domain dari alamat email sumber
        $parts  = explode('@', $email, 2);
        $domain = $parts[1] ?? '';

        $res = self::api2('Email', 'addforward', [
            'domain'   => $domain,
            'email'    => $email,
            'fwdopt'   => 'fwd',        // wajib: tipe forward ke email lain
            'fwdemail' => $destination, // wajib untuk fwdopt=fwd
        ]);
        return self::checkStatus($res);
    }

    public static function forwarderDelete(string $email, string $destination): array {
        $res = self::api2('Email', 'delforward', [
            'email'     => $email,       // alamat sumber (user@domain.com)
            'emaildest' => $destination, // alamat tujuan (dest@gmail.com)
        ]);
        return self::checkStatus($res);
    }

    // ===== B4. CRON JOB MANAGEMENT (menggunakan API2 karena UAPI Cron tidak tersedia di semua server) =====

    public static function cronList(): array {
        $res = self::api2('Cron', 'listcron');
        if (!empty($res['errors'])) return ['error' => implode(', ', $res['errors'])];
        $data = $res['data'] ?? [];
        // API2 listcron mengembalikan array langsung atau array of arrays
        if (isset($data[0]) && is_array($data[0])) return $data;
        if (!empty($data) && !isset($data[0])) return [$data];
        return $data;
    }

    public static function cronAdd(string $minute, string $hour, string $day, string $month, string $weekday, string $command): array {
        $res = self::api2('Cron', 'add_line', [
            'minute'  => $minute,
            'hour'    => $hour,
            'day'     => $day,
            'month'   => $month,
            'weekday' => $weekday,
            'command' => $command,
        ]);
        return self::checkStatus($res);
    }

    public static function cronDelete(int $lineKey): array {
        // API2 menggunakan parameter 'line' (0-indexed)
        $res = self::api2('Cron', 'remove_line', ['line' => $lineKey]);
        return self::checkStatus($res);
    }

    // ===== B5. DISK USAGE & QUOTA (UAPI: Quota::get_quota_info → StatsBar::get_stats fallback) =====

    public static function diskUsage(): array {
        $lastErr = 'Gagal mengambil info disk.';

        // ── Metode 1: UAPI Quota::get_quota_info (modern, recommended) ──
        $res = self::uapi('Quota', 'get_quota_info');
        if (empty($res['errors']) && isset($res['data']['bytes_used'])) {
            $d = $res['data'];
            $result = [
                'megabytesused'  => (int)round(($d['bytes_used']  ?? 0) / 1048576),
                'megabyteslimit' => (int)round(($d['bytes_limit'] ?? 0) / 1048576),
                'inodes_used'    => (int)($d['inodes_used']  ?? 0),
                'inodes_limit'   => (int)($d['inodes_limit'] ?? 0),
            ];
            // Tambahan: ambil stats lain (files, bandwidth, mysql) dari StatsBar
            $sb = self::uapi('StatsBar', 'get_stats', ['display' => 'filesusage|bandwidthusage|mysqldiskusage']);
            if (empty($sb['errors']) && is_array($sb['data'] ?? null)) {
                foreach ($sb['data'] as $stat) {
                    $result['extra'][$stat['name']] = [
                        'count'   => $stat['count']   ?? '?',
                        'max'     => $stat['max']     ?? 'Unlimited',
                        'percent' => $stat['percent'] ?? 0,
                    ];
                }
            }
            return $result;
        }
        if (!empty($res['errors'])) $lastErr = implode(', ', $res['errors']);

        // ── Metode 2: UAPI StatsBar::get_stats (fallback) ──
        $sb = self::uapi('StatsBar', 'get_stats', [
            'display' => 'diskusage|filesusage|bandwidthusage|mysqldiskusage',
        ]);
        if (empty($sb['errors']) && is_array($sb['data'] ?? null)) {
            $result = ['source' => 'statsbar', 'extra' => []];
            foreach ($sb['data'] as $stat) {
                $result['extra'][$stat['name']] = [
                    'count'   => $stat['count']   ?? '?',
                    'max'     => $stat['max']     ?? 'Unlimited',
                    'percent' => $stat['percent'] ?? 0,
                ];
            }
            if (isset($result['extra']['diskusage'])) {
                $result['disk_count']   = $result['extra']['diskusage']['count'];
                $result['disk_max']     = $result['extra']['diskusage']['max'];
                $result['disk_percent'] = $result['extra']['diskusage']['percent'];
            }
            return $result;
        }
        if (!empty($sb['errors'])) $lastErr = implode(', ', $sb['errors']);

        return ['error' => $lastErr];
    }

    // ===== B6. SSL/TLS CERTIFICATE INFO =====

    public static function sslList(): array {
        $res = self::uapi('SSL', 'list_certs');
        if (!empty($res['errors'])) return ['error' => implode(', ', $res['errors'])];
        return $res['data'] ?? [];
    }

    public static function sslInstallAutoSSL(): array {
        $res = self::uapi('SSL', 'start_autossl_check');
        return self::checkStatus($res);
    }
}

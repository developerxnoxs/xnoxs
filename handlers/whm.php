<?php
/**
 * ================================================================
 *  WHM MANAGER — Superadmin Panel
 *  WHM JSON API v1 — Port 2087 (HTTPS) / 2086 (HTTP)
 * ================================================================
 */

class WhmManager {

    private static string $host  = '';
    private static int    $port  = 2087;
    private static string $user  = '';
    private static string $pass  = '';
    private static string $token = '';

    public static function configure(array $cfg): void {
        self::$host  = $cfg['whm_host']  ?: WHM_HOST;
        self::$port  = (int)($cfg['whm_port']  ?: WHM_PORT);
        self::$user  = $cfg['whm_user']  ?: WHM_USER;
        self::$pass  = $cfg['whm_pass']  ?: WHM_PASS;
        self::$token = $cfg['whm_token'] ?: WHM_TOKEN;
    }

    public static function getRuntimeConfig(): array {
        return [
            'whm_host'  => self::$host  ?: WHM_HOST,
            'whm_port'  => self::$port  ?: WHM_PORT,
            'whm_user'  => self::$user  ?: WHM_USER,
            'whm_pass'  => self::$pass  ?: WHM_PASS,
            'whm_token' => self::$token ?: WHM_TOKEN,
        ];
    }

    // ===== WHM JSON API CALL =====

    private static function whmAPI(string $func, array $params = []): array {
        $host  = self::$host  ?: WHM_HOST;
        $port  = self::$port  ?: WHM_PORT;
        $user  = self::$user  ?: WHM_USER;
        $pass  = self::$pass  ?: WHM_PASS;
        $token = self::$token ?: WHM_TOKEN;

        $url = "https://{$host}:{$port}/json-api/{$func}?api.version=1";
        if (!empty($params)) {
            $url .= '&' . http_build_query($params);
        }

        $ch = curl_init($url);
        $headers = ['Accept: application/json'];

        if ($token) {
            $headers[] = "Authorization: whm {$user}:{$token}";
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT        => 20,
        ]);

        if (!$token && $user && $pass) {
            curl_setopt($ch, CURLOPT_USERPWD, "$user:$pass");
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        }

        $res = curl_exec($ch);
        $err = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err) return ['error' => $err, 'status' => 0];
        if ($httpCode === 403) return ['error' => 'Autentikasi gagal (403 Forbidden). Periksa kredensial WHM.', 'status' => 0];
        if ($httpCode === 401) return ['error' => 'Tidak diizinkan (401 Unauthorized). Periksa user/token WHM.', 'status' => 0];

        $decoded = json_decode($res ?: '{}', true);
        if (!$decoded) return ['error' => 'Respons JSON tidak valid dari WHM.', 'status' => 0];
        return $decoded;
    }

    // ===== cPanel UAPI via WHM (untuk akun spesifik) =====

    private static function cpanelUAPI(string $cpanelUser, string $module, string $func, array $params = []): array {
        $host  = self::$host  ?: WHM_HOST;
        $port  = self::$port  ?: WHM_PORT;
        $user  = self::$user  ?: WHM_USER;
        $token = self::$token ?: WHM_TOKEN;
        $pass  = self::$pass  ?: WHM_PASS;

        $url = "https://{$host}:{$port}/json-api/cpanel?api.version=1&cpanel_jsonapi_user={$cpanelUser}&cpanel_jsonapi_apiversion=3&cpanel_jsonapi_module={$module}&cpanel_jsonapi_func={$func}";
        if (!empty($params)) {
            $url .= '&' . http_build_query($params);
        }

        $ch = curl_init($url);
        $headers = ['Accept: application/json'];

        if ($token) {
            $headers[] = "Authorization: whm {$user}:{$token}";
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT        => 20,
        ]);

        if (!$token && $user && $pass) {
            curl_setopt($ch, CURLOPT_USERPWD, "$user:$pass");
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        }

        $res = curl_exec($ch);
        $err = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err) return ['error' => $err];
        if ($httpCode === 403 || $httpCode === 401) return ['error' => "HTTP {$httpCode}: Autentikasi gagal."];

        $decoded = json_decode($res ?: '{}', true);
        if (!$decoded) return ['error' => 'Respons JSON tidak valid.'];
        return $decoded;
    }

    // ===== FITUR WHM =====

    public static function testConnection(): array {
        $res = self::whmAPI('version');
        if (isset($res['error'])) return ['success' => false, 'message' => $res['error']];
        // Ref: https://api.docs.cpanel.net/openapi/whm/operation/version/
        // Response: data.version
        $version = $res['data']['version'] ?? 'N/A';
        return ['success' => true, 'version' => $version];
    }

    public static function serverInfo(): array {
        $res = self::whmAPI('loadavg');
        if (isset($res['error'])) return ['error' => $res['error']];

        $ver  = self::whmAPI('version');
        $host = self::whmAPI('gethostname');

        $loadData = $res['data'] ?? [];
        $verData  = $ver['data'] ?? [];
        $hostData = $host['data'] ?? [];

        return [
            'hostname' => $hostData['hostname'] ?? 'N/A',
            'version'  => $verData['version']   ?? 'N/A',
            'load1'    => $loadData['one']       ?? 'N/A',
            'load5'    => $loadData['five']      ?? 'N/A',
            'load15'   => $loadData['fifteen']   ?? 'N/A',
        ];
    }

    public static function listAccounts(int $limit = 20): array {
        $res = self::whmAPI('listaccts', ['want' => 'user,domain,email,diskused,disklimit,maxpop,suspended', 'searchtype' => 'username', 'search' => '']);
        if (isset($res['error'])) return ['error' => $res['error']];

        $accounts = $res['data']['acct'] ?? [];
        if (!is_array($accounts)) return ['error' => 'Format respons tidak dikenal.'];

        return array_slice($accounts, 0, $limit);
    }

    public static function accountExists(string $cpanelUser): bool {
        $res = self::whmAPI('accountsummary', ['user' => $cpanelUser]);
        return !isset($res['error']) && !empty($res['data']['acct']);
    }

    // ===== A1. SUSPEND / UNSUSPEND AKUN =====

    public static function suspendAccount(string $user, string $reason = ''): array {
        $params = ['user' => $user];
        if ($reason !== '') $params['reason'] = $reason;
        $res = self::whmAPI('suspendacct', $params);
        if (isset($res['error'])) return ['success' => false, 'error' => $res['error']];
        // Ref: https://api.docs.cpanel.net/openapi/whm/operation/suspendacct/
        // Response hanya menggunakan metadata, tidak ada data.result[0]
        $meta   = $res['metadata'] ?? [];
        $status = (int)($meta['result'] ?? 0);
        if ($status) return ['success' => true];
        return ['success' => false, 'error' => $meta['reason'] ?? 'Gagal suspend akun.'];
    }

    public static function unsuspendAccount(string $user): array {
        $res = self::whmAPI('unsuspendacct', ['user' => $user]);
        if (isset($res['error'])) return ['success' => false, 'error' => $res['error']];
        // Ref: https://api.docs.cpanel.net/openapi/whm/operation/unsuspendacct/
        $meta   = $res['metadata'] ?? [];
        $status = (int)($meta['result'] ?? 0);
        if ($status) return ['success' => true];
        return ['success' => false, 'error' => $meta['reason'] ?? 'Gagal unsuspend akun.'];
    }

    // ===== A2. BUAT AKUN CPANEL BARU =====

    public static function createAccount(string $user, string $domain, string $pass, string $plan = '', string $email = ''): array {
        $params = [
            'username' => $user,
            'domain'   => $domain,
            'password' => $pass,
        ];
        if ($plan  !== '') $params['plan']         = $plan;
        if ($email !== '') $params['contactemail'] = $email;

        $res = self::whmAPI('createacct', $params);
        if (isset($res['error'])) return ['success' => false, 'error' => $res['error']];

        // Ref: https://api.docs.cpanel.net/openapi/whm/operation/createacct/
        // Status ada di metadata.result; IP & nameserver langsung di data (bukan di data.result[0].options)
        $meta   = $res['metadata'] ?? [];
        $status = (int)($meta['result'] ?? 0);
        if ($status) {
            $data = $res['data'] ?? [];
            $ns   = implode(', ', array_filter([
                $data['nameserver']  ?? '',
                $data['nameserver2'] ?? '',
                $data['nameserver3'] ?? '',
                $data['nameserver4'] ?? '',
            ]));
            return ['success' => true, 'ip' => $data['ip'] ?? 'N/A', 'nameservers' => $ns];
        }
        return ['success' => false, 'error' => $meta['reason'] ?? 'Gagal membuat akun.'];
    }

    // ===== A3. HAPUS AKUN CPANEL =====

    public static function removeAccount(string $user, bool $keepdns = false): array {
        $params = ['username' => $user];
        if ($keepdns) $params['keepdns'] = 1;
        $res = self::whmAPI('removeacct', $params);
        if (isset($res['error'])) return ['success' => false, 'error' => $res['error']];
        // Ref: https://api.docs.cpanel.net/openapi/whm/operation/removeacct/
        $meta   = $res['metadata'] ?? [];
        $status = (int)($meta['result'] ?? 0);
        if ($status) return ['success' => true];
        return ['success' => false, 'error' => $meta['reason'] ?? 'Gagal menghapus akun.'];
    }

    // ===== A4. UBAH PASSWORD AKUN CPANEL =====

    public static function changePassword(string $user, string $newPass): array {
        $res = self::whmAPI('passwd', ['user' => $user, 'password' => $newPass]);
        if (isset($res['error'])) return ['success' => false, 'error' => $res['error']];
        // Ref: https://api.docs.cpanel.net/openapi/whm/operation/passwd/
        $meta   = $res['metadata'] ?? [];
        $status = (int)($meta['result'] ?? 0);
        if ($status) return ['success' => true];
        return ['success' => false, 'error' => $meta['reason'] ?? 'Gagal mengubah password.'];
    }

    // ===== A5. RESTART SERVICES =====

    public static function restartService(string $service): array {
        $res = self::whmAPI('restartservice', ['service' => $service]);
        if (isset($res['error'])) return ['success' => false, 'error' => $res['error']];

        // WHM API v1 restartservice menggunakan metadata untuk status hasil
        // Ref: https://api.docs.cpanel.net/openapi/whm/operation/restartservice/
        $meta   = $res['metadata'] ?? [];
        $status = (int)($meta['result'] ?? 0);
        $reason = $meta['reason'] ?? '';

        if ($status) {
            $data    = $res['data'] ?? [];
            $stdout  = trim($data['stdout']    ?? '');
            $statmsg = trim($data['statusmsg'] ?? '');
            $stderr  = trim($data['stderr']    ?? '');
            $output  = $stdout ?: $statmsg;
            if (!$output && $stderr) $output = $stderr;
            return ['success' => true, 'output' => $output];
        }

        $err = $reason ?: 'Gagal restart service.';
        return ['success' => false, 'error' => $err];
    }

    // ===== A6. BANDWIDTH & DISK USAGE =====

    public static function getBandwidth(): array {
        $res = self::whmAPI('showbw');
        if (isset($res['error'])) return ['error' => $res['error']];
        $accts = $res['data']['acct'] ?? [];
        if (!is_array($accts)) return ['error' => 'Format respons bandwidth tidak dikenal.'];
        usort($accts, fn($a, $b) => ($b['totalbytes'] ?? 0) <=> ($a['totalbytes'] ?? 0));
        return array_slice($accts, 0, 10);
    }

    // ===== C3. WHM DASHBOARD =====

    public static function serverDashboard(): array {
        $load   = self::whmAPI('loadavg');
        $ver    = self::whmAPI('version');
        $host   = self::whmAPI('gethostname');
        $accts  = self::listAccounts(1000);

        $loadData = $load['data']  ?? [];
        $verData  = $ver['data']   ?? [];
        $hostData = $host['data']  ?? [];

        $total     = is_array($accts) ? count($accts) : 0;
        $suspended = 0;
        if (is_array($accts)) {
            foreach ($accts as $a) {
                if (!empty($a['suspended'])) $suspended++;
            }
        }

        return [
            'hostname'  => $hostData['hostname'] ?? 'N/A',
            'version'   => $verData['version']   ?? 'N/A',
            'load1'     => $loadData['one']       ?? 'N/A',
            'load5'     => $loadData['five']      ?? 'N/A',
            'load15'    => $loadData['fifteen']   ?? 'N/A',
            'total'     => $total,
            'suspended' => $suspended,
        ];
    }

    // ===== DNS MANAGEMENT =====

    public static function dnsListZones(): array {
        $res = self::whmAPI('listzones');
        if (isset($res['error'])) return ['error' => $res['error']];
        $zones = $res['data']['zone'] ?? [];
        if (!is_array($zones)) return ['error' => 'Format respons zone tidak dikenal.'];
        return $zones;
    }

    public static function dnsGetZone(string $domain): array {
        $res = self::whmAPI('dumpzone', ['domain' => $domain]);
        if (isset($res['error'])) return ['error' => $res['error']];
        $zones   = $res['data']['zone'] ?? [];
        $records = $zones[0]['record'] ?? [];
        if (!is_array($records)) return ['error' => 'Format respons record tidak dikenal.'];
        return $records;
    }

    public static function dnsAddRecord(string $domain, string $type, string $name, string $value, int $ttl = 14400, int $priority = 10): array {
        $type   = strtoupper($type);
        $params = [
            'domain' => $domain,
            'name'   => $name,
            'type'   => $type,
            'ttl'    => $ttl,
            'class'  => 'IN',
        ];
        switch ($type) {
            case 'A':
            case 'AAAA':
                $params['address'] = $value; break;
            case 'CNAME':
                $params['cname']   = $value; break;
            case 'NS':
                $params['nsdname'] = $value; break;
            case 'MX':
                $params['exchange']    = $value;
                $params['preference']  = $priority;
                break;
            case 'TXT':
                $params['txtdata'] = $value; break;
            default:
                $params['address'] = $value;
        }
        $res = self::whmAPI('addzonerecord', $params);
        if (isset($res['error'])) return ['success' => false, 'error' => $res['error']];
        $meta = $res['metadata'] ?? [];
        if (($meta['result'] ?? 0) == 1) return ['success' => true];
        return ['success' => false, 'error' => $meta['reason'] ?? 'Gagal menambah DNS record.'];
    }

    public static function dnsRemoveRecord(string $domain, int $line): array {
        $res = self::whmAPI('removezonerecord', ['domain' => $domain, 'line' => $line]);
        if (isset($res['error'])) return ['success' => false, 'error' => $res['error']];
        $meta = $res['metadata'] ?? [];
        if (($meta['result'] ?? 0) == 1) return ['success' => true];
        return ['success' => false, 'error' => $meta['reason'] ?? 'Gagal menghapus DNS record.'];
    }

    public static function dnsResetZone(string $domain): array {
        $res = self::whmAPI('resetzone', ['domain' => $domain]);
        if (isset($res['error'])) return ['success' => false, 'error' => $res['error']];
        $meta = $res['metadata'] ?? [];
        if (($meta['result'] ?? 0) == 1) return ['success' => true];
        return ['success' => false, 'error' => $meta['reason'] ?? 'Gagal reset DNS zone.'];
    }

    // ===== FITUR FTP via WHM (proxy ke cPanel UAPI) =====

    public static function ftpList(string $cpanelUser): array {
        $res = self::cpanelUAPI($cpanelUser, 'Ftp', 'list_ftp_with_disk');
        if (isset($res['error'])) return ['error' => $res['error']];

        $result = $res['result'] ?? $res['cpanelresult'] ?? [];
        $data   = $result['data'] ?? ($res['data'] ?? []);

        if (is_array($data)) return $data;
        return ['error' => 'Format respons FTP tidak dikenal.'];
    }

    public static function ftpCreate(string $cpanelUser, string $ftpUser, string $ftpPass, string $homedir = '', int $quota = 0): array {
        $params = [
            'login'    => $ftpUser,
            'password' => $ftpPass,
            'quota'    => $quota,
        ];
        if ($homedir !== '') $params['homedir'] = $homedir;

        $res = self::cpanelUAPI($cpanelUser, 'Ftp', 'add_ftp', $params);
        if (isset($res['error'])) return ['success' => false, 'error' => $res['error']];

        $result = $res['result'] ?? $res['cpanelresult'] ?? [];
        $status = $result['status'] ?? 0;
        $errors = $result['errors'] ?? ($result['error'] ?? null);

        if ($status && !$errors) return ['success' => true];
        $errMsg = is_array($errors) ? implode(', ', $errors) : (string)($errors ?? 'Unknown error');
        return ['success' => false, 'error' => $errMsg];
    }

    public static function ftpDelete(string $cpanelUser, string $ftpUser, string $domain): array {
        $res = self::cpanelUAPI($cpanelUser, 'Ftp', 'delete_ftp', [
            'login'  => $ftpUser,
            'domain' => $domain,
        ]);
        if (isset($res['error'])) return ['success' => false, 'error' => $res['error']];

        $result = $res['result'] ?? $res['cpanelresult'] ?? [];
        $status = $result['status'] ?? 0;
        $errors = $result['errors'] ?? null;

        if ($status) return ['success' => true];
        $errMsg = is_array($errors) ? implode(', ', $errors) : (string)($errors ?? 'Unknown error');
        return ['success' => false, 'error' => $errMsg];
    }

    public static function ftpPasswd(string $cpanelUser, string $ftpUser, string $domain, string $newPass): array {
        $res = self::cpanelUAPI($cpanelUser, 'Ftp', 'passwd', [
            'login'    => $ftpUser,
            'domain'   => $domain,
            'password' => $newPass,
        ]);
        if (isset($res['error'])) return ['success' => false, 'error' => $res['error']];

        $result = $res['result'] ?? $res['cpanelresult'] ?? [];
        $status = $result['status'] ?? 0;
        $errors = $result['errors'] ?? null;

        if ($status) return ['success' => true];
        $errMsg = is_array($errors) ? implode(', ', $errors) : (string)($errors ?? 'Unknown error');
        return ['success' => false, 'error' => $errMsg];
    }

    // ===== PACKAGE / PLAN MANAGEMENT =====
    // Ref: https://api.docs.cpanel.net/openapi/whm/operation/listpkgs/

    public static function listPackages(): array {
        $res = self::whmAPI('listpkgs');
        if (isset($res['error'])) return ['error' => $res['error']];
        $pkgs = $res['data']['pkg'] ?? [];
        if (!is_array($pkgs)) return ['error' => 'Format respons package tidak dikenal.'];
        return $pkgs;
    }

    // Ref: https://api.docs.cpanel.net/openapi/whm/operation/addpkg/
    public static function addPackage(string $name, int $quota = 0, int $bwlimit = 0, int $maxftp = 0, int $maxsql = 0, int $maxpop = 0): array {
        $params = [
            'name'    => $name,
            'quota'   => $quota,
            'bwlimit' => $bwlimit,
            'maxftp'  => $maxftp,
            'maxsql'  => $maxsql,
            'maxpop'  => $maxpop,
        ];
        $res = self::whmAPI('addpkg', $params);
        if (isset($res['error'])) return ['success' => false, 'error' => $res['error']];
        $meta = $res['metadata'] ?? [];
        if ((int)($meta['result'] ?? 0)) return ['success' => true];
        return ['success' => false, 'error' => $meta['reason'] ?? 'Gagal membuat package.'];
    }

    // Ref: https://api.docs.cpanel.net/openapi/whm/operation/killpkg/
    public static function removePackage(string $pkgname): array {
        $res = self::whmAPI('killpkg', ['pkgname' => $pkgname]);
        if (isset($res['error'])) return ['success' => false, 'error' => $res['error']];
        $meta = $res['metadata'] ?? [];
        if ((int)($meta['result'] ?? 0)) return ['success' => true];
        return ['success' => false, 'error' => $meta['reason'] ?? 'Gagal menghapus package.'];
    }

    // ===== MODIFIKASI AKUN =====
    // Ref: https://api.docs.cpanel.net/openapi/whm/operation/modifyacct/

    public static function modifyAccount(string $user, array $opts = []): array {
        $allowed = ['plan', 'QUOTA', 'BWLIMIT', 'MAXFTP', 'MAXPOP', 'MAXSQL', 'MAXLST', 'MAXADDON', 'MAXPARK', 'HASSHELL', 'IP', 'LANG'];
        $params  = ['user' => $user];
        foreach ($allowed as $k) {
            if (array_key_exists($k, $opts)) $params[$k] = $opts[$k];
        }
        $res = self::whmAPI('modifyacct', $params);
        if (isset($res['error'])) return ['success' => false, 'error' => $res['error']];
        $meta = $res['metadata'] ?? [];
        if ((int)($meta['result'] ?? 0)) return ['success' => true];
        return ['success' => false, 'error' => $meta['reason'] ?? 'Gagal modifikasi akun.'];
    }

    // ===== DISK INFO SERVER =====
    // Ref: https://api.docs.cpanel.net/openapi/whm/operation/getdiskinfo/
    // Response: data.disk_info[] — fields: disk, mount, blocks, blocks_used, blocks_free,
    //           blocks_percent_used, inodes, inodes_used, inodes_free, inodes_percent_used
    // Semua nilai "blocks" dalam satuan 1K-byte (sama seperti df -k)

    public static function getDiskInfo(): array {
        $res = self::whmAPI('getdiskinfo');
        if (isset($res['error'])) return ['error' => $res['error']];
        $meta = $res['metadata'] ?? [];
        if ((int)($meta['result'] ?? 1) === 0) {
            return ['error' => $meta['reason'] ?? 'Gagal mengambil disk info.'];
        }
        $diskInfo = $res['data']['disk_info'] ?? [];
        if (!is_array($diskInfo) || empty($diskInfo)) {
            return ['error' => 'Tidak ada data disk info dari server.'];
        }
        return $diskInfo;
    }

    // ===== MANAJEMEN IP =====
    // Ref: https://api.docs.cpanel.net/openapi/whm/operation/listips/

    public static function listIPs(): array {
        $res = self::whmAPI('listips');
        if (isset($res['error'])) return ['error' => $res['error']];
        $ips = $res['data']['ip'] ?? [];
        if (!is_array($ips)) return ['error' => 'Format respons IP tidak dikenal.'];
        return $ips;
    }

    // ===== MANAJEMEN RESELLER =====
    // Ref: https://api.docs.cpanel.net/openapi/whm/operation/listresellers/

    public static function listResellers(): array {
        $res = self::whmAPI('listresellers');
        if (isset($res['error'])) return ['error' => $res['error']];
        // Returns data.reseller[] or data as flat list
        $list = $res['data']['reseller'] ?? ($res['data'] ?? []);
        if (!is_array($list)) return ['error' => 'Format respons reseller tidak dikenal.'];
        return $list;
    }

    // Ref: https://api.docs.cpanel.net/openapi/whm/operation/makereseller/
    public static function makeReseller(string $user, bool $makeOwner = false): array {
        $params = ['user' => $user];
        if ($makeOwner) $params['makeowner'] = 1;
        $res = self::whmAPI('makereseller', $params);
        if (isset($res['error'])) return ['success' => false, 'error' => $res['error']];
        $meta = $res['metadata'] ?? [];
        if ((int)($meta['result'] ?? 0)) return ['success' => true];
        return ['success' => false, 'error' => $meta['reason'] ?? 'Gagal menjadikan reseller.'];
    }

    // Ref: https://api.docs.cpanel.net/openapi/whm/operation/unsetupreseller/
    public static function removeReseller(string $user): array {
        $res = self::whmAPI('unsetupreseller', ['user' => $user]);
        if (isset($res['error'])) return ['success' => false, 'error' => $res['error']];
        $meta = $res['metadata'] ?? [];
        if ((int)($meta['result'] ?? 0)) return ['success' => true];
        return ['success' => false, 'error' => $meta['reason'] ?? 'Gagal menghapus reseller.'];
    }
}

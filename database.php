<?php
/**
 * Database Handler — SQLite via PDO
 * Tidak memerlukan library eksternal
 */

class Database {
    private $db;
    private static $instance = null;

    private function __construct() {
        $dir = dirname(DB_PATH);
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        $this->db = new PDO('sqlite:' . DB_PATH);
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('PRAGMA journal_mode=WAL');
        $this->db->exec('PRAGMA foreign_keys=ON');
        $this->db->exec('PRAGMA synchronous=NORMAL');
        $this->db->exec('PRAGMA cache_size=-8000');
        $this->db->exec('PRAGMA temp_store=MEMORY');
        $this->db->exec('PRAGMA mmap_size=134217728');
        // Jika ada write lock (concurrent request), tunggu maks 5 detik sebelum throw exception.
        // Tanpa ini, SQLite langsung error "database is locked" pada request concurrent pertama.
        $this->db->exec('PRAGMA busy_timeout=5000');
        $this->createTables();
        $this->migrate();
        $this->createIndexes();
    }

    public static function getInstance(): self {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    // ===================== BUAT TABEL =====================

    private function createTables(): void {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS group_settings (
                chat_id INTEGER PRIMARY KEY,
                title TEXT DEFAULT '',
                username TEXT DEFAULT '',
                welcome_enabled INTEGER DEFAULT 1,
                welcome_message TEXT DEFAULT 'Selamat datang {mention} di <b>{group}</b>! 👋',
                welcome_media TEXT DEFAULT '',
                welcome_buttons TEXT DEFAULT '[]',
                goodbye_enabled INTEGER DEFAULT 0,
                goodbye_message TEXT DEFAULT 'Sampai jumpa {name}! 👋',
                antiflood_enabled INTEGER DEFAULT 1,
                antiflood_limit INTEGER DEFAULT 5,
                antiflood_action TEXT DEFAULT 'mute',
                antiflood_duration INTEGER DEFAULT 300,
                antilink_enabled INTEGER DEFAULT 0,
                antilink_whitelist TEXT DEFAULT '[]',
                antispam_enabled INTEGER DEFAULT 1,
                word_filter_enabled INTEGER DEFAULT 0,
                banned_words TEXT DEFAULT '[]',
                captcha_enabled INTEGER DEFAULT 0,
                captcha_timeout INTEGER DEFAULT 60,
                anti_forward INTEGER DEFAULT 0,
                anti_bot INTEGER DEFAULT 0,
                anti_raid INTEGER DEFAULT 0,
                raid_limit INTEGER DEFAULT 10,
                rules_btn_enabled INTEGER DEFAULT 0,
                lock_sticker INTEGER DEFAULT 0,
                lock_gif INTEGER DEFAULT 0,
                lock_media INTEGER DEFAULT 0,
                lock_voice INTEGER DEFAULT 0,
                lock_game INTEGER DEFAULT 0,
                lock_poll INTEGER DEFAULT 0,
                clean_service INTEGER DEFAULT 0,
                log_channel TEXT DEFAULT '',
                rules TEXT DEFAULT '',
                ro_mode INTEGER DEFAULT 0,
                warn_limit INTEGER DEFAULT 3,
                warn_action TEXT DEFAULT 'kick',
                created_at INTEGER DEFAULT (strftime('%s','now')),
                updated_at INTEGER DEFAULT (strftime('%s','now'))
            );

            CREATE TABLE IF NOT EXISTS warnings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                chat_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                warned_by INTEGER NOT NULL,
                reason TEXT DEFAULT 'Tidak ada alasan',
                warned_at INTEGER DEFAULT (strftime('%s','now'))
            );

            CREATE TABLE IF NOT EXISTS bans (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                chat_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                banned_by INTEGER NOT NULL,
                reason TEXT DEFAULT 'Tidak ada alasan',
                expires_at INTEGER DEFAULT 0,
                banned_at INTEGER DEFAULT (strftime('%s','now')),
                UNIQUE(chat_id, user_id)
            );

            CREATE TABLE IF NOT EXISTS mutes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                chat_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                muted_by INTEGER NOT NULL,
                reason TEXT DEFAULT 'Tidak ada alasan',
                expires_at INTEGER DEFAULT 0,
                muted_at INTEGER DEFAULT (strftime('%s','now')),
                UNIQUE(chat_id, user_id)
            );

            CREATE TABLE IF NOT EXISTS flood_tracker (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                chat_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                count INTEGER DEFAULT 1,
                window_start INTEGER DEFAULT (strftime('%s','now')),
                UNIQUE(chat_id, user_id)
            );

            CREATE TABLE IF NOT EXISTS join_tracker (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                chat_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                joined_at INTEGER DEFAULT (strftime('%s','now'))
            );

            CREATE TABLE IF NOT EXISTS statistics (
                chat_id INTEGER PRIMARY KEY,
                total_messages INTEGER DEFAULT 0,
                total_members INTEGER DEFAULT 0,
                total_banned INTEGER DEFAULT 0,
                total_warned INTEGER DEFAULT 0,
                total_muted INTEGER DEFAULT 0,
                updated_at INTEGER DEFAULT (strftime('%s','now'))
            );

            CREATE TABLE IF NOT EXISTS saved_filters (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                chat_id INTEGER NOT NULL,
                keyword TEXT NOT NULL,
                response TEXT NOT NULL,
                added_by INTEGER NOT NULL,
                added_at INTEGER DEFAULT (strftime('%s','now')),
                UNIQUE(chat_id, keyword)
            );

            CREATE TABLE IF NOT EXISTS group_notes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                chat_id INTEGER NOT NULL,
                keyword TEXT NOT NULL,
                content TEXT NOT NULL,
                file_id TEXT DEFAULT '',
                file_type TEXT DEFAULT '',
                added_by INTEGER NOT NULL,
                added_at INTEGER DEFAULT (strftime('%s','now')),
                UNIQUE(chat_id, keyword)
            );

            CREATE TABLE IF NOT EXISTS global_bans (
                user_id INTEGER PRIMARY KEY,
                banned_by INTEGER NOT NULL,
                reason TEXT DEFAULT 'Tidak ada alasan',
                banned_at INTEGER DEFAULT (strftime('%s','now'))
            );

            CREATE TABLE IF NOT EXISTS captcha_pending (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                chat_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                answer INTEGER NOT NULL,
                message_id INTEGER NOT NULL,
                expires_at INTEGER NOT NULL,
                first_name TEXT DEFAULT '',
                hcaptcha_token TEXT DEFAULT '',
                dm_message_id INTEGER DEFAULT 0,
                UNIQUE(chat_id, user_id)
            );

            CREATE TABLE IF NOT EXISTS broadcast_history (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                sent_by INTEGER NOT NULL,
                message TEXT NOT NULL,
                success_count INTEGER DEFAULT 0,
                fail_count INTEGER DEFAULT 0,
                sent_at INTEGER DEFAULT (strftime('%s','now'))
            );

            CREATE TABLE IF NOT EXISTS broadcast_queue (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                sent_by INTEGER NOT NULL,
                message TEXT DEFAULT '',
                media_type TEXT DEFAULT NULL,
                file_id TEXT DEFAULT NULL,
                caption TEXT DEFAULT '',
                scheduled_at INTEGER NOT NULL,
                status TEXT DEFAULT 'pending',
                created_at INTEGER DEFAULT (strftime('%s','now'))
            );

            CREATE TABLE IF NOT EXISTS member_verifications (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                chat_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                first_name TEXT DEFAULT '',
                username TEXT DEFAULT '',
                phone_number TEXT DEFAULT '',
                latitude REAL DEFAULT 0,
                longitude REAL DEFAULT 0,
                verified_at INTEGER DEFAULT (strftime('%s','now')),
                UNIQUE(chat_id, user_id)
            );

            CREATE TABLE IF NOT EXISTS pending_input (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                chat_id INTEGER NOT NULL DEFAULT 0,
                action TEXT NOT NULL,
                msg_id INTEGER DEFAULT 0,
                settings_msg_id INTEGER DEFAULT 0,
                extra TEXT DEFAULT '{}',
                created_at INTEGER DEFAULT (strftime('%s','now')),
                UNIQUE(user_id)
            );

            CREATE TABLE IF NOT EXISTS admin_cache (
                chat_id INTEGER PRIMARY KEY,
                admin_ids TEXT DEFAULT '[]',
                cached_at INTEGER DEFAULT (strftime('%s','now'))
            );

            CREATE TABLE IF NOT EXISTS spam_tracker (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                chat_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                msg_hash TEXT NOT NULL,
                sent_at INTEGER DEFAULT (strftime('%s','now')),
                UNIQUE(chat_id, user_id, msg_hash)
            );

            CREATE TABLE IF NOT EXISTS user_cache (
                user_id INTEGER PRIMARY KEY,
                username TEXT DEFAULT '',
                first_name TEXT DEFAULT '',
                last_name TEXT DEFAULT '',
                updated_at INTEGER DEFAULT (strftime('%s','now'))
            );

            CREATE TABLE IF NOT EXISTS violation_points (
                chat_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                points INTEGER DEFAULT 1,
                last_violation_at INTEGER DEFAULT (strftime('%s','now')),
                PRIMARY KEY (chat_id, user_id)
            );

            CREATE TABLE IF NOT EXISTS ai_conversations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                chat_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                role TEXT NOT NULL,
                content TEXT NOT NULL,
                created_at INTEGER DEFAULT (strftime('%s','now'))
            );

            CREATE TABLE IF NOT EXISTS votekick_sessions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                chat_id INTEGER NOT NULL,
                target_id INTEGER NOT NULL,
                target_name TEXT DEFAULT '',
                initiator_id INTEGER NOT NULL,
                reason TEXT DEFAULT 'Tidak ada alasan',
                message_id INTEGER DEFAULT 0,
                yes_count INTEGER DEFAULT 0,
                no_count INTEGER DEFAULT 0,
                status TEXT DEFAULT 'active',
                threshold INTEGER DEFAULT 5,
                expires_at INTEGER NOT NULL,
                created_at INTEGER DEFAULT (strftime('%s','now'))
            );

            CREATE TABLE IF NOT EXISTS votekick_votes (
                session_id INTEGER NOT NULL,
                voter_id INTEGER NOT NULL,
                vote TEXT NOT NULL,
                voted_at INTEGER DEFAULT (strftime('%s','now')),
                PRIMARY KEY (session_id, voter_id)
            );

            CREATE TABLE IF NOT EXISTS dynamic_superadmins (
                user_id INTEGER PRIMARY KEY,
                added_by INTEGER NOT NULL,
                note TEXT DEFAULT '',
                added_at INTEGER DEFAULT (strftime('%s','now'))
            );

            CREATE TABLE IF NOT EXISTS bot_settings (
                key TEXT PRIMARY KEY,
                value TEXT DEFAULT ''
            );

            CREATE TABLE IF NOT EXISTS user_admin_groups (
                user_id INTEGER NOT NULL,
                chat_id INTEGER NOT NULL,
                updated_at INTEGER DEFAULT (strftime('%s','now')),
                PRIMARY KEY (user_id, chat_id)
            );

            CREATE TABLE IF NOT EXISTS email_accounts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email TEXT NOT NULL UNIQUE,
                email_user TEXT NOT NULL,
                email_domain TEXT NOT NULL,
                email_password TEXT NOT NULL,
                quota INTEGER DEFAULT 500,
                label TEXT DEFAULT '',
                added_by INTEGER NOT NULL,
                added_at INTEGER DEFAULT (strftime('%s','now'))
            );

            CREATE TABLE IF NOT EXISTS username_history (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                old_username TEXT DEFAULT '',
                new_username TEXT DEFAULT '',
                changed_at INTEGER DEFAULT (strftime('%s','now'))
            );

            CREATE TABLE IF NOT EXISTS message_log (
                chat_id INTEGER NOT NULL,
                message_id INTEGER NOT NULL,
                sent_at INTEGER DEFAULT (strftime('%s','now')),
                PRIMARY KEY (chat_id, message_id)
            );

            CREATE TABLE IF NOT EXISTS processed_updates (
                update_id INTEGER PRIMARY KEY,
                processed_at INTEGER DEFAULT (strftime('%s','now'))
            );
        ");
    }

    // Migrasi: tambah kolom baru jika belum ada (untuk DB lama)
    private function migrate(): void {
        // Migrasi kolom group_settings
        $newCols = [
            'welcome_buttons'    => "TEXT DEFAULT '[]'",
            'anti_forward'       => 'INTEGER DEFAULT 0',
            'anti_bot'           => 'INTEGER DEFAULT 0',
            'anti_raid'          => 'INTEGER DEFAULT 0',
            'raid_limit'         => 'INTEGER DEFAULT 10',
            'lock_sticker'       => 'INTEGER DEFAULT 0',
            'lock_gif'           => 'INTEGER DEFAULT 0',
            'lock_media'         => 'INTEGER DEFAULT 0',
            'lock_voice'         => 'INTEGER DEFAULT 0',
            'lock_game'          => 'INTEGER DEFAULT 0',
            'lock_poll'          => 'INTEGER DEFAULT 0',
            'clean_service'      => 'INTEGER DEFAULT 0',
            'antilink_whitelist' => "TEXT DEFAULT '[]'",
            'antiflood_duration' => 'INTEGER DEFAULT 300',
            'captcha_timeout'    => 'INTEGER DEFAULT 60',
            'violation_enabled'   => 'INTEGER DEFAULT 0',
            'violation_limit'     => 'INTEGER DEFAULT 5',
            'violation_action'    => "TEXT DEFAULT 'kick'",
            'votekick_enabled'    => 'INTEGER DEFAULT 0',
            'votekick_threshold'  => 'INTEGER DEFAULT 5',
            'votekick_duration'   => 'INTEGER DEFAULT 5',
            'votekick_mode'       => 'INTEGER DEFAULT 0',
            'votekick_percent'    => 'INTEGER DEFAULT 10',
            'votekick_admin_only' => 'INTEGER DEFAULT 0',
            'votekick_cooldown'   => 'INTEGER DEFAULT 0',
            'ai_enabled'          => 'INTEGER DEFAULT 0',
            'rules_btn_enabled'      => 'INTEGER DEFAULT 0',
            'language'               => "TEXT DEFAULT 'id'",
            'sang_mata_enabled'      => 'INTEGER DEFAULT 0',
            'captcha_type'           => "TEXT DEFAULT 'math'",
            'require_verification'   => 'INTEGER DEFAULT 0',
        ];
        foreach ($newCols as $col => $def) {
            try {
                $this->db->exec("ALTER TABLE group_settings ADD COLUMN $col $def");
            } catch (PDOException $e) {
                // Kolom sudah ada, abaikan
            }
        }

        // Migrasi kolom captcha_pending
        try {
            $this->db->exec("ALTER TABLE captcha_pending ADD COLUMN first_name TEXT DEFAULT ''");
        } catch (PDOException $e) {}
        try {
            $this->db->exec("ALTER TABLE captcha_pending ADD COLUMN hcaptcha_token TEXT DEFAULT ''");
        } catch (PDOException $e) {}
        try {
            $this->db->exec("ALTER TABLE captcha_pending ADD COLUMN dm_message_id INTEGER DEFAULT 0");
        } catch (PDOException $e) {}

        // Migrasi: buat tabel user_admin_groups jika belum ada (DB lama)
        try {
            $this->db->exec("CREATE TABLE IF NOT EXISTS user_admin_groups (
                user_id INTEGER NOT NULL,
                chat_id INTEGER NOT NULL,
                updated_at INTEGER DEFAULT (strftime('%s','now')),
                PRIMARY KEY (user_id, chat_id)
            )");
        } catch (PDOException $e) {}


        // Migrasi: tambah tabel dynamic_superadmins jika belum ada
        try {
            $this->db->exec("CREATE TABLE IF NOT EXISTS dynamic_superadmins (
                user_id INTEGER PRIMARY KEY,
                added_by INTEGER NOT NULL,
                note TEXT DEFAULT '',
                added_at INTEGER DEFAULT (strftime('%s','now'))
            )");
        } catch (PDOException $e) {}

        // Migrasi: tambah tabel bot_settings jika belum ada
        try {
            $this->db->exec("CREATE TABLE IF NOT EXISTS bot_settings (
                key TEXT PRIMARY KEY,
                value TEXT DEFAULT ''
            )");
        } catch (PDOException $e) {}

        // Migrasi: tambah tabel email_accounts jika belum ada
        try {
            $this->db->exec("CREATE TABLE IF NOT EXISTS email_accounts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email TEXT NOT NULL UNIQUE,
                email_user TEXT NOT NULL,
                email_domain TEXT NOT NULL,
                email_password TEXT NOT NULL,
                quota INTEGER DEFAULT 500,
                label TEXT DEFAULT '',
                added_by INTEGER NOT NULL,
                added_at INTEGER DEFAULT (strftime('%s','now'))
            )");
        } catch (PDOException $e) {}

        // Migrasi: tambah tabel username_history untuk fitur Sang Mata
        try {
            $this->db->exec("CREATE TABLE IF NOT EXISTS username_history (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                old_username TEXT DEFAULT '',
                new_username TEXT DEFAULT '',
                changed_at INTEGER DEFAULT (strftime('%s','now'))
            )");
        } catch (PDOException $e) {}

        // Migrasi: tambah tabel message_log untuk clearall/purge tracking
        try {
            $this->db->exec("CREATE TABLE IF NOT EXISTS message_log (
                chat_id INTEGER NOT NULL,
                message_id INTEGER NOT NULL,
                user_id INTEGER DEFAULT 0,
                sent_at INTEGER DEFAULT (strftime('%s','now')),
                PRIMARY KEY (chat_id, message_id)
            )");
        } catch (PDOException $e) {}

        // Migrasi: tambah kolom user_id ke message_log jika belum ada (DB lama)
        try {
            $this->db->exec("ALTER TABLE message_log ADD COLUMN user_id INTEGER DEFAULT 0");
        } catch (PDOException $e) {}

        // Migrasi: tambah tabel img_usage untuk tracking limit gambar per user per hari
        try {
            $this->db->exec("CREATE TABLE IF NOT EXISTS img_usage (
                user_id INTEGER NOT NULL,
                date TEXT NOT NULL,
                count INTEGER DEFAULT 0,
                PRIMARY KEY (user_id, date)
            )");
        } catch (PDOException $e) {}
    }

    // Buat index untuk query yang sering dipakai (idempotent - IF NOT EXISTS)
    private function createIndexes(): void {
        $indexes = [
            "CREATE INDEX IF NOT EXISTS idx_warnings_chat_user       ON warnings(chat_id, user_id)",
            "CREATE INDEX IF NOT EXISTS idx_warnings_warned_at        ON warnings(warned_at)",
            "CREATE INDEX IF NOT EXISTS idx_join_tracker_chat         ON join_tracker(chat_id)",
            "CREATE INDEX IF NOT EXISTS idx_join_tracker_chat_time    ON join_tracker(chat_id, joined_at)",
            "CREATE INDEX IF NOT EXISTS idx_ai_conv_user_chat         ON ai_conversations(user_id, chat_id)",
            "CREATE INDEX IF NOT EXISTS idx_ai_conv_created           ON ai_conversations(created_at)",
            "CREATE INDEX IF NOT EXISTS idx_mutes_expires             ON mutes(expires_at)",
            "CREATE INDEX IF NOT EXISTS idx_bans_expires              ON bans(expires_at)",
            "CREATE INDEX IF NOT EXISTS idx_user_cache_username       ON user_cache(username)",
            "CREATE INDEX IF NOT EXISTS idx_votekick_chat_status      ON votekick_sessions(chat_id, status)",
            "CREATE INDEX IF NOT EXISTS idx_votekick_expires          ON votekick_sessions(expires_at, status)",
            "CREATE INDEX IF NOT EXISTS idx_filters_chat              ON saved_filters(chat_id)",
            "CREATE INDEX IF NOT EXISTS idx_notes_chat                ON group_notes(chat_id)",
            "CREATE INDEX IF NOT EXISTS idx_user_admin_groups_user    ON user_admin_groups(user_id)",
            "CREATE INDEX IF NOT EXISTS idx_message_log_chat_time     ON message_log(chat_id, sent_at)",
            "CREATE INDEX IF NOT EXISTS idx_message_log_chat_user     ON message_log(chat_id, user_id, sent_at)",
            // Index untuk query cron yang berjalan tiap menit — tanpa ini = full table scan
            "CREATE INDEX IF NOT EXISTS idx_captcha_expires           ON captcha_pending(expires_at)",
            "CREATE INDEX IF NOT EXISTS idx_proc_updates_time         ON processed_updates(processed_at)",
            "CREATE INDEX IF NOT EXISTS idx_broadcast_queue_sched     ON broadcast_queue(status, scheduled_at)",
            // Index untuk spam_tracker agar filter sent_at bisa pakai partial index setelah UNIQUE lookup
            "CREATE INDEX IF NOT EXISTS idx_spam_tracker_time         ON spam_tracker(chat_id, user_id, sent_at)",
        ];
        foreach ($indexes as $sql) {
            try { $this->db->exec($sql); } catch (PDOException $e) {}
        }
    }

    // ===================== QUERY HELPERS =====================

    public function query(string $sql, array $params = []): array {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function execute(string $sql, array $params = []): int {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int)$this->db->lastInsertId();
    }

    public function fetchOne(string $sql, array $params = []): ?array {
        $result = $this->query($sql, $params);
        return $result[0] ?? null;
    }

    // ===================== GROUP SETTINGS =====================

    /**
     * Cache settings per chat_id dalam satu request PHP (static array).
     * Mencegah repeated SELECT ke SQLite untuk grup yang sama dalam 1 webhook call.
     * Cache otomatis reset karena PHP adalah stateless per-request.
     */
    private static array $_settingsCache = [];

    public function getGroupSettings(int $chatId): array {
        if (isset(self::$_settingsCache[$chatId])) {
            return self::$_settingsCache[$chatId];
        }
        $settings = $this->fetchOne("SELECT * FROM group_settings WHERE chat_id = ?", [$chatId]);
        if (!$settings) {
            $this->execute("INSERT OR IGNORE INTO group_settings (chat_id) VALUES (?)", [$chatId]);
            $settings = $this->fetchOne("SELECT * FROM group_settings WHERE chat_id = ?", [$chatId]);
        }
        $result = $settings ?? [];
        self::$_settingsCache[$chatId] = $result;
        return $result;
    }

    public function updateGroupSetting(int $chatId, string $key, $value): void {
        $this->execute(
            "INSERT INTO group_settings (chat_id, $key, updated_at) VALUES (?, ?, strftime('%s','now'))
             ON CONFLICT(chat_id) DO UPDATE SET $key = excluded.$key, updated_at = strftime('%s','now')",
            [$chatId, $value]
        );
        // Invalidasi cache agar pembacaan berikutnya mengambil data terbaru
        unset(self::$_settingsCache[$chatId]);
    }

    // ===================== MESSAGE LOG (untuk clearall/purge) =====================

    /**
     * Catat message_id yang masuk ke grup.
     * Metode ini sama dengan teknik Rose Bot: melacak ID pesan per chat.
     * Telegram hanya mengizinkan hapus pesan < 48 jam → log dibatasi 48 jam.
     */
    public function logMessageId(int $chatId, int $messageId, int $userId = 0): void {
        // Sisipkan message_id ke log beserta user_id pengirim.
        // Cleanup entri lama (>48 jam) ditangani oleh cron.php setiap menit
        // — tidak dilakukan di sini agar tidak menyebabkan write spike di grup aktif.
        $this->execute(
            "INSERT OR IGNORE INTO message_log (chat_id, message_id, user_id, sent_at) VALUES (?, ?, ?, ?)",
            [$chatId, $messageId, $userId, time()]
        );
    }

    /**
     * Ambil daftar message_id yang tercatat untuk sebuah grup,
     * dibatasi 48 jam terakhir (Telegram tidak bisa hapus pesan lebih lama).
     * Hasilnya diurutkan dari ID terbesar (terbaru) ke terkecil.
     */
    public function getLoggedMessageIds(int $chatId, int $limit = 4000): array {
        $cutoff = time() - 172800; // 48 jam
        $rows = $this->query(
            "SELECT message_id FROM message_log WHERE chat_id = ? AND sent_at >= ? ORDER BY message_id DESC LIMIT ?",
            [$chatId, $cutoff, $limit]
        );
        return array_column($rows, 'message_id');
    }

    /**
     * Ambil daftar message_id dari user tertentu dalam 48 jam terakhir.
     * Digunakan untuk fitur /purge @username — hapus semua pesan dari user tertentu.
     */
    public function getLoggedMessageIdsByUser(int $chatId, int $userId, int $limit = 1000): array {
        $cutoff = time() - 172800; // 48 jam
        $rows = $this->query(
            "SELECT message_id FROM message_log WHERE chat_id = ? AND user_id = ? AND sent_at >= ? ORDER BY message_id DESC LIMIT ?",
            [$chatId, $userId, $cutoff, $limit]
        );
        return array_column($rows, 'message_id');
    }

    /**
     * Hapus semua entri log untuk sebuah grup (dipanggil setelah clearall selesai).
     */
    public function clearMessageLog(int $chatId): void {
        $this->execute("DELETE FROM message_log WHERE chat_id = ?", [$chatId]);
    }

    /**
     * Hitung berapa pesan yang tercatat dalam 48 jam terakhir di grup.
     */
    public function countLoggedMessages(int $chatId): int {
        $cutoff = time() - 172800;
        $r = $this->fetchOne(
            "SELECT COUNT(*) as cnt FROM message_log WHERE chat_id = ? AND sent_at >= ?",
            [$chatId, $cutoff]
        );
        return (int)($r['cnt'] ?? 0);
    }

    // ===================== WARNINGS =====================

    public function getWarnCount(int $chatId, int $userId): int {
        $r = $this->fetchOne("SELECT COUNT(*) as cnt FROM warnings WHERE chat_id = ? AND user_id = ?", [$chatId, $userId]);
        return (int)($r['cnt'] ?? 0);
    }

    public function addWarn(int $chatId, int $userId, int $warnedBy, string $reason): int {
        $this->execute("INSERT INTO warnings (chat_id, user_id, warned_by, reason) VALUES (?, ?, ?, ?)", [$chatId, $userId, $warnedBy, $reason]);
        return $this->getWarnCount($chatId, $userId);
    }

    public function removeWarn(int $chatId, int $userId): int {
        $this->execute("DELETE FROM warnings WHERE id = (SELECT id FROM warnings WHERE chat_id = ? AND user_id = ? ORDER BY warned_at DESC LIMIT 1)", [$chatId, $userId]);
        return $this->getWarnCount($chatId, $userId);
    }

    public function clearWarns(int $chatId, int $userId): void {
        $this->execute("DELETE FROM warnings WHERE chat_id = ? AND user_id = ?", [$chatId, $userId]);
    }

    public function getWarnList(int $chatId, int $userId): array {
        return $this->query("SELECT reason, warned_at FROM warnings WHERE chat_id = ? AND user_id = ? ORDER BY warned_at DESC LIMIT 10", [$chatId, $userId]);
    }

    // ===================== FLOOD =====================

    public function checkFlood(int $chatId, int $userId, int $limit): bool {
        $now   = time();
        // Cleanup flood_tracker lama ditangani oleh cron.php setiap menit (DELETE WHERE window_start < now-300)
        // Tidak dilakukan di sini agar tidak ada write tambahan di setiap pesan.
        $flood = $this->fetchOne("SELECT * FROM flood_tracker WHERE chat_id = ? AND user_id = ?", [$chatId, $userId]);

        if (!$flood) {
            $this->execute("INSERT INTO flood_tracker (chat_id, user_id, count, window_start) VALUES (?, ?, 1, ?)", [$chatId, $userId, $now]);
            return false;
        }

        if ($flood['window_start'] < $now - 60) {
            $this->execute("UPDATE flood_tracker SET count = 1, window_start = ? WHERE chat_id = ? AND user_id = ?", [$now, $chatId, $userId]);
            return false;
        }

        $newCount = (int)$flood['count'] + 1;
        $this->execute("UPDATE flood_tracker SET count = ? WHERE chat_id = ? AND user_id = ?", [$newCount, $chatId, $userId]);
        return $newCount >= $limit;
    }

    // ===================== ANTI-RAID =====================

    public function checkRaid(int $chatId, int $userId, int $raidLimit): bool {
        $now = time();
        // Cleanup join_tracker lama ditangani cron.php setiap menit.
        // Di sini hanya INSERT + COUNT dalam window 60 detik → 2 operasi, bukan 3.
        // Menggunakan idx_join_tracker_chat_time(chat_id, joined_at) untuk COUNT cepat.
        $this->execute(
            "INSERT INTO join_tracker (chat_id, user_id, joined_at) VALUES (?, ?, ?)",
            [$chatId, $userId, $now]
        );
        $r = $this->fetchOne(
            "SELECT COUNT(*) as cnt FROM join_tracker WHERE chat_id = ? AND joined_at >= ?",
            [$chatId, $now - 60]
        );
        return (int)($r['cnt'] ?? 0) >= $raidLimit;
    }

    // ===================== NOTES =====================

    public function saveNote(int $chatId, string $keyword, string $content, string $fileId, string $fileType, int $addedBy): void {
        $this->execute(
            "INSERT OR REPLACE INTO group_notes (chat_id, keyword, content, file_id, file_type, added_by) VALUES (?, ?, ?, ?, ?, ?)",
            [$chatId, strtolower($keyword), $content, $fileId, $fileType, $addedBy]
        );
    }

    public function getNote(int $chatId, string $keyword): ?array {
        return $this->fetchOne("SELECT * FROM group_notes WHERE chat_id = ? AND keyword = ?", [$chatId, strtolower($keyword)]);
    }

    public function getNotes(int $chatId): array {
        return $this->query("SELECT keyword, content FROM group_notes WHERE chat_id = ? ORDER BY keyword", [$chatId]);
    }

    public function deleteNote(int $chatId, string $keyword): bool {
        $this->execute("DELETE FROM group_notes WHERE chat_id = ? AND keyword = ?", [$chatId, strtolower($keyword)]);
        return true;
    }

    // ===================== GLOBAL BAN =====================

    public function isGbanned(int $userId): bool {
        return (bool)$this->fetchOne("SELECT user_id FROM global_bans WHERE user_id = ?", [$userId]);
    }

    public function addGban(int $userId, int $bannedBy, string $reason): void {
        $this->execute("INSERT OR REPLACE INTO global_bans (user_id, banned_by, reason) VALUES (?, ?, ?)", [$userId, $bannedBy, $reason]);
    }

    public function removeGban(int $userId): void {
        $this->execute("DELETE FROM global_bans WHERE user_id = ?", [$userId]);
    }

    public function getGbans(int $limit = 50): array {
        return $this->query("SELECT * FROM global_bans ORDER BY banned_at DESC LIMIT ?", [$limit]);
    }

    public function getGbanCount(): int {
        $r = $this->fetchOne("SELECT COUNT(*) as cnt FROM global_bans");
        return (int)($r['cnt'] ?? 0);
    }

    // ===================== STATS =====================

    public function incrementStats(int $chatId, string $field): void {
        $this->execute(
            "INSERT INTO statistics (chat_id, $field) VALUES (?, 1)
             ON CONFLICT(chat_id) DO UPDATE SET $field = $field + 1, updated_at = strftime('%s','now')",
            [$chatId]
        );
    }

    public function getStats(int $chatId): array {
        return $this->fetchOne("SELECT * FROM statistics WHERE chat_id = ?", [$chatId])
            ?? ['total_messages' => 0, 'total_banned' => 0, 'total_warned' => 0, 'total_muted' => 0, 'total_members' => 0];
    }

    // ===================== FILTERS =====================

    public function getFilters(int $chatId): array {
        return $this->query("SELECT keyword, response FROM saved_filters WHERE chat_id = ?", [$chatId]);
    }

    // ===================== PENDING INPUT =====================

    public function setPendingInput(int $userId, int $chatId, string $action, int $msgId = 0, int $settingsMsgId = 0, array $extra = []): void {
        $this->execute(
            "INSERT OR REPLACE INTO pending_input (user_id, chat_id, action, msg_id, settings_msg_id, extra, created_at)
             VALUES (?, ?, ?, ?, ?, ?, strftime('%s','now'))",
            [$userId, $chatId, $action, $msgId, $settingsMsgId, json_encode($extra)]
        );
    }

    public function getPendingInput(int $userId): ?array {
        $r = $this->fetchOne("SELECT * FROM pending_input WHERE user_id = ?", [$userId]);
        if ($r) {
            // Verifikasi kontak+lokasi butuh waktu lebih lama — beri 1 jam
            $timeout = str_starts_with((string)($r['action'] ?? ''), 'verify_') ? 3600 : 300;
            if (time() - (int)$r['created_at'] > $timeout) {
                $this->clearPendingInput($userId);
                return null;
            }
            $r['extra'] = json_decode($r['extra'] ?? '{}', true) ?? [];
        }
        return $r ?: null;
    }

    public function clearPendingInput(int $userId): void {
        $this->execute("DELETE FROM pending_input WHERE user_id = ?", [$userId]);
    }

    // ===================== ADMIN CACHE =====================

    /**
     * Ambil daftar admin dari cache (max 5 menit). Null = cache expired/kosong.
     */
    public function getCachedAdmins(int $chatId): ?array {
        $row = $this->fetchOne("SELECT admin_ids, cached_at FROM admin_cache WHERE chat_id = ?", [$chatId]);
        if (!$row || (time() - (int)$row['cached_at']) > 300) return null;
        return json_decode($row['admin_ids'] ?? '[]', true) ?: [];
    }

    public function setCachedAdmins(int $chatId, array $adminIds): void {
        $this->execute(
            "INSERT OR REPLACE INTO admin_cache (chat_id, admin_ids, cached_at) VALUES (?, ?, ?)",
            [$chatId, json_encode(array_values($adminIds)), time()]
        );
    }

    public function invalidateAdminCache(int $chatId): void {
        $this->execute("DELETE FROM admin_cache WHERE chat_id = ?", [$chatId]);
    }

    // ===================== SPAM TRACKER =====================

    /**
     * Cek apakah pesan ini sudah dikirim user yang sama dalam 60 detik terakhir.
     * Return true = spam (duplikat).
     */
    public function checkSpam(int $chatId, int $userId, string $text): bool {
        if (empty(trim($text))) return false;
        $hash   = md5(strtolower(trim($text)));
        $now    = time();
        $cutoff = $now - 60; // Window deteksi spam: 60 detik

        // Cleanup spam_tracker lama ditangani oleh cron.php setiap menit.
        // Di sini hanya SELECT (1 read) + INSERT (1 write) tanpa DELETE per pesan.

        // Cek apakah hash yang sama sudah dikirim dalam 60 detik terakhir
        $exists = $this->fetchOne(
            "SELECT id FROM spam_tracker WHERE chat_id = ? AND user_id = ? AND msg_hash = ? AND sent_at >= ?",
            [$chatId, $userId, $hash, $cutoff]
        );
        if ($exists) return true;

        // Simpan hash baru (INSERT OR IGNORE mencegah duplikat jika ada race condition)
        $this->execute(
            "INSERT OR IGNORE INTO spam_tracker (chat_id, user_id, msg_hash, sent_at) VALUES (?, ?, ?, ?)",
            [$chatId, $userId, $hash, $now]
        );
        return false;
    }

    // ===================== USER CACHE =====================

    /**
     * Simpan info user ke cache — hanya update jika > 10 menit sejak update terakhir.
     * Mengurangi write ke DB secara drastis di grup aktif.
     */
    public function cacheUser(int $userId, string $username, string $firstName, string $lastName): void {
        if (!$userId) return;
        $existing = $this->fetchOne("SELECT updated_at FROM user_cache WHERE user_id = ?", [$userId]);
        if ($existing && (time() - (int)$existing['updated_at']) < 600) return;
        $this->execute(
            "INSERT OR REPLACE INTO user_cache (user_id, username, first_name, last_name, updated_at)
             VALUES (?, ?, ?, ?, strftime('%s','now'))",
            [$userId, strtolower(ltrim($username, '@')), $firstName, $lastName]
        );
    }

    /**
     * Sang Mata: Cek apakah username berubah, simpan riwayat, update cache.
     * Return [oldUsername, newUsername] jika berubah, atau null jika tidak.
     */
    public function checkAndUpdateUsername(int $userId, string $newUsername, string $firstName, string $lastName): ?array {
        if (!$userId) return null;
        $newUsername = strtolower(ltrim($newUsername, '@'));
        $existing = $this->fetchOne("SELECT * FROM user_cache WHERE user_id = ?", [$userId]);

        if ($existing) {
            $oldUsername = (string)($existing['username'] ?? '');
            // Deteksi perubahan username (keduanya harus tidak kosong agar valid)
            $changed = ($oldUsername !== $newUsername);
            if ($changed) {
                // Simpan ke riwayat username
                $this->execute(
                    "INSERT INTO username_history (user_id, old_username, new_username, changed_at) VALUES (?, ?, ?, strftime('%s','now'))",
                    [$userId, $oldUsername, $newUsername]
                );
                // Update cache segera
                $this->execute(
                    "UPDATE user_cache SET username = ?, first_name = ?, last_name = ?, updated_at = strftime('%s','now') WHERE user_id = ?",
                    [$newUsername, $firstName, $lastName, $userId]
                );
                return [$oldUsername, $newUsername];
            } else {
                // Tidak berubah, throttle 10 menit
                if ((time() - (int)$existing['updated_at']) >= 600) {
                    $this->execute(
                        "UPDATE user_cache SET first_name = ?, last_name = ?, updated_at = strftime('%s','now') WHERE user_id = ?",
                        [$firstName, $lastName, $userId]
                    );
                }
            }
        } else {
            // User baru, simpan ke cache
            $this->execute(
                "INSERT OR REPLACE INTO user_cache (user_id, username, first_name, last_name, updated_at) VALUES (?, ?, ?, ?, strftime('%s','now'))",
                [$userId, $newUsername, $firstName, $lastName]
            );
        }
        return null;
    }

    /**
     * Sang Mata: Ambil riwayat username seorang user (terbaru dulu).
     */
    public function getUsernameHistory(int $userId, int $limit = 10): array {
        return $this->query(
            "SELECT old_username, new_username, changed_at FROM username_history WHERE user_id = ? ORDER BY changed_at DESC LIMIT ?",
            [$userId, $limit]
        );
    }

    /**
     * Cari user_id berdasarkan @username dari cache
     * Return 0 jika tidak ditemukan
     */
    public function getUserIdByUsername(string $username): int {
        $clean = strtolower(ltrim($username, '@'));
        if (!$clean) return 0;
        $row = $this->fetchOne(
            "SELECT user_id FROM user_cache WHERE username = ?",
            [$clean]
        );
        return (int)($row['user_id'] ?? 0);
    }

    // ===================== ALL GROUPS =====================

    public function getAllGroups(): array {
        return $this->query("SELECT chat_id, title, username, created_at, updated_at FROM group_settings WHERE chat_id < 0 ORDER BY updated_at DESC");
    }

    public function getKnownChatUsers(int $chatId): array {
        $rows = $this->query(
            "SELECT DISTINCT user_id FROM (
                SELECT user_id FROM warnings WHERE chat_id = ?
                UNION
                SELECT user_id FROM bans WHERE chat_id = ?
                UNION
                SELECT user_id FROM mutes WHERE chat_id = ?
                UNION
                SELECT user_id FROM violation_points WHERE chat_id = ?
            ) AS t WHERE user_id > 0
            LIMIT 1000",
            [$chatId, $chatId, $chatId, $chatId]
        );
        return array_column($rows, 'user_id');
    }

    /**
     * Ambil daftar user yang pernah mengirim pesan di grup dalam N jam terakhir.
     * Digunakan oleh /tagall agar bisa mention semua member aktif, bukan hanya admin.
     * Data diambil dari message_log yang mencatat setiap pesan masuk.
     *
     * @param int $chatId  ID grup
     * @param int $hours   Rentang waktu mundur (default 48 jam = max hapus Telegram)
     * @param int $limit   Maks user yang diambil (Telegram entities limit ~100/pesan)
     */
    public function getRecentSenders(int $chatId, int $hours = 48, int $limit = 500): array {
        $cutoff = time() - ($hours * 3600);
        $rows   = $this->query(
            "SELECT DISTINCT user_id FROM message_log
             WHERE chat_id = ? AND sent_at >= ? AND user_id > 0
             ORDER BY user_id DESC
             LIMIT ?",
            [$chatId, $cutoff, $limit]
        );
        return array_column($rows, 'user_id');
    }

    // ===================== DYNAMIC SUPERADMINS =====================

    public function isDynamicSA(int $userId): bool {
        return (bool)$this->fetchOne("SELECT user_id FROM dynamic_superadmins WHERE user_id = ?", [$userId]);
    }

    public function addDynamicSA(int $userId, int $addedBy, string $note = ''): void {
        $this->execute(
            "INSERT OR REPLACE INTO dynamic_superadmins (user_id, added_by, note, added_at) VALUES (?, ?, ?, strftime('%s','now'))",
            [$userId, $addedBy, $note]
        );
    }

    public function removeDynamicSA(int $userId): void {
        $this->execute("DELETE FROM dynamic_superadmins WHERE user_id = ?", [$userId]);
    }

    public function getDynamicSuperadmins(): array {
        return $this->query("SELECT * FROM dynamic_superadmins ORDER BY added_at ASC");
    }

    // ===================== BOT SETTINGS =====================

    public function getBotSetting(string $key, string $default = ''): string {
        $r = $this->fetchOne("SELECT value FROM bot_settings WHERE key = ?", [$key]);
        return $r ? (string)$r['value'] : $default;
    }

    public function setBotSetting(string $key, string $value): void {
        $this->execute(
            "INSERT OR REPLACE INTO bot_settings (key, value) VALUES (?, ?)",
            [$key, $value]
        );
    }

    /**
     * Cek apakah update_id sudah pernah diproses.
     * Sekaligus tandai sebagai sudah diproses (atomic INSERT OR IGNORE).
     * Return true  = sudah diproses sebelumnya (duplikat, harus dilewati).
     * Return false = belum pernah diproses (lanjutkan).
     */
    public function checkAndMarkUpdateId(int $updateId): bool {
        // Cleanup processed_updates lama ditangani oleh cron.php setiap menit.
        // Di sini hanya INSERT OR IGNORE — 1 write per request, tidak ada DELETE.
        $stmt = $this->db->prepare(
            "INSERT OR IGNORE INTO processed_updates (update_id) VALUES (?)"
        );
        $stmt->execute([$updateId]);
        // Jika rowCount == 0 berarti baris sudah ada → duplikat
        return $stmt->rowCount() === 0;
    }

    // ===================== GRUP ADMIN (PM SETTINGS) =====================

    /**
     * Catat bahwa userId adalah admin di chatId.
     * $force = true  → tulis langsung (dipakai saat scan PM settings).
     * $force = false → throttle 10 menit (dipakai saat pesan masuk di grup).
     */
    public function recordAdminGroup(int $userId, int $chatId, bool $force = false): void {
        try {
            if (!$force) {
                $existing = $this->fetchOne(
                    "SELECT updated_at FROM user_admin_groups WHERE user_id = ? AND chat_id = ?",
                    [$userId, $chatId]
                );
                if ($existing && (time() - (int)$existing['updated_at']) < 600) return;
            }
            $this->execute(
                "INSERT OR REPLACE INTO user_admin_groups (user_id, chat_id, updated_at)
                 VALUES (?, ?, ?)",
                [$userId, $chatId, time()]
            );
        } catch (\Throwable $e) {}
    }

    /**
     * Hapus relasi admin jika user di-demote dari suatu grup.
     */
    public function removeAdminGroup(int $userId, int $chatId): void {
        $this->execute(
            "DELETE FROM user_admin_groups WHERE user_id = ? AND chat_id = ?",
            [$userId, $chatId]
        );
    }

    /**
     * Ambil semua grup tempat userId terdaftar sebagai admin.
     * Menggunakan tabel user_admin_groups yang diisi secara real-time.
     */
    public function getGroupsForAdmin(int $userId): array {
        return $this->query(
            "SELECT gs.chat_id, gs.title
             FROM group_settings gs
             INNER JOIN user_admin_groups uag ON gs.chat_id = uag.chat_id
             WHERE uag.user_id = ?
             ORDER BY gs.title ASC",
            [$userId]
        );
    }

    // ===================== GLOBAL STATS =====================

    public function getGlobalStats(): array {
        return [
            'total_groups'    => (int)(($this->fetchOne("SELECT COUNT(*) as cnt FROM group_settings WHERE chat_id < 0") ?? [])['cnt'] ?? 0),
            'total_users'     => (int)(($this->fetchOne("SELECT COUNT(*) as cnt FROM user_cache") ?? [])['cnt'] ?? 0),
            'total_gban'      => $this->getGbanCount(),
            'total_broadcast' => (int)(($this->fetchOne("SELECT COUNT(*) as cnt FROM broadcast_history") ?? [])['cnt'] ?? 0),
            'total_warnings'  => (int)(($this->fetchOne("SELECT COUNT(*) as cnt FROM warnings") ?? [])['cnt'] ?? 0),
            'total_bans'      => (int)(($this->fetchOne("SELECT COUNT(*) as cnt FROM bans") ?? [])['cnt'] ?? 0),
            'total_mutes'     => (int)(($this->fetchOne("SELECT COUNT(*) as cnt FROM mutes") ?? [])['cnt'] ?? 0),
            'total_messages'  => (int)(($this->fetchOne("SELECT COALESCE(SUM(total_messages),0) as cnt FROM statistics") ?? [])['cnt'] ?? 0),
            'total_notes'     => (int)(($this->fetchOne("SELECT COUNT(*) as cnt FROM group_notes") ?? [])['cnt'] ?? 0),
            'total_filters'   => (int)(($this->fetchOne("SELECT COUNT(*) as cnt FROM saved_filters") ?? [])['cnt'] ?? 0),
            'total_dyn_sa'    => (int)(($this->fetchOne("SELECT COUNT(*) as cnt FROM dynamic_superadmins") ?? [])['cnt'] ?? 0),
        ];
    }

    // ===================== VIOLATION POINTS =====================

    /**
     * Tambah 1 poin sanksi ke user di grup tertentu.
     * Return: jumlah poin setelah ditambah.
     */
    public function addViolationPoint(int $chatId, int $userId): int {
        $this->execute(
            "INSERT INTO violation_points (chat_id, user_id, points, last_violation_at)
             VALUES (?, ?, 1, strftime('%s','now'))
             ON CONFLICT(chat_id, user_id) DO UPDATE SET
                points = points + 1,
                last_violation_at = strftime('%s','now')",
            [$chatId, $userId]
        );
        $r = $this->fetchOne(
            "SELECT points FROM violation_points WHERE chat_id = ? AND user_id = ?",
            [$chatId, $userId]
        );
        return (int)($r['points'] ?? 1);
    }

    /**
     * Ambil jumlah poin sanksi user di grup.
     */
    public function getViolationPoints(int $chatId, int $userId): int {
        $r = $this->fetchOne(
            "SELECT points FROM violation_points WHERE chat_id = ? AND user_id = ?",
            [$chatId, $userId]
        );
        return (int)($r['points'] ?? 0);
    }

    /**
     * Reset poin sanksi user ke 0.
     */
    public function resetViolationPoints(int $chatId, int $userId): void {
        $this->execute(
            "DELETE FROM violation_points WHERE chat_id = ? AND user_id = ?",
            [$chatId, $userId]
        );
    }

    /**
     * Ambil daftar user dengan poin sanksi tertinggi di grup.
     */
    public function getViolationLeaderboard(int $chatId, int $limit = 10): array {
        return $this->query(
            "SELECT vp.user_id, vp.points, vp.last_violation_at,
                    uc.username, uc.first_name
             FROM violation_points vp
             LEFT JOIN user_cache uc ON uc.user_id = vp.user_id
             WHERE vp.chat_id = ?
             ORDER BY vp.points DESC LIMIT ?",
            [$chatId, $limit]
        );
    }

    // ===================== AI CONVERSATION =====================

    /**
     * Ambil riwayat percakapan AI untuk user di chat tertentu.
     * Return array of ['role'=>'user'/'assistant', 'content'=>'...']
     */
    public function getAiHistory(int $chatId, int $userId, int $limit = 10): array {
        $rows = $this->query(
            "SELECT role, content FROM ai_conversations
             WHERE chat_id = ? AND user_id = ?
             ORDER BY id DESC LIMIT ?",
            [$chatId, $userId, $limit]
        );
        // Balik urutan agar kronologis (terlama dulu)
        return array_reverse(array_map(fn($r) => ['role' => $r['role'], 'content' => $r['content']], $rows));
    }

    /**
     * Simpan satu pesan ke riwayat percakapan AI.
     * Otomatis trim jika sudah > 20 entri per user per chat.
     */
    public function addAiMessage(int $chatId, int $userId, string $role, string $content): void {
        $this->execute(
            "INSERT INTO ai_conversations (chat_id, user_id, role, content) VALUES (?, ?, ?, ?)",
            [$chatId, $userId, $role, $content]
        );
        // Trim: simpan maksimal 20 entri terbaru per user per chat
        $this->execute(
            "DELETE FROM ai_conversations WHERE chat_id = ? AND user_id = ?
             AND id NOT IN (
                 SELECT id FROM ai_conversations
                 WHERE chat_id = ? AND user_id = ?
                 ORDER BY id DESC LIMIT 20
             )",
            [$chatId, $userId, $chatId, $userId]
        );
    }

    /**
     * Hapus seluruh riwayat percakapan AI user di chat tertentu.
     */
    public function clearAiHistory(int $chatId, int $userId): void {
        $this->execute(
            "DELETE FROM ai_conversations WHERE chat_id = ? AND user_id = ?",
            [$chatId, $userId]
        );
    }

    // ===================== VOTE KICK =====================

    /**
     * Buat sesi vote kick baru. Return ID sesi.
     */
    public function createVotekickSession(
        int $chatId, int $targetId, string $targetName,
        int $initiatorId, string $reason, int $threshold, int $expiresAt
    ): int {
        return $this->execute(
            "INSERT INTO votekick_sessions
                (chat_id, target_id, target_name, initiator_id, reason, threshold, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [$chatId, $targetId, $targetName, $initiatorId, $reason, $threshold, $expiresAt]
        );
    }

    /**
     * Simpan message_id pesan vote setelah dikirim.
     */
    public function updateVotekickMessageId(int $sessionId, int $msgId): void {
        $this->execute(
            "UPDATE votekick_sessions SET message_id = ? WHERE id = ?",
            [$msgId, $sessionId]
        );
    }

    /**
     * Ambil sesi vote kick berdasarkan ID.
     */
    public function getVotekickSession(int $sessionId): ?array {
        return $this->fetchOne(
            "SELECT * FROM votekick_sessions WHERE id = ?",
            [$sessionId]
        );
    }

    /**
     * Cek sisa waktu cooldown votekick untuk user (dalam detik). 0 = boleh lanjut.
     */
    public function getVotekickCooldownRemaining(int $chatId, int $userId, int $cooldownSeconds): int {
        $row = $this->fetchOne(
            "SELECT MAX(created_at) as last FROM votekick_sessions WHERE chat_id = ? AND initiator_id = ?",
            [$chatId, $userId]
        );
        if (!$row || !$row['last']) return 0;
        $elapsed   = time() - (int)$row['last'];
        $remaining = $cooldownSeconds - $elapsed;
        return max(0, $remaining);
    }

    /**
     * Cek apakah ada sesi aktif untuk target di grup tertentu.
     */
    public function getActiveVotekickByTarget(int $chatId, int $targetId): ?array {
        return $this->fetchOne(
            "SELECT * FROM votekick_sessions
             WHERE chat_id = ? AND target_id = ? AND status = 'active'",
            [$chatId, $targetId]
        );
    }

    /**
     * Rekam suara vote. Return false jika user sudah pernah vote.
     * Jika berhasil, langsung update yes_count/no_count di sesi.
     */
    public function addVotekickVote(int $sessionId, int $voterId, string $vote): bool {
        try {
            $this->execute(
                "INSERT INTO votekick_votes (session_id, voter_id, vote) VALUES (?, ?, ?)",
                [$sessionId, $voterId, $vote]
            );
            $col = ($vote === 'yes') ? 'yes_count' : 'no_count';
            $this->execute(
                "UPDATE votekick_sessions SET $col = $col + 1 WHERE id = ?",
                [$sessionId]
            );
            return true;
        } catch (\PDOException $e) {
            return false;
        }
    }

    /**
     * Perbarui status sesi: 'active' | 'executed' | 'expired' | 'cancelled'.
     */
    public function updateVotekickStatus(int $sessionId, string $status): void {
        $this->execute(
            "UPDATE votekick_sessions SET status = ? WHERE id = ?",
            [$status, $sessionId]
        );
    }

    /**
     * Ambil semua sesi aktif yang sudah melewati waktu expired (untuk cron).
     */
    public function getExpiredVotekickSessions(): array {
        return $this->query(
            "SELECT * FROM votekick_sessions WHERE status = 'active' AND expires_at < ?",
            [time()]
        );
    }

    // ===================== HOUSEKEEPING =====================

    /**
     * Hapus riwayat percakapan AI yang lebih dari $days hari.
     * Dipanggil dari cron untuk cegah tabel ai_conversations membengkak.
     */
    public function cleanupAiConversations(int $days = 7): int {
        $cutoff = time() - ($days * 86400);
        $this->execute("DELETE FROM ai_conversations WHERE created_at < ?", [$cutoff]);
        $r = $this->fetchOne("SELECT changes() as cnt");
        return (int)($r['cnt'] ?? 0);
    }

    /**
     * Hapus sesi votekick yang sudah selesai/expired > $days hari.
     */
    public function cleanupOldVotekickSessions(int $days = 7): void {
        $cutoff = time() - ($days * 86400);
        $this->execute(
            "DELETE FROM votekick_sessions WHERE status != 'active' AND created_at < ?",
            [$cutoff]
        );
    }

    /**
     * Hapus riwayat broadcast yang lebih dari $days hari.
     */
    public function cleanupBroadcastHistory(int $days = 30): void {
        $cutoff = time() - ($days * 86400);
        $this->execute("DELETE FROM broadcast_history WHERE sent_at < ?", [$cutoff]);
    }

    // ===================== BROADCAST QUEUE =====================

    public function addBroadcastSchedule(int $sentBy, string $message, ?string $mediaType, ?string $fileId, string $caption, int $scheduledAt): int {
        return $this->execute(
            "INSERT INTO broadcast_queue (sent_by, message, media_type, file_id, caption, scheduled_at) VALUES (?, ?, ?, ?, ?, ?)",
            [$sentBy, $message, $mediaType, $fileId, $caption, $scheduledAt]
        );
    }

    public function getPendingSchedules(): array {
        return $this->query(
            "SELECT * FROM broadcast_queue WHERE status = 'pending' AND scheduled_at <= ? ORDER BY scheduled_at ASC",
            [time()]
        );
    }

    public function getScheduleQueue(int $sentBy, int $limit = 5): array {
        return $this->query(
            "SELECT * FROM broadcast_queue WHERE sent_by = ? AND status = 'pending' ORDER BY scheduled_at ASC LIMIT ?",
            [$sentBy, $limit]
        );
    }

    public function markScheduleSent(int $id): void {
        $this->execute("UPDATE broadcast_queue SET status = 'sent' WHERE id = ?", [$id]);
    }

    public function cancelSchedule(int $id, int $sentBy): bool {
        $r = $this->execute(
            "UPDATE broadcast_queue SET status = 'cancelled' WHERE id = ? AND sent_by = ? AND status = 'pending'",
            [$id, $sentBy]
        );
        return $r > 0;
    }

    public function countPendingSchedules(): int {
        $r = $this->fetchOne("SELECT COUNT(*) as cnt FROM broadcast_queue WHERE status = 'pending'");
        return (int)($r['cnt'] ?? 0);
    }

    /**
     * Jalankan VACUUM untuk kompres file DB (panggil hanya jika db besar & saat traffic rendah).
     */
    public function vacuum(): void {
        $this->db->exec('VACUUM');
    }

    /**
     * Pemeliharaan rutin SQLite — dipanggil dari cron.php tiap menit.
     *
     * 1. PRAGMA optimize: perbarui statistik query planner agar query tetap cepat
     *    setelah banyak INSERT/DELETE (docs: https://www.sqlite.org/pragma.html#pragma_optimize)
     * 2. wal_checkpoint(PASSIVE): tulis WAL ke main DB file agar WAL tidak membengkak
     *    tak terbatas. PASSIVE = tidak block reader/writer aktif.
     *    (docs: https://www.sqlite.org/wal.html#checkpointing)
     *
     * Kedua operasi sangat ringan jika tidak ada yang perlu dilakukan.
     */
    public function runMaintenance(): void {
        $this->db->exec('PRAGMA optimize');
        $this->db->exec('PRAGMA wal_checkpoint(PASSIVE)');
    }

    // ===================== EMAIL ACCOUNTS =====================

    public function getEmailAccounts(): array {
        return $this->query("SELECT * FROM email_accounts ORDER BY added_at ASC");
    }

    public function getEmailAccount(int $id): ?array {
        return $this->fetchOne("SELECT * FROM email_accounts WHERE id = ?", [$id]);
    }

    public function getEmailAccountByEmail(string $email): ?array {
        return $this->fetchOne("SELECT * FROM email_accounts WHERE email = ?", [strtolower($email)]);
    }

    public function addEmailAccount(string $emailAddr, string $emailUser, string $emailDomain, string $password, int $quota, string $label, int $addedBy): int {
        return $this->execute(
            "INSERT OR REPLACE INTO email_accounts (email, email_user, email_domain, email_password, quota, label, added_by, added_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, strftime('%s','now'))",
            [strtolower($emailAddr), $emailUser, $emailDomain, $password, $quota, $label, $addedBy]
        );
    }

    // ===================== MEMBER VERIFICATIONS =====================

    public function saveVerification(int $chatId, int $userId, string $firstName, string $username, string $phone, float $lat, float $lon): void {
        $this->execute(
            "INSERT OR REPLACE INTO member_verifications
             (chat_id, user_id, first_name, username, phone_number, latitude, longitude, verified_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, strftime('%s','now'))",
            [$chatId, $userId, $firstName, $username, $phone, $lat, $lon]
        );
    }

    public function getVerification(int $chatId, int $userId): ?array {
        return $this->fetchOne(
            "SELECT * FROM member_verifications WHERE chat_id = ? AND user_id = ?",
            [$chatId, $userId]
        ) ?: null;
    }

    public function getGroupVerifications(int $chatId, int $limit = 50): array {
        return $this->query(
            "SELECT * FROM member_verifications WHERE chat_id = ? ORDER BY verified_at DESC LIMIT ?",
            [$chatId, $limit]
        );
    }

    public function getUserVerifications(string $usernameOrId, int $chatId): ?array {
        if (is_numeric($usernameOrId)) {
            return $this->fetchOne(
                "SELECT * FROM member_verifications WHERE chat_id = ? AND user_id = ?",
                [$chatId, (int)$usernameOrId]
            ) ?: null;
        }
        $clean = strtolower(ltrim($usernameOrId, '@'));
        return $this->fetchOne(
            "SELECT * FROM member_verifications WHERE chat_id = ? AND LOWER(username) = ?",
            [$chatId, $clean]
        ) ?: null;
    }

    public function deleteEmailAccount(int $id): void {
        $this->execute("DELETE FROM email_accounts WHERE id = ?", [$id]);
    }

    public function countEmailAccounts(): int {
        $r = $this->fetchOne("SELECT COUNT(*) as cnt FROM email_accounts");
        return (int)($r['cnt'] ?? 0);
    }

    // ===================== IMAGE USAGE (LIMIT PER USER/HARI) =====================

    public function getImgUsageToday(int $userId): int {
        $date = date('Y-m-d');
        $r = $this->fetchOne("SELECT count FROM img_usage WHERE user_id = ? AND date = ?", [$userId, $date]);
        return (int)($r['count'] ?? 0);
    }

    public function incrementImgUsage(int $userId): void {
        $date = date('Y-m-d');
        $this->execute(
            "INSERT INTO img_usage (user_id, date, count) VALUES (?, ?, 1)
             ON CONFLICT(user_id, date) DO UPDATE SET count = count + 1",
            [$userId, $date]
        );
    }
}

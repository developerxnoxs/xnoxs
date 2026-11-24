<?php
/**
 * Bahasa Indonesia — TeleGroupBot
 */
return [

    // ======================== UMUM ========================
    'error.admin_only'        => '❌ Hanya admin.',
    'error.access_denied'     => '❌ Akses ditolak.',
    'error.user_not_found'    => '❌ User tidak ditemukan.',
    'error.mention_user'      => '❌ Sebut atau reply user.',
    'error.no_target'         => '❌ Reply atau sebut user.',
    'error.username_not_found'=> "❌ User <b>@{username}</b> tidak ditemukan.\n\nKemungkinan penyebab:\n• User belum pernah kirim pesan di grup ini\n• Username salah atau sudah berubah\n\n💡 <b>Solusi:</b> Minta user tersebut kirim pesan di grup dulu, lalu <i>reply</i> pesannya dengan perintah ini.",
    'error.fetch_failed'      => '❌ Gagal mengambil data.',
    'error.group_only'        => '❌ Perintah ini hanya untuk grup.',
    'error.invalid_format'    => '❌ Format tidak valid.',
    'error.invalid_id'        => '❌ Kirim User ID yang valid.',
    'error.cant_target_admin' => '❌ Tidak bisa menargetkan admin!',
    'error.cant_ban_admin'    => '❌ Tidak bisa ban admin!',
    'error.cant_kick_admin'   => '❌ Tidak bisa kick admin!',
    'error.cant_mute_admin'   => '❌ Tidak bisa mute admin!',
    'error.cant_warn_admin'   => '❌ Tidak bisa warn admin!',
    'error.sa_only'           => '❌ Akses ditolak.',
    'error.owner_only'        => '❌ Hanya owner!',
    'error.cancel'            => '❌ Batal',
    'error.no_reason'         => 'Tidak ada alasan',

    // ======================== SUKSES ========================
    'ok'                      => '✅ Berhasil!',
    'ok.updated'              => '✅ Diperbarui!',
    'ok.saved'                => '✅ Tersimpan!',
    'ok.deleted'              => '✅ Dihapus!',
    'ok.done'                 => '✅ Selesai!',

    // ======================== START ========================
    'start.text'              => "🤖 <b>TeleGroupBot v3.1</b>\n\nBot manajemen grup &amp; channel Telegram yang lengkap!\n\n👤 Halo, <b>{name}</b>!{sa}\n\nTambahkan saya ke grup sebagai admin, lalu gunakan /settings untuk mengatur semua fitur via tombol inline.",
    'start.sa_note'           => "\n\n🔐 Anda terdaftar sebagai <b>Superadmin</b>.",
    'start.btn_help'          => '📖 Bantuan',
    'start.btn_features'      => '⭐ Fitur',
    'start.btn_about'         => 'ℹ️ Tentang',
    'start.btn_mygroups'      => '⚙️ Settings Grup Saya',
    'start.btn_sa'            => '🔐 Panel Superadmin',
    'start.btn_back'          => '⬅️ Kembali',
    'start.btn_language'      => '🌐 Bahasa / Language',

    // ======================== HELP ========================
    'help.title'              => "📖 <b>Daftar Perintah Bot</b>\n\n",
    'help.overview'           => "Pilih kategori di tombol bawah atau ketik <code>/help [kategori]</code> (contoh: <code>/help ai</code>, <code>/help admin</code>):\n\n"
                                . "📋 <b>Umum</b> (<code>general</code>): /rules, /me, /id, /info, /stats, /adminlist, /warns, /notes, /report, /checkperms, /cekverif\n"
                                . "🛡️ <b>Admin</b> (<code>admin</code>): /ban, /tban, /unban, /kick, /mute, /unmute, /warn, /unwarn, /clearwarns, /promote, /demote, /settitle, /pin, /unpin, /unpinall, /del, /purge, /clearall, /slowmode, /tagall, /kickbots, /cleandel\n"
                                . "🔒 <b>Konten</b> (<code>content</code>): /lock, /unlock, /locks, /save, /get, /notes, /clear, #keyword, /filter, /filters, /stop, /banword, /unbanword, /banwords\n"
                                . "⚖️ <b>Sanksi &amp; Mata</b> (<code>safety</code>): /vpoints, /resetvp, /topviolators, /sangmata, /cekun, /votekick, /cancelvotekick\n"
                                . "🤖 <b>AI &amp; Tools</b> (<code>ai</code>): /ai, /resetai, /translate, /detect, /summarize, /imagine, /edit, /run, /price, /setlang, /features\n"
                                . "⚙️ <b>Pengaturan</b> (<code>settings</code>): /settings (grup &amp; PM), /setrules, /setwelcome, /setgoodbye, /setlogchannel, /invitelink, /ro, /setlang\n",
    'help.overview_sa'        => "🔐 <b>Superadmin</b> (<code>sa</code>): /sa, /gban, /ungban, /gbanlist, /massban, /cleargban, /broadcast, /announce, /lookup, /sainfo, /ping, /sysinfo, /backup, /logs, /maintenance, /addsa, /rmsa, /salist, /email, /tempmail, /whm, /cpanel\n",
    'help.sa_restricted'      => '❌ Menu bantuan Superadmin hanya dapat diakses oleh Superadmin.',
    'help.btn_overview'       => '📖 Ringkasan',
    'help.btn_general'        => '📋 Umum',
    'help.btn_admin'          => '🛡️ Admin',
    'help.btn_content'        => '🔒 Konten & Filter',
    'help.btn_safety'         => '⚖️ Sanksi & Mata',
    'help.btn_ai'             => '🤖 AI & Tools',
    'help.btn_settings'       => '⚙️ Pengaturan',
    'help.btn_sa'             => '🔐 Superadmin',
    'help.btn_features'       => '⭐ Fitur Lengkap',
    'help.btn_close'          => '✖️ Tutup',
    'help.general'            => "<b>━ 📋 Perintah Umum ━</b>\n"
                                . "/rules — tampilkan peraturan grup\n"
                                . "/me — lihat status saya di grup ini\n"
                                . "/id — tampilkan ID chat &amp; user\n"
                                . "/info @user — informasi lengkap anggota (alias: /whois)\n"
                                . "/stats — statistik aktivitas grup\n"
                                . "/adminlist — daftar admin di grup\n"
                                . "/warns — cek jumlah peringatan saya\n"
                                . "/notes — daftar catatan tersimpan di grup\n"
                                . "/report — laporkan pesan ke admin (reply pesan)\n"
                                . "/checkperms — cek izin bot di grup\n"
                                . "/cekverif [@user|ID] — cek data verifikasi kontak &amp; lokasi (admin)\n"
                                . "/features — daftar fitur lengkap bot (alias: /fitur)\n\n",
    'help.admin'              => "<b>━ 🛡️ Perintah Admin ━</b>\n"
                                . "/ban @user — ban permanen\n"
                                . "/tban @user 1d — ban sementara (contoh: 30m, 2h, 7d)\n"
                                . "/unban @user — cabut ban\n"
                                . "/kick @user — keluarkan dari grup\n"
                                . "/mute @user [durasi] — bisukan permanen atau sementara (1h, 30m)\n"
                                . "/unmute @user — cabut bisukan\n"
                                . "/warn @user — beri peringatan\n"
                                . "/unwarn @user — hapus satu peringatan (alias: /rmwarn)\n"
                                . "/clearwarns @user — hapus semua peringatan\n"
                                . "/promote @user — jadikan admin\n"
                                . "/demote @user — copot dari jabatan admin\n"
                                . "/settitle @user judul — atur judul khusus admin\n"
                                . "/pin — pin pesan (reply ke pesan)\n"
                                . "/unpin — lepas pin pesan\n"
                                . "/unpinall — lepas semua pin\n"
                                . "/del — hapus pesan (reply ke pesan, alias: /delete)\n"
                                . "/purge — hapus massal (reply pesan awal, atau /purge @username)\n"
                                . "/clearall — hapus semua pesan terbaru grup (maks 4.000 pesan / 48 jam)\n"
                                . "/slowmode 30 — atur slowmode detik (0 = nonaktif)\n"
                                . "/tagall — mention semua anggota grup\n"
                                . "/kickbots — keluarkan semua bot lain dari grup\n"
                                . "/cleandel — keluarkan akun yang sudah dihapus (Deleted Account)\n\n",
    'help.lock'               => "<b>━ 🔒 Kunci Konten ━</b>\n"
                                . "/lock &lt;jenis&gt; — kunci jenis konten tertentu\n"
                                . "Jenis: <code>sticker</code>, <code>gif</code>, <code>media</code>, <code>voice</code>, <code>game</code>, <code>poll</code>, <code>forward</code>\n"
                                . "/unlock &lt;jenis&gt; — buka kunci konten tertentu\n"
                                . "/locks — tampilkan status semua kunci\n\n",
    'help.note'               => "<b>━ 📝 Catatan (Notes) ━</b>\n"
                                . "/save kata teks — simpan catatan (alias: /note)\n"
                                . "/get kata — ambil catatan berdasarkan kata kunci\n"
                                . "/notes — tampilkan semua catatan grup\n"
                                . "/clear kata — hapus catatan (alias: /clearnote)\n"
                                . "Atau kirim <code>#kata</code> langsung di grup untuk memanggil catatan\n\n",
    'help.filter'             => "<b>━ 🔁 Auto-Filter &amp; Kata Terlarang ━</b>\n"
                                . "/filter kata respons — tambah filter auto-reply\n"
                                . "/filters — tampilkan semua filter aktif\n"
                                . "/stop kata — hapus filter (alias: /rmfilter)\n"
                                . "/banword kata — tambah kata terlarang (alias: /addword)\n"
                                . "/unbanword kata — hapus kata terlarang (alias: /rmword)\n"
                                . "/banwords — tampilkan daftar kata terlarang\n\n",
    'help.violation'          => "<b>━ ⚖️ Poin Sanksi ━</b>\n"
                                . "/vpoints @user — cek poin pelanggaran (alias: /poin)\n"
                                . "/resetvp @user — reset poin ke 0 (alias: /resetpoin)\n"
                                . "/topviolators — daftar pelanggar terbanyak (alias: /violators)\n\n",
    'help.sang_mata'          => "<b>━ 👁️ Sang Mata ━</b>\n"
                                . "/sangmata @user — riwayat perubahan username &amp; nama\n"
                                . "Alias: /cekun, /usernamehistory\n"
                                . "(aktifkan di /settings untuk notifikasi otomatis)\n\n",
    'help.votekick'           => "<b>━ 🗳️ Vote Kick ━</b>\n"
                                . "/votekick @user alasan — ajukan vote kick anggota (alias: /vkick)\n"
                                . "/cancelvotekick @user — batalkan sesi vote kick (alias: /cancelvo)\n\n",
    'help.ai'                 => "<b>━ 🤖 Asisten AI (Gemini &amp; DeepSeek) ━</b>\n"
                                . "/ai pertanyaan — tanya AI (alias: /tanya, /ask; reply pesan untuk analisis)\n"
                                . "/resetai — hapus riwayat percakapan AI (alias: /clearai)\n"
                                . "/translate &lt;bahasa&gt; &lt;teks&gt; — terjemahkan teks (alias: /terjemahkan, /tr; bisa reply)\n"
                                . "/detect &lt;teks&gt; — deteksi bahasa &amp; asal teks (alias: /deteksi; bisa reply)\n"
                                . "/summarize &lt;teks&gt; — ringkas teks/berita panjang (alias: /ringkas, /tldr; bisa reply)\n\n"
                                . "<b>📸 Gambar AI (hanya di chat pribadi)</b>\n"
                                . "/imagine deskripsi — buat gambar AI (alias: /gambar, /buatgambar, /imgai)\n"
                                . "/edit instruksi — edit foto AI (kirim foto+caption atau reply foto; alias: /editgambar, /editimg)\n\n",
    'help.tools'              => "<b>━ 🛠️ Utilitas &amp; Tools ━</b>\n"
                                . "/run &lt;bahasa&gt;\\n&lt;kode&gt; — eksekusi kode 35+ bahasa via Judge0 CE (Python, JS, TS, PHP, Go, Rust, C/C++, Java, SQL, dll.)\n"
                                . "/price &lt;koin&gt; [mata_uang] — cek harga crypto live (alias: /harga, /crypto)\n"
                                . "/setlang — ubah bahasa bot (Indonesia / English)\n"
                                . "/features — lihat fitur lengkap bot (alias: /fitur)\n\n",
    'help.settings'           => "<b>━ ⚙️ Pengaturan Grup ━</b>\n"
                                . "/settings — buka panel pengaturan inline lengkap (di grup atau chat pribadi via /settings &amp; /mygroupsettings)\n"
                                . "/setrules teks — atur peraturan grup\n"
                                . "/setwelcome teks — atur pesan sambutan anggota baru\n"
                                . "/setgoodbye teks — atur pesan perpisahan\n"
                                . "/setlogchannel — atur channel log aktivitas\n"
                                . "/invitelink — dapatkan link undangan grup\n"
                                . "/ro — aktif/nonaktifkan mode readonly (alias: /readonly)\n"
                                . "/setlang — ubah bahasa grup/bot\n"
                                . "<i>Tips Admin: Pesan dengan tagar #pengumuman / #announcement otomatis di-pin bot!</i>\n\n",
    'help.sa'                 => "<b>━ 🔐 Perintah Superadmin ━</b>\n"
                                . "/sa — buka Panel Superadmin inline (alias: /superadmin)\n"
                                . "/sainfo — statistik global bot (alias: /botstat)\n"
                                . "/ping — cek respons &amp; latensi bot\n"
                                . "/sysinfo — info server/VPS/CPU/RAM/disk (alias: /serverinfo, /hostinfo, /vpsinfo)\n"
                                . "/gban ID alasan — global ban di semua grup\n"
                                . "/ungban ID — cabut global ban\n"
                                . "/gbanlist — daftar global ban\n"
                                . "/massban ID1 ID2 ... — global ban massal\n"
                                . "/cleargban — hapus semua global ban (Owner)\n"
                                . "/broadcast — kirim pesan ke semua grup (+ antrian jadwal di panel SA)\n"
                                . "/announce teks — kirim pengumuman resmi ke semua grup\n"
                                . "/lookup @user|ID — cari riwayat &amp; status user (alias: /cariuser)\n"
                                . "/backup — unduh backup database SQLite\n"
                                . "/logs [n] — lihat log aktivitas bot (alias: /salog)\n"
                                . "/maintenance — toggle mode maintenance\n"
                                . "/addsa ID [catatan] — tambah Superadmin dinamis\n"
                                . "/rmsa ID — hapus Superadmin dinamis (alias: /removesa)\n"
                                . "/salist — daftar Superadmin\n"
                                . "<b>🌐 Panel Layanan &amp; Hosting (SA):</b>\n"
                                . "/email — Panel Email cPanel (IMAP/SMTP, buat akun, inbox, kirim)\n"
                                . "/tempmail — TempMail Manager (generate, inbox, baca, hapus)\n"
                                . "/whm — Panel WHM Server (akun, paket, DNS zone, FTP, restart service)\n"
                                . "/cpanel — Panel cPanel Hosting (FTP, MySQL DB, subdomain, forwarder, cron, disk, AutoSSL)\n\n",

    // ======================== PM SETTINGS ========================
    'pm_settings.choose_group'   => "⚙️ <b>Settings Grup via Chat Pribadi</b>\n\nPilih grup yang ingin Anda kelola:",
    'pm_settings.no_groups'      => "❌ <b>Tidak ada grup ditemukan.</b>\n\nBot sudah melakukan scan dan tidak menemukan Anda sebagai admin di grup manapun.\n\n<b>Pastikan:</b>\n• Bot sudah ditambahkan ke grup Anda\n• Anda memiliki hak admin di grup tersebut\n\nJika sudah benar, tekan tombol <b>Scan Ulang</b> di bawah.",
    'pm_settings.not_admin'      => '❌ Anda bukan admin di grup ini.',
    'pm_settings.btn_back_groups'=> '⬅️ Kembali ke Daftar Grup',
    'pm_settings.btn_rescan'     => '🔍 Scan Ulang',
    'pm_settings.scanning'       => '🔍 Sedang scan grup, mohon tunggu...',

    // ======================== ABOUT ========================
    'about.text'              => "ℹ️ <b>TeleGroupBot v3.1</b>\n\nBahasa: PHP 8.0+ (Pure)\nDatabase: SQLite\nPanel: Full Inline (edit pesan, tanpa spam)\n\nFitur: Pending input system untuk pengaturan yang memerlukan teks dari admin. Bot akan menunggu input dan memproses secara otomatis.",

    // ======================== FEATURES ========================
    'features.title'          => "⭐ <b>Fitur Lengkap Bot</b>\n\n",
    'features.list'           => "<b>🛡️ Moderasi &amp; Proteksi Grup</b>\n"
                                . "✅ Panel inline lengkap via /settings (bisa langsung di grup atau DM)\n"
                                . "✅ Anti-Flood (mute/kick/ban + durasi kustom)\n"
                                . "✅ Anti-Link + Whitelist domain &amp; deteksi link tersembunyi\n"
                                . "✅ Anti-Spam (deteksi duplikat), Anti-Forward, Anti-Bot &amp; Anti-Raid\n"
                                . "✅ Kunci Konten: sticker, GIF, media, voice, game, poll, forward\n"
                                . "✅ Mode Readonly (/ro), Slowmode (/slowmode), Clean Service Messages\n"
                                . "✅ Hapus massal pesan (/purge reply &amp; per-user, /clearall 4.000 pesan)\n"
                                . "✅ Auto-Pin otomatis saat admin kirim #pengumuman / #announcement\n"
                                . "✅ Cek izin bot otomatis saat join &amp; via /checkperms\n\n"
                                . "<b>🛂 Sambutan, Captcha &amp; Verifikasi</b>\n"
                                . "✅ Welcome &amp; Goodbye kustom + tombol URL kustom\n"
                                . "✅ Captcha Matematika (gambar via DM) &amp; Web hCaptcha (captcha_web.php)\n"
                                . "✅ Auto-kick member yang gagal/telat menyelesaikan captcha (cron)\n"
                                . "✅ Tombol \"Saya Setuju\" peraturan grup + Verifikasi Kontak &amp; Lokasi (/cekverif)\n\n"
                                . "<b>⚖️ Sanksi, Vote Kick &amp; Pelacakan</b>\n"
                                . "✅ Sistem Warn (limit &amp; aksi otomatis) + Poin Sanksi (/vpoints, /topviolators)\n"
                                . "✅ Vote Kick demokratis (persentase/kuota suara, cooldown, auto-eksekusi)\n"
                                . "✅ Sang Mata — lacak riwayat perubahan username &amp; notifikasi otomatis\n"
                                . "✅ Sistem laporan pesan ke admin (/report)\n\n"
                                . "<b>📝 Konten &amp; Catatan</b>\n"
                                . "✅ System Note (/save, /get, /notes, #keyword) &amp; Auto-Filter respons (/filter)\n"
                                . "✅ Filter Kata Terlarang (scan teks &amp; caption)\n"
                                . "✅ Multi-bahasa Indonesia &amp; Inggris (/setlang), Log Channel &amp; Statistik Grup\n\n"
                                . "<b>🤖 Asisten AI &amp; Utilitas</b>\n"
                                . "✅ AI Chat Gemini &amp; DeepSeek multi-turn + analisis pesan reply (/ai, /resetai)\n"
                                . "✅ Penerjemah (/translate), Deteksi Bahasa (/detect) &amp; Ringkas Teks (/summarize)\n"
                                . "✅ Buat &amp; Edit Gambar AI via Gemini Image (/imagine, /edit di PM + kuota harian)\n"
                                . "✅ Switcher Default Model AI Chat &amp; AI Image di Panel Superadmin\n"
                                . "✅ Code Runner 35+ bahasa pemrograman via Judge0 CE (/run)\n"
                                . "✅ Cek Harga Crypto live multi-mata uang via CoinGecko (/price)\n\n"
                                . "<b>🔐 Suite Superadmin</b>\n"
                                . "✅ Global Ban lintas grup (/gban, /massban) &amp; Pencarian User (/lookup)\n"
                                . "✅ Broadcast Instan, Pengumuman (/announce) &amp; Antrian Broadcast Terjadwal\n"
                                . "✅ Manajemen Superadmin dinamis, Backup DB, Logs, Sysinfo &amp; Maintenance\n"
                                . "✅ Integrasi Panel Email cPanel (/email), TempMail (/tempmail), WHM (/whm) &amp; cPanel (/cpanel)",

    // ======================== LANGUAGE ========================
    'lang.panel'              => "🌐 <b>Pilih Bahasa Bot</b>\n\nBahasa aktif saat ini: <b>{current}</b>\n\nPilih bahasa yang ingin digunakan:",
    'lang.changed'            => '✅ Bahasa berhasil diubah ke <b>{lang}</b>!',
    'lang.btn_id'             => '🇮🇩 Indonesia',
    'lang.btn_en'             => '🇬🇧 English',
    'lang.btn_back'           => '⬅️ Kembali',
    'lang.already_set'        => '✅ Bahasa sudah diatur ke <b>{lang}</b>.',
    'lang.name_id'            => 'Indonesia',
    'lang.name_en'            => 'English',

    // ======================== MODERATION ========================
    'mod.perm'                => "permanen",
    'mod.banned'              => "🚫 {mention} di-<b>ban</b>\n📝 Alasan: {reason}",
    'mod.ban_temp'            => "⏰🚫 {mention} di-<b>ban sementara</b> ({duration})\n📝 Alasan: {reason}",
    'mod.unbanned'            => "✅ {mention} di-<b>unban</b>",
    'mod.kicked'              => "👢 {mention} di-<b>kick</b>\n📝 Alasan: {reason}",
    'mod.muted'               => "🔇 {mention} di-<b>mute</b> ({duration})\n📝 Alasan: {reason}",
    'mod.unmuted'             => "🔊 {mention} di-<b>unmute</b>",
    'mod.warned'              => "⚠️ {mention} mendapat <b>peringatan</b> ({count}/{limit})\n📝 Alasan: {reason}",
    'mod.warn_limit'          => "\n🚨 Batas tercapai! Aksi: <b>{action}</b>",
    'mod.unwarn'              => "✅ Satu peringatan {mention} dihapus. Sisa: {count}/{limit}",
    'mod.clearwarns'          => "✅ Semua peringatan {mention} dihapus.",
    'mod.promoted'            => "⬆️ {mention} dipromosikan menjadi admin.",
    'mod.demoted'             => "⬇️ {mention} dicopot dari jabatan admin.",
    'mod.pinned'              => "📌 Pesan di-pin.",
    'mod.unpinned'            => "📌 Pesan di-unpin.",

    // ======================== BAN USAGE ========================
    'ban.usage'               => "❌ <b>User tidak ditemukan.</b>\n\n<b>Cara pakai /ban:</b>\n• Reply pesan user → <code>/ban [alasan]</code>\n• <code>/ban @username [alasan]</code>\n• <code>/ban username [alasan]</code>\n• <code>/ban 123456789 [alasan]</code>\n\n⚠️ Jika @username gagal, pastikan user pernah mengirim pesan di grup ini, atau gunakan reply.",
    'kick.usage'              => "❌ <b>User tidak ditemukan.</b>\n\n<b>Cara pakai /kick:</b>\n• Reply pesan user → <code>/kick [alasan]</code>\n• <code>/kick @username [alasan]</code>\n• <code>/kick username [alasan]</code>\n• <code>/kick 123456789 [alasan]</code>\n\n⚠️ Jika @username gagal, pastikan user pernah mengirim pesan di grup ini, atau gunakan reply.",
    'tban.usage'              => "❌ User tidak ditemukan.\n<b>Contoh:</b> <code>/tban @username 1d alasan</code> atau reply pesan user.",
    'tban.invalid_duration'   => "❌ Format durasi salah. Gunakan: 30m / 2h / 7d",

    // ======================== WARN ========================
    'warns.title'             => "⚠️ <b>Peringatan {mention}</b>: {count}/{limit}",

    // ======================== CAPTCHA ========================
    'captcha.text'            => "👋 Selamat datang <b>{name}</b>!\n\n🔐 <b>Verifikasi Diperlukan</b>\n\nJawab pertanyaan berikut untuk bergabung:\n\n➤  <b>{a} + {b} = ?</b>\n\n⏱ Batas waktu: <b>{timeout} detik</b> (pukul {exptime})\n{rules_note}\n\n⚠️ Jika tidak menjawab, Anda akan di-<b>kick</b> otomatis saat ada pesan berikutnya.",
    'captcha.image_caption'   => "👋 Halo <b>{name}</b>!\n\n🔐 <b>Verifikasi Diperlukan</b>\nJawab soal yang tertera pada gambar di atas.\n\n⏱ Waktu: <b>{timeout} detik</b> · Kedaluwarsa pukul <b>{time}</b>{rules_note}\n\n⚠️ Jika tidak menjawab, Anda akan di-<b>kick</b> otomatis.",
    'captcha.rules_note'      => "\n📋 Setelah verifikasi, Anda akan diminta menyetujui <b>peraturan grup</b> sebelum bisa mengirim pesan.",
    'captcha.wrong'           => "❌ Jawaban salah! Coba lagi.",
    'captcha.correct'         => "✅ Verifikasi berhasil! Selamat bergabung, <b>{name}</b>!",
    'captcha.correct_rules'   => "✅ Verifikasi berhasil! Silakan baca dan setujui peraturan grup dulu.",
    'captcha.expired'         => "⏰ {mention} di-<b>kick</b> karena tidak menyelesaikan captcha tepat waktu.",
    'captcha.expired_msg'     => "⏰ {mention} di-<b>kick</b> karena tidak menyelesaikan captcha tepat waktu.",

    // ======================== ANTI-* ========================
    'anti.flood_mute'         => "🌊 {mention} di-<b>mute</b> karena flood! ({duration}s)",
    'anti.flood_kick'         => "🌊 {mention} di-<b>kick</b> karena flood!",
    'anti.flood_ban'          => "🌊 {mention} di-<b>ban</b> karena flood!",
    'anti.forward'            => "🚫 {mention} tidak boleh meneruskan (forward) pesan di sini!",
    'anti.link'               => "🔗 {mention} tidak diizinkan mengirim link!",
    'anti.spam'               => "🔁 {mention} terdeteksi mengirim pesan duplikat. Harap tidak spam!",
    'anti.word'               => "⚠️ {mention} pesan dihapus karena mengandung kata terlarang.",
    'anti.raid'               => "🛡️ Anti-Raid aktif! Bergabung sementara diblokir.",

    // ======================== VIOLATION POINTS ========================
    'vp.point_added'          => "⚠️ {mention} mendapat <b>+1 poin sanksi</b> karena: <b>{reason}</b>\n📊 Poin: <b>{points}/{limit}</b>",
    'vp.point_warn'           => "\n🔴 <b>Peringatan:</b> 1 poin lagi akan di-<b>{action}</b>!",
    'vp.limit_reached'        => "🚨 {mention} {action} karena mencapai batas poin sanksi (<b>{limit}/{limit}</b>)!\n📌 Pelanggaran: <b>{reason}</b>\n🔄 Poin direset ke 0.",
    'vp.check'                => "🔴 <b>Poin Sanksi</b> — {mention}\n\nPoin: <b>{points}/{limit}</b>\nStatus sistem: {status}",
    'vp.status_active'        => '✅ Aktif',
    'vp.status_inactive'      => '❌ Nonaktif',
    'vp.reset'                => "✅ Poin sanksi {mention} direset ke <b>0</b>.",
    'vp.top_title'            => "🔴 <b>Top Pelanggar Grup</b>\n\n",
    'vp.top_empty'            => "✅ Tidak ada user dengan poin sanksi.",

    // ======================== VOTE KICK ========================
    'vk.no_feature'           => '❌ Fitur vote kick belum diaktifkan di grup ini.',
    'vk.self_vote'            => '❌ Kamu tidak bisa memvote kick dirimu sendiri!',
    'vk.cant_vk_admin'        => '❌ Tidak bisa vote kick admin!',
    'vk.admin_only_err'       => '❌ Hanya admin yang bisa memulai vote kick di grup ini.',
    'vk.cooldown_err'         => '⏳ Kamu baru saja memulai vote kick. Tunggu <b>{min} menit</b> lagi.',
    'vk.percent_set'          => '✅ Threshold diatur: {n}% dari total member',
    'vk.session_exists'       => '⚠️ Sudah ada sesi vote kick aktif untuk user tersebut (ID sesi: #{id}).',
    'vk.cancelled_msg'        => "🚫 <b>Vote Kick Dibatalkan</b>\n\nSesi vote kick untuk {name} telah dibatalkan oleh {by}.",
    'vk.cancelled_reply'      => "✅ Sesi vote kick untuk {name} dibatalkan.",
    'vk.no_session'           => '❌ Tidak ada sesi vote kick aktif untuk user tersebut.',
    'vk.cancel_no_perm'       => '❌ Hanya pemrakarsa atau admin yang bisa membatalkan.',
    'vk.executed'             => "🗳️ Vote kick <b>berhasil</b>! {mention} di-kick ({yes} setuju, {no} menolak).",
    'vk.failed'               => "🗳️ Vote kick <b>gagal</b>. Waktu habis ({yes}/{threshold} suara).",
    'vk.title'                => "🗳️ <b>Vote Kick</b>",
    'vk.target'               => "👤 Target: <b>{name}</b>",
    'vk.reason'               => "📝 Alasan: {reason}",
    'vk.by'                   => "🚀 Diajukan oleh: {by}",
    'vk.votes'                => "✅ Setuju: <b>{yes}</b> | ❌ Tolak: <b>{no}</b>",
    'vk.threshold'            => "🎯 Butuh: <b>{threshold}</b> suara",
    'vk.expires'              => "⏱ Berakhir: <b>{time}</b> menit",
    'vk.msg'                  => "🗳️ <b>Vote Kick</b>\n\n👤 Target: <b>{target}</b>\n📝 Alasan: {reason}\n\n{bar}✅ Setuju: <b>{yes}</b>  |  ❌ Tolak: <b>{no}</b>\n📊 Dibutuhkan: <b>{thresh} suara setuju</b>\n⏱️ Sisa waktu: <b>±{min} menit</b>\n\n<i>Setiap anggota hanya bisa memberikan satu suara.</i>",
    'vk.btn_yes'              => '✅ Setuju ({yes})',
    'vk.btn_no'               => '❌ Tolak ({no})',
    'vk.btn_cancel'           => '🚫 Batalkan',
    'vk.already_voted'        => '⚠️ Kamu sudah memberikan suara!',
    'vk.voted_yes'            => '✅ Kamu memilih: Setuju',
    'vk.voted_no'             => '❌ Kamu memilih: Tolak',
    'vk.not_group_only'       => '❌ Hanya untuk grup.',

    // ======================== NOTES ========================
    'note.saved'              => "✅ Note <b>#{keyword}</b> disimpan.",
    'note.deleted'            => "✅ Note <b>#{keyword}</b> dihapus.",
    'note.not_found'          => "❌ Note <b>#{keyword}</b> tidak ditemukan.",
    'note.list_title'         => "📝 <b>Notes Grup</b>\n\n",
    'note.list_empty'         => "📝 Belum ada notes.",
    'note.usage_save'         => "❌ Penggunaan: <code>/save keyword teks</code>",
    'note.usage_get'          => "❌ Penggunaan: <code>/get keyword</code> atau <code>#keyword</code>",

    // ======================== FILTERS ========================
    'filter.saved'            => "✅ Filter <b>{keyword}</b> disimpan.",
    'filter.deleted'          => "✅ Filter <b>{keyword}</b> dihapus.",
    'filter.not_found'        => "❌ Filter tidak ditemukan.",
    'filter.list_title'       => "📋 <b>Filter Aktif</b>\n\n",
    'filter.list_empty'       => "📋 Belum ada filter aktif.",

    // ======================== BANNED WORDS ========================
    'bw.added'                => "✅ Kata terlarang ditambahkan: <b>{word}</b>",
    'bw.removed'              => "✅ Kata <b>{word}</b> dihapus dari daftar terlarang.",
    'bw.not_found'            => "❌ Kata tersebut tidak ada di daftar terlarang.",
    'bw.list_title'           => "🚫 <b>Kata Terlarang</b>:\n\n",
    'bw.list_empty'           => "🚫 Belum ada kata terlarang.",

    // ======================== SETTINGS ========================
    'settings.use_pm'         => "⚙️ Settings sekarang hanya bisa diakses via chat pribadi dengan bot.\n\nKlik tombol di bawah untuk membuka settings grup ini.",
    'settings.group_prompt'   => "⚙️ <b>Panel Pengaturan</b>\n\nPilih cara membuka pengaturan grup ini:",
    'settings.btn_open_here'  => "⚙️ Buka di Sini",
    'settings.btn_open_pm'    => "💬 Buka di Chat Pribadi",
    'settings.title'          => "⚙️ <b>Pengaturan Grup</b>",
    'settings.section_member' => "\n<b>— Anggota —</b>\n",
    'settings.section_sec'    => "\n<b>— Keamanan —</b>\n",
    'settings.section_ctrl'   => "\n<b>— Kontrol —</b>\n",
    'settings.log_channel'    => "📝 Log: {channel}",
    'settings.log_not_set'    => '<i>Belum diatur</i>',

    'settings.welcome_title'  => "👋 <b>Pengaturan Welcome</b>",
    'settings.welcome_status' => "Status: <b>{status}</b>",
    'settings.welcome_msg'    => "<b>Pesan Welcome:</b>\n<i>{message}</i>",
    'settings.welcome_vars'   => "<b>Variabel:</b> <code>{name}</code> <code>{mention}</code> <code>{group}</code> <code>{count}</code> <code>{id}</code>",
    'settings.welcome_btns'   => "<b>Tombol:</b> {info}",
    'settings.welcome_btns_none' => '<i>Belum ada</i>',
    'settings.welcome_btns_count' => '{n} tombol aktif',

    'settings.goodbye_title'  => "👋 <b>Pengaturan Goodbye</b>",
    'settings.goodbye_msg'    => "<b>Pesan Goodbye:</b>\n<i>{message}</i>",

    'settings.captcha_title'  => "🔐 <b>Pengaturan Captcha</b>",
    'settings.captcha_type'   => "Metode: <b>{type}</b>",
    'settings.captcha_info'   => "Captcha akan dikirim ke user baru. User yang tidak menyelesaikan verifikasi dalam batas waktu akan di-kick otomatis.",
    'settings.captcha_timeout'=> "Timeout: <b>{timeout} detik</b>",

    'settings.flood_title'    => "🌊 <b>Pengaturan Anti-Flood</b>",
    'settings.flood_limit'    => "Limit: <b>{limit} pesan/menit</b>",
    'settings.flood_action'   => "Aksi: <b>{action}</b>",
    'settings.flood_duration' => "Durasi mute: <b>{duration} detik</b>",

    'settings.antilink_title' => "🔗 <b>Pengaturan Anti-Link</b>",
    'settings.antilink_wl'    => "<b>Whitelist ({count} domain):</b>",
    'settings.antilink_wl_empty' => '<i>Kosong — semua link diblokir</i>',

    'settings.wordfilter_title' => "🚫 <b>Filter Kata Terlarang</b>",
    'settings.wordfilter_bw'  => "<b>Kata terlarang ({count}):</b>",
    'settings.wordfilter_empty' => '<i>Belum ada kata terlarang</i>',

    'settings.warn_title'     => "⚠️ <b>Pengaturan Warn</b>",
    'settings.warn_limit'     => "Limit peringatan: <b>{limit}</b>",
    'settings.warn_action'    => "Aksi saat batas tercapai: <b>{action}</b>",

    'settings.vp_title'       => "🔴 <b>Pengaturan Poin Sanksi</b>",
    'settings.vp_limit'       => "Batas poin: <b>{limit}</b>",
    'settings.vp_action'      => "Aksi saat batas tercapai: <b>{action}</b>",
    'settings.vp_howto'       => "<b>Cara kerja:</b>\nSetiap pelanggaran aturan aktif (anti-link, anti-spam, word filter, lock, dll.) akan menambah 1 poin sanksi ke pengguna. Jika total poin mencapai batas, pengguna akan otomatis di-<b>{action}</b> dan poinnya direset ke 0.\n\n<b>Perintah:</b>\n<code>/vpoints [@user]</code> — cek poin\n<code>/resetvp @user</code> — reset poin\n<code>/topviolators</code> — daftar pelanggar teratas",

    'settings.vk_title'           => "🗳️ <b>Pengaturan Vote Kick</b>",
    'settings.vk_mode'            => "Mode threshold: <b>{mode}</b>",
    'settings.vk_mode_abs_label'  => "Absolut (jumlah suara tetap)",
    'settings.vk_mode_pct_label'  => "Persentase (% dari member aktif)",
    'settings.vk_threshold'       => "Batas suara 'Setuju': <b>{n} suara</b>",
    'settings.vk_threshold_pct'   => "Threshold: <b>{n}% dari total member</b> (min. 5 suara)",
    'settings.vk_duration'        => "Durasi voting: <b>{n} menit</b>",
    'settings.vk_admin_only_on'   => "🔑 Inisiator: <b>Hanya Admin</b>",
    'settings.vk_admin_only_off'  => "👤 Inisiator: <b>Semua Member</b>",
    'settings.vk_cooldown'        => "⏳ Cooldown per user: <b>{n} menit</b>",
    'settings.vk_cooldown_off'    => "⏳ Cooldown per user: <b>Nonaktif</b>",
    'settings.vk_howto'           => "<b>Cara kerja:</b>\nAnggota grup dapat mengajukan vote kick untuk member bermasalah. Jika jumlah suara 'Setuju' mencapai batas, bot akan langsung meng-kick user tersebut. Setiap anggota hanya bisa memberikan <b>satu suara</b> per sesi.\n\n<b>Mode Persentase:</b> Threshold dihitung otomatis berdasarkan jumlah member (cocok untuk grup besar).\n\n<b>Perintah:</b>\n<code>/votekick @user [alasan]</code> — mulai vote kick\n<code>/cancelvotekick @user</code> — batalkan sesi aktif",
    'settings.ai_title'       => "🤖 <b>Pengaturan AI ({provider})</b>",
    'settings.ai_howto'       => "💬 Model Chat aktif: <b>{chat_model}</b>\n🎨 Model Gambar aktif: <b>{image_model}</b>\n\n<b>Cara pakai:</b>\n<code>/ai [pertanyaan]</code> — tanya AI\nReply pesan + <code>/ai</code> — analisis pesan tersebut\n<code>/resetai</code> — hapus riwayat percakapan\n\n<b>Fitur:</b>\n• Multi-turn (ingat konteks 10 pesan terakhir per user)\n• Reply pesan untuk analisis konteks\n• Tersedia di private chat (selalu aktif)\n• Di grup: harus diaktifkan admin",

    'settings.locks_title'    => "🔒 <b>Pengaturan Lock</b>",

    'settings.rules_title'    => "📋 <b>Peraturan Grup</b>",
    'settings.rules_empty'    => '<i>Belum diatur</i>',

    'settings.status_active'  => '✅ Aktif',
    'settings.status_inactive'=> '❌ Nonaktif',

    'settings.updated'        => '✅ Pengaturan diperbarui!',
    'settings.welcome_updated'=> '✅ Pesan welcome diperbarui!',
    'settings.goodbye_updated'=> '✅ Pesan goodbye diperbarui!',
    'settings.rules_updated'  => '✅ Peraturan grup diperbarui!',
    'settings.logch_updated'  => '✅ Log channel diperbarui: {channel}',
    'settings.wl_updated'     => '✅ Whitelist anti-link diperbarui!',
    'settings.bw_added'       => '✅ Kata terlarang ditambahkan!',
    'settings.captcha_timeout_updated' => '✅ Timeout captcha diatur ke {sec} detik!',
    'settings.flood_dur_updated' => '✅ Durasi mute flood diatur ke {sec} detik!',
    'settings.raid_limit_updated' => '✅ Batas anti-raid diatur ke {n} join/menit!',
    'settings.btn_updated'    => '✅ Tombol welcome ditambahkan!',
    'settings.btn_format_err' => "❌ Format salah! Gunakan:\n<code>Teks Tombol | https://url.com</code>",
    'settings.captcha_range'  => '❌ Waktu captcha harus antara 30-300 detik.',
    'settings.flood_range'    => '❌ Durasi mute harus antara 30-86400 detik.',
    'settings.raid_range'     => '❌ Batas raid harus antara 3-100 join/menit.',

    // ======================== KB LABELS ========================
    'kb.back'                 => '⬅️ Kembali',
    'kb.refresh'              => '🔄 Refresh',
    'kb.cancel'               => '❌ Batal',
    'kb.enable'               => '✅ Aktifkan',
    'kb.disable'              => '❌ Nonaktifkan',
    'kb.toggle'               => 'Toggle',
    'kb.set_msg'              => '✏️ Ubah Pesan',
    'kb.add_btn'              => '🔗 Tambah Tombol ({n})',
    'kb.del_btn'              => '🗑️ Hapus Tombol',
    'kb.clear_btns'           => '🗑️ Hapus Tombol',
    'kb.set_timeout'          => '⏱ Atur Timeout',
    'kb.set_limit'            => '🔢 Atur Limit',
    'kb.set_action'           => '⚡ Atur Aksi',
    'kb.set_duration'         => '⏰ Atur Durasi',
    'kb.set_whitelist'        => '📋 Atur Whitelist',
    'kb.set_wl'               => 'Whitelist ({n})',
    'kb.clear_wl'             => '🗑️ Kosongkan WL',
    'kb.add_word'             => 'Tambah Kata ({n})',
    'kb.del_all'              => '🗑️ Hapus Semua',
    'kb.clear_words'          => '🗑️ Hapus Semua',
    'kb.set_rules'            => '✏️ Set Peraturan',
    'kb.del_rules'            => '🗑️ Hapus Peraturan',
    'kb.rules_agree_btn'      => 'Tombol "Saya Setuju"',
    'kb.require_verification' => 'Verifikasi Kontak+Lokasi',
    'kb.set_log'              => '📝 Atur Log Channel',
    'kb.set_log_ch'           => 'Set Log Channel',
    'kb.delete'               => 'Hapus',
    'kb.invite_link'          => '🔗 Invite Link',
    'kb.readonly'             => '🔇 Readonly',
    'kb.manage_locks'         => '🔒 Kelola Lock',
    'kb.btn_language'         => '🌐 Bahasa',
    'kb.language'             => 'Bahasa',
    'kb.filter'               => 'Filter Kata',
    'kb.clean_svc'            => 'Bersihkan Svc',
    'kb.violation'            => 'Poin Sanksi',
    'kb.rules'                => 'Peraturan',
    'kb.back_settings'        => '⬅️ Kembali ke Pengaturan',
    'kb.close'                => '❌ Tutup',
    'kb.clearall'             => '🗑️ Hapus Semua Pesan',
    'kb.edit_msg_welcome'     => 'Ubah Pesan Welcome',
    'kb.edit_msg_goodbye'     => 'Ubah Pesan Goodbye',
    'kb.action'               => 'Aksi',
    'kb.limit'                => 'Batas',
    'kb.votes'                => 'suara',

    // ======================== SA PANEL ========================
    'sa.panel_title'          => "🔐 <b>Panel Superadmin</b>\n\n👤 ID: <code>{id}</code>\n🕐 {time}\n\n📊 <b>Ringkasan Bot:</b>\n• Grup aktif: <b>{groups}</b>\n• User di cache: <b>{users}</b>\n• Global ban: <b>{gban}</b>\n• Total broadcast: <b>{broadcast}</b>\n• Total SA: <b>{total_sa}</b> (static: {static_sa} | dynamic: {dyn_sa})\n\n🔧 Mode Maintenance: {maint}",
    'sa.maint_on'             => '<b>🔴 Aktif</b>',
    'sa.maint_off'            => '<b>✅ Nonaktif</b>',
    'sa.gban_added'           => "🌐🚫 User <code>{uid}</code> di-global ban!\n📝 Alasan: {reason}",
    'sa.gban_removed'         => "✅ Global ban user <code>{uid}</code> dicabut!",
    'sa.cleargban_confirm'    => "⚠️ <b>Konfirmasi Hapus Semua Global Ban</b>\n\nAnda akan menghapus <b>{count}</b> global ban.\nTindakan ini tidak bisa dibatalkan!\n\nYakin?",
    'sa.cleargban_done'       => "✅ <b>Berhasil!</b>\n\n<b>{count}</b> global ban telah dihapus dari database.",
    'sa.cleargban_all'        => "✅ Semua global ban dihapus. Total <b>{count} user</b> dibebaskan.",
    'sa.broadcast_done'       => "✅ Broadcast selesai!\n📤 Berhasil: {ok} | Gagal: {fail}",
    'sa.groups_title'         => "👥 <b>Daftar Grup</b> (total: <b>{total}</b>)\n\nHalaman {page}/{total_page}\n\n",
    'sa.groups_empty'         => "<i>Tidak ada grup.</i>",
    'sa.groups_no_name'       => 'Tanpa Nama',
    'sa.gbanlist_title'       => "🌐🚫 <b>Global Ban List</b> (total: <b>{total}</b>)\n\n",
    'sa.gbanlist_empty'       => "<i>Daftar kosong.</i>",
    'sa.gbanlist_more'        => "\n<i>...dan {n} lainnya</i>",
    'sa.broadcast_prompt'     => "📢 <b>Broadcast ke {count} Grup</b>\n\nKirim pesan yang ingin di-broadcast:\n\n💡 <i>Mendukung teks, foto, video, dokumen, audio, dan GIF.\nUntuk media, caption akan disertakan otomatis.</i>",
    'sa.gban_add_prompt'      => "🌐🚫 <b>Tambah Global Ban</b>\n\nKirim pesan dengan format:\n<code>USER_ID alasan ban</code>\n\nContoh: <code>123456789 spammer</code>",
    'sa.gban_rm_prompt'       => "✅ <b>Hapus Global Ban</b>\n\nKirim User ID yang ingin di-ungban:",
    'sa.gban_format_err'      => "❌ Format: <code>USER_ID alasan</code>",
    'sa.ping'                 => "🏓 <b>Pong!</b>\n⏱️ Latensi API: <b>{ms}ms</b>\n🤖 Bot: @{username}",
    'sa.stats_title'          => "📊 <b>Statistik Global Bot</b>",
    'sa.stats_groups'         => "👥 Total Grup: <b>{n}</b>",
    'sa.stats_users'          => "👤 Total User (cache): <b>{n}</b>",
    'sa.stats_gban'           => "🌐🚫 Global Ban: <b>{n}</b>",
    'sa.stats_broadcast'      => "📢 Total Broadcast: <b>{n}</b>",
    'sa.stats_warn'           => "⚠️ Total Warn: <b>{n}</b>",
    'sa.stats_ban'            => "🚫 Total Ban: <b>{n}</b>",
    'sa.stats_mute'           => "🔇 Total Mute: <b>{n}</b>",
    'sa.stats_msg'            => "💬 Total Pesan: <b>{n}</b>",
    'sa.stats_notes'          => "📝 Total Note: <b>{n}</b>",
    'sa.stats_filters'        => "📋 Total Filter: <b>{n}</b>",
    'sa.stats_total_sa'       => "👑 Total SA: <b>{n}</b>",
    'sa.stats_maint'          => "🔧 Maintenance: {status}",
    'sa.stats_time'           => "🕐 Waktu Server: <b>{time}</b>",
    'sa.announce_text'        => "📢 <b>PENGUMUMAN RESMI BOT</b>\n\n{text}\n\n— <i>Bot Management</i>",
    'sa.announce_done'        => "📢 <b>Pengumuman Terkirim!</b>\n✅ Berhasil: <b>{ok}</b>\n❌ Gagal: <b>{fail}</b>",
    'sa.lookup_title'         => "🔍 <b>User Lookup</b>",
    'sa.maintenance_on'       => "🔴 Mode maintenance <b>diaktifkan</b>.",
    'sa.maintenance_off'      => "✅ Mode maintenance <b>dinonaktifkan</b>.",
    'sa.maintenance_msg'      => "🔧 Bot sedang dalam maintenance. Silakan coba lagi nanti.",
    'vk.threshold_set'        => "✅ Batas suara setuju diatur: {n}",
    'vk.duration_set'         => "✅ Durasi voting diatur: {n} menit",

    // ======================== RULES ========================
    'rules.title'             => "📋 <b>Peraturan Grup</b>",
    'rules.empty'             => 'Belum ada peraturan yang ditetapkan.',
    'rules.agree_btn'              => '✅ Saya Setuju',
    'rules.agreed'                 => '✅ Kamu telah menyetujui peraturan grup!',
    'rules.agree_generic_prompt'   => "👋 Halo <b>{name}</b>!\n\nSebelum bisa mengirim pesan di grup ini, tekan tombol di bawah untuk menyetujui aturan grup.\n\n─────────────────────\nTekan <b>✅ Saya Setuju</b> untuk membuka akses.",
    'rules.dm_sent_notif'          => "👋 {mention}, kamu mendapat DM dari bot — buka DM dan tekan tombol <b>✅ Saya Setuju</b> untuk bisa mengirim pesan!",
    'rules.verify_required_alert'  => "🔐 Verifikasi diperlukan — cek DM kamu dari bot!",
    'rules.verify_dm_failed'       => "❌ Gagal kirim DM. Mulai bot dulu: klik @botname lalu /start, kemudian tekan tombol lagi.",
    'rules.verify_already_done'    => "✅ Kamu sudah terverifikasi sebelumnya! Akses diberikan.",
    'rules.verify_contact_prompt'  => "🔐 <b>Verifikasi Anggota Grup</b>\n\nHalo! Untuk bergabung dengan grup <b>{group}</b>, kamu perlu verifikasi singkat sebagai langkah pencegahan bullying.\n\n<b>Langkah 1 dari 2 — Nomor HP</b>\nTekan tombol di bawah untuk berbagi nomor HP kamu.\n\n<i>⚠️ Data disimpan dan hanya dapat dilihat oleh admin grup untuk keperluan keamanan.</i>",
    'rules.verify_location_prompt' => "📍 <b>Langkah 2 dari 2 — Lokasi</b>\n\nTekan tombol di bawah untuk berbagi lokasimu.\n\n<i>Lokasi digunakan untuk memverifikasi bahwa kamu adalah pengguna nyata dan mencegah akun palsu/bot.</i>",
    'rules.verify_done'            => "✅ <b>Verifikasi Berhasil!</b>\n\nData kamu telah tersimpan. Sekarang kamu sudah bisa mengirim pesan di grup. Selamat bergabung! 🎉",
    'rules.verify_btn_contact'     => '📱 Bagikan Nomor HP',
    'rules.verify_btn_location'    => '📍 Bagikan Lokasi',

    // ======================== USER INFO ========================
    'me.title'                => "👤 <b>Profil Saya</b>",
    'me.name'                 => "• Nama: <b>{name}</b>",
    'me.username'             => "\n• Username: @{username}",
    'me.id'                   => "• ID: <code>{id}</code>",
    'me.warns'                => "⚠️ Peringatan: <b>{count}/{limit}</b>",
    'me.vp'                   => "🔴 Poin Sanksi: <b>{points}/{limit}</b>",
    'me.mute'                 => "🔇 Mute: {status}",
    'me.mute_active'          => '🔇 Aktif',
    'me.mute_until'           => ' hingga {time}',
    'me.mute_perm'            => ' (permanen)',
    'me.mute_no'              => '✅ Tidak',
    'me.gban'                 => "🌐🚫 Global Ban: {status}",
    'me.gban_yes'             => '🚫 Ya',
    'me.gban_no'              => '✅ Tidak',

    'id.info'                 => "🪪 <b>Info ID</b>\n• Chat ID: <code>{chat_id}</code>\n• User ID Anda: <code>{user_id}</code>",
    'id.target'               => "\n• ID target: <code>{id}</code>",

    'info.title'              => "👤 <b>Info User</b>",
    'info.name'               => "• Nama: <b>{name}</b>",
    'info.username'           => "• Username: @{username}",
    'info.id'                 => "• ID: <code>{id}</code>",
    'info.status'             => "• Status: {status}",
    'info.warns'              => "• Peringatan: {count}/{limit}",
    'info.vp'                 => "• Poin Sanksi: {points}/{limit}",
    'info.gban'               => "• Global ban: {status}",

    'stats.title'             => "📊 <b>Statistik Grup</b>",
    'stats.members'           => "\n👥 Anggota: <b>{n}</b>",
    'stats.messages'          => "\n💬 Pesan: <b>{n}</b>",
    'stats.banned'            => "\n🚫 Ban: <b>{n}</b>",
    'stats.warned'            => "\n⚠️ Warn: <b>{n}</b>",
    'stats.muted'             => "\n🔇 Mute: <b>{n}</b>",

    'adminlist.title'         => "👮 <b>Daftar Admin</b>\n\n",

    // ======================== AI ========================
    'ai.usage'                => "🤖 <b>Cara pakai:</b>\n<code>/ai [pertanyaan kamu]</code>\n\nContoh: <code>/ai apa itu machine learning?</code>",
    'ai.disabled'             => '❌ Fitur AI belum diaktifkan di grup ini.',
    'ai.reset'                => '🗑️ Riwayat percakapan AI kamu berhasil dihapus.',

    // ======================== TRANSLATE ========================
    'translate.usage'         => "🌐 <b>Cara pakai:</b>\n<code>/translate [bahasa_tujuan] [teks]</code>\n\nAtau reply ke pesan:\n<code>/translate [bahasa_tujuan]</code>\n\nContoh:\n• <code>/translate en Halo, apa kabar?</code>\n• <code>/translate id Hello, how are you?</code>\n• <code>/translate ja Selamat pagi</code>\n\n📌 Gunakan kode bahasa: <code>id</code> Indonesia, <code>en</code> Inggris, <code>ja</code> Jepang, <code>ko</code> Korea, <code>zh</code> Mandarin, <code>ar</code> Arab, <code>fr</code> Prancis, <code>de</code> Jerman, <code>es</code> Spanyol, dll.",
    'translate.disabled'      => '❌ Fitur AI belum diaktifkan di grup ini.',
    'translate.working'       => '🌐 Sedang menerjemahkan...',
    'translate.result'        => "🌐 <b>Terjemahan</b> → <b>{lang}</b>\n\n{result}",
    'translate.error'         => '❌ Gagal menerjemahkan. Coba lagi.',

    // ======================== DETECT LANGUAGE ========================
    'detect.usage'            => "🔍 <b>Cara pakai:</b>\n<code>/detect [teks]</code>\n\nAtau reply ke pesan:\n<code>/detect</code>\n\nContoh:\n• <code>/detect Bonjour tout le monde</code>\n• <code>/detect 今日は良い天気ですね</code>",
    'detect.disabled'         => '❌ Fitur AI belum diaktifkan di grup ini.',
    'detect.working'          => '🔍 Mendeteksi bahasa...',
    'detect.result'           => "🔍 <b>Deteksi Bahasa</b>\n\n{result}",
    'detect.error'            => '❌ Gagal mendeteksi bahasa. Coba lagi.',

    // ======================== SUMMARIZE ========================
    'summarize.usage'         => "📝 <b>Cara pakai:</b>\n<code>/summarize [teks panjang]</code>\n\nAtau reply ke pesan:\n<code>/summarize</code>\n\nContoh:\n• <code>/summarize [paste artikel panjang di sini]</code>\n• Reply pesan panjang lalu ketik <code>/summarize</code>",
    'summarize.disabled'      => '❌ Fitur AI belum diaktifkan di grup ini.',
    'summarize.working'       => '📝 Sedang meringkas...',
    'summarize.result'        => "📝 <b>Ringkasan</b>\n\n{result}",
    'summarize.error'         => '❌ Gagal meringkas teks. Coba lagi.',

    // ======================== AI IMAGE ========================
    'img.usage'               => "🎨 <b>Cara pakai:</b>\n<code>/imagine [deskripsi gambar]</code>\n\nContoh:\n• <code>/imagine kucing lucu sedang bermain di taman</code>\n• <code>/imagine logo startup teknologi minimalis</code>\n• <code>/imagine pemandangan gunung saat sunset</code>",
    'img.generating'          => '🎨 Sedang membuat gambar, mohon tunggu...',
    'img.failed'              => '❌ Gagal membuat gambar. Coba lagi dengan deskripsi yang berbeda.',
    'img.caption'             => "🎨 <b>Gambar AI</b>\n📝 <i>{prompt}</i>",
    'img.disabled'            => '❌ Fitur AI belum diaktifkan di grup ini.',
    'img.private_only'        => "🔒 Perintah <b>/gambar</b> hanya bisa digunakan di <b>chat pribadi</b> dengan bot.\n\nKirim perintahnya langsung ke bot ya!",
    'img.limit_reached'       => "⛔ Kamu sudah mencapai batas pembuatan gambar hari ini (<b>{used}/{limit}</b>).\n\nCoba lagi besok ya!",
    'img.edit_usage'          => "✏️ <b>Edit Gambar AI</b>\n\nReply ke foto (terutama hasil /imagine) lalu kirim:\n<code>/edit [instruksi perubahan]</code>\n\nContoh:\n• <code>/edit ubah latar belakang jadi malam hari</code>\n• <code>/edit tambahkan efek bokeh</code>\n• <code>/edit ganti warna jadi hitam putih</code>",
    'img.edit_no_photo'       => "❌ Reply ke sebuah foto terlebih dahulu, lalu gunakan:\n<code>/edit [instruksi perubahan]</code>",
    'img.edit_no_instruction' => '❌ Tulis instruksi perubahan setelah /edit.\nContoh: <code>/edit ubah latar belakang jadi pantai</code>',
    'img.edit_not_image'      => '❌ File yang kamu kirim bukan gambar. Harap kirim file berformat JPG, PNG, atau WEBP.',
    'img.edit_generating'     => '✏️ Sedang memproses edit gambar, mohon tunggu...',
    'img.edit_caption'        => "✏️ <b>Gambar AI (Diedit)</b>\n📝 <i>{prompt}</i>",

    // ======================== CODE RUNNER ========================
    'run.help'                =>
        "🖥 <b>Code Runner</b>\n" .
        "Eksekusi kode langsung dari Telegram!\n\n" .
        "📌 <b>Format penggunaan:</b>\n" .
        "<code>/run &lt;bahasa&gt;\nkode kamu di sini</code>\n\n" .
        "✏️ <b>Contoh:</b>\n" .
        "<code>/run python\nprint(\"Hello, World!\")</code>\n\n" .
        "Atau gunakan <i>code block</i> Markdown:\n" .
        "<code>/run js\n```\nconsole.log(\"Hello!\")\n```</code>\n\n" .
        "📋 <b>Bahasa yang didukung:</b>\n" .
        "<code>python</code>  <code>js</code>  <code>ts</code>  <code>java</code>  <code>kotlin</code>\n" .
        "<code>c</code>  <code>cpp</code>  <code>c#</code>  <code>go</code>  <code>rust</code>  <code>swift</code>\n" .
        "<code>php</code>  <code>ruby</code>  <code>perl</code>  <code>bash</code>  <code>lua</code>\n" .
        "<code>r</code>  <code>dart</code>  <code>groovy</code>  <code>haskell</code>  <code>scala</code>\n" .
        "<code>elixir</code>  <code>erlang</code>  <code>fortran</code>  <code>pascal</code>\n" .
        "<code>prolog</code>  <code>clojure</code>  <code>lisp</code>  <code>asm</code>  <code>sql</code>\n" .
        "<code>cobol</code>  <code>objc</code>  <code>ocaml</code>  <code>d</code>  <code>vb</code>  <code>f#</code>\n\n" .
        "💡 <i>Alias tersedia: </i><code>py</code><i>, </i><code>node</code><i>, </i><code>rs</code><i>, </i><code>sh</code><i>, dll.</i>",
    'run.no_output'           => '<i>Tidak ada output.</i>',
    'run.truncated'           => '... (dipotong)',
    'run.output_truncated'    => '... (output dipotong)',
    'run.conn_error'          => 'Koneksi ke server kompilasi gagal: {err}',
    'run.invalid_response'    => 'Respons tidak valid dari server (HTTP {code})',

    // ======================== SLOWMODE ========================
    'slowmode.set'            => "⏱ Slowmode diatur ke <b>{sec} detik</b>.",
    'slowmode.off'            => "⏱ Slowmode dimatikan.",
    'slowmode.usage'          => "❌ Penggunaan: <code>/slowmode [detik]</code> atau <code>/slowmode 0</code> untuk mematikan.",

    // ======================== LOCKS ========================
    'lock.locked'             => "🔒 <b>{type}</b> dikunci.",
    'lock.unlocked'           => "🔓 <b>{type}</b> dibuka.",
    'lock.usage'              => "❌ Penggunaan: <code>/lock [sticker|gif|media|voice|game|poll|forward]</code>",
    'lock.invalid'            => "❌ Tipe lock tidak valid.",

    // ======================== MISC ========================
    'report.sent'             => "📢 <b>Laporan dikirim ke admin!</b>\n{mention} melaporkan pesan.",
    'pin.usage'               => "❌ Reply pesan yang ingin di-pin.",
    'unpin.usage'             => "❌ Reply pesan yang ingin di-unpin.",
    'del.usage'               => "❌ Reply pesan yang ingin dihapus.",
    'ro.on'                   => "🔇 Mode <b>Readonly</b> diaktifkan.",
    'ro.off'                  => "🔊 Mode <b>Readonly</b> dinonaktifkan.",
    'invite.link'             => "🔗 <b>Invite Link:</b>\n{link}",
    'invite.failed'           => "❌ Gagal membuat invite link.",
    'tagall.text'             => "📣 <b>Semua anggota dipanggil!</b>\n{mentions}",
    'kickbots.done'           => "🤖 Mengeluarkan bot dari grup...",
    'kickbots.done_n'         => "✅ {n} bot dikeluarkan.",
    'purge.done'              => "🗑️ {n} pesan dihapus.",
    'purge.usage'             => "❌ Reply pesan pertama yang ingin dihapus.",
    'purge.user_usage'        => "❌ <b>Cara pakai:</b>\n• Reply pesan → <code>/purge</code> (hapus dari situ sampai sekarang)\n• <code>/purge @username</code> — hapus semua pesan dari user tersebut (maks 48 jam)",
    'purge.user_not_found'    => "❌ Tidak ada pesan dari <b>{mention}</b> yang tercatat dalam 48 jam terakhir.\n\n<i>Hanya pesan yang dikirim setelah bot aktif yang bisa dihapus.</i>",
    'purge.user_done'         => "🗑️ <b>{n}</b> pesan dari {mention} berhasil dihapus.",
    'clearall.confirm'        => "⚠️ <b>Hapus Semua Pesan?</b>\n\nBot akan menghapus <b>{n} pesan</b> yang tercatat dalam 48 jam terakhir di grup ini.\n\n⚠️ Tindakan ini <b>tidak dapat dibatalkan!</b>\n\n<i>Catatan: Telegram hanya mengizinkan penghapusan pesan yang berumur &lt; 48 jam.</i>",
    'clearall.btn_yes'        => "🗑️ Ya, Hapus Semua",
    'clearall.btn_no'         => "❌ Batal",
    'clearall.done'           => "✅ Selesai! Berhasil menghapus <b>{n}</b> pesan.",
    'clearall.cancelled'      => "❌ Dibatalkan.",
    'settitle.set'            => "✅ Gelar <b>{title}</b> berhasil diatur untuk {mention}.",
    'settitle.usage'          => "❌ Penggunaan: <code>/settitle @user gelar</code>\n\n<i>Contoh: /settitle @user Moderator</i>",
    'settitle.failed'         => "❌ Gagal mengatur gelar.\n\n⚠️ <b>Catatan:</b> Fitur ini hanya bisa digunakan untuk admin yang dipromosi melalui bot (via /promote).\nAdmin yang dipromosi secara manual lewat Telegram tidak dapat diubah gelarnya oleh bot.",
    'auto_pin'                => "📌 Pengumuman dari {mention} telah di-pin otomatis!",

    // ======================== WELCOME/GOODBYE ========================
    'welcome.default'         => 'Selamat datang {mention} di <b>{group}</b>! 👋',
    'goodbye.default'         => 'Sampai jumpa {name}! 👋',

    // ======================== PENDING INPUT ========================
    'input.set_welcome'       => "✏️ Kirim pesan welcome baru:\n\nVariabel: <code>{name}</code> <code>{mention}</code> <code>{group}</code> <code>{count}</code> <code>{id}</code>",
    'input.set_goodbye'       => "✏️ Kirim pesan goodbye baru:\n\nVariabel: <code>{name}</code> <code>{mention}</code>",
    'input.set_rules'         => "✏️ Kirim teks peraturan grup:",
    'input.set_logchannel'    => "✏️ Kirim username atau ID log channel:",
    'input.set_antilink_wl'   => "✏️ Kirim domain whitelist (pisahkan dengan koma):\n\nContoh: <code>telegram.me, t.me, google.com</code>",
    'input.set_banword'       => "✏️ Kirim kata yang ingin dilarang (pisahkan koma):",
    'input.set_welcome_btn'   => "✏️ Kirim data tombol dengan format:\n<code>Teks Tombol | https://url.com</code>",
    'input.set_captcha_timeout' => "✏️ Kirim durasi timeout captcha dalam detik (30-300):",
    'input.set_flood_duration'  => "✏️ Kirim durasi mute flood dalam detik (30-86400):",
    'input.set_raid_limit'      => "✏️ Kirim batas maksimal join per menit (3-100):",

    // ======================== JOIN ========================
    'join.raid_active'          => "🛡️ Anti-raid aktif! Join massal terdeteksi.",
    'join.gban_kicked'          => "🚫 {mention} di-ban karena ada di daftar Global Ban.",
    'join.rules_text'           => "📋 <b>Peraturan Grup</b>\n\nHalo <b>{name}</b>! Sebelum dapat mengirim pesan, baca dan setujui peraturan berikut:\n\n{rules}\n\n─────────────────────\nTekan tombol <b>✅ Saya Setuju</b> untuk membuka akses mengirim pesan.",

    // ======================== GBAN ========================
    'gban.done_mention'         => "🌐🚫 {mention} di-<b>global ban</b>\n📝 Alasan: {reason}",
    'gban.undone_mention'       => "✅ {mention} di-<b>ungban global</b>",

    // ======================== MODERATION EXTRA ========================
    'mod.unpinall'              => "📌 Semua pesan di-unpin!",

    // ======================== SA ACTIONS ========================
    'sa.addsa_done'             => "✅ User <code>{uid}</code> ditambahkan sebagai <b>Superadmin</b>!",
    'sa.addsa_note'             => "\n📝 Catatan: {note}",
    'sa.rmsa_done'              => "✅ User <code>{uid}</code> dihapus dari daftar <b>Superadmin</b>.",
    'sa.rmsa_static_err'        => "❌ Tidak bisa hapus SA static. Edit config.php langsung.",
    'sa.lookup_not_found'       => "❌ User tidak ditemukan di cache bot.\n\n<i>Hanya user yang pernah mengirim pesan di grup bot yang tersimpan di cache.</i>",
    'sa.no_dyn_sa'              => "<i>Tidak ada SA dinamis.</i>",

    // SA command messages
    'sa.backup_caption'         => "💾 <b>Backup Database</b>\n📅 {date}\n📦 Ukuran: <b>{size} KB</b>",
    'sa.logs_empty'             => "ℹ️ Log masih kosong.",
    'sa.logs_title'             => "📋 <b>Bot Log</b> (terakhir {n} baris)",
    'sa.addsa_usage'            => "❌ Gunakan: /addsa [user_id] [catatan opsional]",
    'sa.rmsa_usage'             => "❌ Gunakan: /rmsa [user_id]",
    'sa.lookup_usage'           => "❌ Gunakan: /lookup [user_id atau @username]",
    'sa.massban_usage'          => "❌ Gunakan: /massban [id1] [id2] [id3] ...",
    'sa.massban_reason'         => "Mass ban oleh SA",
    'sa.salist_title'           => "👑 <b>Daftar Superadmin</b>",
    'sa.salist_static'          => "<b>Static (config.php):</b>",
    'sa.salist_dynamic'         => "<b>Dynamic (database):</b>",
    'sa.announce_usage'         => "❌ Tulis pengumuman setelah /announce",
    'sa.ungban_btn'             => "Cabut Global Ban",

    // SA Panel Button Labels
    'sa.btn_stats'              => "📊 Statistik Global",
    'sa.btn_groups'             => "👥 Daftar Grup",
    'sa.btn_salist'             => "👑 Kelola SA",
    'sa.btn_history'            => "📋 Riwayat BC",
    'sa.btn_lookup'             => "🔍 Cari User",
    'sa.btn_logs'               => "📋 Log Bot",

    // ======================== WARNS/VP ========================
    'warn.list_header'          => "⚠️ <b>Peringatan {mention}</b>: {count}/{limit}",
    'vp.display'                => "🔴 <b>Poin Sanksi</b> — {mention}\n\nPoin: <b>{points}/{limit}</b>\nStatus sistem: {status}",
    'vp.reset_done'             => "✅ Poin sanksi {mention} direset ke <b>0</b>.",
    'vp.top_title'              => "🔴 <b>Top Pelanggar Grup</b>\n\n",
    'vp.no_violators'           => "✅ Tidak ada user dengan poin sanksi.",
    'vp.status_on'              => "Aktif",
    'vp.status_off'             => "Nonaktif",

    // ======================== VOTE KICK ========================
    'vk.cancel_no_target'       => "❌ Sebut user target sesi yang ingin dibatalkan.",

    // ======================== REPORT ========================
    'report.usage'              => "❌ Reply pesan yang ingin dilaporkan!",
    'report.text'               => "🚨 <b>LAPORAN BARU</b>\n\n👤 Dilaporkan: {reported}\n📝 Alasan: {reason}\n👮 Oleh: {by}\n💬 Pesan: {msg}\n🔗 Grup: <b>{group}</b>",
    'report.sent_n'             => "✅ Laporan terkirim ke {n} admin.",
    'report.no_reason'          => "Tidak ada alasan",

    // ======================== BROADCAST ========================
    'broadcast.announce'        => "📢 <b>Pesan dari Admin Bot</b>\n\n{text}",
    'broadcast.done'            => "✅ Broadcast selesai!\n📤 Berhasil: {ok} | Gagal: {fail}",

    // ======================== NOTE EXTRAS ========================
    'note.list_header'          => "📝 <b>Daftar Note</b> ({n})\n\n",
    'note.save_usage'           => "❌ Gunakan: /save [keyword] [teks]",
    'note.usage_clear'          => "❌ Gunakan: /clear [keyword]",
    'note.empty_content'        => "❌ Isi note tidak boleh kosong.",
    'note.saved'                => "✅ Note <b>#{keyword}</b> disimpan! Gunakan: /get {keyword} atau #{keyword}",
    'note.get_usage'            => "❌ Gunakan: /get [keyword]",
    'note.not_found'            => "❌ Note <b>#{keyword}</b> tidak ditemukan.",
    'note.list_empty'           => "ℹ️ Belum ada note.",
    'note.deleted'              => "✅ Note <b>#{keyword}</b> dihapus.",

    // ======================== WORD FILTER ========================
    'bw.exists'                 => "ℹ️ Kata sudah ada di daftar terlarang.",
    'bw.usage'                  => "❌ Gunakan: /banword [kata]",
    'bw.usage_rm'               => "❌ Gunakan: /unbanword [kata]",
    'bw.added'                  => "✅ Kata <b>{word}</b> ditambahkan. Filter kata diaktifkan.",
    'bw.removed'                => "✅ Kata <b>{word}</b> dihapus dari daftar terlarang.",
    'bw.empty'                  => "ℹ️ Belum ada kata terlarang.",
    'bw.list'                   => "🚫 <b>Kata Terlarang</b> ({count})\n\n{list}",

    // ======================== FILTERS ========================
    'filter.usage_add'          => "❌ Gunakan: /filter [keyword] [respons]",
    'filter.usage_rm'           => "❌ Sebut keyword yang ingin dihapus.",
    'filter.added'              => "✅ Filter <b>{keyword}</b> ditambahkan!",
    'filter.empty'              => "ℹ️ Belum ada filter.",
    'filter.list'               => "📋 <b>Filter aktif:</b> {list}",
    'filter.deleted'            => "✅ Filter <b>{keyword}</b> dihapus.",

    // ======================== SETTINGS ========================
    'settings.not_set'          => "Belum diatur",
    'settings.btn_added_named'  => "✅ Tombol welcome ditambahkan: <b>{text}</b>",
    'settings.btn_cleared'      => "✅ Semua tombol welcome dihapus.",
    'settings.rules_display'    => "📋 <b>Peraturan Grup</b>\n\n{content}",
    'settings.rules_not_set'    => '<i>Belum diatur.</i>',
    'settings.logchannel_text'  => "📝 <b>Log Channel</b>\n\nLog channel saat ini: <code>{channel}</code>\n\nLog channel menerima notifikasi:\n• Laporan dari anggota\n• Pelanggaran yang dideteksi bot",
    'settings.logchannel_cleared'=> "📝 <b>Log Channel</b>\n\nLog channel saat ini: <i>Belum diatur</i>",

    // ======================== COMMANDS ========================
    'cmd.tagall_default'        => "📣 Perhatian semua anggota!",
    'cmd.tagall_no_data'        => "⚠️ Belum ada data member aktif. Bot perlu mencatat beberapa pesan terlebih dahulu.",
    'cmd.tban_usage'            => "❌ User tidak ditemukan.\n<b>Contoh:</b> <code>/tban @username 1d alasan</code> atau reply pesan user.",
    'cmd.tban_dur_err'          => "❌ Format durasi salah. Gunakan: 30m / 2h / 7d",
    'cmd.setrules_usage'        => "❌ Tulis aturan setelah perintah atau reply ke pesan.",
    'cmd.setrules_done'         => "✅ Peraturan grup diperbarui!",
    'cmd.setwelcome_usage'      => "❌ Tulis pesan welcome.\n\nVariabel: {name} {mention} {group} {count} {id}",
    'cmd.setwelcome_done'       => "✅ Pesan welcome diperbarui!\n\nPreview:\n{preview}",
    'cmd.setgoodbye_usage'      => "❌ Tulis pesan goodbye.",
    'cmd.setgoodbye_done'       => "✅ Pesan goodbye diperbarui!",
    'cmd.setlogchannel_usage'   => "❌ Gunakan: /setlogchannel @channel atau -100xxx",
    'cmd.setlogchannel_done'    => "✅ Log channel diatur ke <b>{channel}</b>",
    'cmd.setwelcomebtn_usage'   => "❌ Format: /setwelcomebutton Teks|URL",
    'cmd.setwelcomebtn_invalid' => "❌ Format salah. Gunakan: Teks|https://url.com",
    'cmd.massban_done'          => "✅ <b>{n} user</b> berhasil ditambahkan ke Global Ban.",
    'cmd.cleargban_done'        => "✅ Semua global ban dihapus. Total <b>{n} user</b> dibebaskan.",
    'cmd.broadcast_usage'       => "❌ Tulis pesan setelah /broadcast\n\n💡 <b>Tips:</b> Balas (reply) ke pesan foto/video/dokumen/audio/GIF lalu ketik /broadcast untuk broadcast media.",
    'cmd.gbanlist_header'       => "🌐🚫 <b>Global Ban</b> (total: {count})\n\n",
    'cmd.gbanlist_empty'        => "Daftar kosong.",
    'cmd.kickbots_done_n'       => "🤖 {n} bot di-kick.",
    'cmd.cleandel_scanning'     => "🔍 Memindai anggota dengan akun yang sudah dihapus...",
    'cmd.cleandel_done'         => "🧹 Selesai! Diperiksa: <b>{checked}</b> anggota yang diketahui.\n👻 Akun terhapus ditemukan & dikeluarkan: <b>{kicked}</b>.",

    // ======================== CAPTCHA ========================
    'captcha.text'              => "👋 Selamat datang <b>{name}</b>!\n\n🔐 <b>Verifikasi Diperlukan</b>\n\nJawab pertanyaan berikut untuk bergabung:\n\n➤  <b>{a} + {b} = ?</b>\n\n⏱ Batas waktu: <b>{timeout} detik</b> (pukul {time}){rules_note}\n\n⚠️ Jika tidak menjawab, Anda akan di-<b>kick</b> otomatis saat ada pesan berikutnya.",
    'captcha.rules_note'        => "\n📋 Setelah verifikasi, Anda akan diminta menyetujui <b>peraturan grup</b> sebelum bisa mengirim pesan.",
    'captcha.expired_msg'       => "⏰ {mention} di-<b>kick</b> karena tidak menyelesaikan captcha tepat waktu.",
    'captcha.invalid'           => "❌ Sesi captcha tidak valid.",
    'captcha.expired_kick'      => "⏰ Waktu captcha habis! Anda di-kick.",
    'captcha.wrong'             => "❌ Jawaban salah! Coba lagi.",
    'captcha.correct_rules'     => "✅ Captcha benar! Baca peraturan terlebih dahulu.",
    'captcha.correct'           => "✅ Verifikasi berhasil! Selamat datang!",
    'captcha.rules_text'        => "📋 <b>Peraturan Grup</b>\n\nHalo <b>{name}</b>! Sebelum dapat mengirim pesan, baca dan setujui peraturan berikut:\n\n{rules}\n\n─────────────────────\nTekan tombol <b>✅ Saya Setuju</b> untuk membuka akses mengirim pesan.",
    'captcha.rules_agree_btn'   => "✅ Saya Setuju & Bergabung",
    'captcha.hcaptcha_msg'      => "👋 Halo <b>{name}</b>!\n\n🔐 <b>Verifikasi Diperlukan</b>\nKlik tombol di bawah untuk menyelesaikan verifikasi keamanan via web.\n\n⏱ Waktu: <b>{timeout} detik</b> · Kedaluwarsa pukul <b>{time}</b>{rules_note}\n\n⚠️ Jika tidak diverifikasi, Anda akan di-<b>kick</b> otomatis.",
    'captcha.hcaptcha_btn'      => "🌐 Verifikasi Sekarang",
    'captcha.hcaptcha_not_configured' => "❌ hCaptcha belum dikonfigurasi di server. Hubungi administrator bot.",
    'captcha.hcaptcha_dm_sent'        => "🔐 {mention}, link verifikasi sudah dikirim ke <b>chat pribadi</b> kamu.\nBuka DM bot dan klik tombol verifikasi sebelum pukul <b>{time}</b> (batas: <b>{timeout} detik</b>).\n\n⚠️ Jika tidak diverifikasi, kamu akan di-<b>kick</b> otomatis.",
    'captcha.hcaptcha_start_btn'      => "🤖 Mulai Bot & Verifikasi",
    'captcha.hcaptcha_start_msg'      => "🔐 {mention}, klik tombol di bawah untuk membuka chat bot dan dapatkan link verifikasi.\n\n⏱ Selesaikan sebelum pukul <b>{time}</b> (batas: <b>{timeout} detik</b>).\n\n⚠️ Jika tidak diverifikasi, kamu akan di-<b>kick</b> otomatis.",
    'captcha.hcaptcha_dm_msg'         => "👋 Halo <b>{name}</b>!\n\n🔐 <b>Verifikasi Masuk Grup</b>\nKlik tombol di bawah untuk menyelesaikan verifikasi keamanan dan mendapatkan akses ke grup.",
    'captcha.hcaptcha_expired_dm'     => "⏰ Sesi verifikasi sudah kedaluwarsa atau tidak valid.\nJika kamu masih ingin bergabung, silakan hubungi admin grup.",
    'captcha.math_dm_sent'            => "🔢 {mention}, soal verifikasi sudah dikirim ke <b>chat pribadi</b> kamu.\nBuka DM bot dan jawab soal sebelum pukul <b>{time}</b> (batas: <b>{timeout} detik</b>).\n\n⚠️ Jika tidak dijawab, kamu akan di-<b>kick</b> otomatis.",
    'captcha.math_start_msg'          => "🔢 {mention}, klik tombol di bawah untuk membuka chat bot dan dapatkan soal verifikasi.\n\n⏱ Selesaikan sebelum pukul <b>{time}</b> (batas: <b>{timeout} detik</b>).\n\n⚠️ Jika tidak diverifikasi, kamu akan di-<b>kick</b> otomatis.",
    'captcha.math_dm_msg'             => "👋 Halo <b>{name}</b>!\n\n🔢 <b>Verifikasi Masuk Grup</b>\nJawab soal matematika di bawah ini untuk mendapatkan akses ke grup. Kedaluwarsa pukul <b>{time}</b>.",
    'captcha.type_switched_math'    => "✅ Metode captcha diubah ke: Matematika",
    'captcha.type_switched_hcaptcha'=> "✅ Metode captcha diubah ke: hCaptcha (web)",

    // ======================== FLOOD ========================
    'flood.muted'               => "🌊 {mention} di-<b>mute</b> karena flood! ({dur}s)",
    'flood.kicked'              => "🌊 {mention} di-<b>kick</b> karena flood!",
    'flood.banned'              => "🌊 {mention} di-<b>ban</b> karena flood!",

    // ======================== VIOLATION ========================
    'vp.limit_reached'          => "🚨 {mention} {action} karena mencapai batas poin sanksi (<b>{limit}/{limit}</b>)!\n📌 Pelanggaran: <b>{reason}</b>\n🔄 Poin direset ke 0.",
    'vp.point_added'            => "⚠️ {mention} mendapat <b>+1 poin sanksi</b> karena: <b>{reason}</b>\n📊 Poin: <b>{points}/{limit}</b>{warning}",
    'vp.warning_text'           => "\n🔴 <b>Peringatan:</b> 1 poin lagi akan di-<b>{action}</b>!",

    // ======================== ACTION LABELS ========================
    'action.kicked'             => "di-<b>kick</b>",
    'action.banned'             => "di-<b>ban</b>",
    'action.muted'              => "di-<b>mute</b>",

    // ======================== TOGGLES ========================
    'toggle.welcome_on'         => "✅ Welcome diaktifkan!",
    'toggle.welcome_off'        => "❌ Welcome dinonaktifkan!",
    'toggle.goodbye_on'         => "✅ Goodbye diaktifkan!",
    'toggle.goodbye_off'        => "❌ Goodbye dinonaktifkan!",
    'toggle.captcha_on'         => "✅ Captcha diaktifkan!",
    'toggle.captcha_off'        => "❌ Captcha dinonaktifkan!",
    'toggle.antilink_on'        => "✅ Anti-link aktif!",
    'toggle.antilink_off'       => "❌ Anti-link nonaktif!",
    'toggle.flood_on'           => "✅ Anti-flood aktif!",
    'toggle.flood_off'          => "❌ Anti-flood nonaktif!",
    'toggle.wordfilter_on'      => "✅ Filter kata aktif!",
    'toggle.wordfilter_off'     => "❌ Filter kata nonaktif!",
    'toggle.violation_on'       => "✅ Poin sanksi aktif!",
    'toggle.violation_off'      => "❌ Poin sanksi nonaktif!",
    'toggle.votekick_on'          => "✅ Vote kick aktif!",
    'toggle.votekick_off'         => "❌ Vote kick nonaktif!",
    'toggle.vk_mode_abs'          => "🔢 Mode diubah ke Absolut (jumlah suara tetap)",
    'toggle.vk_mode_pct'          => "📊 Mode diubah ke Persentase (% member)",
    'toggle.vk_admin_only_on'     => "🔑 Sekarang hanya admin yang bisa mulai votekick",
    'toggle.vk_admin_only_off'    => "👤 Sekarang semua member bisa mulai votekick",
    'toggle.vk_cooldown_set'      => "⏳ Cooldown diatur ke {n} menit per user",
    'toggle.vk_cooldown_off'      => "⏳ Cooldown votekick dinonaktifkan",
    'toggle.ai_on'              => "✅ AI aktif!",
    'toggle.ai_off'             => "❌ AI nonaktif!",
    'toggle.antiraid_on'        => "✅ Anti-raid aktif!",
    'toggle.antiraid_off'       => "❌ Anti-raid nonaktif!",
    'toggle.rules_btn_on'       => "✅ Tombol persetujuan aktif!",
    'toggle.rules_btn_off'      => "❌ Tombol persetujuan nonaktif!",
    'toggle.require_verif_on'   => "✅ Verifikasi kontak+lokasi diaktifkan!",
    'toggle.require_verif_off'  => "❌ Verifikasi kontak+lokasi dinonaktifkan!",
    'toggle.on'                 => "✅ Aktif",
    'toggle.off'                => "❌ Nonaktif",
    'toggle.btns_cleared'       => "✅ Tombol welcome dihapus!",
    'toggle.rules_cleared'      => "✅ Peraturan dihapus!",
    'toggle.wl_cleared'         => "✅ Whitelist dikosongkan!",
    'toggle.banwords_cleared'   => "✅ Semua kata terlarang dihapus!",
    'toggle.logchannel_cleared' => "✅ Log channel dihapus!",
    'toggle.cancelled'          => "Dibatalkan.",
    'toggle.flood_limit'        => "Limit flood: {n} pesan/menit",
    'toggle.flood_action'       => "Aksi flood: {action}",
    'toggle.warn_limit'         => "Warn limit: {n}",
    'toggle.warn_action'        => "Aksi warn: {action}",
    'toggle.violation_limit'    => "Batas poin sanksi: {n}",
    'toggle.violation_action'   => "Aksi poin sanksi: {action}",
    'toggle.votekick_threshold' => "Batas suara: {n}",
    'toggle.votekick_duration'  => "Durasi voting: {n} menit",
    'toggle.lock_locked'        => "🔒 {name} dikunci",
    'toggle.lock_unlocked'      => "🔓 {name} dibuka",
    'toggle.raid_limit'         => "Batas raid: {n} user/menit",

    // ======================== VOTE KICK RESULT ========================
    'vk.executed'               => "🗳️ <b>Vote Kick Berhasil!</b>\n\n👤 Target: <b>{name}</b>\n✅ Setuju: <b>{yes}</b> | ❌ Tolak: <b>{no}</b>\n📊 Batas: <b>{thresh} suara</b>\n\n🚪 <b>{name}</b> telah di-<b>kick</b> dari grup!",
    'vk.cancelled_full'         => "🚫 <b>Vote Kick Dibatalkan</b>\n\nSesi vote kick untuk <b>{name}</b> dibatalkan oleh {by}.",
    'vk.vote_yes'               => "✅ Suara 'Setuju' tercatat! ({yes}/{thresh})",
    'vk.vote_no'                => "❌ Suara 'Tolak' tercatat!",
    'vk.inactive'               => "❌ Sesi vote ini sudah tidak aktif.",
    'vk.expired'                => "⏰ Waktu voting sudah habis.",
    'vk.self_vote'              => "❌ Target tidak bisa ikut vote!",
    'vk.already_voted'          => "⚠️ Kamu sudah memberikan suara sebelumnya!",
    'vk.cancel_inactive'        => "❌ Sesi sudah tidak aktif.",
    'vk.cancel_no_perm'         => "❌ Hanya pemrakarsa atau admin yang bisa membatalkan!",
    'vk.cancel_done'            => "✅ Sesi vote kick dibatalkan.",

    // ======================== RULES AGREE ========================
    'rules.not_for_you'         => "❌ Tombol ini bukan untukmu!",
    'rules.agreed_ok'           => "✅ Kamu sudah setuju! Sekarang bisa mengirim pesan.",

    // ======================== ANTIRAID SETTINGS ========================
    'antiraid.full_text'        => "🛡️ <b>Anti-Raid</b>\n\nStatus: <b>{status}</b>\nBatas join: <b>{limit} user/menit</b>\n\nJika jumlah user yang join dalam 1 menit melebihi batas, mereka akan di-kick otomatis.",
    'antiraid.short_text'       => "🛡️ <b>Anti-Raid</b>\n\nStatus: <b>{status}</b>\nBatas join: <b>{limit} user/menit</b>",
    'antiraid.status_on'        => "✅ Aktif",
    'antiraid.status_off'       => "❌ Nonaktif",

    // ======================== SA CALLBACKS ========================
    'sa.access_denied_alert'    => "❌ Akses ditolak!",
    'sa.admin_only_alert'       => "❌ Hanya admin!",
    'sa.owner_only_alert'       => "❌ Hanya owner!",
    'sa.cancelled_input'        => "Dibatalkan.",
    'sa.groups_header'          => "👥 <b>Daftar Grup</b> (total: <b>{total}</b>)\n\nHalaman {page}/{total_page}\n\n",
    'sa.groups_no_name'         => "Tanpa Nama",
    'sa.groups_empty'           => "<i>Tidak ada grup.</i>",
    'sa.groups_back'            => "⬅️ Kembali",
    'sa.stats_text'             => "📊 <b>Statistik Global Bot</b>\n\n👥 Total Grup: <b>{groups}</b>\n👤 Total User (cache): <b>{users}</b>\n🌐🚫 Global Ban: <b>{gban}</b>\n📢 Total Broadcast: <b>{broadcast}</b>\n⚠️ Total Warn: <b>{warns}</b>\n🚫 Total Ban: <b>{bans}</b>\n🔇 Total Mute: <b>{mutes}</b>\n💬 Total Pesan: <b>{messages}</b>\n📝 Total Note: <b>{notes}</b>\n📋 Total Filter: <b>{filters}</b>\n\n👑 Total SA: <b>{total_sa}</b>\n🔧 Maintenance: {maint}\n🕐 Waktu Server: <b>{time}</b>",
    'sa.stats_maint_on'         => "<b>🔴 Aktif</b>",
    'sa.stats_maint_off'        => "<b>✅ Nonaktif</b>",
    'sa.stats_refresh_btn'      => "🔄 Refresh",
    'sa.stats_back_btn'         => "⬅️ Kembali",
    'sa.gbanlist_header'        => "🌐🚫 <b>Global Ban List</b> (total: <b>{count}</b>)\n\n",
    'sa.gbanlist_empty_text'    => "<i>Daftar kosong.</i>",
    'sa.gbanlist_more'          => "\n<i>...dan {n} lainnya</i>",
    'sa.gbanlist_add_btn'       => "➕ Tambah Gban",
    'sa.gbanlist_rm_btn'        => "➖ Hapus Gban",
    'sa.gbanlist_clear_btn'     => "🗑️ Hapus Semua Gban",
    'sa.gbanlist_back_btn'      => "⬅️ Kembali",
    'sa.cleargban_confirm_txt'  => "⚠️ <b>Konfirmasi Hapus Semua Global Ban</b>\n\nAnda akan menghapus <b>{count}</b> global ban.\nTindakan ini tidak bisa dibatalkan!\n\nYakin?",
    'sa.cleargban_yes_btn'      => "✅ Ya, Hapus Semua",
    'sa.cleargban_cancel_btn'   => "❌ Batal",
    'sa.cleargban_done_alert'   => "✅ Semua global ban dihapus!",
    'sa.cleargban_done_txt'     => "✅ <b>Berhasil!</b>\n\n<b>{count}</b> global ban telah dihapus dari database.",
    'sa.cleargban_back_btn'     => "⬅️ Kembali ke Gban List",
    'sa.gban_add_prompt'        => "🌐🚫 <b>Tambah Global Ban</b>\n\nKirim pesan dengan format:\n<code>USER_ID alasan ban</code>\n\nContoh: <code>123456789 spammer</code>",
    'sa.gban_rm_prompt'         => "✅ <b>Hapus Global Ban</b>\n\nKirim User ID yang ingin di-ungban:",
    'sa.cancel_btn'             => "❌ Batal",
    'sa.broadcast_prompt'       => "📢 <b>Broadcast ke {count} Grup</b>\n\nKirim pesan yang ingin di-broadcast:",
    'sa.broadcast_history_text' => "📋 <b>Riwayat Broadcast</b>\n\n",
    'sa.broadcast_history_empty'=> "<i>Belum ada.</i>",
    'sa.salist_text'            => "👑 <b>Daftar Superadmin</b>\n\n<b>Static (config.php):</b>\n",
    'sa.salist_dyn_header'      => "\n<b>Dynamic (database):</b>\n",
    'sa.salist_no_dyn'          => "\n<i>Belum ada SA dinamis.</i>",
    'sa.addsa_btn'              => "➕ Tambah SA",
    'sa.rmsa_btn'               => "➖ Hapus SA",
    'sa.salist_back_btn'        => "⬅️ Kembali",
    'sa.addsa_prompt'           => "👑 <b>Tambah Superadmin</b>\n\nKirim User ID yang ingin dijadikan SA.\n(Opsional: tambahkan catatan setelah ID)\n\nContoh: <code>123456789 nama teman</code>",
    'sa.rmsa_prompt'            => "➖ <b>Hapus Superadmin Dinamis</b>\n\nKirim User ID SA yang ingin dihapus:",
    'sa.no_dyn_sa_to_rm'        => "ℹ️ Tidak ada SA dinamis untuk dihapus.",
    'sa.logs_empty_text'        => "📋 <b>Bot Log</b>\n\n<i>Log masih kosong.</i>",
    'sa.logs_text'              => "📋 <b>Bot Log</b> ({n} baris terakhir)\n\n<pre>{log}</pre>",
    'sa.logs_clear_btn'         => "🗑️ Bersihkan Log",
    'sa.logs_refresh_btn'       => "🔄 Refresh",
    'sa.logs_back_btn'          => "⬅️ Kembali",
    'sa.logs_clear_alert'       => "✅ Log berhasil dihapus!",
    'sa.logs_cleared_txt'       => "📋 <b>Bot Log</b>\n\n<i>Log telah dibersihkan.</i>",
    'sa.backup_sending_alert'   => "⏳ Mengirim file backup...",
    'sa.backup_not_found_txt'   => "❌ File database tidak ditemukan.",
    'sa.ping_text'              => "🏓 Pong! Latensi: {ms}ms",
    'sa.maint_status_on'        => "🔴 Aktif",
    'sa.maint_status_off'       => "✅ Nonaktif",
    'sa.maint_alert'            => "🔧 Maintenance: {status}",
    'sa.lookup_prompt'          => "🔍 <b>Cari User</b>\n\nKirim User ID atau @username yang ingin dicari:",
    'sa.quick_gban_reason'      => "Quick global ban dari panel SA",
    'sa.quick_gban_id_invalid'  => "❌ ID tidak valid!",
    'sa.quick_gban_done_alert'  => "✅ User {uid} di-global ban!",
    'sa.quick_gban_done_txt'    => "✅ User <b>{name}</b> (<code>{uid}</code>) berhasil di-<b>Global Ban</b>.",
    'sa.quick_ungban_btn'       => "↩️ Cabut Global Ban",
    'sa.quick_gban_btn'         => "🌐🚫 Gban Lagi",
    'sa.quick_ungban_done_alert'=> "✅ Global ban {uid} dicabut!",
    'sa.quick_ungban_done_txt'  => "✅ Global ban untuk <b>{name}</b> (<code>{uid}</code>) telah <b>dicabut</b>.",
    'sa.kb_stats'               => "📊 Statistik Global",
    'sa.kb_groups'              => "👥 Daftar Grup",
    'sa.kb_gban'                => "🌐🚫 Global Ban",
    'sa.kb_sa'                  => "👑 Kelola SA",
    'sa.kb_broadcast'           => "📢 Broadcast",
    'sa.kb_history'             => "📋 Riwayat BC",
    'sa.kb_schedule'            => "📅 Jadwal BC",
    'sa.schedule_prompt_msg'    => "📅 <b>Jadwal Broadcast ke {count} Grup</b>\n\nLangkah 1/2 — Kirim pesan yang ingin dijadwalkan:\n\n💡 <i>Mendukung teks, foto, video, dokumen, audio, dan GIF.</i>",
    'sa.schedule_prompt_time'   => "🕐 <b>Langkah 2/2 — Pilih Waktu Kirim</b>\n\n📌 Pesan sudah tersimpan!\n\nKirim waktu pengiriman dalam format:\n<code>DD/MM/YYYY HH:MM</code>\n\nContoh: <code>{example}</code>\n\n⚠️ Waktu mengacu ke zona server.",
    'sa.schedule_saved'         => "✅ <b>Broadcast dijadwalkan!</b>\n\n🕐 Waktu: <b>{time}</b>\n📋 Jenis: <b>{type}</b>\n\nBroadcast akan dikirim otomatis ke <b>{count}</b> grup.",
    'sa.schedule_invalid_time'  => "❌ Format waktu salah atau waktu sudah lewat.\n\nGunakan format: <code>DD/MM/YYYY HH:MM</code>\nContoh: <code>{example}</code>",
    'sa.schedule_list_title'    => "📅 <b>Antrian Broadcast Terjadwal</b>\n\n",
    'sa.schedule_list_empty'    => "<i>Tidak ada broadcast yang dijadwalkan.</i>",
    'sa.schedule_cancel_done'   => "✅ Broadcast terjadwal dibatalkan.",
    'sa.schedule_cancel_fail'   => "❌ Gagal membatalkan. Mungkin sudah dikirim atau bukan milik Anda.",
    'sa.schedule_type_text'     => "Teks",
    'sa.schedule_type_photo'    => "Foto 🖼️", 'sa.schedule_type_video'    => "Video 🎥",
    'sa.schedule_type_document' => "Dokumen 📄", 'sa.schedule_type_audio'    => "Audio 🎵",
    'sa.schedule_type_animation'=> "GIF 🎞️",   'sa.schedule_type_voice'    => "Voice 🎙️",
    'sa.schedule_type_video_note'=> "Video Note 📹",
    'sa.kb_lookup'              => "🔍 Cari User",
    'sa.kb_backup'              => "💾 Backup DB",
    'sa.kb_logs'                => "📋 Log Bot",
    'sa.kb_ping'                => "🏓 Ping",
    'sa.kb_maint_on'            => "🔴 Maintenance: ON",
    'sa.kb_maint_off'           => "✅ Maintenance: OFF",
    'sa.kb_img_settings'        => "🎨 Setting Gambar AI",
    'sa.kb_ai_settings'         => "🤖 Pengaturan AI (Chat & Image)",
    'sa.ai_settings_title'      => "🤖 <b>Pengaturan Default AI Bot</b>\n\n💬 <b>Default AI Chat:</b> {chat_label}\n   └ ID: <code>{chat_id}</code>\n\n🖼️ <b>Default AI Image:</b> {image_label}\n   └ ID: <code>{image_id}</code>\n\n🔑 <b>Status API Server:</b>\n• Gemini API: {gemini_status}\n• DeepSeek API: {deepseek_status}\n\n<i>Pilih kategori di bawah untuk mengubah AI default yang digunakan bot:</i>",
    'sa.ai_kb_chat'             => "💬 Pilih Default AI Chat",
    'sa.ai_kb_image'            => "🖼️ Pilih Default AI Image",
    'sa.ai_chat_menu_title'     => "💬 <b>Pilih Default AI Chat</b>\n\nAktif saat ini: <b>{current}</b>\n\nModel yang dipilih akan digunakan untuk <code>/ai</code>, <code>/translate</code>, <code>/detect</code>, dan <code>/summarize</code>:",
    'sa.ai_image_menu_title'    => "🖼️ <b>Pilih Default AI Image</b>\n\nAktif saat ini: <b>{current}</b>\n\nModel yang dipilih akan digunakan untuk <code>/imagine</code>, <code>/gambar</code>, dan <code>/edit</code>:",
    'sa.ai_chat_updated'        => "✅ Default AI Chat diubah ke {label}",
    'sa.ai_image_updated'       => "✅ Default AI Image diubah ke {label}",
    'sa.ai_key_ok'              => "✅ Terpasang",
    'sa.ai_key_missing'         => "⚠️ Belum diset (.env)",
    'sa.kb_verif'               => "🔐 Verifikasi Member",
    'sa.kb_cat_broadcast'       => "📢 Broadcast",
    'sa.kb_cat_stats'           => "📊 Statistik & Grup",
    'sa.kb_cat_users'           => "👥 Manajemen User",
    'sa.kb_cat_settings'        => "🛠 Pengaturan Bot",
    'sa.kb_cat_services'        => "📧 Layanan",
    'sa.kb_cat_system'          => "🖥 Sistem",
    'sa.cat_broadcast_title'    => "📢 <b>Broadcast</b>\n\nPilih fitur broadcast:",
    'sa.cat_stats_title'        => "📊 <b>Statistik & Grup</b>\n\nPilih menu:",
    'sa.cat_users_title'        => "👥 <b>Manajemen User</b>\n\nPilih menu:",
    'sa.cat_settings_title'     => "🛠 <b>Pengaturan Bot</b>\n\nPilih menu:",
    'sa.cat_services_title'     => "📧 <b>Layanan Eksternal</b>\n\nPilih layanan:",
    'sa.cat_system_title'       => "🖥 <b>Sistem</b>\n\nPilih menu:",
    'sa.verif_title'            => "🔐 <b>Verifikasi Anggota</b>\n\nPilih grup untuk melihat data verifikasi:",
    'sa.verif_empty'            => "<i>Belum ada data verifikasi di grup manapun.</i>",
    'sa.verif_group_title'      => "🔐 <b>Verifikasi — {title}</b>\n\nTotal terverifikasi: <b>{total}</b>\n\n",
    'sa.verif_group_entry'      => "👤 <b>{name}</b> ({un}) | <code>{uid}</code>\n📱 <code>{phone}</code> | 📍 <a href=\"https://maps.google.com/?q={lat},{lon}\">Lihat Peta</a> | 🕒 {time}\n\n",
    'sa.verif_group_empty'      => "<i>Tidak ada data verifikasi.</i>",
    'sa.verif_back_btn'         => "⬅️ Kembali ke Daftar",
    'sa.cat_back_btn'           => "⬅️ Kembali",
    'sa.img_settings_title'     => "🎨 <b>Setting Gambar AI</b>\n\n📊 Limit per user per hari: <b>{limit}</b> gambar\n\n<i>0 = tidak ada batas</i>",
    'sa.img_limit_prompt'       => "🎨 <b>Set Limit Gambar Harian</b>\n\nKirim angka batas pembuatan gambar per user per hari.\n\n• Contoh: <code>5</code> (5 gambar/hari)\n• Kirim <code>0</code> untuk tanpa batas\n• Rentang: 0 – 100",
    'sa.img_limit_updated'      => "✅ Limit gambar diperbarui menjadi <b>{limit}</b> gambar/hari.",
    'sa.img_limit_range_err'    => "❌ Angka tidak valid. Masukkan angka antara 0 dan 100.",
    'prompt.cancel_btn'         => "❌ Batal",

    // ======================== EMAIL PANEL ========================
    'email.panel_header'        => "📧 <b>Panel Email</b>\n\nKelola akun email cPanel Anda langsung dari bot.\n\n📊 Akun tersimpan: <b>{count}</b>\n🖥️ Server: <code>{host}</code>\n🔌 IMAP: <b>{imap} (SSL)</b> | SMTP: <b>{smtp} (STARTTLS)</b>\n\n<i>Pilih menu di bawah:</i>",
    'email.accounts_header'     => "📧 <b>Akun Email Tersimpan</b>\n\n",
    'email.accounts_empty'      => "Belum ada akun email yang tersimpan.\nTekan <b>Buat Akun</b> untuk menambah akun email baru via cPanel.",
    'email.accounts_row'        => "   📦 Quota: {quota} MB | 📅 {date}\n\n",
    'email.acct_detail'         => "📧 <b>Detail Akun Email</b>\n\n✉️ Email: <code>{email}</code>\n🔑 Password: <code>{pass}</code>\n📦 Quota: {quota} MB\n{label}📅 Ditambah: {date}\n\n<i>Pilih aksi:</i>",
    'email.acct_detail_label'   => "🏷️ Label: {label}\n",
    'email.del_confirm'         => "⚠️ <b>Hapus Akun Email</b>\n\nYakin ingin menghapus <code>{email}</code>?\n\n<b>Akun akan dihapus dari bot DAN dari cPanel!</b>",
    'email.deleting_alert'      => "⏳ Menghapus akun...",
    'email.del_done'            => "✅ <b>Akun Dihapus</b>\n\n✉️ <code>{email}</code>\n\n🗄️ Database bot: <b>Dihapus</b>\n🖥️ cPanel: {cpanel_status}",
    'email.del_cpanel_ok'       => "<b>Dihapus</b>",
    'email.del_cpanel_fail'     => "⚠️ Gagal — {err}",
    'email.invalid_fmt_cpanel'  => "Format email tidak valid",
    'email.create_prompt'       => "📧 <b>Buat Akun Email Baru</b>\n\nDomain tersedia:\n{domains}\nKirim dalam format:\n<code>email@domain password [label]</code>\n\nContoh:\n<code>info@{example} Passw0rd! Email Utama</code>\n\n<i>label opsional</i>",
    'email.create_choose'       => "📧 <b>Tambah Akun Email</b>\n\nDomain tersedia di cPanel:\n{domains}\nPilih cara menambahkan akun:",
    'email.create_cpanel_prompt'=> "📧 <b>Buat Akun via cPanel</b>\n\nAkun baru akan dibuat di cPanel sekaligus disimpan ke bot.\n\nKirim dalam format:\n<code>email@domain password [label]</code>\n\nContoh:\n<code>info@{example} Passw0rd123! Label Opsional</code>",
    'email.create_manual_prompt'=> "📥 <b>Tambah Akun Sudah Ada</b>\n\nGunakan ini untuk akun yang sudah ada di server lain (tidak perlu akses cPanel).\n\nKirim dalam format:\n<code>email@domain password [label]</code>\n\nContoh:\n<code>info@webmail.xnoxsguard.my.id Passw0rd! Email Saya</code>\n\n<i>label opsional</i>",
    'email.manual_added'        => "✅ <b>Akun Email Ditambahkan!</b>\n\n✉️ Email: <code>{email}</code>\n🔑 Password: <code>{pass}</code>\n{label}\n<i>Akun berhasil disimpan ke bot. Tidak ada perubahan di cPanel.</i>",
    'email.manual_already_exists' => "⚠️ Akun <code>{email}</code> sudah ada di bot.",
    'email.sync_alert'          => "⏳ Mengambil daftar akun dari cPanel...",
    'email.sync_fail'           => "❌ Gagal menghubungi cPanel:\n{error}",
    'email.sync_header'         => "🔄 <b>Daftar Akun di cPanel</b>\n\n",
    'email.sync_empty'          => "Tidak ada akun email di cPanel.",
    'email.sync_row'            => "   Penggunaan: {used} / {max} MB\n\n",
    'email.sync_footer'         => "\n<i>Untuk menggunakan akun ini, tambahkan via menu Buat Akun dengan password yang Anda ketahui.</i>",
    'email.inbox_alert'         => "⏳ Membuka inbox...",
    'email.inbox_fail'          => "❌ <b>Gagal Buka Inbox</b>\n\n{error}",
    'email.inbox_header'        => "📥 <b>Inbox: {email}</b>\nHalaman {page} • Total: {total} email\n\n",
    'email.inbox_empty'         => "<i>Inbox kosong.</i>",
    'email.read_alert'          => "⏳ Membuka email...",
    'email.read_fail'           => "❌ <b>Gagal Membaca Email</b>\n\n{error}",
    'email.truncated'           => "\n<i>...[dipotong]</i>",
    'email.delete_ok_alert'     => "🗑️ Email dihapus",
    'email.delete_fail_alert'   => "❌ Gagal hapus email",
    'email.delete_done'         => "✅ Email berhasil dihapus.",
    'email.compose_pick_method' => "✉️ <b>Kirim Email Baru</b>\n\nDari: <code>{email}</code>\n\nPilih metode pengiriman:",
    'email.compose_form'        => "✉️ <b>Kirim Email Baru</b> — {method}\n\nDari: <code>{from}</code>\n\nKetik dalam format:\n<code>tujuan@domain.com\nSubjek Email\nIsi pesan email Anda di sini.</code>\n\nBaris 1: Alamat tujuan\nBaris 2: Subjek\nBaris 3+: Isi pesan",
    'email.send_alert'          => "⏳ Mengirim email via {method} ke <code>{to}</code>...",
    'email.send_ok'             => "✅ <b>Email Berhasil Dikirim!</b>\n\n📤 Via: {method}\n✉️ Dari: <code>{from}</code>\n📬 Ke: <code>{to}</code>\n📝 Subjek: {subject}",
    'email.send_fail'           => "❌ <b>Gagal Mengirim Email</b>\n\nVia: {method}\nError: {error}\n\n<i>Pastikan SMTP aktif dan kredensial benar.</i>",
    'email.create_alert'        => "⏳ Membuat akun email <code>{email}</code> via cPanel...",
    'email.create_ok'           => "✅ <b>Akun Email Berhasil Dibuat!</b>\n\n✉️ Email: <code>{email}</code>\n🔑 Password: <code>{pass}</code>\n📦 Quota: {quota} MB\n{label}",
    'email.create_label'        => "🏷️ Label: {label}\n",
    'email.create_exists'       => "⚠️ <b>Akun sudah ada di cPanel</b>\n\nEmail <code>{email}</code> disimpan ke bot.\n🔑 Password: <code>{pass}</code>",
    'email.create_fail'         => "❌ <b>Gagal Membuat Akun Email</b>\n\nError: {error}\n\n<i>Pastikan domain benar dan password memenuhi syarat cPanel (min 8 karakter, campuran huruf & angka).</i>",
    'email.create_invalid_fmt'  => "❌ Format tidak valid.\n\nGunakan: <code>username domain password quota label</code>\nContoh: <code>info xnoxsguard.my.id Passw0rd! 500 Email Utama</code>",
    'email.compose_incomplete'  => "❌ Format tidak lengkap.\n\nHarus ada 3 baris:\n<code>tujuan@email.com\nSubjek Email\nIsi pesan</code>",
    'email.compose_invalid_to'  => "❌ Alamat email tujuan tidak valid: <code>{to}</code>",
    'email.not_found_txt'       => "❌ Akun email tidak ditemukan.",
    'email.not_found_alert'     => "❌ Akun tidak ditemukan",
    // Button labels
    'email.kb_accounts'         => "📭 Akun Email",
    'email.kb_create'           => "➕ Buat Akun Baru",
    'email.kb_sync'             => "🔄 Sinkron cPanel",
    'email.kb_back_sa'          => "◀️ Kembali ke SA",
    'email.kb_back'             => "◀️ Kembali",
    'email.kb_inbox'            => "📥 Inbox",
    'email.kb_open_inbox'       => "📥 Buka Inbox",
    'email.kb_back_inbox'       => "◀️ Inbox",
    'email.kb_back_to_inbox'    => "📥 Kembali ke Inbox",
    'email.kb_back_accounts'    => "◀️ Daftar Akun",
    'email.kb_back_email_panel' => "◀️ Panel Email",
    'email.kb_test_conn'        => "🔌 Tes Koneksi IMAP/SMTP",
    'email.test_alert'          => "🔌 Mengetes koneksi, tunggu sebentar...",
    'email.test_result'         => "🔌 <b>Hasil Tes Koneksi</b>\n📧 <code>{email}</code>\n🖥️ Host: <code>{host}</code>\n\n{imap}\n{smtp465}\n{smtp587}\n\n💡 Jika ada yang ❌, kemungkinan password salah atau server memblokir akses dari IP bot ini.",
    'email.kb_del_acct'         => "🗑️ Hapus Akun",
    'email.kb_del_confirm'      => "✅ Ya, Hapus Permanen",
    'email.kb_del_email'        => "🗑️ Hapus Email",
    'email.kb_add_to_bot'       => "➕ Tambah Akun ke Bot",
    'email.kb_compose'          => "✉️ Kirim",
    'email.kb_resend'           => "✉️ Kirim Lagi",
    'email.kb_send_email'       => "✉️ Kirim Email",
    'email.kb_refresh'          => "🔄 Refresh",
    'email.kb_retry'            => "🔁 Coba Lagi",
    'email.kb_prev'             => "◀️ Prev",
    'email.kb_next'             => "Next ▶️",
    'email.kb_webmail'          => "🌐 Webmail SMTP",
    'email.kb_create_cpanel'    => "🖥️ Buat via cPanel",
    'email.kb_create_manual'    => "📥 Tambah Akun Ada",
    'email.kb_settings'         => "⚙️ Pengaturan Server",
    'email.kb_edit_server'      => "✏️ Ubah Pengaturan Server",
    'email.server_settings_txt' => "⚙️ <b>Pengaturan Server Email</b>\n\n🖥️ <b>cPanel</b>\n  Host: <code>{cpanel_host}</code>\n  User: <code>{cpanel_user}</code>\n  Pass: <code>{cpanel_pass}</code>\n\n📧 <b>Mail Server</b>\n  Host: <code>{mail_host}</code>\n  IMAP: <b>{imap_port}</b> | SMTP: <b>{smtp_port}</b>\n\n<i>Tekan tombol di bawah untuk mengubah pengaturan.</i>",
    'email.server_settings_prompt' => "⚙️ <b>Ubah Pengaturan Server Email</b>\n\nKirim 4 baris dalam format:\n<code>cpanel.domain.com\nusername_cpanel\npassword_cpanel\nwebmail.domain.com</code>\n\nContoh:\n<code>cpanel.xnoxsguard.my.id\nmyuser\nmypassword123\nwebmail.xnoxsguard.my.id</code>\n\n<i>Baris yang dikosongkan akan tetap menggunakan nilai lama.</i>",
    'email.server_settings_saved' => "✅ <b>Pengaturan Server Email Disimpan!</b>\n\n🖥️ cPanel: <code>{cpanel_host}</code>\n👤 User: <code>{cpanel_user}</code>\n📧 Mail Server: <code>{mail_host}</code>\n\n<i>Pengaturan baru berlaku sekarang.</i>",

    // ======================== FORMAT SETTINGS PANEL ========================
    'settings.per_min'          => 'mnt',
    'settings.violation_label'  => 'Sanksi',
    'kb.duration_min'           => '⏱️ Durasi: {n}m',

    // ======================== CRON ========================
    'cron.captcha_kick'         => "⏰ {mention} di-<b>kick</b> karena tidak menyelesaikan captcha dalam batas waktu.",
    'cron.mute_expired'         => "🔊 Masa mute {mention} telah berakhir. Akun telah dipulihkan.",
    'vk.expired_text'           => "⏰ <b>Waktu Vote Kick Habis</b>\n\n👤 Target: <b>{target}</b>\n✅ Setuju: <b>{yes}</b>  |  ❌ Tolak: <b>{no}</b>\n📊 Dibutuhkan: <b>{thresh} suara</b>\n\n<i>Voting berakhir tanpa mencapai batas suara. {target} tidak di-kick.</i>",

    // ======================== SYSINFO ========================
    'sa.kb_sysinfo'             => '🖥️ Info Server',
    'sa.sysinfo_title'          => "🖥️ <b>Informasi Server / Hosting / VPS</b>\n",
    'sa.sysinfo_refresh_btn'    => '🔄 Refresh',
    'sa.sysinfo_back_btn'       => '⬅️ Kembali',
    'sa.sysinfo_alert'          => '⏳ Mengambil info server...',

    // ======================== SANG MATA ========================
    'sang_mata.label'           => 'Sang Mata (Lacak Username)',
    'sang_mata.alert'           => "👁 <b>Sang Mata — Username Berubah!</b>\n\n👤 {mention} (<code>{user_id}</code>)\n\n{old_un} → {new_un}",
    'sang_mata.no_username'     => 'tanpa username',
    'sang_mata.no_target'       => "❌ Reply pesan user atau sebutkan @username / ID untuk cek riwayat username.",
    'sang_mata.no_history'      => "👁 <b>Sang Mata</b>\n\nTidak ada riwayat perubahan username untuk <b>{name}</b> (<code>{uid}</code>).\n\n<i>Bot akan mencatat perubahan username secara otomatis mulai sekarang.</i>",
    'sang_mata.history_title'   => "👁 <b>Sang Mata — Riwayat Username</b>\n\n👤 <b>{name}</b> (<code>{uid}</code>)\n\n",
    'toggle.sang_mata_on'       => "👁 Sang Mata diaktifkan! Bot akan memantau perubahan username.",
    'toggle.sang_mata_off'      => "👁 Sang Mata dinonaktifkan.",

];

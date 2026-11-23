# 🤖 Telegram Group Management Bot v3.1

Bot manajemen grup & channel Telegram berbasis PHP dengan fitur lengkap: moderasi otomatis, AI, eksekusi kode, manajemen server hosting (WHM/cPanel), email, dan banyak lagi.

---

## 📋 Daftar Isi

- [Persyaratan](#persyaratan)
- [Instalasi & Konfigurasi](#instalasi--konfigurasi)
- [Struktur Proyek](#struktur-proyek)
- [Daftar Fitur & Command](#daftar-fitur--command)
  - [Manajemen Grup](#-manajemen-grup)
  - [Notes & Filter](#-notes--filter)
  - [Welcome / Goodbye / Rules](#-welcome--goodbye--rules)
  - [Proteksi Otomatis](#-proteksi-otomatis-auto-moderation)
  - [Sistem Poin Pelanggaran](#-sistem-poin-pelanggaran)
  - [Global Ban](#-global-ban)
  - [Sang Mata — Pelacak Username](#-sang-mata--pelacak-username)
  - [Fitur AI (DeepSeek)](#-fitur-ai-deepseek)
  - [Eksekusi Kode](#-eksekusi-kode)
  - [Harga Crypto](#-harga-crypto)
  - [Panel Superadmin](#-panel-superadmin)
  - [Manajemen Server WHM](#-manajemen-server-whm)
  - [Manajemen cPanel](#-manajemen-cpanel)
  - [Email Manager](#-email-manager)
  - [Temp Mail](#-temp-mail)
  - [Pengaturan Grup (Settings)](#-pengaturan-grup-settings)
  - [Cron Jobs Otomatis](#-cron-jobs-otomatis)
- [Bahasa yang Didukung](#bahasa-yang-didukung)
- [Keamanan](#keamanan)

---

## Persyaratan

- PHP 8.1+ dengan ekstensi: `curl`, `pdo_sqlite`, `openssl`, `sockets`
- Web server dengan HTTPS (wajib untuk Telegram Webhook)
- SQLite (sudah termasuk, tidak perlu instalasi terpisah)
- Domain publik dengan SSL valid

---

## Instalasi & Konfigurasi

### 1. Upload file ke server

Upload semua file ke direktori publik web server Anda (misal `/home/user/public_html/bot/`).

### 2. Edit `config.php`

```php
define('BOT_TOKEN',      'TOKEN_BOT_KAMU');       // Token dari @BotFather
define('BOT_USERNAME',   'nama_bot_kamu');         // Username bot tanpa @
define('SUPERADMINS',    [123456789]);              // Telegram User ID superadmin

define('HCAPTCHA_SITEKEY', 'site-key-hcaptcha');  // Dari hcaptcha.com
define('HCAPTCHA_SECRET',  'secret-hcaptcha');

// cPanel (untuk fitur Email & cPanel Panel)
define('CPANEL_HOST', 'cpanel.domain.com');
define('CPANEL_PORT', 2083);
define('CPANEL_USER', 'username_cpanel');
define('CPANEL_PASS', 'password_cpanel');

// WHM (untuk fitur WHM Panel)
define('WHM_HOST',  'cpanel.domain.com');
define('WHM_PORT',  2087);
define('WHM_USER',  'root_atau_reseller');
define('WHM_TOKEN', 'api_token_whm');  // Disarankan pakai token, bukan password

// Mail Server
define('MAIL_HOST',  'mail.domain.com');
define('IMAP_PORT',  993);
define('SMTP_PORT',  465);
```

### 3. Daftarkan Webhook

Buka browser atau jalankan cURL:

```
https://api.telegram.org/botTOKEN_BOT/setWebhook?url=https://domain-kamu.com/webhook.php
```

Atau gunakan halaman setup:
```
https://domain-kamu.com/setup.php
```

### 4. Atur Cron Job

Tambahkan cron job di server untuk menjalankan tugas otomatis setiap menit:

```
* * * * * php /path/to/bot/cron.php >> /dev/null 2>&1
```

### 5. Tambahkan Bot ke Grup

- Tambahkan bot ke grup Telegram
- Jadikan bot sebagai **Administrator** dengan izin: Delete Messages, Ban Users, Restrict Members, Pin Messages, Invite Users

---

## Struktur Proyek

```
├── webhook.php           # Entry point utama, semua logika command & callback
├── config.php            # Konfigurasi token, API, superadmin, dll
├── database.php          # Kelas Database (SQLite), semua query & schema
├── ai.php                # Integrasi DeepSeek AI (chat, gambar, translate, dll)
├── lang.php              # Loader bahasa
├── cron.php              # Tugas otomatis terjadwal
├── index.php             # Halaman status bot
├── setup.php             # Halaman setup webhook
├── captcha_web.php       # Halaman web untuk verifikasi hCaptcha
├── handlers/
│   ├── captcha_image.php # Generator gambar captcha matematika
│   ├── code_runner.php   # Eksekusi kode via Judge0 API
│   ├── email.php         # Manajemen email IMAP/SMTP
│   ├── whm.php           # WHM API handler
│   ├── cpanel.php        # cPanel UAPI handler
│   └── tempmail.php      # Temp mail via WebSocket
├── lang/
│   ├── id.php            # Teks bahasa Indonesia
│   └── en.php            # Teks bahasa Inggris
└── data/
    ├── bot.db            # Database SQLite (auto-dibuat)
    └── bot.log           # Log aktivitas
```

---

## Daftar Fitur & Command

> **Legenda hak akses:**
> - 👤 Semua member
> - 🔑 Admin grup
> - 👑 Superadmin bot

---

### 👥 Manajemen Grup

| Command | Alias | Akses | Keterangan |
|---------|-------|-------|------------|
| `/ban` | — | 🔑 | Ban permanen user (reply/username/ID) |
| `/tban` | — | 🔑 | Ban sementara, misal `/tban 1h` atau `/tban 30m` |
| `/unban` | — | 🔑 | Cabut ban user |
| `/kick` | — | 🔑 | Kick user dari grup |
| `/mute` | — | 🔑 | Bisukan user |
| `/unmute` | — | 🔑 | Bebaskan user dari mute |
| `/warn` | — | 🔑 | Beri peringatan (otomatis kick/ban setelah batas) |
| `/unwarn` | `/rmwarn` | 🔑 | Hapus 1 peringatan user |
| `/warns` | — | 🔑 | Lihat daftar peringatan user |
| `/clearwarns` | — | 🔑 | Hapus semua peringatan user |
| `/promote` | — | 🔑 | Jadikan user sebagai admin grup |
| `/demote` | — | 🔑 | Copot admin dari jabatannya |
| `/settitle` | — | 🔑 | Set judul jabatan admin |
| `/pin` | — | 🔑 | Pin pesan (reply ke pesan yang ingin di-pin) |
| `/unpin` | — | 🔑 | Unpin pesan yang di-pin |
| `/unpinall` | — | 🔑 | Unpin semua pesan |
| `/del` | `/delete` | 🔑 | Hapus pesan (reply ke pesan target) |
| `/purge` | — | 🔑 | Hapus semua pesan dari titik reply hingga sekarang |
| `/lock` | — | 🔑 | Kunci tipe pesan: `sticker`, `gif`, `media`, `voice`, `game`, `poll`, `forward` |
| `/unlock` | — | 🔑 | Buka kunci tipe pesan |
| `/locks` | — | 🔑 | Lihat status semua kunci |
| `/slowmode` | — | 🔑 | Atur slow mode (detik), `0` untuk nonaktifkan |
| `/readonly` | `/ro` | 🔑 | Toggle mode hanya-baca (semua member tidak bisa kirim pesan) |
| `/kickbots` | — | 🔑 | Kick semua bot dari grup |
| `/tagall` | — | 🔑 | Mention semua member grup |
| `/invitelink` | — | 🔑 | Generate invite link baru |
| `/report` | — | 👤 | Laporkan pesan ke admin (reply ke pesan) |
| `/votekick` | `/vkick` | 👤 | Mulai voting kick user |
| `/cancelvotekick` | `/cancelvo` | 🔑 | Batalkan voting kick yang sedang berjalan |
| `/adminlist` | — | 👤 | Tampilkan daftar admin grup |
| `/stats` | — | 👤 | Statistik aktivitas grup |
| `/id` | — | 👤 | Tampilkan ID user/grup |
| `/info` | `/whois` | 👤 | Informasi lengkap user (ID, username, status gban, dll) |
| `/me` | — | 👤 | Informasi diri sendiri |
| `/checkperms` | — | 🔑 | Cek izin bot di grup |
| `/cleandel` | — | 🔑 | Bersihkan pesan layanan yang terhapus |
| `/cekverif` | — | 👤 | Cek status verifikasi captcha user |

**Contoh penggunaan:**
```
/tban @user 2h alasan              → ban 2 jam
/tban @user 30m spam               → ban 30 menit
/lock sticker                      → kunci sticker
/slowmode 10                       → slow mode 10 detik
/purge                             → reply ke pesan awal yang ingin dihapus
```

---

### 📝 Notes & Filter

| Command | Alias | Akses | Keterangan |
|---------|-------|-------|------------|
| `/save <kata> <isi>` | `/note` | 🔑 | Simpan catatan dengan kata kunci |
| `/get <kata>` | — | 👤 | Ambil catatan berdasarkan kata kunci |
| `/notes` | — | 👤 | Tampilkan semua catatan tersimpan |
| `/clear <kata>` | `/clearnote` | 🔑 | Hapus catatan tertentu |
| `/filter <kata> <balasan>` | — | 🔑 | Tambah filter otomatis (bot balas jika kata muncul) |
| `/filters` | — | 👤 | Tampilkan semua filter aktif |
| `/stop <kata>` | `/rmfilter` | 🔑 | Hapus filter tertentu |
| `/banword <kata>` | `/addword` | 🔑 | Tambah kata terlarang ke daftar |
| `/unbanword <kata>` | `/rmword` | 🔑 | Hapus kata dari daftar terlarang |
| `/banwords` | — | 🔑 | Tampilkan semua kata terlarang |

**Catatan:** Notes bisa menyimpan teks, foto, video, dokumen, sticker, dan audio.

---

### 🎉 Welcome / Goodbye / Rules

| Command | Alias | Akses | Keterangan |
|---------|-------|-------|------------|
| `/welcome` | — | 🔑 | Toggle pesan sambutan on/off |
| `/setwelcome` | — | 🔑 | Set teks pesan sambutan |
| `/goodbye` | — | 🔑 | Toggle pesan perpisahan on/off |
| `/setgoodbye` | — | 🔑 | Set teks pesan perpisahan |
| `/setrules` | — | 🔑 | Set peraturan grup |
| `/rules` | — | 👤 | Tampilkan peraturan grup |
| `/clearwelcomebuttons` | — | 🔑 | Hapus semua tombol di pesan welcome |
| `/setlogchannel` | — | 🔑 | Set channel untuk log aktivitas moderasi |
| `/setlang` | — | 🔑 | Pilih bahasa bot: `id` (Indonesia) atau `en` (English) |

**Variabel yang bisa digunakan di teks welcome/goodbye:**
```
{first}     → Nama depan user
{last}      → Nama belakang user
{fullname}  → Nama lengkap user
{username}  → Username (@ + nama)
{mention}   → Mention user (klik nama)
{id}        → User ID
{group}     → Nama grup
{count}     → Jumlah member
```

**Contoh pesan welcome:**
```
Halo {mention}! 👋
Selamat datang di {group}!
Kamu adalah member ke-{count}. Silakan baca /rules ya.
```

---

### 🛡️ Proteksi Otomatis (Auto-Moderation)

Semua fitur ini berjalan otomatis dan bisa diatur via `/settings` (admin grup):

| Fitur | Keterangan |
|-------|------------|
| **Anti-Flood** | Batasi kecepatan kirim pesan. Atur batas & aksi (kick/mute/ban) + durasi mute otomatis |
| **Anti-Link** | Blokir link/URL. Bisa whitelist domain tertentu (misal: t.me) |
| **Anti-Forward** | Blokir pesan yang di-forward dari grup/channel lain |
| **Anti-Bot** | Otomatis kick bot yang bergabung (kecuali yang di-whitelist) |
| **Anti-Raid** | Deteksi serangan massal: jika banyak user bergabung dalam waktu singkat, otomatis aktifkan perlindungan |
| **Word Filter** | Filter kata/frasa terlarang kustom. Pesan yang mengandung kata tersebut otomatis dihapus |
| **Spam Detector** | Deteksi pesan repetitif (pesan sama dikirim berkali-kali) |
| **Captcha System** | Verifikasi member baru sebelum bisa kirim pesan. Dua mode: **Math** (teka-teki matematika) atau **hCaptcha** (web captcha) |
| **Global Ban Auto-Kick** | Member yang masuk ke daftar Global Ban otomatis dikick saat bergabung ke grup manapun yang dikelola bot |
| **Sang Mata** | Pantau perubahan username member dan kirim notifikasi ke grup |

**Pengaturan Anti-Flood:**
- Batas pesan per interval (misal: 5 pesan per 10 detik)
- Aksi: kick, mute, atau ban
- Durasi mute otomatis

**Captcha — Mode Math:**
- Bot kirim soal matematika (penjumlahan, pengurangan, perkalian)
- Member harus menjawab dalam waktu yang ditentukan
- Jika gagal/timeout → otomatis kick

**Captcha — Mode hCaptcha:**
- Bot kirim tombol verifikasi
- Member diarahkan ke halaman web untuk menyelesaikan captcha
- Setelah berhasil → otomatis diberi izin kirim pesan

---

### ⚠️ Sistem Poin Pelanggaran

Sistem poin otomatis yang terakumulasi saat member melanggar aturan (kena anti-flood, anti-link, dll).

| Command | Alias | Akses | Keterangan |
|---------|-------|-------|------------|
| `/vpoints` | `/poin` | 👤 | Lihat poin pelanggaran diri sendiri (atau reply ke user lain) |
| `/resetvp` | `/resetpoin` | 🔑 | Reset poin pelanggaran user |
| `/topviolators` | `/violators` | 🔑 | Ranking member dengan poin pelanggaran tertinggi |
| `/violation` | — | 🔑 | Toggle sistem poin on/off via settings |

**Alur kerja:**
- Setiap pelanggaran otomatis menambah 1 poin
- Jika poin mencapai batas → aksi otomatis (kick/mute/ban) dijalankan
- Batas dan aksi bisa dikonfigurasi via `/settings`

---

### 🌐 Global Ban

Sistem ban lintas grup — user yang di-gban otomatis dikick dari semua grup yang dikelola bot.

| Command | Akses | Keterangan |
|---------|-------|------------|
| `/gban <user> [alasan]` | 👑 | Tambah user ke daftar Global Ban |
| `/ungban <user>` | 👑 | Hapus user dari Global Ban |
| `/gbanlist` | 👑 | Tampilkan daftar user yang di-gban |
| `/massban <id1> <id2> ...` | 👑 | Global ban banyak user sekaligus |
| `/cleargban` | 👑 | Hapus semua entri Global Ban |

---

### 👁️ Sang Mata — Pelacak Username

Fitur otomatis yang memantau perubahan username Telegram semua member grup.

| Command | Alias | Akses | Keterangan |
|---------|-------|-------|------------|
| `/sangmata` | `/usernamehistory`, `/cekun` | 🔑 | Lihat riwayat perubahan username user |

- Pantauan berjalan otomatis setiap ada aktivitas member
- Menyimpan histori hingga 10 username terakhir per user
- Berguna untuk melacak user yang sering ganti username

---

### 🤖 Fitur AI (DeepSeek)

| Command | Alias | Akses | Keterangan |
|---------|-------|-------|------------|
| `/ai <pertanyaan>` | `/tanya`, `/ask` | 👤 | Chat dengan AI (DeepSeek) |
| `/resetai` | `/clearai` | 👤 | Reset/hapus riwayat percakapan AI |
| `/translate <teks>` | `/terjemahkan`, `/tr` | 👤 | Terjemahkan teks ke bahasa lain |
| `/detect <teks>` | `/deteksi` | 👤 | Deteksi bahasa dari teks |
| `/summarize <teks>` | `/ringkas`, `/tldr` | 👤 | Buat ringkasan teks panjang |
| `/imagine <deskripsi>` | `/gambar`, `/buatgambar`, `/imgai` | 👤 | Generate gambar dari deskripsi teks |
| `/edit <instruksi>` | `/editgambar`, `/editimg` | 👤 | Edit gambar dengan AI (reply ke foto) |

**Contoh penggunaan:**
```
/ai Jelaskan apa itu blockchain
/tr Halo dunia
/translate en: Selamat pagi semua
/summarize [paste teks panjang]
/imagine A futuristic city at sunset, digital art
/edit Ubah background menjadi langit malam berbintang
```

**Catatan:**
- AI menyimpan riwayat percakapan per user (per grup)
- Gunakan `/resetai` untuk memulai percakapan baru
- Fitur AI di grup bisa di-toggle via `/settings`

---

### 💻 Eksekusi Kode

| Command | Akses | Keterangan |
|---------|-------|------------|
| `/run <bahasa>\n<kode>` | 👤 | Jalankan kode dan tampilkan output |

**Bahasa yang didukung (40+ bahasa):**
```
Python, JavaScript, TypeScript, PHP, Java, C, C++, C#, Go, Rust,
Ruby, Kotlin, Swift, Scala, R, MATLAB, Perl, Haskell, Lua,
Bash/Shell, SQL, dan banyak lagi
```

**Contoh:**
```
/run python
print("Hello, World!")

/run javascript
console.log([1,2,3].map(x => x * 2))

/run bash
echo "Server: $(hostname)"
```

---

### 💰 Harga Crypto

| Command | Alias | Akses | Keterangan |
|---------|-------|-------|------------|
| `/price <symbol>` | `/harga`, `/crypto` | 👤 | Cek harga cryptocurrency real-time |

**Contoh:**
```
/price btc
/price ethereum
/harga sol
/crypto bnb
```

---

### 👑 Panel Superadmin

Diakses via `/sa` atau `/superadmin`. Hanya untuk superadmin bot.

| Command | Alias | Akses | Keterangan |
|---------|-------|-------|------------|
| `/sa` | `/superadmin` | 👑 | Buka panel superadmin |
| `/sysinfo` | `/serverinfo`, `/hostinfo`, `/vpsinfo` | 👑 | Info CPU, RAM, disk, uptime server |
| `/sainfo` | `/botstat` | 👑 | Statistik global bot (total grup, user, pesan, dll) |
| `/backup` | — | 👑 | Download backup database SQLite |
| `/logs` | `/salog` | 👑 | Lihat log aktivitas superadmin terbaru |
| `/maintenance` | — | 👑 | Toggle mode maintenance (bot tidak merespons di grup) |
| `/addsa <user>` | — | 👑 | Tambah superadmin dinamis |
| `/rmsa <user>` | `/removesa` | 👑 | Hapus superadmin dinamis |
| `/salist` | — | 👑 | Daftar semua superadmin aktif |
| `/lookup <user>` | `/cariuser` | 👑 | Cari informasi user berdasarkan ID/username |
| `/announce <pesan>` | — | 👑 | Kirim pengumuman ke semua grup |
| `/broadcast` | — | 👑 | Kirim broadcast terjadwal ke semua grup |
| `/gban` | — | 👑 | Global ban user |
| `/massban` | — | 👑 | Global ban banyak user sekaligus |
| `/cleargban` | — | 👑 | Hapus semua global ban |

---

### 🖥️ Manajemen Server WHM

Diakses via `/whm` di chat privat dengan bot. Hanya untuk superadmin.

#### Manajemen Akun Hosting
| Fitur | Keterangan |
|-------|------------|
| Daftar Akun | Lihat semua akun hosting yang ada |
| Buat Akun | Buat akun hosting baru (domain, user, password, paket) |
| Hapus Akun | Hapus akun hosting secara permanen |
| Suspend Akun | Suspend akun (non-aktifkan sementara) |
| Unsuspend Akun | Aktifkan kembali akun yang di-suspend |
| Ganti Password | Ubah password akun cPanel |
| Modifikasi Akun | Ubah paket/limit akun |

#### Manajemen Paket
| Fitur | Keterangan |
|-------|------------|
| Tambah Paket | Buat paket hosting baru (kuota, bandwidth, FTP, database, POP) |
| Daftar Paket | Lihat semua paket yang tersedia |

#### Manajemen DNS
| Fitur | Keterangan |
|-------|------------|
| Daftar Zone | Lihat semua zone DNS |
| Lihat Zone | Detail record DNS per domain |
| Tambah Record | Tambah record (A, AAAA, CNAME, MX, TXT, dll) |
| Hapus Record | Hapus record DNS tertentu |
| Reset Zone | Reset zone DNS ke default |

#### Manajemen FTP (WHM)
| Fitur | Keterangan |
|-------|------------|
| Daftar FTP | Lihat semua akun FTP di suatu cPanel user |
| Buat FTP | Buat akun FTP baru |

#### Manajemen Reseller
| Fitur | Keterangan |
|-------|------------|
| Jadikan Reseller | Beri akses reseller ke akun cPanel |
| Copot Reseller | Cabut akses reseller |
| Daftar Reseller | Lihat semua reseller aktif |

#### Dashboard & Monitoring
| Fitur | Keterangan |
|-------|------------|
| Server Dashboard | CPU, RAM, disk, load average, uptime |
| Bandwidth | Statistik penggunaan bandwidth |
| Daftar IP | Semua IP yang terdaftar di server |
| Restart Service | Restart layanan: Apache, MySQL, FTP, DNS, dll |

---

### ⚙️ Manajemen cPanel

Diakses via `/cpanel` di chat privat dengan bot. Hanya untuk superadmin.

#### FTP
| Fitur | Keterangan |
|-------|------------|
| Daftar FTP | Lihat semua akun FTP |
| Buat FTP | Buat akun FTP baru (user, password, direktori, quota) |
| Hapus FTP | Hapus akun FTP |
| Ganti Password FTP | Ubah password akun FTP |
| Atur Quota FTP | Ubah batas penyimpanan FTP |

#### Database MySQL
| Fitur | Keterangan |
|-------|------------|
| Daftar Database | Lihat semua database |
| Buat Database | Buat database baru |
| Hapus Database | Hapus database |
| Buat DB User | Buat user database baru |
| Daftar DB User | Lihat semua user database |
| Grant Privilege | Berikan akses user ke database |

#### Subdomain
| Fitur | Keterangan |
|-------|------------|
| Daftar Subdomain | Lihat semua subdomain |
| Buat Subdomain | Buat subdomain baru |
| Hapus Subdomain | Hapus subdomain |

#### Email Forwarder
| Fitur | Keterangan |
|-------|------------|
| Daftar Forwarder | Lihat semua email forwarder |
| Buat Forwarder | Buat forwarder (dari email A ke email B) |
| Hapus Forwarder | Hapus forwarder |

#### Cron Job
| Fitur | Keterangan |
|-------|------------|
| Daftar Cron | Lihat semua cron job aktif |
| Tambah Cron | Buat cron job baru (format crontab standar) |
| Hapus Cron | Hapus cron job tertentu |

#### Monitoring & SSL
| Fitur | Keterangan |
|-------|------------|
| Disk Usage | Lihat penggunaan disk per direktori |
| Daftar SSL | Lihat semua sertifikat SSL yang terpasang |
| Install AutoSSL | Pasang AutoSSL (Let's Encrypt) untuk domain |

---

### 📧 Email Manager

Diakses via `/email` di chat privat dengan bot. Hanya untuk superadmin.

| Fitur | Keterangan |
|-------|------------|
| Buat Akun Email | Buat akun email baru di cPanel |
| Inbox | Lihat daftar email masuk (20 terbaru) |
| Baca Email | Buka isi email tertentu |
| Hapus Email | Hapus email dari inbox |
| Kirim Email | Compose & kirim email baru |
| Test Koneksi | Test koneksi IMAP & SMTP server |
| Pengaturan Server | Konfigurasi host IMAP/SMTP, port, kredensial |

---

### 📬 Temp Mail

Diakses via `/tempmail` di chat privat dengan bot. Tersedia untuk superadmin.

| Fitur | Keterangan |
|-------|------------|
| Generate Email | Buat alamat email sementara secara acak |
| Inbox Real-time | Pantau email masuk secara real-time via WebSocket |
| Baca Pesan | Buka isi email yang masuk |
| Hapus Pesan | Hapus email tertentu |
| Hapus Akun | Hapus alamat email sementara sepenuhnya |

---

### ⚙️ Pengaturan Grup (Settings)

Diakses via `/settings` (admin grup). Semua pengaturan bisa dikonfigurasi via tombol interaktif tanpa mengetik command.

**Kategori Pengaturan:**

| Kategori | Opsi yang Tersedia |
|----------|--------------------|
| **Welcome** | On/Off, set teks, set tombol, clear tombol |
| **Goodbye** | On/Off, set teks |
| **Rules** | Set teks, hapus, tombol setuju |
| **Captcha** | On/Off, pilih tipe (Math/hCaptcha), atur timeout |
| **Anti-Link** | On/Off, whitelist domain |
| **Word Filter** | On/Off, tambah/hapus kata |
| **Log Channel** | Set/hapus channel log |
| **Locks** | Toggle per tipe: sticker, gif, media, voice, game, poll, forward |
| **Anti-Flood** | On/Off, atur batas pesan, atur aksi, atur durasi |
| **Warn System** | Atur batas warn, atur aksi setelah batas |
| **Violation Points** | On/Off, atur batas poin, atur aksi |
| **Vote Kick** | On/Off, atur threshold vote, atur durasi voting |
| **AI** | On/Off (izinkan AI di grup) |
| **Anti-Raid** | On/Off, atur batas member/detik |
| **Require Verification** | On/Off (wajib verifikasi kontak/lokasi) |

---

### 🕐 Cron Jobs Otomatis

Dijalankan setiap menit via `cron.php`. Tidak perlu intervensi manual.

| Job | Keterangan |
|-----|------------|
| **Expire Ban Sementara** | Unban otomatis user yang masa bannya sudah habis |
| **Expire Mute Sementara** | Unmute otomatis user yang masa mutenya sudah habis |
| **Expire Captcha Timeout** | Kick user yang tidak menyelesaikan captcha tepat waktu |
| **Bersihkan Log Lama** | Hapus log aktivitas yang sudah terlalu lama dari database |
| **Kirim Broadcast Terjadwal** | Kirim pesan broadcast ke semua grup sesuai jadwal yang ditentukan superadmin |

---

## Bahasa yang Didukung

Bot mendukung dua bahasa antarmuka:

| Kode | Bahasa |
|------|--------|
| `id` | 🇮🇩 Indonesia (default) |
| `en` | 🇬🇧 English |

Ganti bahasa per grup dengan `/setlang id` atau `/setlang en`.

---

## Keamanan

- **Webhook Secret**: Atur `WEBHOOK_SECRET` di `config.php` untuk memastikan hanya Telegram yang bisa mengakses webhook
- **Superadmin ID**: Gunakan Telegram User ID (bukan username) untuk `SUPERADMINS` — tidak bisa dipalsukan
- **WHM API Token**: Gunakan API token WHM daripada password root untuk keamanan lebih baik
- **Database SQLite**: Simpan di luar direktori publik jika memungkinkan (ubah `DB_PATH` di `config.php`)
- **Update ID Deduplication**: Bot otomatis menolak update duplikat untuk mencegah replay attack
- **Anti-Bot & Anti-Raid**: Proteksi bawaan terhadap serangan flood member
- **hCaptcha**: Verifikasi member baru via CAPTCHA web yang aman

---

## Lisensi

Proyek ini dibuat untuk penggunaan internal. Dilarang mendistribusikan ulang tanpa izin.

---

*Telegram Group Management Bot v3.1 — Dibuat dengan PHP*

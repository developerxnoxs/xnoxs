<?php
/**
 * ================================================================
 *  CaptchaImage — Generator gambar captcha menggunakan PHP GD
 *  Menghasilkan soal matematika sebagai gambar PNG dengan noise
 *  agar tidak mudah dibaca oleh bot/OCR.
 * ================================================================
 */

class CaptchaImage {

    const W       = 340;
    const H       = 115;
    const PADDING = 20;   // jarak minimal teks dari tepi kiri/kanan

    /**
     * Cari font TTF yang benar-benar bisa dipakai oleh PHP GD.
     * Menguji setiap kandidat dengan imagettfbbox — kalau gagal, lanjut berikutnya.
     * Kembalikan path yang valid, atau false jika tidak ada sama sekali.
     */
    private static function findFont(): string|false {
        $candidates = [
            __DIR__ . '/../data/DejaVuSansMono-Bold.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSansMono-Bold.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/dejavu/DejaVuSansMono-Bold.ttf',
            '/usr/share/fonts/truetype/ttf-dejavu/DejaVuSansMono-Bold.ttf',
            '/usr/share/fonts/TTF/DejaVuSansMono-Bold.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationMono-Bold.ttf',
            '/usr/share/fonts/liberation/LiberationMono-Bold.ttf',
            '/usr/share/fonts/truetype/freefont/FreeMono.ttf',
            '/usr/share/fonts/truetype/ubuntu/Ubuntu-B.ttf',
            '/usr/share/fonts/truetype/msttcorefonts/courbd.ttf',
            '/usr/share/fonts/truetype/Arial.ttf',
            '/usr/share/fonts/truetype/arial.ttf',
            '/usr/share/fonts/truetype/cour.ttf',
        ];

        foreach ($candidates as $path) {
            if (!file_exists($path) || !is_readable($path)) {
                continue;
            }
            $test = @imagettfbbox(10, 0, $path, 'test');
            if ($test !== false) {
                return $path;
            }
        }
        return false;
    }

    /**
     * Hitung ukuran font TTF terbesar yang masih muat dalam lebar $maxW.
     * Mulai dari $startSize lalu turun jika perlu.
     */
    private static function fitFontSize(string $font, string $text, int $maxW, int $startSize = 42): int {
        $size = $startSize;
        while ($size > 10) {
            $bbox = @imagettfbbox($size, 0, $font, $text);
            if ($bbox === false) {
                return $size;
            }
            $tw = abs($bbox[2] - $bbox[0]);
            if ($tw <= $maxW) {
                return $size;
            }
            $size -= 2;
        }
        return $size;
    }

    /**
     * Render teks menggunakan font bawaan GD (tidak butuh file eksternal).
     * Teks digambar di canvas 3x besar lalu di-resample ke $img agar lebih halus.
     */
    private static function renderGdText(\GdImage $img, string $text, int $w, int $h): void {
        $gdFont = 5;
        $scale  = 3;
        $sw     = $w * $scale;
        $sh     = $h * $scale;

        $canvas = imagecreatetruecolor($sw, $sh);

        // Gradient background
        for ($y = 0; $y < $sh; $y++) {
            $v = (int)(238 + ($y / $sh) * 12);
            $c = imagecolorallocate($canvas, $v, $v, min(255, $v + 15));
            imageline($canvas, 0, $y, $sw, $y, $c);
        }

        // Posisi teks di tengah canvas
        $charW = imagefontwidth($gdFont);
        $charH = imagefontheight($gdFont);
        $tw    = $charW * strlen($text);
        $th    = $charH;
        $tx    = (int)(($sw - $tw) / 2);
        $ty    = (int)(($sh - $th) / 2);

        $shadow    = imagecolorallocate($canvas, 190, 190, 205);
        $textColor = imagecolorallocate($canvas, 22, 32, 98);
        imagestring($canvas, $gdFont, $tx + 2, $ty + 2, $text, $shadow);
        imagestring($canvas, $gdFont, $tx, $ty, $text, $textColor);

        imagecopyresampled($img, $canvas, 0, 0, 0, 0, $w, $h, $sw, $sh);
        imagedestroy($canvas);
    }

    /**
     * Generate gambar captcha berisi soal: {a} + {b} = ?
     * Kembalikan binary PNG.
     */
    public static function generate(int $a, int $b): string {
        $w = self::W;
        $h = self::H;

        $img = imagecreatetruecolor($w, $h);
        imagesavealpha($img, true);

        // ── Background gradient vertikal ─────────────────────────
        for ($y = 0; $y < $h; $y++) {
            $v = (int)(238 + ($y / $h) * 12);
            $c = imagecolorallocate($img, $v, $v, min(255, $v + 15));
            imageline($img, 0, $y, $w, $y, $c);
        }

        // ── Noise: titik-titik acak ───────────────────────────────
        for ($i = 0; $i < 700; $i++) {
            $r  = rand(120, 200);
            $g  = rand(120, 200);
            $b2 = rand(120, 200);
            $c  = imagecolorallocate($img, $r, $g, $b2);
            imagesetpixel($img, rand(0, $w - 1), rand(0, $h - 1), $c);
        }

        // ── Noise: garis diagonal tipis ──────────────────────────
        for ($i = 0; $i < 7; $i++) {
            $c = imagecolorallocate($img, rand(190, 220), rand(190, 220), rand(190, 220));
            imageline($img, rand(0, $w), rand(0, $h), rand(0, $w), rand(0, $h), $c);
        }

        // ── Render teks soal ─────────────────────────────────────
        // Gunakan spasi tunggal agar teks tidak terlalu panjang
        $text    = "{$a} + {$b} = ?";
        $font    = self::findFont();
        $maxW    = $w - (self::PADDING * 2);
        $usedTTF = false;

        if ($font !== false) {
            $size = self::fitFontSize($font, $text, $maxW, 42);
            $bbox = @imagettfbbox($size, 0, $font, $text);

            if ($bbox !== false) {
                $tw = abs($bbox[2] - $bbox[0]);
                $th = abs($bbox[7] - $bbox[1]);
                $tx = (int)(($w - $tw) / 2);
                $ty = (int)(($h + $th) / 2);

                $shadow    = imagecolorallocate($img, 190, 190, 205);
                $textColor = imagecolorallocate($img, 22, 32, 98);
                @imagettftext($img, $size, 0, $tx + 2, $ty + 2, $shadow, $font, $text);
                @imagettftext($img, $size, 0, $tx, $ty, $textColor, $font, $text);
                $usedTTF = true;
            }
        }

        if (!$usedTTF) {
            self::renderGdText($img, $text, $w, $h);
        }

        // ── Noise: gelombang sinus ────────────────────────────────
        for ($i = 0; $i < 2; $i++) {
            $c     = imagecolorallocate($img, rand(175, 210), rand(175, 210), rand(175, 210));
            $yBase = rand(20, $h - 20);
            $amp   = rand(3, 6);
            $freq  = rand(15, 30);
            for ($x = 0; $x < $w; $x++) {
                $yw = (int)($yBase + sin(($x + $i * 50) / $freq) * $amp);
                $yw = max(0, min($h - 1, $yw));
                imagesetpixel($img, $x, $yw, $c);
            }
        }

        // ── Border luar ───────────────────────────────────────────
        $border = imagecolorallocate($img, 195, 198, 220);
        imagerectangle($img, 0, 0, $w - 1, $h - 1, $border);

        // ── Output sebagai PNG ────────────────────────────────────
        ob_start();
        imagepng($img, null, 6);
        $data = ob_get_clean();
        imagedestroy($img);

        return $data;
    }
}

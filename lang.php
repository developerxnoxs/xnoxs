<?php


class Lang {
    private static array  $strings = [];
    private static string $current = 'id';
    private static bool   $loaded  = false;

    public static function load(string $lang = 'id'): void {
        $lang = in_array($lang, ['id', 'en']) ? $lang : 'id';
        if (self::$loaded && self::$current === $lang) return;
        self::$current = $lang;
        $file = __DIR__ . "/lang/{$lang}.php";
        self::$strings = file_exists($file) ? require $file : [];
        self::$loaded  = true;
    }

    public static function t(string $key, array $params = []): string {
        if (!self::$loaded) self::load('id');
        $str = self::$strings[$key] ?? $key;
        foreach ($params as $k => $v) {
            $str = str_replace("{{$k}}", (string)$v, $str);
        }
        return $str;
    }

    public static function current(): string {
        return self::$current;
    }

    public static function isValid(string $lang): bool {
        return in_array($lang, ['id', 'en']);
    }
}

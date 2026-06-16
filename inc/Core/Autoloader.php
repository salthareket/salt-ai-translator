<?php
namespace SAT\Core;

class Autoloader {
    public static function register(): void {
        spl_autoload_register(function (string $class) {
            if (strpos($class, 'SAT\\') !== 0) return;
            $relative = substr($class, 4);
            $file = SAT_DIR . 'inc/' . str_replace('\\', '/', $relative) . '.php';
            if (file_exists($file)) {
                require_once $file;
            }
        });
    }
}

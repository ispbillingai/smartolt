<?php
spl_autoload_register(function ($class) {
    $baseDir = __DIR__ . '/';
    $class = str_replace('\\', '/', $class);
    
    if ($class === 'Mikrotik') {
        $file = $baseDir . 'Mikrotik.php';
        if (file_exists($file)) {
            require_once $file;
        } else {
            error_log("Failed to load Mikrotik.php: File $file does not exist");
        }
    }
    
    if (strpos($class, 'PEAR2/Net/RouterOS') === 0 || strpos($class, 'PEAR2/Net/Transmitter') === 0) {
        $file = $baseDir . $class . '.php';
        if (file_exists($file)) {
            require_once $file;
        } else {
            error_log("Failed to load $class: File $file does not exist");
        }
    }
});
<?php
$files = array_merge(
    glob(__DIR__ . '/*.php'),
    glob(__DIR__ . '/admin/*.php'),
    glob(__DIR__ . '/includes/*.php'),
    glob(__DIR__ . '/config/*.php')
);

$errors = 0;
foreach ($files as $f) {
    $out = [];
    $ret = 0;
    exec('"c:\\xampp\\php\\php.exe" -l ' . escapeshellarg($f), $out, $ret);
    if ($ret !== 0) {
        echo "SYNTAX ERROR in $f:\n" . implode("\n", $out) . "\n";
        $errors++;
    }
}

if ($errors === 0) {
    echo "SUCCESS: All " . count($files) . " PHP files passed linting with 0 errors!\n";
} else {
    echo "FAILED: $errors files had syntax errors.\n";
    exit(1);
}

<?php

declare(strict_types=1);

/**
 * One command: php bench/run.php [--rows=200000] [--runs=9]
 * Runs bench.php twice, with the tracing JIT and without it (opcache on in both, so only the JIT differs).
 */

$args = implode(' ', array_map('escapeshellarg', array_slice($argv, 1)));
$php = escapeshellarg(PHP_BINARY);
$common = '-d opcache.enable_cli=1 -d opcache.file_update_protection=0';
$script = escapeshellarg(__DIR__ . '/bench.php');

echo "# With the tracing JIT\n\n";
passthru("$php $common -d opcache.jit_buffer_size=128M -d opcache.jit=1255 $script $args", $a);
echo "\n# Without the JIT\n\n";
passthru("$php $common -d opcache.jit_buffer_size=0 $script $args", $b);
exit($a ?: $b);

<?php

declare(strict_types=1);

/**
 * Benchmarks charm/csv against fgetcsv()/str_getcsv()/fputcsv() on generated data.
 *
 *   php bench/bench.php                      # no opcache, no JIT
 *   php -d opcache.enable_cli=1 -d opcache.jit=tracing -d opcache.jit_buffer_size=128M bench/bench.php
 *
 * Options: --rows=200000  --runs=3  --skip-memory
 * Prints markdown tables. Times are the best of --runs, include building the full result array
 * unless the row says "lazy".
 */

use Charm\Csv\Dialect;
use Charm\Csv\Reader;
use Charm\Csv\Writer;

require __DIR__ . '/../vendor/autoload.php';

$opts = getopt('', ['rows::', 'runs::', 'skip-memory', 'mem:', 'file:']);
$rowsN = (int) ($opts['rows'] ?? 200000);
$runs = (int) ($opts['runs'] ?? 3);
$dir = __DIR__ . '/data';

/** Deterministic datasets: "plain" has no quotes at all, "messy" has quoted fields, doubled quotes, embedded newlines and delimiters. */
function generate(string $file, int $rows, bool $messy): void
{
    mt_srand($messy ? 2 : 1);
    $words = ['alpha', 'beta', 'gamma', 'delta', 'oslo', 'bergen', 'lorem ipsum', 'x', '12345', '3.14159'];
    $out = fopen($file, 'wb');
    for ($i = 0; $i < $rows; $i++) {
        $f = [];
        for ($c = 0; $c < 8; $c++) {
            $v = $words[mt_rand(0, 9)] . (mt_rand(0, 1) ? (string) mt_rand(0, 99999) : '');
            if ($messy) {
                $r = mt_rand(0, 19);
                if ($r === 0) { $v = "\"$v\""; }
                elseif ($r === 1) { $v = "\"$v\nline2\""; }
                elseif ($r === 2) { $v = "\"$v, $v\""; }
                elseif ($r === 3) { $v = '"say ""' . $v . '"""'; }
            }
            $f[] = $v;
        }
        fwrite($out, implode(',', $f) . "\n");
    }
    fclose($out);
}

function best(int $runs, callable $fn): float
{
    $best = INF;
    for ($i = 0; $i < $runs; $i++) {
        gc_collect_cycles();
        $t = hrtime(true);
        $fn();
        $best = min($best, (hrtime(true) - $t) / 1e9);
    }
    return $best;
}

function mem_child(string $shape, string $file): void
{
    $base = memory_get_usage();
    $r = Reader::fromFile($file);
    $result = match ($shape) {
        'rows' => $r->toArray(),
        'assoc' => $r->withHeader()->toArray(),
        'columns' => $r->withHeader()->columns(),
        'fgetcsv' => (function () use ($file) { $h = fopen($file, 'r'); $o = []; while (($x = fgetcsv($h, null, ',', '"', '')) !== false) { $o[] = $x; } return $o; })(),
        'columns-int' => $r->withHeader()->withCasts(array_fill_keys(['c0', 'c1', 'c2', 'c3', 'c4'], intval(...)))->columns(),
        'columns-int' => $r->withHeader()->withCasts(array_fill_keys(['c0', 'c1', 'c2', 'c3', 'c4'], intval(...)))->columns(),
        'lazy' => (function () use ($r) { $n = 0; foreach ($r as $_) { $n++; } return $n; })(),
    };
    echo json_encode(memory_get_peak_usage() - $base), "\n";
}

if (isset($opts['mem'])) { // child process: peak memory of one shape
    mem_child($opts['mem'], $opts['file']);
    exit;
}

@mkdir($dir);
foreach (['plain' => false, 'messy' => true] as $name => $messy) {
    if (!is_file("$dir/$name-$rowsN.csv")) { generate("$dir/$name-$rowsN.csv", $rowsN, $messy); }
}
$jit = function_exists('opcache_get_status') && (opcache_get_status(false)['jit']['on'] ?? false);
printf("PHP %s, %s, %s, opcache %s, JIT %s\n\n", PHP_VERSION, php_uname('s') . ' ' . php_uname('r'), trim((string) shell_exec("grep -m1 'model name' /proc/cpuinfo | cut -d: -f2")),
    ini_get('opcache.enable_cli') ? 'on' : 'off', $jit ? 'tracing' : 'off');

foreach (['plain', 'messy'] as $name) {
    $file = "$dir/$name-$rowsN.csv";
    $mb = filesize($file) / 1e6;
    $expect = [];
    $h = fopen($file, 'rb');
    while (($x = fgetcsv($h, null, ',', '"', '')) !== false) { $expect[] = $x; }
    fclose($h);

    $cases = [
        'fgetcsv() loop' => function () use ($file) { $h = fopen($file, 'rb'); $o = []; while (($x = fgetcsv($h, null, ',', '"', '')) !== false) { $o[] = $x; } return $o; },
    ];
    if ($name === 'plain') { // str_getcsv per line is only valid without embedded newlines
        $cases['str_getcsv() per line'] = function () use ($file) { $h = fopen($file, 'rb'); $o = []; while (($l = fgets($h)) !== false) { $o[] = str_getcsv($l, ',', '"', ''); } return $o; };
    }
    $cases += [
        'Reader rows (toArray)' => fn() => Reader::fromFile($file)->toArray(),
        'Reader rows (lazy foreach)' => function () use ($file) { $n = 0; foreach (Reader::fromFile($file) as $_) { $n++; } return $n; },
        'Reader strict (toArray)' => fn() => Reader::fromFile($file, new Dialect(strict: true))->toArray(),
        'Reader assoc (header)' => fn() => Reader::fromFile($file)->withHeader()->toArray(),
        'Reader columns' => fn() => Reader::fromFile($file)->columns(),
    ];
    $base = null;
    printf("### Reading %s: %d rows, %.1f MB\n\n| Method | ms | MB/s | vs fgetcsv |\n|---|---:|---:|---:|\n", $name, $rowsN, $mb);
    foreach ($cases as $label => $fn) {
        $t = best($runs, $fn);
        $base ??= $t;
        printf("| %s | %.0f | %.1f | %.2fx |\n", $label, $t * 1000, $mb / $t, $base / $t);
    }
    // sanity: same rows as fgetcsv (messy rows differ in width, so compare lists only)
    if (Reader::fromFile($file)->toArray() !== $expect) { echo "\nMISMATCH against fgetcsv!\n"; exit(1); }
    echo "\n";

    printf("Chunk size (rows, lazy foreach, %s):", $name);
    foreach ([1 << 12, 1 << 14, 1 << 16, 1 << 18, 1 << 20, 1 << 22] as $cs) {
        $t = best($runs, function () use ($file, $cs) { $n = 0; foreach (Reader::fromFile($file)->withChunkSize($cs) as $_) { $n++; } return $n; });
        printf(" %s=%.0fms", $cs >= 1 << 20 ? ($cs >> 20) . 'M' : ($cs >> 10) . 'K', $t * 1000);
    }
    echo "\n\n";
}

// ---- writing --------------------------------------------------------------------------------
$rows = Reader::fromFile("$dir/messy-$rowsN.csv")->toArray();
$plainRows = Reader::fromFile("$dir/plain-$rowsN.csv")->toArray();
echo "### Writing {$rowsN} rows to a file\n\n| Method | data | ms | MB/s | vs fputcsv |\n|---|---|---:|---:|---:|\n";
$out = "$dir/out.csv";
foreach (['plain' => $plainRows, 'messy' => $rows] as $name => $data) {
    $fput = function () use ($out, $data) { $h = fopen($out, 'wb'); foreach ($data as $r) { fputcsv($h, $r, ',', '"', '', "\r\n"); } fclose($h); };
    $mine = function () use ($out, $data) { $w = Writer::toFile($out); $w->writeRows($data); $w->close(); };
    $a = best($runs, $fput);
    $size = filesize($out);
    $b = best($runs, $mine);
    printf("| fputcsv() loop | %s | %.0f | %.1f | 1.00x |\n| Writer::toFile | %s | %.0f | %.1f | %.2fx |\n", $name, $a * 1000, $size / 1e6 / $a, $name, $b * 1000, filesize($out) / 1e6 / $b, $a / $b);
}
unlink($out);
echo "\n";

// ---- memory ---------------------------------------------------------------------------------
if (!isset($opts['skip-memory'])) {
    $file = "$dir/plain-$rowsN.csv";
    printf("### Peak memory above baseline, plain data (%.1f MB file, %d rows x 8 columns)\n\n| Shape | MB |\n|---|---:|\n", filesize($file) / 1e6, $rowsN);
    foreach (['fgetcsv' => 'fgetcsv() rows array', 'rows' => 'Reader rows (toArray)', 'assoc' => 'Reader assoc rows (toArray)', 'columns' => 'Reader columns()', 'lazy' => 'Reader lazy foreach'] as $shape => $label) {
        $cmd = escapeshellarg(PHP_BINARY) . ' -d memory_limit=-1 ' . escapeshellarg(__FILE__) . " --mem=$shape --file=" . escapeshellarg($file);
        printf("| %s | %.1f |\n", $label, (int) shell_exec($cmd) / 1e6);
    }
    echo "\n";

    // Numeric data is where column-major + casting pays off: ints are 16-byte values, strings are heap allocations.
    $num = "$dir/numeric-$rowsN.csv";
    if (!is_file($num)) {
        mt_srand(3);
        $h = fopen($num, 'wb');
        fwrite($h, "c0,c1,c2,c3,c4\n");
        for ($i = 0; $i < $rowsN; $i++) { fwrite($h, mt_rand(0, 999999) . ',' . mt_rand(0, 999999) . ',' . mt_rand(0, 999) . ',' . mt_rand(0, 99999999) . ',' . mt_rand(0, 9) . "\n"); }
        fclose($h);
    }
    printf("### Peak memory above baseline, numeric data (%.1f MB file, %d rows x 5 integer columns)\n\n| Shape | MB |\n|---|---:|\n", filesize($num) / 1e6, $rowsN);
    foreach (['assoc' => 'Reader assoc rows (strings)', 'columns' => 'Reader columns() (strings)', 'columns-int' => 'Reader columns() with intval casts'] as $shape => $label) {
        $cmd = escapeshellarg(PHP_BINARY) . ' -d memory_limit=-1 ' . escapeshellarg(__FILE__) . " --mem=$shape --file=" . escapeshellarg($num);
        printf("| %s | %.1f |\n", $label, (int) shell_exec($cmd) / 1e6);
    }
    echo "\n";
}

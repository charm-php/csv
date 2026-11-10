<?php

declare(strict_types=1);

/**
 * Benchmarks charm/csv against fgetcsv(), str_getcsv() and fputcsv() on generated data.
 * Run it through run.php, which starts this script with and without the JIT:
 *
 *     php bench/run.php
 *
 * Options: --rows=200000  --runs=9
 * Times are best and median of --runs after two warm-up runs.
 */

use Charm\Csv\Bench\Cases;
use Charm\Csv\Mode;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/Cases.php';

$opts = getopt('', ['rows::', 'runs::']);
$rowsN = (int) ($opts['rows'] ?? 200000);
$runs = (int) ($opts['runs'] ?? 9);
$dir = __DIR__ . '/data';

/** Deterministic data: "plain" has no quotes at all; "messy" has quoted fields, doubled quotes, embedded newlines and delimiters. */
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

/** @return array{float, float} best and median in milliseconds */
function measure(int $runs, callable $fn): array
{
    for ($i = 0; $i < 2; $i++) { $fn(); } // warm-up: lets the tracing JIT see the hot loops
    $t = [];
    for ($i = 0; $i < $runs; $i++) {
        gc_collect_cycles();
        $s = hrtime(true);
        $x = $fn();
        $t[] = (hrtime(true) - $s) / 1e6;
        unset($x);
    }
    sort($t);
    return [$t[0], $t[intdiv(count($t), 2)]];
}

function table(string $title, array $results, float $bytes, string $baseline): void
{
    printf("### %s\n\n| Method | best ms | median ms | MB/s (best) | vs %s (best) |\n|---|---:|---:|---:|---:|\n", $title, $baseline);
    $base = null;
    foreach ($results as $label => [$best, $median]) {
        $base ??= $best;
        printf("| %s | %.0f | %.0f | %.0f | %.2fx |\n", $label, $best, $median, $bytes / 1e6 / ($best / 1000), $base / $best);
    }
    echo "\n";
}

@mkdir($dir);
foreach (['plain' => false, 'messy' => true] as $name => $messy) {
    if (!is_file("$dir/$name-$rowsN.csv")) { generate("$dir/$name-$rowsN.csv", $rowsN, $messy); }
}

$status = function_exists('opcache_get_status') ? opcache_get_status(false) : false;
$jitOn = is_array($status) && ($status['jit']['on'] ?? false);
printf("PHP %s, %s, %s, opcache %s, JIT %s\n\n", PHP_VERSION, php_uname('s') . ' ' . php_uname('r'),
    trim((string) shell_exec("grep -m1 'model name' /proc/cpuinfo | cut -d: -f2")),
    is_array($status) ? 'on' : 'off', $jitOn ? 'tracing (opcache.jit=' . ini_get('opcache.jit') . ')' : 'off');

// ---- decoding ----------------------------------------------------------------------------------
foreach (['plain', 'messy'] as $name) {
    $file = "$dir/$name-$rowsN.csv";
    $expected = Cases::fgetcsvCollect($file);
    if (Cases::decoderCollect($file) !== $expected) { echo "MISMATCH against fgetcsv on $name data\n"; exit(1); }

    $cases = [
        'fgetcsv() loop, collect' => fn() => Cases::fgetcsvCollect($file),
        'fgetcsv() loop, stream' => fn() => Cases::fgetcsvStream($file),
    ];
    if ($name === 'plain') { // str_getcsv() per line is only valid without line breaks inside fields
        $cases['str_getcsv() per line, collect'] = fn() => Cases::strGetcsvCollect($file);
    }
    $cases += [
        'Decoder (Lax), collect' => fn() => Cases::decoderCollect($file),
        'Decoder (Lax), stream' => fn() => Cases::decoderStream($file),
        'Decoder (Strict), stream' => fn() => Cases::decoderStream($file, Mode::Strict),
        'Decoder (Lax, header: true), stream' => fn() => Cases::decoderStream($file, Mode::Lax, true),
    ];
    $results = [];
    foreach ($cases as $label => $fn) { $results[$label] = measure($runs, $fn); }
    table("Decoding $name data: $rowsN rows, " . round(filesize($file) / 1e6, 1) . ' MB', $results, (float) filesize($file), 'fgetcsv collect');
}

// ---- encoding ----------------------------------------------------------------------------------
echo "### Encoding $rowsN rows to php://memory\n\n| Data | Method | best ms | median ms | MB/s (best) | vs fputcsv (best) |\n|---|---|---:|---:|---:|---:|\n";
foreach (['plain', 'messy'] as $name) {
    $rows = Cases::fgetcsvCollect("$dir/$name-$rowsN.csv");
    $a = measure($runs, fn() => Cases::fputcsv($rows));
    $b = measure($runs, fn() => Cases::encoder($rows));
    $sizeA = Cases::fputcsv($rows);
    $sizeB = Cases::encoder($rows);
    printf("| %s | fputcsv() loop | %.0f | %.0f | %.0f | 1.00x |\n", $name, $a[0], $a[1], $sizeA / 1e6 / ($a[0] / 1000));
    printf("| %s | Encoder + fwrite() | %.0f | %.0f | %.0f | %.2fx |\n", $name, $b[0], $b[1], $sizeB / 1e6 / ($b[0] / 1000), $a[0] / $b[0]);
}
echo "\n(fputcsv() also encloses fields containing a space or tab, so its output is slightly larger; MB/s uses each method's own output size.)\n\n";

// ---- proof that the JIT compiled something -----------------------------------------------------
$status = function_exists('opcache_get_status') ? opcache_get_status(false) : false;
if ($jitOn) {
    $used = $status['jit']['buffer_size'] - $status['jit']['buffer_free'];
    printf("JIT check: %d bytes of the %d byte JIT buffer in use after the run%s\n", $used, $status['jit']['buffer_size'], $used > 0 ? '' : ' (NOTHING COMPILED: numbers are not JIT numbers)');
    if ($used === 0) { exit(2); }
}

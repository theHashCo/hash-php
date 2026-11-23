<?php

declare(strict_types=1);

// Generates src/Enum, src/Model and src/Service from spec/openapi.json.
//
//   php scripts/generate.php           write the files
//   php scripts/generate.php --check   exit 1 when the committed files differ from a fresh run

require __DIR__ . '/Generator.php';

use Hash\Dev\Generator;

$root = dirname(__DIR__);
$files = (new Generator(Generator::loadSpec($root)))->generate();
$check = in_array('--check', $argv, true);
$drift = false;

// Generated files the spec no longer produces.
foreach (['src/Enum', 'src/Model', 'src/Service'] as $dir) {
    foreach (glob("$root/$dir/*.php") ?: [] as $abs) {
        $rel = substr($abs, strlen($root) + 1);
        $content = (string) file_get_contents($abs);
        if (!isset($files[$rel]) && str_contains($content, Generator::MARKER)) {
            if ($check) {
                fwrite(STDERR, "$rel is no longer generated: delete it (run composer generate).\n");
                $drift = true;
            } else {
                unlink($abs);
                echo "deleted $rel\n";
            }
        }
    }
}

foreach ($files as $rel => $content) {
    $abs = "$root/$rel";
    if ($check) {
        if (!is_file($abs) || file_get_contents($abs) !== $content) {
            fwrite(STDERR, "$rel is out of date. Run composer generate.\n");
            $drift = true;
        }
        continue;
    }
    if (!is_dir(dirname($abs))) {
        mkdir(dirname($abs), 0777, true);
    }
    file_put_contents($abs, $content);
}
if (!$check) {
    echo 'Wrote ' . count($files) . " files.\n";
}
exit($drift ? 1 : 0);

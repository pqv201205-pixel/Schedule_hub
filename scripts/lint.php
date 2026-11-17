<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
$count = 0;
foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php' || str_contains($file->getPathname(), DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR)) continue;
    $command = escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file->getPathname());
    exec($command, $out, $status);
    if ($status !== 0) { fwrite(STDERR, implode("\n", $out) . "\n"); exit(1); }
    $count++;
    $out = [];
}
echo "PHP lint PASSED ($count files).\n";

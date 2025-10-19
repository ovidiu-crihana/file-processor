<?php
namespace App\Service;

use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

class SystemCheckService
{
    private Filesystem $fs;
    private array $env;

    public function __construct()
    {
        $this->fs = new Filesystem();
        $this->env = $_ENV;
    }

    public function runChecks(SymfonyStyle $io): bool
    {
        $io->section('Environment Checks');
        $ok = true;

        // PHP
        $io->text("PHP version: " . PHP_VERSION);
        if (version_compare(PHP_VERSION, '8.2', '<')) {
            $io->error('PHP >= 8.2 required');
            $ok = false;
        }

        // Required extensions
        $requiredExt = ['mbstring','intl','imagick','fileinfo'];
        foreach ($requiredExt as $ext) {
            if (!extension_loaded($ext)) {
                $io->warning("Missing extension: $ext");
            }
        }

        // Composer packages check
        $composerLock = __DIR__ . '/../../composer.lock';
        if (!$this->fs->exists($composerLock)) {
            $io->warning('composer.lock not found - run composer install');
        }

        // Paths
        $paths = [
            'SOURCE_BASE_PATH' => $this->env['SOURCE_BASE_PATH'] ?? null,
            'OUTPUT_BASE_PATH' => $this->env['OUTPUT_BASE_PATH'] ?? null,
            'CSV_PATH'         => $this->env['CSV_PATH'] ?? null,
            'IMAGEMAGICK_PATH' => $this->env['IMAGEMAGICK_PATH'] ?? null,
        ];

        foreach ($paths as $name => $path) {
            if (!$path) {
                $io->error("$name not defined");
                $ok = false;
                continue;
            }
            if (in_array($name, ['SOURCE_BASE_PATH','OUTPUT_BASE_PATH'])) {
                $exists = $this->fs->exists($path);
                $io->text("$name → " . ($exists ? "OK" : "MISSING"));
                if (!$exists) $ok = false;
            }
            if ($name === 'IMAGEMAGICK_PATH') {
                $process = new Process([$path, '-version']);
                $process->run();
                if (!$process->isSuccessful()) {
                    $io->error("ImageMagick not runnable at $path");
                    $ok = false;
                } else {
                    $io->text("ImageMagick OK: " . trim(explode("\n", $process->getOutput())[0]));
                }
            }
        }

        // CSV
        $csvPath = $this->env['CSV_PATH'] ?? null;
        if ($csvPath && $this->fs->exists($csvPath)) {
            $size = filesize($csvPath);
            $io->text("CSV file found (" . round($size/1024/1024,2) . " MB)");
        } else {
            $io->warning("CSV not found or not accessible");
        }

        // Permissions
        foreach (['LOGS_PATH','TEMP_PATH','OUTPUT_BASE_PATH'] as $p) {
            $dir = $this->env[$p] ?? null;
            if ($dir && !$this->fs->exists($dir)) {
                $this->fs->mkdir($dir, 0775);
                $io->text("Created missing folder: $dir");
            }
            if ($dir && !is_writable($dir)) {
                $io->error("Folder not writable: $dir");
                $ok = false;
            }
        }

        $io->newLine();
        $io->success($ok ? 'All essential components are OK' : 'Some checks failed');
        return $ok;
    }
}

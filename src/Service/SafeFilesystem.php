<?php
declare(strict_types=1);

namespace App\Service;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Exception\IOException;

/**
 * Filesystem "safe" con retry per lock temporanei su Windows/SMB.
 */
final class SafeFilesystem extends Filesystem
{
    private int $retries;
    private int $sleepMs;

    public function __construct(int $retries = 5, int $sleepMs = 150)
    {
        $this->retries = $retries;
        $this->sleepMs = $sleepMs;
    }

    public function safeMkdir(string $dir, int $mode = 0777): void
    {
        if ($this->exists($dir)) return;

        $attempt = 0;
        do {
            try {
                $this->mkdir($dir, $mode);
                return;
            } catch (IOException $e) {
                if (++$attempt > $this->retries) {
                    throw $e;
                }
                usleep($this->sleepMs * 1000);
            }
        } while (true);
    }

    public function safeCopy(string $originFile, string $targetFile, bool $overwrite = false): void
    {
        $attempt = 0;
        do {
            try {
                parent::copy($originFile, $targetFile, $overwrite);
                return;
            } catch (IOException $e) {
                if (++$attempt > $this->retries) throw $e;
                usleep($this->sleepMs * 1000);
            }
        } while (true);
    }

    public function safeDumpFile(string $filename, string $content): void
    {
        $attempt = 0;
        do {
            try {
                parent::dumpFile($filename, $content);
                return;
            } catch (IOException $e) {
                if (++$attempt > $this->retries) throw $e;
                usleep($this->sleepMs * 1000);
            }
        } while (true);
    }

    public function safeRename(string $origin, string $target, bool $overwrite = false): void
    {
        $attempt = 0;
        do {
            try {
                parent::rename($origin, $target, $overwrite);
                return;
            } catch (IOException $e) {
                if (++$attempt > $this->retries) throw $e;
                usleep($this->sleepMs * 1000);
            }
        } while (true);
    }
}

<?php
declare(strict_types=1);

namespace App\Service;

use Symfony\Component\Process\Process;

final class FileOperations
{
    public function __construct(
        private readonly LoggerService $logger,
        private readonly SafeFilesystem $fs
    ) {}

    /**
     * Esegue il merge dei file TIFF e crea il PDF.
     * Tutte le operazioni pesanti avvengono su cartella temporanea locale
     * per minimizzare RAM e I/O su rete.
     *
     * @param array<int,string> $tifFiles
     * @return 'OK'|'OK_PARTIAL'|'ERROR'
     */
    public function mergeTiffGroup(
        array $tifFiles,
        string $tifOut,
        string $pdfOut,
        string $magickPath
    ): string {
        $total = count($tifFiles);
        if ($total === 0) {
            $this->logger->warn("⚠️  Nessun file nel gruppo — merge saltato (simulazione).");
            usleep(100_000);
            return 'OK_PARTIAL';
        }

        // Filtra file esistenti
        $existing = array_filter($tifFiles, fn($f) => is_string($f) && file_exists($f));
        $missingCount = $total - count($existing);

        if ($missingCount > 0) {
            $this->logger->warn("⚠️  Mancano {$missingCount} file TIFF su {$total} — verranno ignorati.");
        }

        if (count($existing) === 0) {
            $this->logger->warn("⚠️  Nessun file esistente — simulazione merge vuoto.");
            usleep(150_000);
            return 'OK_PARTIAL';
        }

        // === Cartella temporanea locale ===
        $tmpBase = rtrim((string)($_ENV['TEMP_PATH'] ?? sys_get_temp_dir()), "\\/");
        if (!is_dir($tmpBase)) {
            @mkdir($tmpBase, 0777, true);
        }

        $uid      = uniqid('fp_', true);
        $listFile = $tmpBase . DIRECTORY_SEPARATOR . $uid . '_list.txt';
        $tifTmp   = $tmpBase . DIRECTORY_SEPARATOR . $uid . '.tif';
        $pdfTmp   = $tmpBase . DIRECTORY_SEPARATOR . $uid . '.pdf';

        $memLimit = $_ENV['IMAGEMAGICK_MEMORY_LIMIT'] ?? '1GiB';
        $mapLimit = $_ENV['IMAGEMAGICK_MAP_LIMIT']    ?? '2GiB';
        $thrLimit = (int)($_ENV['IMAGEMAGICK_THREAD_LIMIT'] ?? 1);
        $tmpDirIM = $tmpBase;

        $fileListStr = implode(' ', array_map(
            static fn($f) => '"' . $f . '"',
            $existing
        ));

        try {
            // Merge TIFF multipagina (Group4 per BN; rows-per-strip aiuta memoria)
            $cmdTif = sprintf(
                '"%s" -limit memory %s -limit map %s -limit thread %d ' .
                '-define registry:temporary-path="%s" ' .
                '%s -compress Group4 -strip -define tiff:rows-per-strip=64K "%s"',
                $magickPath, $memLimit, $mapLimit, $thrLimit, $tmpDirIM,
                $fileListStr, $tifTmp
            );
            $this->runCommand($cmdTif, 'merge-tiff');

            // TIFF → PDF
            $cmdPdf = sprintf(
                '"%s" -limit memory %s -limit map %s -limit thread %d ' .
                '-define registry:temporary-path="%s" "%s" "%s"',
                $magickPath, $memLimit, $mapLimit, $thrLimit, $tmpDirIM,
                $tifTmp, $pdfTmp
            );
            $this->runCommand($cmdPdf, 'tiff2pdf');

            // Spostamento finale atomico verso la share
            $this->fs->rename($tifTmp, $tifOut, true);
            $this->fs->rename($pdfTmp, $pdfOut, true);
        } catch (\Throwable $e) {
            $this->logger->error("❌ Errore durante merge: " . $e->getMessage());
            @unlink($tifTmp);
            @unlink($pdfTmp);
            return 'ERROR';
        } finally {
            @unlink($listFile);
        }

        return $missingCount > 0 ? 'OK_PARTIAL' : 'OK';
    }

    /**
     * Esegue un comando shell e lancia eccezione in caso di errore.
     */
    private function runCommand(string $cmd, string $label): void
    {
        $this->logger->debug("▶️  $label → $cmd");
        $process = Process::fromShellCommandline($cmd);
        $process->setTimeout(0);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(sprintf(
                'Comando %s fallito (code %d): %s',
                $label,
                $process->getExitCode(),
                trim($process->getErrorOutput() ?: $process->getOutput())
            ));
        }
    }

    // ============================================================
    //  FUNZIONI PER LE "TAVOLE" (COPY + PDF)
    // ============================================================

    public function buildTavolaSourcePath(string $triggerValue, string $imageName): string
    {
        $base = rtrim((string)($_ENV['TAVOLE_BASE_PATH'] ?? ''), "\\/");
        $filename = $triggerValue . '_' . $imageName;
        return $base . DIRECTORY_SEPARATOR . $filename;
    }

    public function copyFile(string $src, string $dst): bool
    {
        try {
            $dir = dirname($dst);
            if (!is_dir($dir)) {
                @mkdir($dir, 0777, true);
            }

            if (!file_exists($src)) {
                $this->logger->warn("⚠️  File sorgente non trovato: $src");
                return false;
            }

            // Usa SafeFilesystem per coerenza (se implementa copy/rename atomica)
            if (method_exists($this->fs, 'copy')) {
                $this->fs->copy($src, $dst, true);
                return true;
            }

            if (!@copy($src, $dst)) {
                $this->logger->error("❌ Copia fallita: $src -> $dst");
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            $this->logger->error("❌ Errore durante copia: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Conversione singolo TIFF → PDF (usata per le tavole).
     * Usa i limiti di memoria configurabili e staging locale.
     */
    public function convertSingleTiffToPdf(string $srcTif, string $dstPdf): bool
    {
        $magickPath = $_ENV['IMAGEMAGICK_PATH'] ?? ($_ENV['MAGICK_PATH'] ?? 'magick');
        if (!file_exists($srcTif)) {
            $this->logger->warn("⚠️  File TIFF sorgente mancante per conversione: $srcTif");
            return false;
        }

        $dir = dirname($dstPdf);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        $tmpBase = rtrim((string)($_ENV['TEMP_PATH'] ?? sys_get_temp_dir()), "\\/");
        $pdfTmp  = $tmpBase . DIRECTORY_SEPARATOR . uniqid('fp_', true) . '.pdf';

        $memLimit = $_ENV['IMAGEMAGICK_MEMORY_LIMIT'] ?? '512MiB';
        $mapLimit = $_ENV['IMAGEMAGICK_MAP_LIMIT']    ?? '1GiB';
        $thrLimit = (int)($_ENV['IMAGEMAGICK_THREAD_LIMIT'] ?? 1);

        $cmd = sprintf(
            '"%s" -limit memory %s -limit map %s -limit thread %d ' .
            '-define registry:temporary-path="%s" "%s" "%s"',
            $magickPath, $memLimit, $mapLimit, $thrLimit, $tmpBase, $srcTif, $pdfTmp
        );

        try {
            $this->runCommand($cmd, 'tiff2pdf-single');
            $this->fs->rename($pdfTmp, $dstPdf, true);
            return true;
        } catch (\Throwable $e) {
            $this->logger->error("❌ Errore durante conversione singolo TIFF→PDF: " . $e->getMessage());
            @unlink($pdfTmp);
            return false;
        }
    }

    public function buildOutputPath(string $type, int $folderIndex, string $filename): string
    {
        $base = rtrim($_ENV['OUTPUT_BASE_PATH'] ?? '', "\\/");
        return sprintf('%s\\%s\\%03d\\%s', $base, strtoupper($type), $folderIndex, $filename);
    }
}

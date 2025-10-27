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
     * Ritorna lo stato: OK / OK_PARTIAL / ERROR
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
            usleep(100000);
            return 'OK_PARTIAL';
        }

        // Filtra solo file esistenti
        $existing = array_filter($tifFiles, fn($f) => file_exists($f));
        $missingCount = $total - count($existing);

        if ($missingCount > 0) {
            $this->logger->warn("⚠️  Mancano $missingCount file TIFF su $total — verranno ignorati.");
        }

        // Se nessuno esiste → simulazione (utile in test locale)
        if (count($existing) === 0) {
            $this->logger->warn("⚠️  Nessun file esistente — simulazione merge vuoto.");
            usleep(150000);
            return 'OK_PARTIAL';
        }

        $listFile = tempnam(sys_get_temp_dir(), 'merge_');
        file_put_contents($listFile, implode(PHP_EOL, $existing));

        $tifTmp = $tifOut . '.tmp.tif';
        $pdfTmp = $pdfOut . '.tmp.pdf';

        try {
            // ✅ Merge TIFF (rimuoviamo il subcomando convert)
            $cmdTif = sprintf('"%s" @%s -compress Group4 -strip -define tiff:rows-per-strip=64K "%s"', $magickPath, $listFile, $tifTmp);
            $this->runCommand($cmdTif, 'merge-tif');

            // ✅ Conversione PDF
            $cmdPdf = sprintf('"%s" "%s" "%s"', $magickPath, $tifTmp, $pdfTmp);
            $this->runCommand($cmdPdf, 'tiff2pdf');

            // ✅ Sostituzione atomica
            $this->fs->rename($tifTmp, $tifOut, true);
            $this->fs->rename($pdfTmp, $pdfOut, true);

            @unlink($listFile);
        } catch (\Throwable $e) {
            $this->logger->error("❌ Errore durante merge: " . $e->getMessage());
            return 'ERROR';
        }

        return $missingCount > 0 ? 'OK_PARTIAL' : 'OK';
    }

    /**
     * Esegue un comando shell e solleva eccezione se fallisce
     */
    private function runCommand(string $cmd, string $label): void
    {
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
    // 🆕 NUOVE FUNZIONI PER GESTIONE "TAVOLE" (COPY + PDF)
    // ============================================================

    /**
     * Costruisce il path sorgente per le tavole.
     * Esempio:
     *  trigger = "abc123", immagine = "000001.tif"
     *  → \\server\Work\Tavole\Importate\abc123_000001.tif
     */
    public function buildTavolaSourcePath(string $triggerValue, string $imageName): string
    {
        $base = rtrim((string)($_ENV['TAVOLE_BASE_PATH'] ?? ''), "\\/");
        $filename = $triggerValue . '_' . $imageName;
        return $base . DIRECTORY_SEPARATOR . $filename;
    }

    /**
     * Copia un file (creando la cartella destinazione se necessario)
     */
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
     * Conversione TIFF → PDF per singolo file (usata dalle tavole)
     */
    public function convertSingleTiffToPdf(string $srcTif, string $dstPdf): bool
    {
        $magickPath = $_ENV['MAGICK_PATH'] ?? 'magick';
        $dir = dirname($dstPdf);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        if (!file_exists($srcTif)) {
            $this->logger->warn("⚠️  File TIFF sorgente mancante per conversione: $srcTif");
            return false;
        }

        $pdfTmp = $dstPdf . '.tmp.pdf';
        $cmd = sprintf('"%s" "%s" "%s"', $magickPath, $srcTif, $pdfTmp);

        try {
            $this->runCommand($cmd, 'tiff2pdf-single');
            $this->fs->rename($pdfTmp, $dstPdf, true);
            return true;
        } catch (\Throwable $e) {
            $this->logger->error("❌ Errore durante conversione singolo TIFF→PDF: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Utility già presente nel progetto (riconfermata):
     * Costruisce il path completo di output (Output\TIFF|PDF\{n_cartella}\{filename})
     */
    public function buildOutputPath(string $type, int $folderIndex, string $filename): string
    {
        $base = rtrim($_ENV['OUTPUT_BASE_PATH'] ?? '', "\\/");
        return sprintf('%s\\%s\\%03d\\%s', $base, strtoupper($type), $folderIndex, $filename);
    }
}

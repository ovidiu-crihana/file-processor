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
}

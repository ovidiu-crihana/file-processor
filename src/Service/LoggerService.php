<?php
declare(strict_types=1);

namespace App\Service;

use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Symfony\Component\Console\Style\SymfonyStyle;

final class LoggerService
{
    private Logger $logger;

    public function __construct()
    {
        $this->logger = new Logger('file_processor');
        $logFile = $_ENV['LOG_FILE'] ?? 'var/logs/file_processor.log';
        $level = $_ENV['LOG_LEVEL'] ?? 'info';
        $this->logger->pushHandler(new StreamHandler($logFile, Logger::toMonologLevel($level)));
    }

    public function info(string $message): void { $this->logger->info($message); }
    public function warn(string $message): void { $this->logger->warning($message); }
    public function error(string $message): void { $this->logger->error($message); }

    public function groupStart(
        SymfonyStyle $io,
        string       $fileName,
        int          $folderNum,
        int          $fileCount,
        string       $batch,
        int          $iddoc,
        string       $tipo,
        bool         $isTavola,
        bool         $hasSuffix
    ): void
    {
        $io->newLine();
        $io->writeln(sprintf("📁 [#%03d] <fg=cyan>%s</>", $folderNum, $fileName));
        $io->writeln(sprintf("   ├─ Batch: <fg=gray>%s</>", $batch));
        $io->writeln(sprintf("   ├─ Tipo documento: <fg=yellow>%s</>", $tipo));
        $io->writeln(sprintf("   ├─ ID Documento: <fg=gray>%d</>", $iddoc));
        $io->writeln(sprintf(
            "   ├─ Eccezioni: Tavole=%s | Suffisso=%s",
            $isTavola ? '<fg=red>SI</>' : '<fg=green>NO</>',
            $hasSuffix ? '<fg=red>SI</>' : '<fg=green>NO</>'
        ));
        $io->writeln(sprintf("   ├─ File previsti: <fg=gray>%d</>", $fileCount));
        $io->writeln("   ├─ Stato: ▶️ Avvio elaborazione...");
    }

    public function groupEndDetailed(
        SymfonyStyle $io,
        string       $fileName,
        int          $folderNum,
        float        $duration,
        string       $status,
        int          $foundCount,
        int          $missingCount,
        string       $batch,
        int          $iddoc,
        string       $tipo
    ): void
    {
        $symbol = match ($status) {
            'OK' => '✅',
            'OK_PARTIAL' => '⚠️',
            default => '❌'
        };
        $label = match ($status) {
            'OK' => 'OK',
            'OK_PARTIAL' => 'PARZIALE',
            default => 'ERRORE'
        };

        $io->writeln(sprintf(
            "   └─ Risultato: %s %s (%d/%d file, %d mancanti, durata %.2f s)",
            $symbol,
            $label,
            $foundCount,
            $foundCount + $missingCount,
            $missingCount,
            $duration
        ));
    }
}

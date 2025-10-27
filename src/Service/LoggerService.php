<?php
declare(strict_types=1);

namespace App\Service;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\ErrorLogHandler;
use Symfony\Component\Console\Style\SymfonyStyle;

final class LoggerService
{
    private Logger $logger;

    public function __construct()
    {
        $logFile = $_ENV['LOG_FILE'] ?? __DIR__ . '/../../var/logs/file_processor.log';
        $level   = $_ENV['LOG_LEVEL'] ?? 'info';

        // Normalizza livello
        $levelMap = [
            'debug' => Logger::DEBUG,
            'info' => Logger::INFO,
            'notice' => Logger::NOTICE,
            'warning' => Logger::WARNING,
            'error' => Logger::ERROR,
            'critical' => Logger::CRITICAL,
        ];
        $levelCode = $levelMap[strtolower($level)] ?? Logger::INFO;

        $this->logger = new Logger('file_processor');
        $this->logger->pushHandler(new StreamHandler($logFile, $levelCode, true, 0644));
        // opzionale: anche su stderr in caso di errori non gestiti
        $this->logger->pushHandler(new ErrorLogHandler(ErrorLogHandler::OPERATING_SYSTEM, $levelCode));
    }

    public function info(string $message): void    { $this->logger->info($message); }
    public function warning(string $message): void { $this->logger->warning($message); }
    public function warn(string $message): void    { $this->logger->warning($message); }
    public function error(string $message): void   { $this->logger->error($message); }
    public function debug(string $message): void   { $this->logger->debug($message); }

    // ────────────────────────────────────────────────
    //  Metodi per formattazione console (immutati)
    // ────────────────────────────────────────────────
    public function logGroupStart(SymfonyStyle $io, array $ctx): void
    {
        $folderNum  = (string)($ctx['folderNum'] ?? '000');
        $groupName  = (string)($ctx['groupName'] ?? '');
        $batch      = (string)($ctx['batch'] ?? '');
        $tipo       = (string)($ctx['tipo'] ?? '');
        $idDoc      = (string)($ctx['idDoc'] ?? '');
        $isTavola   = (bool)($ctx['isTavola'] ?? false);
        $isTitolo   = (bool)($ctx['isTitolo'] ?? false);
        $total      = (int)($ctx['totalFiles'] ?? 0);
        $found      = (int)($ctx['foundFiles'] ?? 0);
        $missing    = (int)($ctx['missingFiles'] ?? max(0, $total - $found));
        $srcFile    = (string)($ctx['srcFile'] ?? '');
        $tifOut     = (string)($ctx['tifOut'] ?? '');
        $pdfOut     = (string)($ctx['pdfOut'] ?? '');
        $pdfEnabled = (bool)($ctx['pdfEnabled'] ?? true);

        $summary = sprintf(
            '[%s] %s | batch=%s | tipo=%s | id=%s | tot=%d | found=%d | miss=%d',
            $folderNum, $groupName, $batch, $tipo, $idDoc, $total, $found, $missing
        );
        $this->logger->info("Start: {$summary}");

        $io->writeln(sprintf('<fg=cyan>📁 [#%s] %s</>', $folderNum, $groupName));
        $io->writeln(sprintf('   ├─ Batch: %s', $batch));
        if ($isTitolo) {
            $io->writeln(sprintf('   ├─ <fg=yellow>Tipo documento: %s</>', $tipo));
        } else {
            $io->writeln(sprintf('   ├─ Tipo documento: %s', $tipo));
        }
        $io->writeln(sprintf('   ├─ ID Documento: %s', $idDoc));
        $io->writeln(sprintf('   ├─ Eccezioni: Tavole=%s', $isTavola ? 'SI' : 'NO'));
        $io->writeln(sprintf('   ├─ File previsti: %d', $total));
        if ($isTavola && $srcFile !== '') {
            $io->writeln(sprintf('   ├─ <fg=magenta>File sorgente</>: %s', $srcFile));
        }
        if ($tifOut !== '') {
            $io->writeln(sprintf('   ├─ Output TIFF: %s', $tifOut));
        }
        if ($pdfEnabled && $pdfOut !== '') {
            $io->writeln(sprintf('   ├─ Output PDF:  %s', $pdfOut));
        }
        $io->writeln('   ├─ Stato: ▶️ Avvio elaborazione...');
    }

    public function logGroupResult(SymfonyStyle $io, array $ctx): void
    {
        $status   = strtoupper((string)($ctx['status'] ?? 'OK'));
        $found    = (int)($ctx['found'] ?? 0);
        $total    = (int)($ctx['total'] ?? 0);
        $missing  = (int)($ctx['missing'] ?? max(0, $total - $found));
        $duration = (float)($ctx['duration'] ?? 0.0);

        [$icon, $color, $label] = match ($status) {
            'OK'         => ['✅', 'green',   'OK'],
            'OK_PARTIAL' => ['⚠️', 'yellow',  'PARZIALE'],
            default      => ['❌', 'red',     'ERRORE'],
        };

        $io->writeln(sprintf(
            '   └─ Risultato: <fg=%s>%s %s</> (%d/%d file, %d mancanti, durata %.2f s)',
            $color, $icon, $label, $found, $total, $missing, $duration
        ));

        $this->logger->info(sprintf(
            'End: %s | %s | %d/%d | miss=%d | %.2fs',
            $label, $status, $found, $total, $missing, $duration
        ));
    }
}

<?php
declare(strict_types=1);

namespace App\Command;

use App\Service\CsvReader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:debug-merge',
    description: 'Anteprima completa del processo di merge/copy senza esecuzione reale'
)]
final class DebugMergeCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Filtra per BATCH specifico', null)
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Limita il numero di gruppi mostrati (0=tutti)', '0')
            ->addOption('max-rows', null, InputOption::VALUE_REQUIRED, 'Leggi massimo N righe (0=tutte)', '0')
            ->addOption('show-files', null, InputOption::VALUE_NONE, 'Mostra elenco file sorgenti per i gruppi standard')
            ->addOption('summary', null, InputOption::VALUE_NONE, 'Mostra solo riepilogo finale (niente dettagli gruppi/cartelle)')
            ->addOption('log', null, InputOption::VALUE_NONE, 'Scrive l’output dettagliato su file fisso (var/logs/debug-merge.log)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $summaryOnly = (bool)$input->getOption('summary');
        $useLog = (bool)$input->getOption('log');

        // Gestione file di log fisso
        $logger = null;
        $logPath = null;
        if ($useLog) {
            $logDir = __DIR__ . '/../../var/logs';
            @mkdir($logDir, 0777, true);
            $logPath = $logDir . '/debug-merge.log';
            $logger = fopen($logPath, 'wb');
            if (!$logger) {
                $io->error("Impossibile aprire il file di log: {$logPath}");
                return Command::FAILURE;
            }
        }

        $write = function (string $line) use ($summaryOnly, $logger, $io) {
            if ($logger) fwrite($logger, $line . PHP_EOL);
            elseif (!$summaryOnly) $io->writeln($line);
        };

        $csvPath = $_ENV['CSV_PATH'] ?? '';
        if (!$csvPath || !is_file($csvPath)) {
            $io->error('CSV_PATH non impostato o file non trovato.');
            return Command::FAILURE;
        }

        $batchF    = $input->getOption('batch') ?: null;
        $limit     = (int)$input->getOption('limit');
        $maxRows   = (int)$input->getOption('max-rows');
        $showFiles = (bool)$input->getOption('show-files');

        // Variabili .env
        $sourceBase     = rtrim($_ENV['SOURCE_BASE_PATH'] ?? '', '\\/');
        $tavoleBase     = rtrim($_ENV['TAVOLE_BASE_PATH'] ?? 'Work\\Tavole\\Importate', '\\/');
        $tavoleTrigger  = $_ENV['TAVOLE_TRIGGER_PATTERN'] ?? '^[A-Za-z0-9]+\\.$';
        $patternEnv     = str_replace('{SUFFISSO}', '', $_ENV['OUTPUT_FILENAME_PATTERN'] ?? '{TIPO_PRATICA}_{PRAT_NUM}_{ANNO}_{TIPO_DOCUMENTO}.{EXT}');
        $tavolePdf      = filter_var($_ENV['TAVOLE_PDF_ENABLED'] ?? 'true', FILTER_VALIDATE_BOOL);

        // Stato
        $folderNum = 0;
        $folderKey = null;
        $groupKey  = null;
        $prefix    = null;
        $groupRows = [];
        $groupFiles = [];
        $groupsShown = 0;
        $rowsRead = 0;
        $groupNormalCount = 0;
        $groupTavolaCount = 0;
        $totalFiles = 0;

        // Calcola offset se batch filtrato
        $folderOffset = 0;
        if ($batchF) {
            $found = false;
            $seen = [];
            foreach (CsvReader::iterate($csvPath) as $r) {
                $batch = trim($r['BATCH'] ?? '');
                $iddoc = trim($r['IDDOCUMENTO'] ?? '');
                $key = "{$batch}|{$iddoc}";
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    if ($batch !== $batchF && !$found) {
                        $folderOffset++;
                    } elseif ($batch === $batchF) {
                        $found = true;
                    }
                }
                if ($found) break;
            }
        }

        // Helpers ---------------------------------------------------------------
        $extractAnno = fn(string $protData): string =>
        substr(preg_replace('/[^0-9]/', '', $protData), -4) ?: '0000';

        $resolveSource = function (array $row, string $sourceBase): string {
            $nome = trim($row['IMMAGINE'] ?? '');
            $batch = trim($row['BATCH'] ?? '');
            $tsStr = trim($row['DATA_ORA_ACQ'] ?? '');
            $ts = strtotime($tsStr);
            if (!$ts) return "[INVALID_DATE]\\{$nome}";
            $yyyy = date('Y', $ts); $mm = date('m', $ts); $dd = date('d', $ts);
            return "{$sourceBase}\\{$yyyy}\\{$mm}\\{$dd}\\{$batch}\\{$nome}";
        };

        $extractTavolaSuffix = function (string $image): string {
            $base = pathinfo($image, PATHINFO_FILENAME);
            $parts = explode('_', $base);
            return end($parts) ?: $base;
        };

        // ----------------------------------------------------------------------
        try {
            foreach (CsvReader::iterate($csvPath) as $row) {
                if ($maxRows > 0 && $rowsRead >= $maxRows) break;
                $rowsRead++;

                $stato = strtoupper(trim($row['STATO'] ?? ''));
                $batch = trim($row['BATCH'] ?? '');
                $iddoc = trim($row['IDDOCUMENTO'] ?? '');
                $tipo  = trim($row['TIPO_DOCUMENTO'] ?? '');
                $imm   = trim($row['IMMAGINE'] ?? '');

                if ($batchF && $batch !== $batchF) continue;

                // trigger tavole
                if ($stato !== 'CORRETTO' && preg_match('/'.$tavoleTrigger.'/i', $tipo)) {
                    $prefix = rtrim($tipo, '.');
                    continue;
                }
                if ($stato !== 'CORRETTO') continue;

                // cambio cartella
                $newFolderKey = "{$batch}|{$iddoc}";
                if ($newFolderKey !== $folderKey) {
                    if (!empty($groupRows)) {
                        $this->flushGroup($groupRows, $groupFiles, $folderOffset + ++$folderNum, $patternEnv, $showFiles, $limit, $groupsShown, $write);
                        $groupRows = []; $groupFiles = [];
                        $groupNormalCount++;
                    } else {
                        $folderNum++;
                    }

                    $folderKey = $newFolderKey;
                    $groupKey = null;
                    $prefix = null;

                    if (!$summaryOnly)
                        $write(str_repeat('═', 80) . PHP_EOL . "📁 Cartella #" . sprintf('%03d', $folderOffset + $folderNum) . " — BATCH={$batch}" . PHP_EOL . str_repeat('─', 80));
                }

                // tavola
                if (preg_match('/tavola|elaborato/i', $tipo) && $prefix) {
                    $trigger = $prefix;
                    $src = "{$tavoleBase}\\{$trigger}_{$imm}";
                    $suffix = $extractTavolaSuffix($imm);
                    $base = "ELABORATO_GRAFICO_{$suffix}";
                    $num = sprintf('%03d', $folderOffset + $folderNum);
                    $tifDst = "Output\\TIFF\\{$num}\\{$base}.tif";
                    $pdfDst = "Output\\PDF\\{$num}\\{$base}.pdf";

                    if (!$summaryOnly) {
                        $write("<fg=cyan>---- Cartella #{$num}, Gruppo [TAVOLA] {$base} (BATCH={$batch}) ----</>");
                        $write("ID documento: {$iddoc}");
                        $write("Tipo documento: {$tipo}");
                        $write("Totale file nel gruppo: 1 (" . (is_file($src)?'1':'0') . "/1 trovati)");
                        $write("<fg=yellow>File originale: {$src}</>");
                        $write("Output TIFF: {$tifDst}");
                        $write("Output PDF:  {$pdfDst}");
                        $write(str_repeat('─', 80));
                    }

                    $groupTavolaCount++;
                    $groupsShown++;
                    $totalFiles++;
                    if ($limit > 0 && $groupsShown >= $limit) throw new \RuntimeException('__STOP__');
                    continue;
                }

                // gruppo standard
                $newGroupKey = "{$batch}|{$tipo}";
                if ($groupKey !== null && $newGroupKey !== $groupKey && !empty($groupRows)) {
                    $this->flushGroup($groupRows, $groupFiles, $folderOffset + $folderNum, $patternEnv, $showFiles, $limit, $groupsShown, $write);
                    $groupRows = []; $groupFiles = [];
                    $groupNormalCount++;
                }
                $groupKey = $newGroupKey;

                $src = $resolveSource($row, $sourceBase);
                $groupRows[] = $row;
                $groupFiles[] = $src;
                $totalFiles++;
            }

            if (!empty($groupRows)) {
                $this->flushGroup($groupRows, $groupFiles, $folderOffset + $folderNum, $patternEnv, $showFiles, $limit, $groupsShown, $write);
                $groupNormalCount++;
            }
        } catch (\RuntimeException $e) {
            if ($e->getMessage() !== '__STOP__') throw $e;
        } finally {
            if ($logger) fclose($logger);
        }

        // Riepilogo finale
        $io->newLine(2);
        $io->section('📊 Riepilogo esecuzione');
        $io->listing([
            "Batch filtrato: " . ($batchF ?: '[tutti]'),
            "Totale righe lette: {$rowsRead}",
            "Totale cartelle: " . ($folderOffset + $folderNum),
            "Gruppi normali (merge): {$groupNormalCount}",
            "Gruppi tavole (copy): {$groupTavolaCount}",
            "File totali analizzati: {$totalFiles}",
        ]);
        $io->success('Anteprima completata senza errori.');
        if ($useLog && $logPath) $io->writeln("📄 Log salvato in: <fg=yellow>{$logPath}</>");
        return Command::SUCCESS;
    }

    // -------------------------------------------------------------------------
    private function flushGroup(
        array &$rows,
        array &$files,
        int $cartellaNum,
        string $pattern,
        bool $showFiles,
        int $limit,
        int &$groupsShown,
        callable $write
    ): void {
        if (empty($rows)) return;

        $first = $rows[0];
        $batch = trim($first['BATCH'] ?? '');
        $iddoc = trim($first['IDDOCUMENTO'] ?? '');
        $tipo  = trim($first['TIPO_DOCUMENTO'] ?? '');

        $extractAnno = fn(string $protData): string =>
        substr(preg_replace('/[^0-9]/', '', $protData), -4) ?: '0000';

        $repl = [
            '{TIPO_PRATICA}'   => trim($first['TIPO_PRATICA'] ?? ''),
            '{PRAT_NUM}'       => trim($first['PRAT_NUM'] ?? ''),
            '{ANNO}'           => $extractAnno($first['PROT_PRAT_DATA'] ?? ''),
            '{TIPO_DOCUMENTO}' => preg_match('/TITOLO_AUTORIZZATIVO/i', $first['TIPO_DOCUMENTO'] ?? '')
                ? trim($first['SIGLA_PRATICA'] ?? '')
                : trim($first['TIPO_DOCUMENTO'] ?? ''),
            '{EXT}'            => 'tif',
        ];

        $outBase = strtr($pattern, $repl);
        $outBase = str_replace([' ', '/', '\\', ':', '*', '?', '"', '<', '>', '|'], '_', $outBase);
        $outBase = preg_replace('/_+/', '_', pathinfo($outBase, PATHINFO_FILENAME));

        $num = sprintf('%03d', $cartellaNum);
        $found = array_filter($files, 'is_file');

        $isTavola = str_contains(strtoupper($tipo), 'TAVOLA') || str_contains(strtoupper($tipo), 'ELABORATO');
        $isTitolo = str_contains(strtoupper($tipo), 'TITOLO_AUTORIZZATIVO');

        $write("<fg=cyan>---- Cartella #{$num}, Gruppo {$outBase} (BATCH={$batch}) ----</>");
        $write("ID documento: {$iddoc}");

        if ($isTitolo)
            $write("<fg=magenta>Tipo documento: {$tipo}</>");
        else
            $write("Tipo documento: {$tipo}");

        $write("Totale file nel gruppo: " . count($files) . " (" . count($found) . "/" . count($files) . " trovati)");

        if ($isTavola && isset($files[0]))
            $write("<fg=yellow>File originale: {$files[0]}</>");

        $write("Output TIFF: Output\\TIFF\\{$num}\\{$outBase}.tif");
        $write("Output PDF:  Output\\PDF\\{$num}\\{$outBase}.pdf");
        $write(str_repeat('─', 80));

        if ($showFiles && $files) {
            foreach ($files as $f) {
                $mark = is_file($f) ? '✅' : '❌';
                $write("  {$mark} {$f}");
            }
            $write(str_repeat('─', 80));
        }

        $groupsShown++;
        if ($limit > 0 && $groupsShown >= $limit) {
            throw new \RuntimeException('__STOP__');
        }
    }
}

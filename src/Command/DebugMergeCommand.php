<?php
declare(strict_types=1);

namespace App\Command;

use App\Service\CsvReader;
use App\Service\FileOperations;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:debug-merge',
    description: 'Anteprima gruppi, numerazione cartelle e nomi output'
)]
final class DebugMergeCommand extends Command
{
    public function __construct(
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('batch',        null, InputOption::VALUE_REQUIRED, 'Filtra per BATCH', null)
            ->addOption('tipo',         null, InputOption::VALUE_REQUIRED, 'Filtra per TIPO_DOCUMENTO', null)
            ->addOption('limit',        null, InputOption::VALUE_REQUIRED, 'Limita gruppi mostrati (0=tutti)', '0')
            ->addOption('max-rows',     null, InputOption::VALUE_REQUIRED, 'Leggi massimo N righe dal CSV (0=tutte)', '0')
            ->addOption('show-files',   null, InputOption::VALUE_NONE,     'Elenca i file dei gruppi (potrebbero essere tanti)')
            ->addOption('verbose-scan', null, InputOption::VALUE_NONE,     'Stampa motivi di skip riga (diagnostica)')
            ->addOption('summary-only', null, InputOption::VALUE_NONE,     'Mostra solo riepilogo finale (niente gruppi).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io        = new SymfonyStyle($input, $output);
        $csvPath   = $_ENV['CSV_PATH'] ?? '';
        if (!$csvPath || !is_file($csvPath)) {
            $io->error('CSV_PATH non impostato o file non trovato.');
            return Command::FAILURE;
        }

        $batchF    = $input->getOption('batch') ?: null;
        $tipoF     = $input->getOption('tipo')  ?: null;
        $limit     = (int)$input->getOption('limit');
        $maxRows   = (int)$input->getOption('max-rows');
        $showFiles = (bool)$input->getOption('show-files');
        $summary   = (bool)$input->getOption('summary-only');
        $vscan     = (bool)$input->getOption('verbose-scan');

        $io->title("Anteprima merge e output ($csvPath)");
        $io->newLine();

        // ENV
        $sourceBase   = rtrim($_ENV['SOURCE_BASE_PATH'] ?? '', '\\/');
        $tavoleBase   = rtrim($_ENV['TAVOLE_BASE_PATH']  ?? 'Work\\Tavole\\Importate', '\\/');
        $triggerRx    = $_ENV['TAVOLE_TRIGGER_PATTERN'] ?? '^[A-Za-z0-9]+\\.$';
        $planVals     = array_values(array_filter(array_map('trim', explode(';', $_ENV['TAVOLE_PLANIMETRIE_VALUES'] ?? 'ELABORATO_GRAFICO'))));
        $suffixTitolo = $_ENV['OUTPUT_SUFFIX_TITOLO_AUTORIZZATIVO'] ?? '';

        // Stati
        $currentFolderKey = null; // (BATCH|IDDOCUMENTO)
        $currentFolderNum = 0;
        $currentGroupKey  = null; // (BATCH|TIPO_DOCUMENTO)
        $currentPrefix    = null; // prefisso tavole nello scope (BATCH, IDDOCUMENTO)

        $groupFiles = [];
        $groupRows  = [];
        $shownGroups = 0;

        // Diagnostica
        $rowsRead = 0;
        $rowsCorretto = 0;
        $rowsFiltered = 0;
        $rowsTriggers = 0;
        $rowsSkipped = 0;
        $totalFolders = 0;

        // Helpers
        $buildOutputName = function(array $row) use ($suffixTitolo): string {
            $tipo  = trim($row['TIPO_PRATICA'] ?? '');
            $prot  = trim($row['PROT_PRAT_NUMERO'] ?? '');
            $data  = trim($row['PROT_PRAT_DATA'] ?? '');
            $anno  = substr(preg_replace('/[^0-9]/', '', $data), -4);
            $doc   = trim($row['TIPO_DOCUMENTO'] ?? '');
            $suff  = ($doc === 'TITOLO_AUTORIZZATIVO' && $suffixTitolo !== '') ? $suffixTitolo : '';
            $name  = "{$tipo}_{$prot}_{$anno}_{$doc}{$suff}";
            $name  = str_replace([' ', '/', '\\', ':', '*', '?', '"', '<', '>', '|'], '_', $name);
            return preg_replace('/_+/', '_', $name);
        };
        $resolveSource = function(array $row, bool $isPlanimetria) use ($sourceBase, $tavoleBase, &$currentPrefix) {
            $nomeFile = trim($row['IMMAGINE'] ?? '');
            $batch    = trim($row['BATCH'] ?? '');
            $tsStr    = trim($row['DATA_ORA_ACQ'] ?? '');
            $ts       = strtotime($tsStr);
            if ($ts === false) return "[INVALID_DATE] {$nomeFile}";
            $yyyy = date('Y', $ts); $mm = date('m', $ts); $dd = date('d', $ts);

            if ($isPlanimetria) {
                if (!$currentPrefix) return "[MISSING_PREFIX] {$nomeFile}";
                return "{$tavoleBase}\\{$currentPrefix}{$nomeFile}";
            }
            return "{$sourceBase}\\{$yyyy}\\{$mm}\\{$dd}\\{$batch}\\{$nomeFile}";
        };
        $flushGroup = function() use (&$groupFiles, &$groupRows, &$currentFolderNum, &$shownGroups, $limit, $io, $buildOutputName, $showFiles, &$currentFolderKey, &$totalFolders, $summary) {
            if (empty($groupFiles) || empty($groupRows)) return;
            $first = $groupRows[0];
            $out   = $buildOutputName($first);
            $pages = count($groupFiles);

            if (!$summary) {
                $batch = $first['BATCH'] ?? '';
                $iddoc = $first['IDDOCUMENTO'] ?? '';
                $isTavole = str_contains($out, 'ELABORATO_GRAFICO') || str_contains($out, 'PLANIMETRIA');
                $label = $isTavole ? "⭐️ [TAVOLE]" : "";
                $folderLabel = sprintf("🗂️ Cartella #%03d %s (BATCH=%s, IDDOC=%s)", $currentFolderNum, $label, $batch, $iddoc);
                $io->section($folderLabel);
                $io->text("Output: {$out}.tif / {$out}.pdf");
                $io->text("Pagine: {$pages}");
                if ($showFiles) $io->listing(array_map(fn($f) => " - $f", $groupFiles));
            }

            $groupFiles = []; $groupRows = [];
            $shownGroups++;
            if ($limit > 0 && $shownGroups >= $limit) throw new \RuntimeException('__STOP__');
        };

        try {
            $timeStart = microtime(true);
            $memStart = memory_get_usage();

            foreach (CsvReader::iterate($csvPath) as $row) {
                if ($maxRows > 0 && $rowsRead >= $maxRows) break;
                $rowsRead++;

                $stato = strtoupper(trim($row['STATO'] ?? ''));
                $batch = trim($row['BATCH'] ?? '');
                $iddoc = trim($row['IDDOCUMENTO'] ?? '');
                $tipo  = trim($row['TIPO_DOCUMENTO'] ?? '');

                // Filtri opzionali
                if ($batchF && $batch !== $batchF && $rowsCorretto > 0) {
                    break;
                }
                if ($tipoF  && $tipo  !== $tipoF)   { $rowsFiltered++; continue; }

                // Trigger tavole: riga non corretta + match regex
                if ($stato !== 'CORRETTO' && preg_match('/'.$triggerRx.'/i', $tipo)) {
                    $currentPrefix = $tipo; $rowsTriggers++;
                    if ($vscan) $io->text("trigger: set prefix {$currentPrefix} (BATCH=$batch, IDDOC=$iddoc)");
                    continue;
                }

                // Skip righe non corrette
                if ($stato !== 'CORRETTO') { $rowsSkipped++; continue; }
                $rowsCorretto++;

                $folderKey = "{$batch}|{$iddoc}";
                $groupKey  = "{$batch}|{$tipo}";

                // Cambio cartella numerata
                if ($folderKey !== $currentFolderKey) {
                    if ($currentFolderKey !== null && !empty($groupFiles)) $flushGroup();
                    $currentFolderNum++;
                    $totalFolders++;
                    $currentFolderKey = $folderKey;
                    $currentPrefix = null;
                }

                // Cambio gruppo
                if ($groupKey !== $currentGroupKey && $currentGroupKey !== null && !empty($groupFiles)) {
                    $flushGroup();
                }
                $currentGroupKey = $groupKey;

                $isPlanimetria = in_array($tipo, $planVals, true);
                $src = $resolveSource($row, $isPlanimetria);

                $groupFiles[] = $src;
                $groupRows[]  = $row;
            }

            if (!empty($groupFiles)) $flushGroup();
        } catch (\RuntimeException $e) {
            if ($e->getMessage() !== '__STOP__') throw $e;
        }

        $timeEnd = microtime(true);
        $memEnd = memory_get_peak_usage();

        $elapsed = round($timeEnd - $timeStart, 2);
        $memoryMb = round($memEnd / 1024 / 1024, 2);

        // 🧾 Riepilogo finale
        $io->section('Riepilogo CSV');
        $io->listing([
            "Totale righe lette: {$rowsRead}",
            "Righe STATO=CORRETTO: {$rowsCorretto}",
            "Righe skippate (non corrette): {$rowsSkipped}",
            "Righe filtrate da opzioni: {$rowsFiltered}",
            "Trigger tavole rilevati: {$rowsTriggers}",
            "Cartelle numerate totali: {$totalFolders}",
            "Gruppi generati: {$shownGroups}",
        ]);
        $io->newLine();
        $io->writeln(sprintf(" • Tempo totale: <info>%s sec</info>", number_format($elapsed, 2)));
        $io->writeln(sprintf(" • Memoria massima: <info>%s MB</info>", number_format($memoryMb, 2)));

        $io->success('Anteprima completata.');
        return Command::SUCCESS;
    }
}

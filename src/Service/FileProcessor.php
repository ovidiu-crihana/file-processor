<?php
declare(strict_types=1);

namespace App\Service;

use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Style\SymfonyStyle;

final class FileProcessor
{
    private bool $tavolePdfEnabled;

    public function __construct(
        private readonly LoggerService  $log,
        private readonly FileOperations $ops,
    )
    {
        $this->tavolePdfEnabled = filter_var($_ENV['TAVOLE_PDF_ENABLED'] ?? 'true', FILTER_VALIDATE_BOOL);
    }

    /**
     * Conta i gruppi per progress bar usando le stesse regole di grouping:
     * - gruppi = cambi di (BATCH + TIPO_DOCUMENTO) dentro una cartella
     * - cartella = cambia quando cambia (BATCH + IDDOCUMENTO)
     * - tavole: la riga trigger (tipo alfanum con punto) NON conta come gruppo; conta il doc planimetrie a seguire
     */
    public function estimateTotalGroups(?string $batchFilter, ?string $tipoFilter, string $tavolePattern): int
    {
        $csv = $_ENV['CSV_PATH'] ?? '';
        if (!is_file($csv)) return 0;

        $count = 0;
        $folderKey = null;
        $groupKey = null;
        $prefix = null;

        foreach (CsvReader::iterate($csv) as $row) {
            $stato = strtoupper(trim($row['STATO'] ?? ''));
            $batch = trim($row['BATCH'] ?? '');
            $iddoc = trim($row['IDDOCUMENTO'] ?? '');
            $tipo = trim($row['TIPO_DOCUMENTO'] ?? '');

            if ($batchFilter && $batch !== $batchFilter) continue;
            if ($tipoFilter && $tipo !== $tipoFilter) continue;

            // trigger tavole
            if ($stato !== 'CORRETTO' && preg_match('/' . $tavolePattern . '/i', $tipo)) {
                $prefix = $tipo;
                continue;
            }
            if ($stato !== 'CORRETTO') continue;

            $newFolderKey = "{$batch}|{$iddoc}";
            $newGroupKey = "{$batch}|{$tipo}";

            if ($newFolderKey !== $folderKey) {
                $folderKey = $newFolderKey;
                $groupKey = null;
                $prefix = null;
            }

            // 🆕 ogni riga di tavola ora è un gruppo autonomo
            $isTavola = preg_match('/tavola|elaborato/i', $tipo);
            if ($isTavola) {
                $count++;
                continue;
            }

            if ($newGroupKey !== $groupKey) {
                $groupKey = $newGroupKey;
                $count++;
            }
        }
        return $count;
    }

    /**
     * Esecuzione reale in streaming
     */
    public function process(
        string       $csvPath,
        string       $magickPath,
        string       $checkpointPath,
        string       $outputBase,
        string       $sourceBase,
        string       $tavoleBase,
        string       $tavolePattern,
        array        $planimetrie,
        bool         $dryRun,
        bool         $resume,
        ?int         $limitGroups,
        ?string      $batchFilter,
        ?string      $tipoFilter,
        ?ProgressBar $progress,
        SymfonyStyle $io,
    ): void
    {
        $skipStatuses = array_filter(array_map(
            'strtoupper',
            array_map('trim', explode(',', $_ENV['RESUME_SKIP_STATUSES'] ?? 'OK'))
        ));

        $done = $this->loadCheckpointMap($checkpointPath);
        if ($resume && count($done) > 0) {
            $toSkip = 0;
            foreach ($done as $st) {
                if (in_array(strtoupper(trim((string)$st)), $skipStatuses, true)) {
                    $toSkip++;
                }
            }
            $io->newLine();
            $io->writeln(sprintf(
                "🔁 Modalità <fg=yellow>resume</> — stati da saltare: <fg=yellow>%s</> — gruppi saltati: <fg=yellow>%d</>.",
                implode(', ', $skipStatuses),
                $toSkip
            ));
            $lastKey = array_key_last($done);
            if ($lastKey) $io->writeln("   ↳ Ultimo record checkpoint: <fg=gray>{$lastKey}</>");
            $io->newLine();
        }

        $folderKey = null;
        $groupKey = null;
        $folderNum = 0;
        $prefix = null;
        $groupFiles = [];
        $groupRows = [];
        $groupsDone = 0;

        $flush = function () use (&$groupFiles, &$groupRows, &$folderNum, $outputBase, $magickPath, $checkpointPath, $resume, $done, $dryRun, &$groupsDone, $progress, $io) {
            if (!$groupFiles || !$groupRows) return;

            $first = $groupRows[0];
            $batch = (string)($first['BATCH'] ?? '');
            $iddoc = (int)($first['IDDOCUMENTO'] ?? 0);
            $tipo = (string)($first['TIPO_DOCUMENTO'] ?? '');
            $ckey = "{$batch}|{$iddoc}|{$tipo}";
            $prevStatus = $done[$ckey] ?? null;

            $skipStatuses = array_filter(array_map(
                'strtoupper',
                array_map('trim', explode(',', $_ENV['RESUME_SKIP_STATUSES'] ?? 'OK'))
            ));
            $prevStatusNorm = $prevStatus ? strtoupper(trim((string)$prevStatus)) : null;

            if ($resume && $prevStatusNorm && in_array($prevStatusNorm, $skipStatuses, true)) {
                $io->writeln(sprintf(
                    "⏭️  Resume: salto gruppo [%s|%s|%s] (stato=%s)",
                    $batch, $iddoc, $tipo, $prevStatusNorm
                ));
                $groupFiles = [];
                $groupRows  = [];
                return;
            }

            $baseName = $this->buildOutputBase($first);
            $tifDir = $outputBase . "\\TIFF\\" . $folderNum;
            $pdfDir = $outputBase . "\\PDF\\" . $folderNum;
            @mkdir($tifDir, 0777, true);
            @mkdir($pdfDir, 0777, true);
            $tifPath = $tifDir . "\\" . $baseName . ".tif";
            $pdfPath = $pdfDir . "\\" . $baseName . ".pdf";

            $found = array_filter($groupFiles, 'is_file');
            $missing = array_diff($groupFiles, $found);

            $isTavola = (bool)preg_match('/tavola|elaborato/i', $tipo);
            $hasSuffix = (stripos($tipo, 'TITOLO_AUTORIZZATIVO') !== false);

            $this->log->groupStart($io, $baseName, $folderNum, count($groupFiles), $batch, $iddoc, $tipo, $isTavola, $hasSuffix);

            $t0 = microtime(true);
            $status = 'OK';

            try {
                if ($dryRun) {
                    usleep(100000 + count($groupFiles) * 4000);
                } else {
                    if (count($found) === 0) {
                        $status = 'ERROR';
                    } else {
                        $mergeStatus = $this->ops->mergeTiffGroup($found, $tifPath, $pdfPath, $magickPath);
                        $status = $mergeStatus;
                    }
                }
            } catch (\Throwable) {
                $status = 'ERROR';
            }

            if ($status === 'OK' && count($missing) > 0) {
                $status = 'OK_PARTIAL';
            }

            $dt = microtime(true) - $t0;
            $this->log->groupEndDetailed($io, $baseName, $folderNum, $dt, $status, count($found), count($missing), $batch, $iddoc, $tipo);
            $this->appendCheckpoint($checkpointPath, $batch, $iddoc, $tipo, $folderNum, $status);

            if ($progress) $progress->advance();
            $groupsDone++;
            $groupFiles = [];
            $groupRows = [];
        };

        // ===========================================================
        // 🧠 Loop principale CSV
        // ===========================================================
        foreach (CsvReader::iterate($csvPath) as $row) {
            $stato = strtoupper(trim($row['STATO'] ?? ''));
            $batch = trim($row['BATCH'] ?? '');
            $iddoc = trim($row['IDDOCUMENTO'] ?? '');
            $tipo = trim($row['TIPO_DOCUMENTO'] ?? '');

            if ($batchFilter && $batch !== $batchFilter) continue;
            if ($tipoFilter && $tipo !== $tipoFilter) continue;

            // Trigger tavole
            if ($stato !== 'CORRETTO' && preg_match('/' . $tavolePattern . '/i', $tipo)) {
                $prefix = $tipo;
                continue;
            }
            if ($stato !== 'CORRETTO') continue;

            $newFolderKey = "{$batch}|{$iddoc}";
            if ($newFolderKey !== $folderKey) {
                $flush();
                $folderNum++;
                $folderKey = $newFolderKey;
                $groupKey = null;
                $prefix = null;
            }

            $nome = trim($row['IMMAGINE'] ?? '');
            $isTavola = preg_match('/tavola|elaborato/i', $tipo);

            // 🆕 Se è una TAVOLA, la elaboriamo come gruppo singolo (senza merge)
            if ($isTavola && $prefix) {
                $this->processSingleTavolaRow($row, $folderNum, $prefix, $tavoleBase, $outputBase, $magickPath, $dryRun, $checkpointPath, $io);
                $groupsDone++;
                if ($progress) $progress->advance();
                if ($limitGroups !== null && $groupsDone >= $limitGroups) break;
                continue;
            }

            // Raggruppamento standard
            $newGroupKey = "{$batch}|{$tipo}";
            if ($groupKey !== null && $newGroupKey !== $groupKey) {
                $flush();
                if ($limitGroups !== null && $groupsDone >= $limitGroups) break;
            }
            $groupKey = $newGroupKey;

            // Costruisci path sorgente standard
            $ts = strtotime((string)($row['DATA_ORA_ACQ'] ?? ''));
            $yyyy = $ts ? date('Y', $ts) : '0000';
            $mm = $ts ? date('m', $ts) : '00';
            $dd = $ts ? date('d', $ts) : '00';
            $src = $sourceBase . "\\{$yyyy}\\{$mm}\\{$dd}\\{$batch}\\{$nome}";

            $groupFiles[] = $src;
            $groupRows[] = $row;
        }

        $flush();

        $io->newLine(2);
        $memoryUsed = memory_get_peak_usage(true) / 1024 / 1024;
        $io->success(sprintf("Completato. Memoria di picco: %.2f MB", $memoryUsed));
    }

    // ===============================================================
    // 🆕 Gestione TAVOLA singola
    // ===============================================================
    private function processSingleTavolaRow(
        array $row,
        int $folderNum,
        string $prefix,
        string $tavoleBase,
        string $outputBase,
        string $magickPath,
        bool $dryRun,
        string $checkpointPath,
        SymfonyStyle $io
    ): void {
        $batch = (string)($row['BATCH'] ?? '');
        $iddoc = (int)($row['IDDOCUMENTO'] ?? 0);
        $tipo = (string)($row['TIPO_DOCUMENTO'] ?? '');
        $immagine = trim((string)($row['IMMAGINE'] ?? ''));

        $src = $this->ops->buildTavolaSourcePath($prefix, $immagine);

        $tifName = "ELABORATO_GRAFICO_" . $immagine;
        $pdfName = preg_replace('/\.tif$/i', '.pdf', $tifName);

        $tifDst = $outputBase . "\\TIFF\\{$folderNum}\\" . $tifName;
        $pdfDst = $outputBase . "\\PDF\\{$folderNum}\\" . $pdfName;

        $io->writeln("📄 Tavola: {$src}");

        $status = 'OK';
        $t0 = microtime(true);

        try {
            if ($dryRun) {
                usleep(100000);
            } else {
                if (!is_file($src)) {
                    $this->log->warn("⚠️  Tavola mancante: $src");
                    $status = 'ERROR';
                } else {
                    $okCopy = $this->ops->copyFile($src, $tifDst);
                    $okPdf = true;

                    if ($okCopy && $this->tavolePdfEnabled) {
                        $okPdf = $this->ops->convertSingleTiffToPdf($tifDst, $pdfDst);
                    }

                    if (!$okCopy) $status = 'ERROR';
                    elseif (!$okPdf) $status = 'OK_PARTIAL';
                }
            }
        } catch (\Throwable $e) {
            $status = 'ERROR';
            $this->log->error("❌ Tavola exception: " . $e->getMessage());
        }

        $dt = microtime(true) - $t0;
        $this->log->groupEndDetailed($io, basename($tifDst), $folderNum, $dt, $status, $status === 'OK' ? 1 : 0, $status === 'OK' ? 0 : 1, $batch, $iddoc, $tipo);
        $this->appendCheckpoint($checkpointPath, $batch, $iddoc, $tipo, $folderNum, $status);
    }

    // ===============================================================
    // Costruttore nome output (gestisce “TITOLO AUTORIZZATIVO”)
    // ===============================================================
    private function buildOutputBase(array $row): string
    {
        $tipoPrat = trim($row['TIPO_PRATICA'] ?? '');
        $pratNum = trim($row['PRAT_NUM'] ?? '');
        $protData = trim($row['PROT_PRAT_DATA'] ?? '');
        $anno = substr(preg_replace('/[^0-9]/', '', $protData), -4);
        $tipoDoc = trim($row['TIPO_DOCUMENTO'] ?? '');
        $siglaPrat = trim($row['SIGLA_PRATICA'] ?? '');

        // Se contiene “TITOLO AUTORIZZATIVO”, usa SIGLA_PRATICA
        if (stripos($tipoDoc, 'TITOLO AUTORIZZATIVO') !== false && $siglaPrat !== '') {
            $tipoDoc = $siglaPrat;
        }

        $name = "{$tipoPrat}_{$pratNum}_{$anno}_{$tipoDoc}";
        $name = str_replace([' ', '/', '\\', ':', '*', '?', '"', '<', '>', '|'], '_', $name);
        return preg_replace('/_+/', '_', $name);
    }

    // ---------------------------------------------------------------
    // Checkpoint helpers (invariati)
    // ---------------------------------------------------------------
    private function loadCheckpointMap(string $path): array
    {
        $map = [];
        if (!is_file($path)) return $map;

        $f = fopen($path, 'rb');
        if (!$f) return $map;
        fgetcsv($f);
        while (($r = fgetcsv($f)) !== false) {
            if (count($r) < 6) continue;
            [$b, $i, $t, $folder, $status] = $r;
            $key = trim($b).'|'.trim((string)$i).'|'.trim($t);
            $map[$key] = trim((string)$status);
        }
        fclose($f);
        return $map;
    }

    private function appendCheckpoint(string $path, string $batch, int $iddoc, string $tipo, int $folderNum, string $status): void
    {
        $header = "BATCH,IDDOCUMENTO,TIPO_DOCUMENTO,FOLDER_NUM,STATUS,UPDATED_AT\n";
        $key = "{$batch}|{$iddoc}|{$tipo}";

        $rows = [];
        if (is_file($path)) {
            $f = fopen($path, 'rb');
            if ($f) {
                fgetcsv($f);
                while (($r = fgetcsv($f)) !== false) {
                    if (count($r) < 6) continue;
                    [$b, $i, $t, $folder, $st, $ts] = $r;
                    $rows["{$b}|{$i}|{$t}"] = [$b, $i, $t, $folder, $st, $ts];
                }
                fclose($f);
            }
        }

        $rows[$key] = [$batch, $iddoc, $tipo, $folderNum, $status, (new \DateTimeImmutable())->format('c')];

        @mkdir(dirname($path), 0777, true);
        $f = fopen($path, 'wb');
        fwrite($f, $header);
        foreach ($rows as $row) fputcsv($f, $row);
        fclose($f);
    }
}

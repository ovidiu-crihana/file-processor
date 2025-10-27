<?php
declare(strict_types=1);

namespace App\Service;

use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

final class FileProcessor
{
    public function __construct(
        private readonly LoggerService  $log,
        private readonly FileOperations $ops,
    ) {}

    public function process(
        string       $csvPath,
        string       $magickPath,
        string       $checkpoint,
        string       $outputBase,
        string       $sourceBase,
        string       $tavoleBase,
        string       $tavoleTrigger,
        string       $patternEnv,
        bool         $dryRun,
        bool         $resume,
        ?int         $limitGroups,
        ?string      $batchFilter,
        int          $maxRows,
        SymfonyStyle $io,
    ): void {
        $tStart = microtime(true);

        // Env / flags
        $tavolePdfEnabled = filter_var($_ENV['TAVOLE_PDF_ENABLED'] ?? 'true', FILTER_VALIDATE_BOOL);
        $resumeSkip = array_filter(array_map(
            'strtoupper',
            array_map('trim', explode(',', $_ENV['RESUME_SKIP_STATUSES'] ?? 'OK'))
        )) ?: ['OK'];

        // Checkpoint (mappa chiave => STATUS)
        $done = $this->loadCheckpointMap($checkpoint);

        // Calcolo offset per numerazione reale (se filtro batch)
        $folderOffset = $this->computeFolderOffset($csvPath, $batchFilter);

        $io->writeln(str_repeat('═', 80));
        $io->writeln(sprintf(
            '📂 Offset calcolato: %d — la prima cartella sarà #%s',
            $folderOffset,
            str_pad((string)($folderOffset + 1), 3, '0', STR_PAD_LEFT)
        ));
        $io->writeln(str_repeat('═', 80));
        $io->newLine();

        // Stato di scansione
        $folderKey = null; // BATCH|IDDOCUMENTO
        $groupKey  = null; // BATCH|TIPO_DOCUMENTO
        $prefix    = null; // trigger tavole (senza punto)
        $folderNum = 0;    // contatore locale (senza offset)
        $groupsDone = 0;

        // Buffer per gruppo standard
        $groupRows  = [];
        $groupFiles = [];

        // Statistiche
        $rowsRead = 0;
        $groupsStandardCount = 0;
        $groupsTavoleCount   = 0;

        $extractAnno = fn(string $protData): string =>
        substr(preg_replace('/[^0-9]/', '', $protData), -4) ?: '0000';

        $buildOutputBase = function(array $row) use ($extractAnno, $patternEnv): string {
            $repl = [
                '{TIPO_PRATICA}'   => trim($row['TIPO_PRATICA'] ?? ''),
                '{PRAT_NUM}'       => trim($row['PRAT_NUM'] ?? ''),
                '{ANNO}'           => $extractAnno($row['PROT_PRAT_DATA'] ?? ''),
                '{TIPO_DOCUMENTO}' => trim($row['TIPO_DOCUMENTO'] ?? ''),
                '{EXT}'            => 'tif',
            ];
            if (preg_match('/TITOLO_AUTORIZZATIVO/i', $repl['{TIPO_DOCUMENTO}'])) {
                $repl['{TIPO_DOCUMENTO}'] = trim($row['SIGLA_PRATICA'] ?? '');
            }
            $name = strtr($patternEnv, $repl);
            $name = str_replace([' ', '/', '\\', ':', '*', '?', '"', '<', '>', '|'], '_', $name);
            $name = preg_replace('/_+/', '_', $name);
            return pathinfo($name, PATHINFO_FILENAME);
        };

        $resolveStandardSource = function(array $row) use ($sourceBase) {
            $nome  = trim($row['IMMAGINE'] ?? '');
            $batch = trim($row['BATCH'] ?? '');
            $tsStr = trim($row['DATA_ORA_ACQ'] ?? '');
            $ts    = strtotime($tsStr);
            if (!$ts) return "[INVALID_DATE]\\{$nome}";
            $yyyy = date('Y', $ts); $mm = date('m', $ts); $dd = date('d', $ts);
            return "{$sourceBase}\\{$yyyy}\\{$mm}\\{$dd}\\{$batch}\\{$nome}";
        };

        $extractTavolaSuffix = function (string $image): string {
            $base  = pathinfo($image, PATHINFO_FILENAME);
            $parts = explode('_', $base);
            return end($parts) ?: $base;
        };

        // Flush del gruppo standard (merge)
        $flushStandard = function() use (
            &$groupRows, &$groupFiles, &$folderNum, &$groupsDone, &$groupsStandardCount,
            $folderOffset, $outputBase, $buildOutputBase, $resume, $resumeSkip, $done, $dryRun,
            $magickPath, $checkpoint, $io
        ) {
            if (empty($groupRows)) return;

            $first = $groupRows[0];
            $batch = trim($first['BATCH'] ?? '');
            $iddoc = (string)($first['IDDOCUMENTO'] ?? '');
            $tipo  = trim($first['TIPO_DOCUMENTO'] ?? '');

            $ckey = "{$batch}|{$iddoc}|{$tipo}";
            $prevStatus = $done[$ckey] ?? null;
            $prevNorm = $prevStatus ? strtoupper(trim((string)$prevStatus)) : null;

            $cartellaNum = $folderOffset + $folderNum;
            $numStr = str_pad((string)$cartellaNum, 3, '0', STR_PAD_LEFT);

            $baseName = $buildOutputBase($first);
            $tifDir = $outputBase . "\\TIFF\\" . $numStr;
            $pdfDir = $outputBase . "\\PDF\\" . $numStr;
            @mkdir($tifDir, 0777, true);
            @mkdir($pdfDir, 0777, true);
            $tifOut = "{$tifDir}\\{$baseName}.tif";
            $pdfOut = "{$pdfDir}\\{$baseName}.pdf";

            // Resume?
            if ($resume && $prevNorm && in_array($prevNorm, $resumeSkip, true)) {
                $io->writeln(sprintf(
                    "⏭️  Resume: salto gruppo <fg=yellow>%s</> in cartella #<fg=cyan>%s</> (prev=%s).",
                    $baseName, $numStr, $prevNorm
                ));
                $groupRows = []; $groupFiles = [];
                return;
            }

            // Conteggio file
            $total  = count($groupFiles);
            $foundFiles = array_values(array_filter($groupFiles, 'is_file'));
            $found  = count($foundFiles);
            $missing = max(0, $total - $found);

            // Log inizio
            $this->log->logGroupStart($io, [
                'folderNum'    => $numStr,
                'groupName'    => $baseName,
                'batch'        => $batch,
                'tipo'         => $tipo,
                'idDoc'        => (int)$iddoc,
                'isTavola'     => false,
                'isTitolo'     => (bool)preg_match('/TITOLO_AUTORIZZATIVO/i', $tipo),
                'totalFiles'   => $total,
                'foundFiles'   => $found,
                'missingFiles' => $missing,
                'tifOut'       => $tifOut,
                'pdfOut'       => $pdfOut,
                'pdfEnabled'   => true,
            ]);

            $t0 = microtime(true);
            $status = 'OK';

            try {
                if ($dryRun) {
                    usleep(100_000 + $total * 3_000);
                } else {
                    if ($found === 0) {
                        $status = 'OK_PARTIAL'; // tutti mancanti → partial
                    } else {
                        // 👉 merge ottimizzato (staging locale + limiti IM)
                        $status = $this->ops->mergeTiffGroup($foundFiles, $tifOut, $pdfOut, $_ENV['IMAGEMAGICK_PATH'] ?? $magickPath);
                    }
                }
            } catch (\Throwable $e) {
                $status = 'ERROR';
                $io->writeln('<fg=red>❌ Eccezione durante merge: ' . $e->getMessage() . '</>');
            }

            if ($status === 'OK' && $missing > 0) {
                $status = 'OK_PARTIAL';
            }

            $dt = microtime(true) - $t0;
            $this->log->logGroupResult($io, [
                'status'   => $status,
                'found'    => $found,
                'total'    => $total,
                'missing'  => $missing,
                'duration' => $dt,
            ]);

            // Checkpoint
            $this->appendCheckpoint($checkpoint, $batch, (int)$iddoc, $tipo, (int)$cartellaNum, $status);

            $groupsDone++;
            $groupsStandardCount++;
            $groupRows = []; $groupFiles = [];
        };

        // SCANSIONE CSV
        try {
            foreach (CsvReader::iterate($csvPath) as $row) {
                if ($maxRows > 0 && $rowsRead >= $maxRows) break;
                $rowsRead++;

                $stato = strtoupper(trim($row['STATO'] ?? ''));
                $batch = trim($row['BATCH'] ?? '');
                $iddoc = trim($row['IDDOCUMENTO'] ?? '');
                $tipo  = trim($row['TIPO_DOCUMENTO'] ?? '');
                $imm   = trim($row['IMMAGINE'] ?? '');

                if ($batchFilter && $batch !== $batchFilter) continue;

                // Trigger tavole (riga non corretta + match pattern) → imposta prefisso senza punto
                if ($stato !== 'CORRETTO' && preg_match('/'.$tavoleTrigger.'/i', $tipo)) {
                    $prefix = rtrim($tipo, '.');
                    continue;
                }
                if ($stato !== 'CORRETTO') continue;

                // Cambio cartella: (BATCH|IDDOCUMENTO)
                $newFolderKey = "{$batch}|{$iddoc}";
                if ($newFolderKey !== $folderKey) {
                    // flush eventuale gruppo aperto
                    $flushStandard();
                    $folderNum++;
                    $folderKey = $newFolderKey;
                    $groupKey  = null;
                    $prefix    = null;

                    $io->newLine();
                    $io->writeln(str_repeat('═', 80));
                    $io->writeln("📁 Cartella #" . str_pad((string)($folderOffset + $folderNum), 3, '0', STR_PAD_LEFT) . " — BATCH={$batch}");
                    $io->writeln(str_repeat('─', 80));
                }

                // TAVOLA: una riga = un gruppo (copy + pdf opzionale)
                if (preg_match('/tavola|elaborato/i', $tipo) && $prefix) {
                    $trigger = $prefix; // senza punto
                    $src = "{$tavoleBase}\\{$trigger}_{$imm}";
                    $suffix = $extractTavolaSuffix($imm);
                    $base   = "ELABORATO_GRAFICO_{$suffix}";
                    $numStr = str_pad((string)($folderOffset + $folderNum), 3, '0', STR_PAD_LEFT);

                    $tifDir = $outputBase . "\\TIFF\\" . $numStr;
                    $pdfDir = $outputBase . "\\PDF\\" . $numStr;
                    @mkdir($tifDir, 0777, true);
                    @mkdir($pdfDir, 0777, true);
                    $tifOut = "{$tifDir}\\{$base}.tif";
                    $pdfOut = "{$pdfDir}\\{$base}.pdf";

                    // Conteggi
                    $total = 1;
                    $found = is_file($src) ? 1 : 0;
                    $missing = $total - $found;

                    // Log inizio, stile unificato
                    $this->log->logGroupStart($io, [
                        'folderNum'    => $numStr,
                        'groupName'    => "[TAVOLA] {$base}",
                        'batch'        => $batch,
                        'tipo'         => $tipo,
                        'idDoc'        => (int)$iddoc,
                        'isTavola'     => true,
                        'isTitolo'     => false,
                        'totalFiles'   => $total,
                        'foundFiles'   => $found,
                        'missingFiles' => $missing,
                        'srcFile'      => $src,
                        'tifOut'       => $tifOut,
                        'pdfOut'       => $pdfOut,
                        'pdfEnabled'   => $tavolePdfEnabled,
                    ]);

                    // Resume: chiave estesa per tavole (includendo l'immagine)
                    $ckey = "{$batch}|{$iddoc}|{$tipo}|{$imm}";
                    $prev = $done[$ckey] ?? null;
                    $prevNorm = $prev ? strtoupper(trim((string)$prev)) : null;
                    if ($resume && $prevNorm && in_array($prevNorm, $resumeSkip, true)) {
                        $this->log->logGroupResult($io, [
                            'status'   => $prevNorm,
                            'found'    => $found,
                            'total'    => $total,
                            'missing'  => $missing,
                            'duration' => 0.00,
                        ]);
                        $groupsDone++;
                        $groupsTavoleCount++;
                        if ($limitGroups !== null && $groupsDone >= $limitGroups) break;
                        continue;
                    }

                    // Esecuzione
                    $t0 = microtime(true);
                    $status = 'OK';
                    if ($dryRun) {
                        usleep(50_000);
                        $status = ($missing > 0) ? 'OK_PARTIAL' : 'OK';
                    } else {
                        if ($found === 0) {
                            $status = 'OK_PARTIAL';
                        } else {
                            // copia con filesystem "sicuro"
                            $copied = $this->ops->copyFile($src, $tifOut);
                            if (!$copied) {
                                $status = 'ERROR';
                            } else {
                                if ($tavolePdfEnabled) {
                                    // 👉 conversione con limiti e temp locale
                                    $conv = $this->convertTavolaWithLimits($tifOut, $pdfOut, $_ENV['IMAGEMAGICK_PATH'] ?? $magickPath);
                                    if ($conv !== 'OK') {
                                        $io->writeln('<fg=yellow>⚠️  PDF tavola non creato (conversione fallita)</>');
                                    }
                                }
                            }
                        }
                    }
                    $dt = microtime(true) - $t0;

                    if ($status === 'OK' && $missing > 0) {
                        $status = 'OK_PARTIAL';
                    }

                    // Log risultato
                    $this->log->logGroupResult($io, [
                        'status'   => $status,
                        'found'    => $found,
                        'total'    => $total,
                        'missing'  => $missing,
                        'duration' => $dt,
                    ]);

                    // Checkpoint (chiave estesa per tavole)
                    $this->appendCheckpoint($checkpoint, $batch, (int)$iddoc, "{$tipo}|{$imm}", (int)($folderOffset + $folderNum), $status);

                    $groupsDone++;
                    $groupsTavoleCount++;
                    if ($limitGroups !== null && $groupsDone >= $limitGroups) break;
                    continue;
                }

                // GRUPPO STANDARD (merge)
                $newGroupKey = "{$batch}|{$tipo}";
                if ($groupKey !== null && $newGroupKey !== $groupKey && !empty($groupRows)) {
                    $flushStandard();
                    if ($limitGroups !== null && $groupsDone >= $limitGroups) break;
                }
                $groupKey = $newGroupKey;

                $src = $resolveStandardSource($row);
                $groupRows[]  = $row;
                $groupFiles[] = $src;
            }

            if (!empty($groupRows)) {
                $flushStandard();
            }
        } catch (\Throwable $e) {
            $io->writeln('<fg=red>❌ Errore in fase di processo: ' . $e->getMessage() . '</>');
        }

        // Riepilogo finale + durata & RAM
        $io->newLine(1);
        $io->section('📊 Riepilogo processo');
        $totalFolders = $folderOffset + $folderNum;
        $io->listing([
            "Totale righe lette: {$rowsRead}",
            "Totale cartelle numerate: {$totalFolders}",
            "Gruppi standard (merge): {$groupsStandardCount}",
            "Gruppi tavole (copy): {$groupsTavoleCount}",
            "Gruppi totali processati: " . ($groupsStandardCount + $groupsTavoleCount),
        ]);

        $duration = microtime(true) - $tStart;
        $memMb = memory_get_peak_usage(true) / 1024 / 1024;
        $io->writeln(sprintf(' 🕒 Durata totale: <info>%.2f s</info>', $duration));
        $io->writeln(sprintf(' 💾 Memoria massima: <info>%.1f MB</info>', $memMb));
        $io->success('Processo completato.');
    }

    /**
     * Conversione singola per le Tavole (TIFF → PDF) con limiti IM e temp locale.
     *
     * @return 'OK'|'ERROR'
     */
    private function convertTavolaWithLimits(string $tifPath, string $pdfPath, string $magickPath): string
    {
        $memLimit = $_ENV['IMAGEMAGICK_MEMORY_LIMIT'] ?? '512MiB';
        $mapLimit = $_ENV['IMAGEMAGICK_MAP_LIMIT']    ?? '1GiB';
        $thrLimit = (int)($_ENV['IMAGEMAGICK_THREAD_LIMIT'] ?? 1);
        $tmpDirIM = rtrim((string)($_ENV['TEMP_PATH'] ?? sys_get_temp_dir()), "\\/");

        $cmd = sprintf(
            '"%s" -limit memory %s -limit map %s -limit thread %d ' .
            '-define registry:temporary-path="%s" "%s" "%s"',
            $magickPath, $memLimit, $mapLimit, $thrLimit, $tmpDirIM, $tifPath, $pdfPath
        );

        $p = Process::fromShellCommandline($cmd);
        $p->setTimeout(0);
        $p->run();

        if (!$p->isSuccessful()) {
            $this->log->error("Errore tavola $tifPath → $pdfPath: " . trim($p->getErrorOutput() ?: $p->getOutput()));
            return 'ERROR';
        }

        return 'OK';
    }

    // ===================== Checkpoint compatibile =====================

    /** @return array<string,string> */
    private function loadCheckpointMap(string $path): array
    {
        $map = [];
        if (!is_file($path)) return $map;

        $f = fopen($path, 'rb');
        if (!$f) return $map;

        // header
        fgetcsv($f);
        while (($r = fgetcsv($f)) !== false) {
            if (count($r) < 6) continue;
            // compat precedente: 5 colonne lette nell'uso, ma il file ne ha 6
            [$b, $i, $t, $folder, $status] = $r; // legacy: ignora UPDATED_AT
            $key = trim($b) . '|' . trim((string)$i) . '|' . trim($t);
            $map[$key] = trim((string)$status);
        }
        fclose($f);
        return $map;
    }

    private function appendCheckpoint(string $path, string $batch, int $iddoc, string $tipo, int $folderNum, string $status): void
    {
        $header = "BATCH,IDDOCUMENTO,TIPO_DOCUMENTO,FOLDER_NUM,STATUS,UPDATED_AT\n";
        $key    = "{$batch}|{$iddoc}|{$tipo}";

        // carica
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

        // upsert
        $rows[$key] = [
            $batch,
            $iddoc,
            $tipo,
            $folderNum,
            $status,
            (new \DateTimeImmutable())->format('c')
        ];

        @mkdir(\dirname($path), 0777, true);
        $f = fopen($path, 'wb');
        fwrite($f, $header);
        foreach ($rows as $r) {
            fputcsv($f, $r);
        }
        fclose($f);
    }

    /** Conta quante cartelle (BATCH|IDDOCUMENTO) precedono il batch filtrato. */
    private function computeFolderOffset(string $csvPath, ?string $batchFilter): int
    {
        if (!$batchFilter) return 0;

        $found = false;
        $seen = [];
        $offset = 0;
        foreach (CsvReader::iterate($csvPath) as $r) {
            $batch = trim($r['BATCH'] ?? '');
            $iddoc = trim($r['IDDOCUMENTO'] ?? '');
            $key = "{$batch}|{$iddoc}";
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                if ($batch !== $batchFilter && !$found) {
                    $offset++;
                } elseif ($batch === $batchFilter) {
                    $found = true;
                }
            }
            if ($found) break;
        }
        return $offset;
    }
}

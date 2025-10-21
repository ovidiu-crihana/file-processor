<?php
namespace App\Service;

use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;

class FileProcessor
{
    private Filesystem $fs;

    private string $csvPath;
    private string $checkpointFile;
    private string $filterCol;
    private string $filterVal;
    private array $groupKeys;
    private string $idCol;

    public function __construct(
        private readonly LoggerService $logger,
        private readonly FileOperations $ops
    ) {
        $this->fs             = new Filesystem();
        $this->csvPath        = $_ENV['CSV_PATH'] ?? '';
        $this->checkpointFile = $_ENV['CHECKPOINT_FILE'] ?? 'var/state/checkpoint.json';
        $this->filterCol      = $_ENV['CSV_FILTER_COLUMN'] ?? 'STATO';
        $this->filterVal      = $_ENV['CSV_FILTER_VALUE'] ?? 'CORRETTO';
        $this->groupKeys      = array_map('trim', explode(',', $_ENV['CSV_GROUP_KEYS'] ?? 'BATCH,PROT_PRAT_NUMERO'));
        $this->idCol          = $_ENV['CSV_COL_IDRECORD'] ?? 'IDRECORD';
    }

    public function run(SymfonyStyle $io, bool $dryRun = false, bool $resume = false, int $limit = 0): array
    {
        $start = microtime(true);

        if (!$this->fs->exists($this->csvPath)) {
            $io->error("CSV non trovato: {$this->csvPath}");
            $this->logger->error("CSV non trovato: {$this->csvPath}");
            return ['processed' => 0, 'total' => 0, 'duration' => '00:00:00'];
        }

        $rows = $this->loadCsv();
        if ($limit > 0 && $limit < count($rows)) $rows = array_slice($rows, 0, $limit);
        $total = count($rows);
        $io->text("Trovati {$total} record da elaborare.");
        if ($total === 0) return ['processed' => 0, 'total' => 0, 'duration' => '00:00:00'];

        $io->section('Raggruppamento logico');
        $io->text('Chiavi di gruppo: ' . implode(', ', $this->groupKeys));

        $resumeKey = null;
        if ($resume && file_exists($this->checkpointFile)) {
            $data = json_decode(file_get_contents($this->checkpointFile), true);
            if (!empty($data['last_group'])) $resumeKey = $data['last_group'];
        }

        // --- Raggruppamento dinamico
        $grouped = [];
        foreach ($rows as $r) {
            $values = [];
            foreach ($this->groupKeys as $col) $values[] = trim($r[$col] ?? '');
            $key = implode('|', $values);
            $grouped[$key][] = $r;
        }

        $keys = array_keys($grouped);
        $io->progressStart(count($keys));
        $processed = 0;
        $skip = $resume && $resumeKey !== null;

        foreach ($keys as $groupKey) {
            if ($skip) {
                if ($groupKey === $resumeKey) { $skip = false; continue; }
                continue;
            }

            $records = $grouped[$groupKey];
            $count   = count($records);
            $io->newLine(1);
            $io->text("→ Gruppo: <info>{$groupKey}</info> ({$count} record)");
            $this->logger->info("Inizio gruppo {$groupKey} ({$count} record)");

            usort($records, function(array $a, array $b) {
                $aa = (int)preg_replace('/\D+/', '', (string)($a[$this->idCol] ?? '0'));
                $bb = (int)preg_replace('/\D+/', '', (string)($b[$this->idCol] ?? '0'));
                return $aa <=> $bb;
            });

            try {
                $this->ops->mergeGroup($groupKey, $records, $dryRun);
                $processed += $count;
            } catch (\Throwable $e) {
                $this->logger->error("Errore gruppo {$groupKey}: " . $e->getMessage());
                $io->warning("Errore sul gruppo {$groupKey}");
            }

            $this->safeWriteCheckpoint([
                'processed'  => $processed,
                'total'      => $total,
                'last_group' => $groupKey,
                'updated_at' => date('c'),
            ], $this->checkpointFile);

            $this->logger->info("Gruppo completato: {$groupKey}");
            $io->progressAdvance();
        }

        $io->progressFinish();
        $elapsed = microtime(true) - $start;
        $formatted = gmdate('H:i:s', (int)$elapsed);
        $this->logger->info("Elaborazione completata: {$processed}/{$total} file in {$formatted}");
        $io->success("Completato: {$processed} file su {$total} in {$formatted}");

        return ['processed' => $processed, 'total' => $total, 'duration' => $formatted];
    }

    private function loadCsv(): array
    {
        $rows = [];
        $h = fopen($this->csvPath, 'r');
        if (!$h) return $rows;

        $header = fgetcsv($h, 0, ';');
        if ($header === false) return [];

        while (($data = fgetcsv($h, 0, ';')) !== false) {
            if (count($data) !== count($header)) continue;
            $r = array_combine(array_map(fn($h) => trim(str_replace("\xEF\xBB\xBF", '', $h)), $header), $data);
            if (strcasecmp(trim($r[$this->filterCol] ?? ''), $this->filterVal) === 0) $rows[] = $r;
        }
        fclose($h);
        return $rows;
    }

    private function safeWriteCheckpoint(array $data, string $path): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $tmp = $path . '.tmp';
        for ($t = 0; $t < 3; $t++) {
            try {
                file_put_contents($tmp, $json);
                if (file_exists($path)) @unlink($path);
                rename($tmp, $path);
                return;
            } catch (\Throwable $e) {
                usleep(200_000);
            }
        }
        try {
            file_put_contents($path, $json);
        } catch (\Throwable $e) {
            $this->logger->warning("Impossibile aggiornare checkpoint: " . $e->getMessage());
        }
    }
}

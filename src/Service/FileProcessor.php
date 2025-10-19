<?php
namespace App\Service;

use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;

class FileProcessor
{
    private LoggerService $logger;
    private FileOperations $ops;
    private Filesystem $fs;

    public function __construct(LoggerService $logger, FileOperations $ops)
    {
        $this->logger = $logger;
        $this->ops = $ops;
        $this->fs = new Filesystem();
    }

    public function run(SymfonyStyle $io, bool $dryRun = false, bool $resume = false, int $limit = 0): array
    {
        $csvPath = $_ENV['CSV_PATH'] ?? '';
        $checkpointPath = $_ENV['CHECKPOINT_FILE'] ?? 'var/state/checkpoint.json';
        $filterCol = $_ENV['CSV_FILTER_COLUMN'] ?? '';
        $filterVal = $_ENV['CSV_FILTER_VALUE'] ?? '';
        $start = microtime(true);

        if (!$this->fs->exists($csvPath)) {
            $io->error("CSV non trovato: $csvPath");
            $this->logger->error("CSV non trovato: $csvPath");
            return ['processed' => 0, 'total' => 0, 'duration' => '00:00:00'];
        }

        $handle = fopen($csvPath, 'r');
        if (!$handle) {
            $io->error('Impossibile aprire il file CSV.');
            return ['processed' => 0, 'total' => 0, 'duration' => '00:00:00'];
        }

        $header = fgetcsv($handle, 0, ';');
        $rows = [];
        while (($data = fgetcsv($handle, 0, ';')) !== false) {
            $record = array_combine($header, $data);

            if ($filterCol && $filterVal && ($record[$filterCol] ?? null) !== $filterVal) {
                continue;
            }

            $rows[] = $record;
        }
        fclose($handle);

        $total = count($rows);
        $processed = 0;

        if ($limit > 0) {
            $rows = array_slice($rows, 0, $limit);
            $total = count($rows);
        }

        $io->text("Trovati $total record da elaborare.");
        $io->progressStart($total);

        foreach ($rows as $r) {
            $id = $r[$_ENV['CSV_COL_ID'] ?? 'ID'] ?? null;

            try {
                $this->ops->processRecord($r, $dryRun);
                $processed++;
            } catch (\Throwable $e) {
                $this->logger->error("Errore record {$id}: " . $e->getMessage());
                $io->warning("Errore su ID {$id}");
            }

            $checkpoint = [
                'processed' => $processed,
                'total' => $total,
                'last_id' => $id,
                'updated_at' => date('c'),
            ];
            $this->fs->dumpFile($checkpointPath, json_encode($checkpoint, JSON_PRETTY_PRINT));

            $io->progressAdvance();
        }

        $io->progressFinish();

        $duration = round(microtime(true) - $start, 2);
        $formatted = gmdate('H:i:s', (int)$duration);

        $this->logger->info("Elaborazione completata: {$processed}/{$total} file in {$formatted}");

        return [
            'processed' => $processed,
            'total' => $total,
            'duration' => $formatted,
        ];
    }
}

<?php
namespace App\Command;

use App\Service\FileOperations;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Mostra una simulazione di merge per ogni gruppo,
 * senza scrivere alcun file.
 */
#[AsCommand(
    name: 'app:debug-merge',
    description: 'Simula il merge per gruppi e mostra i percorsi sorgenti/destinazione previsti'
)]
class DebugMergeCommand extends Command
{
    public function __construct(
        private readonly FileOperations $ops
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $csvPath = $_ENV['CSV_PATH'] ?? '';

        if (!file_exists($csvPath)) {
            $io->error("CSV non trovato: {$csvPath}");
            return Command::FAILURE;
        }

        $groupKeys = array_map('trim', explode(',', $_ENV['CSV_GROUP_KEYS'] ?? 'BATCH,PROT_PRAT_NUMERO'));
        $io->section('Analisi gruppi logici');
        $io->text('Chiavi di gruppo: ' . implode(', ', $groupKeys));

        $rows = $this->loadCsv($csvPath);
        if (empty($rows)) {
            $io->warning('Nessun record valido nel CSV (verifica filtro STATO).');
            return Command::SUCCESS;
        }

        // Raggruppamento
        $grouped = [];
        foreach ($rows as $r) {
            $values = [];
            foreach ($groupKeys as $col) $values[] = trim($r[$col] ?? '');
            $key = implode('|', $values);
            $grouped[$key][] = $r;
        }

        $totalGroups = count($grouped);
        $io->text("Trovati {$totalGroups} gruppi.\n");

        $index = 0;
        foreach ($grouped as $key => $records) {
            $index++;
            $batch = explode('|', $key)[0] ?? '';
            $label = explode('|', $key)[1] ?? 'group';
            $io->section("[{$index}/{$totalGroups}] Gruppo {$key} ({$batch}) — " . count($records) . " record");

            // nome finale previsto
            $destBase = rtrim($_ENV['OUTPUT_BASE_PATH'], '\\/');
            $destFolder = "{$destBase}\\{$batch}\\{$label}";
            $io->text("Cartella output prevista: {$destFolder}");

            // preview del nome file finale
            $first = $records[0];
            $pattern = $_ENV['OUTPUT_FILENAME_PATTERN'] ?? '{TIPO_PRATICA}_{PRAT_NUM}_{ANNO}_{TIPO_DOCUMENTO}_{GROUP}.tif';
            $tipoPratica = trim($first['TIPO_PRATICA'] ?? '');
            $pratNum = trim($first['PRAT_NUM'] ?? '');
            $anno = substr(preg_replace('/\D+/', '', (string)($first['PROT_PRAT_DATA'] ?? '')), -4);
            $tipoDoc = trim($first['TIPO_DOCUMENTO'] ?? '');
            $sigla = trim($first['SIGLA_PRATICA'] ?? '');
            $elaborato = (stripos($tipoDoc, 'titolo autorizzativo') !== false) ? $sigla : $tipoDoc;
            $finalName = strtr($pattern, [
                '{TIPO_PRATICA}' => $tipoPratica,
                '{PRAT_NUM}' => $pratNum,
                '{ANNO}' => $anno,
                '{TIPO_DOCUMENTO}' => $elaborato,
                '{GROUP}' => $label,
                '{IDRECORD}' => $label,
            ]);
            $io->text("File finale previsto: {$destFolder}\\{$finalName}");

            // ordina per IDRECORD
            usort($records, fn($a, $b) => (int)$a['IDRECORD'] <=> (int)$b['IDRECORD']);

            // elenca i file sorgenti
            $io->newLine();
            $io->text("Sorgenti trovati:");
            foreach ($records as $r) {
                $src = $this->ops->buildSourcePath($r);
                if ($src)
                    $io->text("  • {$src}");
                else
                    $io->warning("  ✗ Non trovato: {$r['IMMAGINE']}");
            }
        }

        $io->success("Analisi completata: {$totalGroups} gruppi ispezionati.");
        return Command::SUCCESS;
    }

    /**
     * Lettura CSV filtrando STATO=CORRETTO.
     */
    private function loadCsv(string $path): array
    {
        $rows = [];
        $filterCol = $_ENV['CSV_FILTER_COLUMN'] ?? 'STATO';
        $filterVal = $_ENV['CSV_FILTER_VALUE'] ?? 'CORRETTO';

        $h = fopen($path, 'r');
        if (!$h) return [];
        $header = fgetcsv($h, 0, ';');
        while (($data = fgetcsv($h, 0, ';')) !== false) {
            if (count($data) !== count($header)) continue;
            $r = array_combine($header, $data);
            if (strcasecmp(trim($r[$filterCol] ?? ''), $filterVal) === 0) {
                $rows[] = $r;
            }
        }
        fclose($h);
        return $rows;
    }
}

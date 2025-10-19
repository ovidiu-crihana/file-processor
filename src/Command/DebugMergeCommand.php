<?php
namespace App\Command;

use App\Service\FileOperations;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Mostra un riepilogo per ogni gruppo logico (senza generare file)
 * con percorso completo e nome file previsto.
 */
#[AsCommand(
    name: 'app:debug-merge',
    description: 'Mostra il riepilogo dei gruppi e verifica i file disponibili'
)]
class DebugMergeCommand extends Command
{
    public function __construct(private readonly FileOperations $ops)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('group', null, InputOption::VALUE_OPTIONAL, 'Filtra per chiave gruppo (BATCH|IDDOCUMENTO)')
            ->addOption('limit', null, InputOption::VALUE_OPTIONAL, 'Limita il numero di gruppi mostrati', 20);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $csvPath = $_ENV['CSV_PATH'] ?? '';

        if (!file_exists($csvPath)) {
            $io->error("CSV non trovato: {$csvPath}");
            return Command::FAILURE;
        }

        $groupKeys = array_map('trim', explode(',', $_ENV['CSV_GROUP_KEYS'] ?? 'BATCH,IDDOCUMENTO'));
        $filterGroup = $input->getOption('group');
        $limit = (int) $input->getOption('limit');

        $io->section('Analisi gruppi logici');
        $io->text('Chiavi di gruppo: ' . implode(', ', $groupKeys));

        $rows = $this->loadCsv($csvPath);
        if (empty($rows)) {
            $io->warning('Nessun record valido (filtrati STATO != CORRETTO).');
            return Command::SUCCESS;
        }

        // Raggruppamento dinamico
        $grouped = [];
        foreach ($rows as $r) {
            $values = [];
            foreach ($groupKeys as $col) $values[] = trim($r[$col] ?? '');
            $key = implode('|', $values);
            $grouped[$key][] = $r;
        }

        $totalGroups = count($grouped);
        $io->text("Trovati {$totalGroups} gruppi totali.");

        $shown = 0;
        $summary = [];

        foreach ($grouped as $key => $records) {
            if ($filterGroup && stripos($key, $filterGroup) === false) continue;
            if ($limit > 0 && $shown >= $limit) break;

            $batch = explode('|', $key)[0] ?? '';
            $group = explode('|', $key)[1] ?? '';
            $countTotal = count($records);

            // verifica file
            $found = 0;
            $missing = 0;
            foreach ($records as $r) {
                $src = $this->ops->buildSourcePath($r);
                if ($src && file_exists($src)) $found++; else $missing++;
            }

            // costruzione nome finale
            $destFolder = "\\{$batch}\\{$group}";
            $first = $records[0];
            $pattern = $_ENV['OUTPUT_FILENAME_PATTERN'] ?? '{TIPO_PRATICA}_{PRAT_NUM}_{ANNO}_{TIPO_DOCUMENTO}_{NUMERO_RELATIVO}.tif';
            $tipoPratica = $first['TIPO_PRATICA'] ?? '';
            $pratNum = $first['PRAT_NUM'] ?? '';
            $anno = substr(preg_replace('/\D+/', '', (string)($first['PROT_PRAT_DATA'] ?? '')), -4);
            $tipoDoc = $first['TIPO_DOCUMENTO'] ?? '';
            $sigla = $first['SIGLA_PRATICA'] ?? '';
            $elaborato = (stripos($tipoDoc, 'titolo autorizzativo') !== false) ? $sigla : $tipoDoc;

            $finalName = strtr($pattern, [
                '{TIPO_PRATICA}' => $tipoPratica,
                '{PRAT_NUM}' => $pratNum,
                '{ANNO}' => $anno,
                '{TIPO_DOCUMENTO}' => $elaborato,
                '{NUMERO_RELATIVO}' => $group,
            ]);

            $baseName = pathinfo($finalName, PATHINFO_FILENAME);

            $status = $found === 0 ? '❌ Nessun file trovato'
                : ($found < $countTotal ? '⚠️ Parziale' : '✅ Completo');

            $summary[] = [
                'Gruppo' => $key,
                'Record' => $countTotal,
                'Trovati' => "{$found}/{$countTotal}",
                'Stato' => $status,
                'Path' => $destFolder,
                'Filename' => $baseName,
            ];

            $shown++;
        }

        $io->newLine();
        $io->table(
            ['Gruppo', 'Record', 'Trovati', 'Stato', 'Path', 'Filename'],
            $summary
        );

        $io->success("Analisi completata: mostrati {$shown} gruppi su {$totalGroups}.");
        $io->text('Usa --limit=<N> o --group="<parte chiave>" per filtrare.');

        return Command::SUCCESS;
    }

    private function loadCsv(string $path): array
    {
        $rows = [];
        $filterCol = $_ENV['CSV_FILTER_COLUMN'] ?? 'STATO';
        $filterVal = $_ENV['CSV_FILTER_VALUE'] ?? 'CORRETTO';
        $h = fopen($path, 'r');
        if (!$h) return [];

        $header = fgetcsv($h, 0, ';');
        $header = array_map(fn($h) => trim(str_replace("\xEF\xBB\xBF", '', $h)), $header);

        while (($data = fgetcsv($h, 0, ';')) !== false) {
            if (count($data) !== count($header)) continue;
            $r = array_combine($header, $data);
            if (strcasecmp(trim($r[$filterCol] ?? ''), $filterVal) === 0) $rows[] = $r;
        }
        fclose($h);
        return $rows;
    }
}

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
 * con percorso completo, nome file previsto e conteggio tavole.
 */
#[AsCommand(
    name: 'app:debug-merge',
    description: 'Mostra il riepilogo dei gruppi e verifica i file disponibili (inclusi eventuali TAVOLE)'
)]
class DebugMergeCommand extends Command
{
    private string $csvPath;
    private array $groupKeys;
    private string $filterCol;
    private string $filterVal;

    // configurazioni tavole
    private ?string $tavoleColumn;
    private ?string $tavoleValue;
    private ?string $tavolePath;
    private string $sourceBase;

    public function __construct(private readonly FileOperations $ops)
    {
        parent::__construct();

        $this->csvPath      = $_ENV['CSV_PATH'] ?? '';
        $this->groupKeys    = array_map('trim', explode(',', $_ENV['CSV_GROUP_KEYS'] ?? 'BATCH,IDDOCUMENTO'));
        $this->filterCol    = $_ENV['CSV_FILTER_COLUMN'] ?? 'STATO';
        $this->filterVal    = $_ENV['CSV_FILTER_VALUE'] ?? 'CORRETTO';

        $this->tavoleColumn = $_ENV['TAVOLE_TRIGGER_COLUMN'] ?? null;
        $this->tavoleValue  = $_ENV['TAVOLE_TRIGGER_VALUE'] ?? null;
        $this->tavolePath   = $_ENV['TAVOLE_PATH'] ?? 'tavole';
        $this->sourceBase   = $_ENV['SOURCE_BASE_PATH'] ?? '';
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

        if (!file_exists($this->csvPath)) {
            $io->error("CSV non trovato: {$this->csvPath}");
            return Command::FAILURE;
        }

        $filterGroup = $input->getOption('group');
        $limit = (int)$input->getOption('limit');

        $io->section('Analisi gruppi logici');
        $io->text('Chiavi di gruppo: ' . implode(', ', $this->groupKeys));

        $rows = $this->loadCsv($this->csvPath);
        if (empty($rows)) {
            $io->warning('Nessun record valido (filtrati STATO != CORRETTO).');
            return Command::SUCCESS;
        }

        // Raggruppamento dinamico
        $grouped = [];
        foreach ($rows as $r) {
            $values = [];
            foreach ($this->groupKeys as $col) $values[] = trim($r[$col] ?? '');
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

            $found = 0;
            $missing = 0;
            $tavoleFound = 0;
            $tavoleRequired = false;

            foreach ($records as $r) {
                $src = $this->ops->buildSourcePath($r);
                if ($src && file_exists($src)) {
                    $found++;
                } else {
                    $missing++;
                }

                // --- verifica tavole aggiuntive per questo record
                if ($this->shouldUseTavole($r)) {
                    $tavoleRequired = true;
                    $tavole = $this->findTavoleFiles($r);
                    $tavoleFound += count($tavole);
                }
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

            // testo tavole più esplicativo
            if ($tavoleRequired) {
                $tavoleText = 'Sì (' . $tavoleFound . ')';
            } else {
                $tavoleText = 'No';
            }

            $summary[] = [
                'Gruppo' => $key,
                'Record' => $countTotal,
                'Trovati' => "{$found}/{$countTotal}",
                'Tavole' => $tavoleText,
                'Stato' => $status,
                'Path' => $destFolder,
                'Filename' => $baseName,
            ];

            $shown++;
        }

        $io->newLine();
        $io->table(
            ['Gruppo', 'Record', 'Trovati', 'Tavole', 'Stato', 'Path', 'Filename'],
            $summary
        );

        $io->success("Analisi completata: mostrati {$shown} gruppi su {$totalGroups}.");
        $io->text('Usa --limit=<N> o --group="<parte chiave>" per filtrare.');

        return Command::SUCCESS;
    }

    private function loadCsv(string $path): array
    {
        $rows = [];
        $h = fopen($path, 'r');
        if (!$h) return [];

        $header = fgetcsv($h, 0, ';');
        $header = array_map(fn($h) => trim(str_replace("\xEF\xBB\xBF", '', $h)), $header);

        while (($data = fgetcsv($h, 0, ';')) !== false) {
            if (count($data) !== count($header)) continue;
            $r = array_combine($header, $data);
            if (strcasecmp(trim($r[$this->filterCol] ?? ''), $this->filterVal) === 0) $rows[] = $r;
        }
        fclose($h);
        return $rows;
    }

    // --- logica TAVOLE in linea con FileOperations ------------------------

    /**
     * Determina se per questo record devono essere ricercate le tavole.
     *
     * Supporta tre modalità di ricerca (case-insensitive):
     *
     * 1️⃣ Parola singola:
     *     TAVOLE_TRIGGER_VALUE=TAVOLA
     *     → attiva se la colonna contiene la parola "TAVOLA"
     *       (es. "TAVOLA_1", "TavolaTecnica", ecc.)
     *
     * 2️⃣ Lista di parole (separate da virgola):
     *     TAVOLE_TRIGGER_VALUE=TAVOLA,PIANTA,CARTA
     *     → attiva se la colonna contiene almeno una di queste parole
     *
     * 3️⃣ Pattern regex:
     *     TAVOLE_TRIGGER_VALUE=/TAVOLA[_\-\s]?\d{0,2}/i
     *     → attiva se la colonna rispetta il pattern indicato
     *       (es. "TAVOLA_1", "TAVOLA-02", "tavola 10")
     *
     * La regex deve essere racchiusa tra "/" e può includere flag "i" per ignore-case.
     */
    private function shouldUseTavole(array $r): bool
    {
        if (!$this->tavoleColumn || !$this->tavoleValue) {
            return false;
        }

        $fieldValue = trim($r[$this->tavoleColumn] ?? '');
        $pattern = $this->tavoleValue;

        // Caso 3: pattern regex
        if (preg_match('/^\/.+\/[a-zA-Z]*$/', $pattern)) {
            return (bool) preg_match($pattern, $fieldValue);
        }

        // Caso 2: lista di parole
        if (str_contains($pattern, ',')) {
            foreach (array_map('trim', explode(',', $pattern)) as $word) {
                if (stripos($fieldValue, $word) !== false) {
                    return true;
                }
            }
            return false;
        }

        // Caso 1: parola singola
        return stripos($fieldValue, $pattern) !== false;
    }


    private function findTavoleFiles(array $r): array
    {
        $files = [];
        if (!$this->shouldUseTavole($r)) return $files;

        $acq  = preg_replace('/\D+/', '', (string)($r['DATA_ORA_ACQ'] ?? ''));
        $yyyy = substr($acq, 0, 4);
        $mm   = substr($acq, 4, 2);
        $dd   = substr($acq, 6, 2);
        $batch = trim($r['BATCH'] ?? '');
        $file  = trim($r['IMMAGINE'] ?? '');

        // Percorso base: relativo o assoluto
        if (str_starts_with($this->tavolePath, '\\') || str_contains($this->tavolePath, ':')) {
            $base = rtrim($this->tavolePath, '\\/') . "\\{$batch}";
        } else {
            $base = rtrim($this->sourceBase, '\\/') . "\\{$yyyy}\\{$mm}\\{$dd}\\{$batch}\\{$this->tavolePath}";
        }

        if (is_dir($base)) {
            foreach (glob($base . '\\*.tif') as $f) {
                $files[] = $f;
            }
        }
        return $files;
    }
}

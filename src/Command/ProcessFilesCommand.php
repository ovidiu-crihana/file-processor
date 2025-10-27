<?php
declare(strict_types=1);

namespace App\Command;

use App\Service\FileProcessor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:process-files',
    description: 'Esegue l’elaborazione effettiva dei gruppi (TIFF → PDF) con resume e checkpoint'
)]
final class ProcessFilesCommand extends Command
{
    public function __construct(
        private readonly FileProcessor $processor,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simula senza generare i file')
            ->addOption('resume', null, InputOption::VALUE_NONE, 'Riprende da checkpoint esistente')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Numero massimo di gruppi da elaborare', 0)
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Filtra per batch specifico')
            ->addOption('tipo', null, InputOption::VALUE_REQUIRED, 'Filtra per tipo documento specifico')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Salta la conferma interattiva');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io         = new SymfonyStyle($input, $output);
        $resume     = (bool)$input->getOption('resume');
        $dryRun     = (bool)$input->getOption('dry-run');
        $limit      = (int)$input->getOption('limit');
        $batch      = $input->getOption('batch');
        $tipo       = $input->getOption('tipo');
        $autoYes    = (bool)$input->getOption('yes');

        $io->title('Elaborazione gruppi');

        // Parametri principali
        $csvPath       = $_ENV['CSV_PATH'] ?? null;
        $magickPath    = $_ENV['IMAGEMAGICK_PATH'] ?? 'magick';
        $checkpoint    = $_ENV['CHECKPOINT_FILE'] ?? 'var/logs/checkpoint.csv';
        $outputBase    = rtrim($_ENV['OUTPUT_BASE_PATH'] ?? 'Output', '\\/');
        $sourceBase    = rtrim($_ENV['SOURCE_BASE_PATH'] ?? 'Work', '\\/');
        $tavoleBase    = rtrim($_ENV['TAVOLE_BASE_PATH'] ?? 'Work\\Tavole\\Importate', '\\/');
        $tavolePattern = $_ENV['TAVOLE_TRIGGER_PATTERN'] ?? '^[A-Za-z0-9]+\\.?$';
        $planimVals    = array_filter(array_map('trim', explode(';', $_ENV['TAVOLE_PLANIMETRIE_VALUES'] ?? 'ELABORATO_GRAFICO')));

        if (!$csvPath || !file_exists($csvPath)) {
            $io->error("CSV non trovato o non valido: $csvPath");
            return Command::FAILURE;
        }

        if (!$autoYes) {
            if (!$io->confirm('Procedere?', false)) {
                $io->warning('Operazione annullata.');
                return Command::SUCCESS;
            }
        }

        // Stima gruppi (progress bar)
        $io->text('Stima numero gruppi…');
        $total = $this->processor->estimateTotalGroups($batch, $tipo, $tavolePattern);
        if ($limit > 0 && $limit < $total) $total = $limit;

        $progress = $io->createProgressBar(max(1, $total));
        $progress->setFormat(' [%bar%] %current%/%max% (%percent:3s%%) ETA: %estimated:-6s% ');
        $progress->start();

        $startAll = microtime(true);

        // 👉 Passiamo solo il path, non l’array
        $this->processor->process(
            csvPath:        $csvPath,
            magickPath:     $magickPath,
            checkpointPath: $checkpoint,
            outputBase:     $outputBase,
            sourceBase:     $sourceBase,
            tavoleBase:     $tavoleBase,
            tavolePattern:  $tavolePattern,
            planimetrie:    $planimVals,
            dryRun:         $dryRun,
            resume:         $resume,
            limitGroups:    $limit > 0 ? $limit : null,
            batchFilter:    $batch,
            tipoFilter:     $tipo,
            progress:       $progress,
            io:             $io,
        );

        $progress->finish();
        $io->newLine(2);
        $io->success(sprintf('Completato in %.2f sec.', microtime(true) - $startAll));

        return Command::SUCCESS;
    }
}

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
    description: 'Esegue il processo reale: merge gruppi standard e copy+PDF per tavole'
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
            ->addOption('batch',    null, InputOption::VALUE_REQUIRED, 'Filtra per BATCH specifico', null)
            ->addOption('limit',    null, InputOption::VALUE_REQUIRED, 'Limita il numero di gruppi da processare (0=tutti)', '0')
            ->addOption('max-rows', null, InputOption::VALUE_REQUIRED, 'Leggi massimo N righe dal CSV (0=tutte)', '0')
            ->addOption('dry-run',  null, InputOption::VALUE_NONE,     'Simula senza scrivere su disco')
            ->addOption('resume',   null, InputOption::VALUE_NONE,     'Salta i gruppi in checkpoint con stati da RESUME_SKIP_STATUSES')
            ->addOption('yes',      'y',  InputOption::VALUE_NONE,     'Salta la conferma iniziale');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);

        $csvPath     = $_ENV['CSV_PATH'] ?? '';
        $outputBase  = rtrim($_ENV['OUTPUT_BASE_PATH'] ?? '', '\\/');
        $sourceBase  = rtrim($_ENV['SOURCE_BASE_PATH'] ?? '', '\\/');
        $tavoleBase  = rtrim($_ENV['TAVOLE_BASE_PATH'] ?? 'Work\\Tavole\\Importate', '\\/');
        $triggerRx   = $_ENV['TAVOLE_TRIGGER_PATTERN'] ?? '^[A-Za-z0-9]+\\.$';
        $patternEnv  = str_replace('{SUFFISSO}', '', $_ENV['OUTPUT_FILENAME_PATTERN'] ?? '{TIPO_PRATICA}_{PRAT_NUM}_{ANNO}_{TIPO_DOCUMENTO}.{EXT}');
        $checkpoint  = $_ENV['CHECKPOINT_FILE'] ?? 'var/checkpoints/process.csv';
        $magickPath  = $_ENV['IMAGEMAGICK_PATH'] ?? 'magick';

        if (!$csvPath || !is_file($csvPath)) {
            $io->error('CSV_PATH non impostato o file non trovato.');
            return Command::FAILURE;
        }
        if (!$outputBase) {
            $io->error('OUTPUT_BASE_PATH non impostato.');
            return Command::FAILURE;
        }

        $batch      = $input->getOption('batch') ?: null;
        $limit      = max(0, (int)$input->getOption('limit'));
        $maxRows    = max(0, (int)$input->getOption('max-rows'));
        $dryRun     = (bool)$input->getOption('dry-run');
        $resume     = (bool)$input->getOption('resume');
        $autoYes    = (bool)$input->getOption('yes');

        $io->title('Processo reale — merge/copy');

        $io->listing([
            "CSV: {$csvPath}",
            "Output base: {$outputBase}",
            "Filtro batch: " . ($batch ?: '[tutti]'),
            "Limit gruppi: " . ($limit ?: 'no'),
            "Max rows: "     . ($maxRows ?: 'tutte'),
            "Dry-run: "      . ($dryRun ? 'sì' : 'no'),
            "Resume: "       . ($resume ? 'sì' : 'no'),
        ]);

        if (!$autoYes) {
            if (!$io->confirm('Confermi di avviare il processo con queste impostazioni?', false)) {
                $io->warning('Operazione annullata.');
                return Command::SUCCESS;
            }
        }

        try {
            $this->processor->process(
                csvPath:      $csvPath,
                magickPath:   $magickPath,
                checkpoint:   $checkpoint,
                outputBase:   $outputBase,
                sourceBase:   $sourceBase,
                tavoleBase:   $tavoleBase,
                tavoleTrigger:$triggerRx,
                patternEnv:   $patternEnv,
                dryRun:       $dryRun,
                resume:       $resume,
                limitGroups:  $limit ?: null,
                batchFilter:  $batch,
                maxRows:      $maxRows,
                io:           $io,
            );
        } catch (\Throwable $e) {
            $io->error('Errore fatale: ' . $e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}

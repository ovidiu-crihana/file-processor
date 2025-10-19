<?php
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
    description: 'Esegue il processamento dei file da CSV con log, checkpoint e simulazione dry-run'
)]
class ProcessFilesCommand extends Command
{
    private FileProcessor $processor;

    public function __construct(FileProcessor $processor)
    {
        parent::__construct();
        $this->processor = $processor;
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Esegue la simulazione senza toccare i file')
            ->addOption('resume', null, InputOption::VALUE_NONE, 'Riprende dal checkpoint precedente')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Numero massimo di record da processare', 0);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = $input->getOption('dry-run');
        $resume = $input->getOption('resume');
        $limit = (int)$input->getOption('limit');

        $io->title('File Processor');
        $io->section('Inizializzazione e lettura CSV...');

        $result = $this->processor->run($io, $dryRun, $resume, $limit);

        $io->success(sprintf(
            'Completato: %d file processati su %d totali in %s',
            $result['processed'],
            $result['total'],
            $result['duration']
        ));

        return Command::SUCCESS;
    }
}

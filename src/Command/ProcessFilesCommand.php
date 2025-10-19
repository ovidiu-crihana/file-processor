<?php
namespace App\Command;

use App\Service\FileProcessor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Comando CLI principale per avviare l’elaborazione dei file
 * dal CSV con supporto a resume, dry-run, limit e checkpoint.
 */
#[AsCommand(
    name: 'app:process-files',
    description: 'Processa i file dal CSV applicando le regole definite e mantenendo checkpoint per resume.'
)]
class ProcessFilesCommand extends Command
{
    public function __construct(
        private readonly FileProcessor $processor
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simulazione: non accede al filesystem (verifica solo logica e output)')
            ->addOption('resume', null, InputOption::VALUE_NONE, 'Riprende dal checkpoint precedente (se esiste)')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Limita il numero massimo di record da processare', 0);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $fs     = new Filesystem();

        $dryRun = (bool)$input->getOption('dry-run');
        $resume = (bool)$input->getOption('resume');
        $limit  = (int)$input->getOption('limit');
        $checkpointFile = $_ENV['CHECKPOINT_FILE'] ?? 'var/state/checkpoint.json';

        $io->title('File Processor');
        $io->section('Inizializzazione e lettura CSV...');

        // --- Mostra informazioni sul checkpoint se presente ---
        if ($resume && $fs->exists($checkpointFile)) {
            $data = json_decode(file_get_contents($checkpointFile), true);
            if ($data) {
                $io->note([
                    'Checkpoint rilevato:',
                    sprintf('  Ultimo gruppo elaborato: %s', $data['last_group'] ?? '(nessuno)'),
                    sprintf('  Totale processati: %s / %s', $data['processed'] ?? '?', $data['total'] ?? '?'),
                    sprintf('  Aggiornato il: %s', $data['updated_at'] ?? '?'),
                ]);
            } else {
                $io->warning('Checkpoint corrotto o non leggibile: verrà ignorato.');
            }
        } elseif ($resume) {
            $io->warning('Nessun checkpoint trovato: elaborazione partirà da zero.');
        }

        // --- Esecuzione principale ---
        try {
            $result = $this->processor->run($io, $dryRun, $resume, $limit);
        } catch (\Throwable $e) {
            $io->error('Errore durante l’esecuzione: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $io->newLine(2);
        $io->success(sprintf(
            'Completato: %d file processati su %d totali in %s',
            $result['processed'],
            $result['total'],
            $result['duration']
        ));

        // --- Post-run: mostra stato checkpoint finale ---
        if ($fs->exists($checkpointFile)) {
            $data = json_decode(file_get_contents($checkpointFile), true);
            if (!empty($data['last_group'])) {
                $io->text(sprintf('Checkpoint aggiornato: ultimo gruppo = %s', $data['last_group']));
            }
        }

        if ($dryRun) {
            $io->note('Esecuzione in modalità DRY-RUN: nessun file modificato.');
        }

        return Command::SUCCESS;
    }
}

<?php
namespace App\Command;

use App\Service\SystemCheckService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;

/**
 * ✅ Controllo completo dell’ambiente
 * - Verifica variabili .env principali
 * - Testa presenza file/folder e permessi
 * - Controlla magick.exe e connessione cartelle di rete
 */
#[AsCommand(
    name: 'app:check-environment',
    description: 'Esegue il controllo completo dell’ambiente e delle dipendenze'
)]
class CheckEnvironmentCommand extends Command
{
    private Filesystem $fs;

    public function __construct(private readonly SystemCheckService $checkService)
    {
        parent::__construct();
        $this->fs = new Filesystem();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('🔍 Verifica ambiente File Processor');

        // Lista variabili chiave da verificare
        $vars = [
            'CSV_PATH',
            'SOURCE_BASE_PATH',
            'OUTPUT_BASE_PATH',
            'IMAGEMAGICK_PATH',
            'LOG_FILE',
            'TAVOLE_PATH',
        ];

        $rows = [];
        $missing = [];
        $failures = 0;

        foreach ($vars as $v) {
            $value = $_ENV[$v] ?? null;

            if (!$value || trim($value) === '') {
                $rows[] = [$v, '❌ Vuota o non definita', '—'];
                $missing[] = $v;
                $failures++;
                continue;
            }

            // Stato e dettaglio
            $status = '✅ OK';
            $detail = '';

            if (str_contains(strtolower($v), 'path') || str_contains(strtolower($v), 'file')) {
                $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $value);
                if ($this->fs->exists($path)) {
                    if (is_dir($path)) {
                        $perm = is_writable($path) ? 'scrivibile' : 'sola lettura';
                        $detail = "Cartella esistente ($perm)";
                    } else {
                        $perm = is_writable($path) ? 'scrivibile' : 'sola lettura';
                        $detail = "File esistente ($perm)";
                    }
                } else {
                    $status = '⚠️ Mancante';
                    $detail = 'Percorso non trovato';
                    $failures++;
                }
            } else {
                $detail = 'Parametro definito';
            }

            // Controllo formattazione UNC o locale
            if (preg_match('/^\\\\\\\\[0-9a-zA-Z\.\-_]+\\\\/', $value)) {
                $detail .= ' (UNC path)';
            } elseif (preg_match('/^[A-Z]:\\\\/', $value)) {
                $detail .= ' (path locale Windows)';
            }

            $rows[] = [$v, $status, $value, $detail];
        }

        $io->section('Variabili ambiente principali');
        $io->table(['Variabile', 'Stato', 'Valore', 'Dettaglio'], $rows);

        // Controllo extra: magick.exe versione
        $imPath = $_ENV['IMAGEMAGICK_PATH'] ?? null;
        if ($imPath && $this->fs->exists($imPath)) {
            $version = shell_exec("\"$imPath\" -version 2>&1");
            $firstLine = strtok($version, "\n");
            $io->writeln("\n🧠 <info>ImageMagick:</info> $firstLine");
        }

        // Check service esteso (eventuali test custom)
        $extraOk = $this->checkService->runChecks($io);

        $io->newLine(1);
        if ($failures === 0 && $extraOk) {
            $io->success('✅ Tutti i controlli superati. Ambiente pronto.');
            return Command::SUCCESS;
        }

        if (!empty($missing)) {
            $io->warning('Variabili mancanti: ' . implode(', ', $missing));
        }

        $io->error("❌ Rilevati {$failures} problemi di configurazione. Verifica i percorsi indicati sopra.");
        return Command::FAILURE;
    }
}

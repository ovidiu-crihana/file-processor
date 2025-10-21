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
 * 🔍 Controlla e mostra lo stato completo dell'ambiente di esecuzione
 * - Verifica variabili .env principali
 * - Testa la presenza e i permessi di file e directory
 * - Mostra versione PHP, ImageMagick, dimensione CSV
 * - Gestisce warning opzionali (es. TAVOLE_PATH)
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

        // --- Lista variabili da controllare
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
        $warnings = 0;

        foreach ($vars as $v) {
            $value = $_ENV[$v] ?? null;

            if (!$value || trim($value) === '') {
                $rows[] = [$v, '❌ Vuota o non definita', '—', 'Variabile non impostata'];
                $missing[] = $v;
                $failures++;
                continue;
            }

            $status = '✅ OK';
            $detail = '';
            $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $value);

            if (str_contains(strtolower($v), 'path') || str_contains(strtolower($v), 'file')) {
                if ($this->fs->exists($path)) {
                    if (is_dir($path)) {
                        $perm = is_writable($path) ? 'scrivibile' : 'sola lettura';
                        $detail = "Cartella esistente ($perm)";
                    } else {
                        $perm = is_writable($path) ? 'scrivibile' : 'sola lettura';
                        $detail = "File esistente ($perm)";
                    }
                } else {
                    // Distinzione tra errori e warning opzionali
                    if ($v === 'TAVOLE_PATH') {
                        $status = 'ℹ️ Opzionale';
                        $detail = 'Percorso non trovato (tavole non attive)';
                        $warnings++;
                    } else {
                        $status = '⚠️ Mancante';
                        $detail = 'Percorso non trovato';
                        $failures++;
                    }
                }
            } else {
                $detail = 'Parametro definito';
            }

            // Formato UNC o locale
            if (preg_match('/^\\\\\\\\[0-9a-zA-Z\.\-_]+\\\\/', $value)) {
                $detail .= ' (UNC path)';
            } elseif (preg_match('/^[A-Z]:\\\\/', $value)) {
                $detail .= ' (path locale Windows)';
            }

            // CSV – mostra dimensione e righe
            if ($v === 'CSV_PATH' && file_exists($path)) {
                $size = round(filesize($path) / (1024 * 1024), 2);
                $lines = max(0, count(file($path)) - 1);
                $detail .= " ({$size} MB, ~{$lines} righe)";
            }

            $rows[] = [$v, $status, $value, $detail];
        }

        $io->section('Variabili ambiente principali');
        $io->table(['Variabile', 'Stato', 'Valore', 'Dettaglio'], $rows);

        // --- Informazioni aggiuntive
        $io->section('Informazioni di sistema');
        $io->text('PHP: ' . PHP_VERSION);

        $imPath = $_ENV['IMAGEMAGICK_PATH'] ?? null;
        if ($imPath && $this->fs->exists($imPath)) {
            $version = shell_exec("\"$imPath\" -version 2>&1");
            $firstLine = strtok($version, "\n");
            $io->text("ImageMagick: $firstLine");
        }

        // --- Check personalizzati
        $io->section('Check interni Symfony');
        $extraOk = $this->checkService->runChecks($io);

        // --- Sintesi finale
        $io->newLine(1);
        $io->block([
            "📦 Totale variabili verificate: " . count($vars),
            "✅ OK: " . (count($vars) - $failures - $warnings),
            "⚠️ Warning: " . $warnings,
            "❌ Errori: " . $failures,
        ], 'SINTESI', 'fg=white;bg=blue', ' ', true);

        if ($failures === 0) {
            $io->success('✅ Tutti i controlli principali superati. Ambiente pronto.');
            return Command::SUCCESS;
        }

        $io->error("❌ Rilevati {$failures} problemi di configurazione. Verifica i percorsi indicati sopra.");
        return Command::FAILURE;
    }
}

<?php
namespace App\Command;

use App\Service\SystemCheckService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;

/**
 * 🔍 Controlla e mostra lo stato completo dell'ambiente di esecuzione
 * - Verifica variabili .env principali
 * - Testa la presenza e i permessi di file e directory
 * - Mostra versione PHP, ImageMagick, dimensione CSV
 * - Modalità diagnostica con dettagli SMB/ACL/permessi effettivi
 */
#[AsCommand(
    name: 'app:check-environment',
    description: 'Esegue il controllo completo dell’ambiente e delle dipendenze'
)]
class CheckEnvironmentCommand extends Command
{
    private Filesystem $fs;

    /** Variabili di ambiente da controllare */
    private const VARS = [
        'CSV_PATH',
        'SOURCE_BASE_PATH',
        'OUTPUT_BASE_PATH',
        'IMAGEMAGICK_PATH',
        'LOG_FILE',
        'TAVOLE_BASE_PATH',
    ];

    public function __construct(private readonly SystemCheckService $checkService)
    {
        parent::__construct();
        $this->fs = new Filesystem();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'diagnostic',
                null,
                InputOption::VALUE_NONE,
                'Stampa diagnostica dettagliata dei path (whoami, icacls, Get-Acl, net use, test R/W, ecc.).'
            )
            ->addOption(
                'path',
                null,
                InputOption::VALUE_REQUIRED,
                'Path singolo aggiuntivo da diagnosticare (utile per test mirati su una cartella UNC/drive).'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io         = new SymfonyStyle($input, $output);
        $diagnostic = (bool)$input->getOption('diagnostic');
        $extraPath  = $input->getOption('path');

        $io->title('🔍 Verifica ambiente File Processor');

        $rows     = [];
        $failures = 0;
        $warnings = 0;

        foreach (self::VARS as $v) {
            $value = $_ENV[$v] ?? null;

            if (!$value || trim($value) === '') {
                $rows[] = [$v, '❌ Vuota o non definita', '—', 'Variabile non impostata'];
                $failures++;
                continue;
            }

            $status = '✅ OK';
            $detail = '';
            $path   = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $value);

            // Formato UNC o locale
            $detailPathType = $this->describePathType($value);

            if (str_contains(strtolower($v), 'path') || str_contains(strtolower($v), 'file')) {
                if ($this->fs->exists($path)) {
                    if (is_dir($path)) {
                        $perm   = is_writable($path) ? 'scrivibile' : 'sola lettura';
                        $detail = "Cartella esistente ($perm)$detailPathType";
                    } else {
                        $perm   = is_writable($path) ? 'scrivibile' : 'sola lettura';
                        $detail = "File esistente ($perm)$detailPathType";
                    }
                    if ($diagnostic) {
                        $detail .= "\n" . $this->diagnosePath($path, true);
                    }
                } else {
                    // Distinzione tra errori e warning opzionali
                    if ($v === 'TAVOLE_BASE_PATH') {
                        $status  = 'ℹ️ Opzionale';
                        $detail  = "Percorso non trovato (tavole non attive)$detailPathType";
                        $warnings++;
                    } else {
                        $status  = '⚠️ Mancante';
                        $detail  = "Percorso non trovato$detailPathType";
                        $failures++;
                    }

                    // Diagnostica profonda se mancante o se richiesto esplicitamente
                    $detail .= "\n" . $this->diagnosePath($path, true);
                }
            } else {
                $detail = 'Parametro definito';
            }

            // CSV – mostra dimensione e righe
            if ($v === 'CSV_PATH' && file_exists($path)) {
                $size  = round(@filesize($path) / (1024 * 1024), 2);
                $lines = 0;
                // Evita di esplodere su CSV giganteschi; usiamo un conteggio sicuro
                if (is_readable($path)) {
                    $f = @fopen($path, 'rb');
                    if ($f !== false) {
                        while (!feof($f)) {
                            $buf = fread($f, 1024 * 1024);
                            $lines += substr_count((string)$buf, "\n");
                        }
                        fclose($f);
                        $lines = max(0, $lines - 1); // tolgo header
                    }
                }
                $detail .= " ({$size} MB, ~{$lines} righe)";
            }

            $rows[] = [$v, $status, $value, $detail];
        }

        // Tabella variabili
        $io->section('Variabili ambiente principali');
        $io->table(['Variabile', 'Stato', 'Valore', 'Dettaglio'], $rows);

        // Informazioni di sistema
        $io->section('Informazioni di sistema');
        $io->text('PHP: ' . PHP_VERSION);

        $whoami = $this->runCmd('whoami');
        if ($whoami !== null) {
            $io->text('Utente effettivo (whoami): ' . $whoami);
        } else {
            $io->text('Utente effettivo (whoami): non disponibile');
        }

        $imPath = $_ENV['IMAGEMAGICK_PATH'] ?? null;
        if ($imPath && $this->fs->exists($imPath)) {
            $version   = $this->runCmd("\"$imPath\" -version");
            $firstLine = $version ? strtok($version, "\n") : null;
            $io->text("ImageMagick: " . ($firstLine ?: '(non rilevabile)'));
        }

        // Mappature SMB (utile quando si usano drive mappati)
        if ($diagnostic && $this->isWindows()) {
            $io->section('Mappature SMB (net use)');
            $netUse = $this->runCmd('net use');
            $io->writeln($netUse ?: 'Nessun output o comando non disponibile.');
        }

        // Path singolo extra da diagnosticare
        if ($extraPath) {
            $io->section('Diagnostica path specifico (--path)');
            $norm = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $extraPath);
            $io->writeln("Path: {$extraPath}");
            $io->writeln($this->diagnosePath($norm, true));
        }

        // Check interni Symfony
        $io->section('Check interni Symfony');
        $this->checkService->runChecks($io);

        // Sintesi finale
        $io->newLine();
        $io->block([
            "📦 Totale variabili verificate: " . count(self::VARS),
            "✅ OK: " . (count(self::VARS) - $failures - $warnings),
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

    /**
     * Descrive il tipo di path (UNC / locale Windows / altro) per il dettaglio.
     */
    private function describePathType(string $value): string
    {
        if (preg_match('/^\\\\\\\\[0-9a-zA-Z\.\-_]+\\\\/', $value)) {
            return ' (UNC path)';
        }
        if (preg_match('/^[A-Z]:\\\\/', $value)) {
            return ' (path locale Windows)';
        }
        return '';
    }

    /**
     * Esegue una diagnostica approfondita sul path:
     * - realpath/file_exists/is_dir/is_readable/is_writable
     * - whoami
     * - scandir
     * - test scrittura
     * - icacls
     * - PowerShell Get-Acl
     */
    private function diagnosePath(string $path, bool $includeAcl): string
    {
        $lines = [];

        $lines[] = "→ Path normalizzato: {$path}";

        $real = @realpath($path);
        $lines[] = "→ realpath(): " . ($real !== false ? $real : 'false');

        $lines[] = "→ file_exists(): "   . ($this->safeFileExists($path)  ? 'true' : 'false');
        $lines[] = "→ is_dir(): "        . (@is_dir($path)               ? 'true' : 'false');
        $lines[] = "→ is_readable(): "   . (@is_readable($path)          ? 'true' : 'false');
        $lines[] = "→ is_writable(): "   . (@is_writable($path)          ? 'true' : 'false');

        $whoami = $this->runCmd('whoami');
        $lines[] = "→ whoami: " . ($whoami ?: 'non disponibile');

        // scandir
        try {
            $scandir = @scandir($path);
            if ($scandir !== false) {
                $lines[] = "→ scandir(): OK (" . count($scandir) . " elementi)";
            } else {
                $lines[] = "→ scandir(): ❌ fallito";
            }
        } catch (\Throwable $e) {
            $lines[] = "→ scandir() errore: " . $e->getMessage();
        }

        // Test di scrittura (solo su directory)
        if (@is_dir($path)) {
            $testFile = rtrim($path, '\\/') . DIRECTORY_SEPARATOR . '__perm_test_' . uniqid() . '.tmp';
            try {
                $wrote = @file_put_contents($testFile, 'test');
                if ($wrote !== false) {
                    @unlink($testFile);
                    $lines[] = "→ Test scrittura: OK (file temporaneo creato e cancellato)";
                } else {
                    $lines[] = "→ Test scrittura: ❌ fallito (permesso negato o path non accessibile)";
                }
            } catch (\Throwable $e) {
                $lines[] = "→ Test scrittura errore: " . $e->getMessage();
            }
        }

        // ACL solo su Windows
        if ($includeAcl && $this->isWindows()) {
            // icacls
            $icacls = $this->runCmd('icacls "' . $path . '"');
            $lines[] = "→ icacls:\n" . ($icacls ?: 'Nessun output o accesso negato');

            // PowerShell Get-Acl
            $ps = 'powershell -NoProfile -Command "try { Get-Acl -Path \"' . $this->escapeForPowerShell($path) . '\" | Format-List } catch { $_ }"';
            $acl = $this->runRaw($ps);
            $lines[] = "→ Get-Acl:\n" . ($acl ?: 'Nessun output o accesso negato');
        }

        return implode("\n", $lines);
    }

    private function isWindows(): bool
    {
        return stripos(PHP_OS_FAMILY, 'Windows') !== false;
    }

    /** Esegue un comando di sistema e ritorna l’output normalizzato (trim) oppure null. */
    private function runCmd(string $cmd): ?string
    {
        $out = $this->runRaw($cmd);
        return $out !== null ? trim($out) : null;
    }

    /** Esegue shell_exec gestendo eccezioni/disabilitazioni di funzione. */
    private function runRaw(string $cmd): ?string
    {
        try {
            if (!function_exists('shell_exec')) {
                return null;
            }
            // reindirizza stderr su stdout
            $res = @shell_exec($cmd . ' 2>&1');
            return $res !== null ? (string)$res : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Evita warning rumorosi su file_exists per path non raggiungibili. */
    private function safeFileExists(string $path): bool
    {
        try {
            return @file_exists($path);
        } catch (\Throwable) {
            return false;
        }
    }

    /** Escape minimale per stringhe inlined in PowerShell -Command "...". */
    private function escapeForPowerShell(string $s): string
    {
        // Raddoppia le doppie virgolette
        return str_replace('"', '""', $s);
    }
}

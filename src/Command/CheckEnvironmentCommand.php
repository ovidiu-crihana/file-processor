<?php
namespace App\Command;

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
 * - Testa presenza, leggibilità e scrivibilità dei percorsi (anche UNC)
 * - Mostra utente corrente e versione ImageMagick
 */
#[AsCommand(
    name: 'app:check-environment',
    description: 'Esegue il controllo completo dell’ambiente e delle dipendenze'
)]
class CheckEnvironmentCommand extends Command
{
    private Filesystem $fs;

    public function __construct()
    {
        parent::__construct();
        $this->fs = new Filesystem();
    }

    protected function configure(): void
    {
        $this->addOption(
            'deep',
            null,
            InputOption::VALUE_NONE,
            'Mostra dettagli tecnici aggiuntivi su errori e permessi'
        );
    }


    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $verbose = $input->getOption('deep');

        $io->title('🔍 Verifica ambiente File Processor');

        $rows = [];
        $ok = 0;
        $warnings = 0;
        $errors = 0;

        // 👤 Mostra utente corrente
        $user = trim(shell_exec('whoami')) ?: '(sconosciuto)';
        $io->text("👤 Utente corrente: <fg=cyan>{$user}</>");
        $io->newLine();

        // --- Variabili principali
        $source = $_ENV['SOURCE_BASE_PATH'] ?? '';
        $outputPath = $_ENV['OUTPUT_BASE_PATH'] ?? '';
        $tavole = $_ENV['TAVOLE_BASE_PATH'] ?? '';
        $csv = $_ENV['CSV_PATH'] ?? '';
        $magick = trim($_ENV['MAGICK_PATH'] ?? 'magick');

        $rows[] = ['PHP version', phpversion(), '✅ OK']; $ok++;

        // Percorsi principali
        $rows[] = $this->analyzePath('SOURCE_BASE_PATH', $source, true, false, $ok, $warnings, $errors, $user, $verbose, $io);
        $rows[] = $this->analyzePath('OUTPUT_BASE_PATH', $outputPath, true, true, $ok, $warnings, $errors, $user, $verbose, $io);
        $rows[] = $this->analyzePath('TAVOLE_BASE_PATH', $tavole, true, false, $ok, $warnings, $errors, $user, $verbose, $io);
        $rows[] = $this->checkCsv($csv, $ok, $warnings, $errors, $verbose, $io);
        $rows[] = $this->checkMagick($magick, $ok, $warnings, $errors, $verbose, $io);

        // Tabella principale
        $io->section('Variabili ambiente principali');
        $io->table(['Variabile', 'Valore', 'Esito'], $rows);

        // Riepilogo finale
        $io->section('📋 Sintesi');
        $io->listing([
            "✅ OK: {$ok}",
            $warnings > 0 ? "⚠️  Warning: {$warnings}" : null,
            $errors > 0 ? "❌ Errori: {$errors}" : null,
        ]);

        if ($errors === 0) {
            $io->success('✅ Tutti i controlli principali superati. Ambiente pronto.');
            return Command::SUCCESS;
        }

        $io->error('❌ Sono stati rilevati errori. Verificare i path, i permessi o la rete SMB.');
        return Command::FAILURE;
    }

    // -------------------------------------------------------------------------
    // 🔧 PATH CHECKS

    private function analyzePath(
        string $name,
        string $path,
        bool $isDir,
        bool $mustBeWritable,
        int &$ok,
        int &$warnings,
        int &$errors,
        string $user,
        bool $verbose,
        SymfonyStyle $io
    ): array {
        if (!$path) {
            $errors++;
            return [$name, '(non impostato)', '❌ Mancante'];
        }

        $resolved = str_replace('\\\\', '\\', $path);
        $exists = $isDir ? is_dir($resolved) : is_file($resolved);

        if (!$exists) {
            $errors++;
            if ($verbose) {
                $io->writeln("<fg=red>[VERBOSE]</> Path non trovato: {$resolved}");
                $io->writeln("Possibili cause: share SMB non montata, permessi negati o path errato.\n");
            }
            return [$name, $resolved, '❌ Non trovato (path inesistente o share non montata)'];
        }

        // Lettura e scrittura
        $readable = is_readable($resolved);
        $canWrite = false;
        $tmpFile = null;
        $details = [];

        if ($readable) $details[] = 'leggibile';

        if ($mustBeWritable && $readable) {
            try {
                $tmpFile = tempnam($resolved, 'chk_');
                if ($tmpFile) {
                    file_put_contents($tmpFile, 'test');
                    $canWrite = true;
                }
            } catch (\Throwable $e) {
                if ($verbose) {
                    $io->writeln("<fg=red>[VERBOSE]</> Scrittura fallita su {$resolved}: " . $e->getMessage());
                }
            } finally {
                if ($tmpFile && file_exists($tmpFile)) @unlink($tmpFile);
            }
        }

        if (!$readable) {
            $errors++;
            return [$name, $resolved, "❌ Non leggibile (permessi negati all’utente {$user})"];
        }

        if ($mustBeWritable && !$canWrite) {
            $warnings++;
            return [$name, $resolved, "⚠️ Non scrivibile (Access denied for {$user})"];
        }

        $ok++;
        $permText = implode(' + ', $details);
        if ($mustBeWritable) $permText .= ' + scrivibile';
        return [$name, "{$resolved} ({$permText})", '✅ OK'];
    }

    private function checkCsv(string $csv, int &$ok, int &$warnings, int &$errors, bool $verbose, SymfonyStyle $io): array
    {
        if (!$csv) {
            $errors++;
            return ['CSV_PATH', '(non impostato)', '❌ Mancante'];
        }

        $resolved = str_replace('\\\\', '\\', $csv);

        if (preg_match('/^\\\\[A-Za-z]:/', $resolved)) {
            $warnings++;
            return ['CSV_PATH', $resolved, '⚠️ Path locale non valido (inizia con "\\C:")'];
        }

        if (!is_file($resolved)) {
            $errors++;
            if ($verbose) {
                $io->writeln("<fg=red>[VERBOSE]</> File CSV non trovato: {$resolved}");
            }
            return ['CSV_PATH', $resolved, '❌ File CSV non trovato'];
        }

        if (!is_readable($resolved)) {
            $errors++;
            return ['CSV_PATH', $resolved, '❌ File CSV non leggibile (permessi negati)'];
        }

        $sizeMb = round(filesize($resolved) / 1024 / 1024, 2);
        $ok++;
        return ['CSV_PATH', "{$resolved} ({$sizeMb} MB, leggibile)", '✅ OK'];
    }

    private function checkMagick(string $magick, int &$ok, int &$warnings, int &$errors, bool $verbose, SymfonyStyle $io): array
    {
        @exec("\"{$magick}\" -version 2>&1", $out, $code);
        if ($code !== 0 || empty($out)) {
            $errors++;
            if ($verbose) {
                $io->writeln("<fg=red>[VERBOSE]</> Impossibile eseguire '{$magick} -version' (codice {$code})");
            }
            return ['ImageMagick', $magick, '❌ Non trovato o non eseguibile'];
        }

        $version = trim($out[0]);
        if (stripos($version, 'ImageMagick') === false) {
            $warnings++;
            if ($verbose) {
                $io->writeln("<fg=yellow>[VERBOSE]</> Output inatteso da ImageMagick: {$version}");
            }
            return ['ImageMagick', $version, '⚠️ Output sconosciuto'];
        }

        $ok++;
        return ['ImageMagick', $version, '✅ OK'];
    }
}

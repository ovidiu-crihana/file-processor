<?php
namespace App\Command;

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
 * - Estende con diagnostica UNC e permessi R/W
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

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('🔍 Verifica ambiente File Processor');
        $io->writeln('');
        $io->writeln(' 👤 Utente corrente (get_current_user): ' . get_current_user());
        $io->newLine();

        // === Variabili da controllare
        $vars = [
            'PHP version'        => PHP_VERSION,
            'SOURCE_BASE_PATH'   => $_ENV['SOURCE_BASE_PATH'] ?? null,
            'OUTPUT_BASE_PATH'   => $_ENV['OUTPUT_BASE_PATH'] ?? null,
            'TAVOLE_BASE_PATH'   => $_ENV['TAVOLE_BASE_PATH'] ?? null,
            'CSV_PATH'           => $_ENV['CSV_PATH'] ?? null,
            'ImageMagick'        => $this->detectImageMagick(),
        ];

        // === Debug raw path
        $io->writeln(sprintf(
            'DEBUG: raw path SOURCE_BASE_PATH => %s',
            var_export($_ENV['SOURCE_BASE_PATH'] ?? '(vuoto)', true)
        ));
        $io->newLine();

        $rows = [];
        $ok = $err = 0;

        foreach ($vars as $name => $value) {
            $esito = '✅ OK';
            $note  = '';

            switch ($name) {
                case 'SOURCE_BASE_PATH':
                case 'OUTPUT_BASE_PATH':
                case 'TAVOLE_BASE_PATH':
                    $note = $this->describePath($value);
                    if (!$this->fs->exists($value)) {
                        $esito = '❌ Non trovato (path inesistente o share non montata)';
                        $err++;
                    } else {
                        $ok++;
                    }
                    break;

                case 'CSV_PATH':
                    if (!is_file($value)) {
                        $esito = '❌ File CSV non trovato';
                        $err++;
                    } else {
                        $size = round(filesize($value) / 1024 / 1024, 2);
                        $note = sprintf('%s (%.2f MB, leggibile)', $value, $size);
                        $ok++;
                    }
                    break;

                case 'ImageMagick':
                    $note = $value;
                    $ok++;
                    break;

                default:
                    $note = (string) $value;
                    $ok++;
                    break;
            }

            $rows[] = [$name, $note, $esito];
        }

        $io->section('Variabili ambiente principali');
        $io->table(['Variabile', 'Valore / Dettaglio', 'Esito'], $rows);

        // === Sintesi finale
        $io->section('📋 Sintesi');
        $io->writeln(sprintf(" * ✅ OK: %d", $ok));
        $io->writeln(sprintf(" * ❌ Errori: %d", $err));
        $io->newLine();

        if ($err > 0) {
            $io->error('❌ Sono stati rilevati errori. Verificare i path, i permessi o la rete SMB.');
            return Command::FAILURE;
        }

        $io->success('✅ Tutti i controlli principali superati. Ambiente pronto.');
        return Command::SUCCESS;
    }

    private function detectImageMagick(): string
    {
        $path = $_ENV['IMAGEMAGICK_PATH'] ?? 'magick';
        @exec("\"$path\" -version 2>&1", $out);
        return $out ? trim($out[0]) : '❌ Non rilevato';
    }

    private function describePath(?string $path): string
    {
        if (!$path) {
            return '(vuoto)';
        }

        $type = $this->detectPathType($path);
        $ping = $this->pingHostFromPath($path) ? 'Host OK' : 'Host KO';
        $escaped = addcslashes($path, '\\');

        $permCheck = $this->checkReadWriteAccess($path);
        $permLabel = sprintf('%s (%s)', $permCheck['flags'], $permCheck['details']);

        return sprintf(
            "%s\n  ↳ Tipo: %s | Permessi: %s | %s\n  ↳ Raw: %s",
            $path,
            $type,
            $permLabel,
            $ping,
            $escaped
        );
    }


    private function detectPathType(string $path): string
    {
        if (preg_match('/^\\\\\\\\/', $path)) {
            return 'UNC';
        }
        if (preg_match('/^[A-Z]:/', $path)) {
            return 'Drive';
        }
        return 'Altro';
    }

    private function pingHostFromPath(string $path): bool
    {
        if (!preg_match('/^\\\\\\\\([^\\\\]+)/', $path, $m)) {
            return false;
        }
        $host = $m[1];
        @exec("ping -n 1 -w 1000 " . escapeshellarg($host), $out, $code);
        return $code === 0;
    }

    private function checkReadWriteAccess(string $dir): array
    {
        $flags = '';
        $details = [];

        if (!is_dir($dir)) {
            return ['flags' => '--', 'details' => 'non directory'];
        }

        $tmpFile = rtrim($dir, '\\/') . DIRECTORY_SEPARATOR . 'test_rw_' . uniqid() . '.txt';

        // Test scrittura
        $canWrite = @file_put_contents($tmpFile, 'test-ok-' . date('YmdHis')) !== false;
        if ($canWrite) {
            $flags .= 'W';
            $details[] = 'scrittura OK';
        } else {
            $flags .= '-';
            $details[] = 'scrittura KO';
        }

        // Test lettura
        $canRead = $canWrite ? @file_get_contents($tmpFile) !== false : @is_readable($dir);
        if ($canRead) {
            $flags = 'R' . $flags;
            $details[] = 'lettura OK';
        } else {
            $flags = '-' . $flags;
            $details[] = 'lettura KO';
        }

        // Cleanup
        if ($canWrite && is_file($tmpFile)) {
            @unlink($tmpFile);
        }

        return [
            'flags' => $flags,
            'details' => implode(', ', $details),
        ];
    }


}

<?php
declare(strict_types=1);

namespace App\Service;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Exception\IOException;

/**
 * SafeFilesystem
 *
 * Estende Symfony Filesystem aggiungendo una dumpFile atomica
 * con retry per gestire i lock temporanei su Windows (Accesso negato code:5).
 */
final class SafeFilesystem extends Filesystem
{
    /**
     * Scrittura atomica con retry nella stessa directory del file di destinazione.
     *
     * - Crea la directory se mancante.
     * - Scrive su file temporaneo nella stessa cartella.
     * - Tenta rename() con retry; se fallisce, prova a rimuovere il target e riprovare.
     *
     * @param string $path       Percorso del file di destinazione (assoluto o relativo)
     * @param string $contents   Contenuto da scrivere (stringa già serializzata)
     * @param int    $maxRetries Numero massimo di tentativi (default 5)
     * @param int    $delayMs    Millisecondi fra i tentativi (default 200)
     *
     * @throws IOException
     */
    public function dumpFileAtomicWithRetry(string $path, string $contents, int $maxRetries = 5, int $delayMs = 200): void
    {
        $dir = dirname($path) ?: '.';

        // Ensure directory exists
        if (!is_dir($dir)) {
            try {
                $this->mkdir($dir, 0775);
            } catch (\Throwable $e) {
                throw new IOException(sprintf('Impossibile creare la directory %s: %s', $dir, $e->getMessage()), 0, $e);
            }
        }

        // Create a temporary file in the same directory (important for atomic rename on same filesystem)
        $tmp = @tempnam($dir, 'chk_');
        if ($tmp === false) {
            throw new IOException(sprintf('Impossibile creare file temporaneo in %s', $dir));
        }

        // Write contents to temporary file
        $written = @file_put_contents($tmp, $contents);
        if ($written === false) {
            @unlink($tmp);
            throw new IOException(sprintf('Impossibile scrivere nel temporaneo %s', $tmp));
        }

        // Try to atomically replace the target using rename with retries
        for ($attempt = 0; $attempt < max(1, $maxRetries); $attempt++) {
            // First try plain rename
            if (@rename($tmp, $path)) {
                // Success
                return;
            }

            // If failed, try to remove the existing target (it might be locked briefly but worth trying)
            @unlink($path);

            // Try rename again after unlink
            if (@rename($tmp, $path)) {
                return;
            }

            // Sleep before next attempt (usleep expects microseconds)
            usleep((int) ($delayMs * 1000));
        }

        // Cleanup temp file if still exists
        @unlink($tmp);

        throw new IOException(sprintf('Impossibile rinominare %s in %s dopo %d tentativi', $tmp, $path, $maxRetries));
    }

    /**
     * Wrapper sicuro per rename che esegue i controlli di sicurezza standard.
     * (Se in futuro vuoi aggiungere controlli più restrittivi sui path, fallo qui)
     *
     * @param string $origin
     * @param string $target
     * @param bool   $overwrite
     *
     * @return void
     */
    public function safeRename(string $origin, string $target, bool $overwrite = false): void
    {
        // puoi inserire qui controlli custom (assertSafePath o simili)
        parent::rename($origin, $target, $overwrite);
    }
}

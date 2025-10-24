<?php
declare(strict_types=1);

namespace App\Service;

final class CsvReader
{
    /**
     * Itera le righe di un CSV in streaming (senza caricare tutto in memoria).
     * - Normalizza header (trim + uppercase)
     * - Gestisce BOM e delimitatore automatico
     * - Ritorna generator per massime prestazioni
     */
    public static function iterate(string $path, ?string $delimiter = null): \Generator
    {
        if (!is_file($path)) {
            throw new \RuntimeException("CSV non trovato: {$path}");
        }

        $handle = fopen($path, 'r');
        if (!$handle) {
            throw new \RuntimeException("Impossibile aprire CSV: {$path}");
        }

        // Leggi la prima riga (header)
        $headerLine = fgets($handle);
        if ($headerLine === false) {
            fclose($handle);
            return;
        }

        // Rimuovi BOM se presente
        $headerLine = preg_replace('/^\xEF\xBB\xBF/', '', $headerLine);

        // Determina delimitatore se non passato
        if ($delimiter === null) {
            $delimiter = (substr_count($headerLine, ';') > substr_count($headerLine, ',')) ? ';' : ',';
        }

        // Rewind e leggi header completo via fgetcsv
        rewind($handle);
        $header = fgetcsv($handle, 0, $delimiter);
        $header = array_map(fn($h) => strtoupper(trim($h ?? '')), $header);

        // Legge righe successive
        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            if (count($row) !== count($header)) {
                // padding per righe corte o malformate
                $row = array_pad($row, count($header), null);
            }

            $assoc = [];
            foreach ($header as $i => $key) {
                $val = $row[$i] ?? '';
                $assoc[$key] = is_string($val) ? trim($val) : $val;
            }
            yield $assoc;
        }

        fclose($handle);
    }

    /**
     * Conta rapidamente le righe del CSV.
     */
    public static function countRows(string $path): int
    {
        $f = new \SplFileObject($path, 'r');
        $f->seek(PHP_INT_MAX);
        return $f->key();
    }
}

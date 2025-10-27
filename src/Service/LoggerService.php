<?php
declare(strict_types=1);

namespace App\Service;

use Symfony\Component\Console\Style\SymfonyStyle;

final class LoggerService
{
    public function __construct() {}

    /**
     * ===== NUOVI METODI DI LOG STRUTTURATO (unificano gruppi standard e tavole) =====
     */

    /**
     * Stampa il blocco "inizio gruppo" in stile compatto e leggibile.
     * $ctx = [
     *   'folderNum'   => '001',
     *   'groupName'   => 'PE_11_2011_FRONTESPIZIO' | '[TAVOLA] ELABORATO_GRAFICO_IMP01',
     *   'batch'       => 'BAT000...',
     *   'tipo'        => 'FRONTESPIZIO' | 'ELABORATO_GRAFICO',
     *   'idDoc'       => 1,
     *   'isTavola'    => false,
     *   'isTitolo'    => false,
     *   'totalFiles'  => 1,
     *   'foundFiles'  => 1,
     *   'missingFiles'=> 0,
     *   'srcFile'     => '\\server\\...'(solo tavole),
     *   'tifOut'      => 'C:\\...\\Output\\TIFF\\NNN\\...',
     *   'pdfOut'      => 'C:\\...\\Output\\PDF\\NNN\\...',
     *   'pdfEnabled'  => true|false,
     * ]
     */
    public function logGroupStart(SymfonyStyle $io, array $ctx): void
    {
        $folderNum  = (string)($ctx['folderNum']   ?? '000');
        $groupName  = (string)($ctx['groupName']   ?? '');
        $batch      = (string)($ctx['batch']       ?? '');
        $tipo       = (string)($ctx['tipo']        ?? '');
        $idDoc      = (string)($ctx['idDoc']       ?? '');
        $isTavola   = (bool)  ($ctx['isTavola']    ?? false);
        $isTitolo   = (bool)  ($ctx['isTitolo']    ?? false);
        $total      = (int)   ($ctx['totalFiles']  ?? 0);
        $found      = (int)   ($ctx['foundFiles']  ?? 0);
        $missing    = (int)   ($ctx['missingFiles']?? max(0, $total - $found));
        $srcFile    = (string)($ctx['srcFile']     ?? '');
        $tifOut     = (string)($ctx['tifOut']      ?? '');
        $pdfOut     = (string)($ctx['pdfOut']      ?? '');
        $pdfEnabled = (bool)  ($ctx['pdfEnabled']  ?? true);

        $io->writeln(sprintf('<fg=cyan>📁 [#%s] %s</>', $folderNum, $groupName));
        $io->writeln(sprintf('   ├─ Batch: %s', $batch));

        if ($isTitolo) {
            $io->writeln(sprintf('   ├─ <fg=yellow>Tipo documento: %s</>', $tipo));
        } else {
            $io->writeln(sprintf('   ├─ Tipo documento: %s', $tipo));
        }

        $io->writeln(sprintf('   ├─ ID Documento: %s', $idDoc));
        $io->writeln(sprintf('   ├─ Eccezioni: Tavole=%s | Suffisso=NO', $isTavola ? 'SI' : 'NO'));
        $io->writeln(sprintf('   ├─ File previsti: %d', $total));

        if ($isTavola && $srcFile !== '') {
            $io->writeln(sprintf('   ├─ <fg=magenta>File sorgente</>: %s', $srcFile));
        }
        if ($tifOut !== '') {
            $io->writeln(sprintf('   ├─ Output TIFF: %s', $tifOut));
        }
        if ($pdfEnabled) {
            if ($pdfOut !== '') {
                $io->writeln(sprintf('   ├─ Output PDF:  %s', $pdfOut));
            }
        } else {
            $io->writeln('   ├─ PDF: disabilitato da env (TAVOLE_PDF_ENABLED=false)');
        }

        $io->writeln('   ├─ Stato: ▶️ Avvio elaborazione...');
    }

    /**
     * Stampa la riga risultato fine gruppo.
     * $ctx = [
     *   'status'   => 'OK'|'OK_PARTIAL'|'ERROR',
     *   'found'    => int,
     *   'total'    => int,
     *   'missing'  => int,
     *   'duration' => float (sec)
     * ]
     */
    public function logGroupResult(SymfonyStyle $io, array $ctx): void
    {
        $status   = strtoupper((string)($ctx['status']   ?? 'OK'));
        $found    = (int)($ctx['found']   ?? 0);
        $total    = (int)($ctx['total']   ?? 0);
        $missing  = (int)($ctx['missing'] ?? max(0, $total - $found));
        $duration = (float)($ctx['duration'] ?? 0.0);

        [$icon, $color, $label] = match ($status) {
            'OK'         => ['✅', 'green',   'OK'],
            'OK_PARTIAL' => ['⚠️', 'yellow',  'PARZIALE'],
            default      => ['❌', 'red',     'ERRORE'],
        };

        $io->writeln(sprintf(
            '   └─ Risultato: <fg=%s>%s %s</> (%d/%d file, %d mancanti, durata %.2f s)',
            $color, $icon, $label, $found, $total, $missing, $duration
        ));
    }

    /**
     * ====== METODI STORICI (lasciati per retrocompatibilità interna) ======
     * Puoi continuare ad usarli altrove, ma il FileProcessor ora usa i due nuovi sopra.
     */

    public function groupStart(
        SymfonyStyle $io,
        string $baseName,
        int $folderNum,
        int $fileCount,
        string $batch,
        int $iddoc,
        string $tipo,
        bool $isTavola,
        bool $hasSuffix
    ): void {
        $io->writeln(sprintf('<fg=cyan>📁 [#%s] %s</>', str_pad((string)$folderNum, 3, '0', STR_PAD_LEFT), $baseName));
        $io->writeln(sprintf('   ├─ Batch: %s', $batch));
        $io->writeln(sprintf('   ├─ Tipo documento: %s', $tipo));
        $io->writeln(sprintf('   ├─ ID Documento: %d', $iddoc));
        $io->writeln(sprintf('   ├─ Eccezioni: Tavole=%s | Suffisso=%s', $isTavola ? 'SI' : 'NO', $hasSuffix ? 'SI' : 'NO'));
        $io->writeln(sprintf('   ├─ File previsti: %d', $fileCount));
        $io->writeln('   ├─ Stato: ▶️ Avvio elaborazione...');
    }

    public function groupEndDetailed(
        SymfonyStyle $io,
        string $baseName,
        int $folderNum,
        float $duration,
        string $status,
        int $found,
        int $missing,
        string $batch,
        int $iddoc,
        string $tipo
    ): void {
        [$icon, $color, $label] = match (strtoupper($status)) {
            'OK'         => ['✅', 'green',   'OK'],
            'OK_PARTIAL' => ['⚠️', 'yellow',  'PARZIALE'],
            default      => ['❌', 'red',     'ERRORE'],
        };
        $total = $found + $missing;

        $io->writeln(sprintf(
            '   └─ Risultato: <fg=%s>%s %s</> (%d/%d file, %d mancanti, durata %.2f s)',
            $color, $icon, $label, $found, $total, $missing, $duration
        ));
    }
}

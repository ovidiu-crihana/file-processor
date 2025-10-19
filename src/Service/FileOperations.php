<?php
namespace App\Service;

use Symfony\Component\Filesystem\Filesystem;

/**
 * Gestisce le operazioni sui file (verifica, copia, rinomina, dry-run).
 *
 * ⚙️ Logica conforme alle specifiche del progetto:
 *  - Percorso sorgente: Work\<anno>\<mese>\<giorno>\<BATCH>\<IMMAGINE>
 *  - Percorso output: Output\<BATCH>\<IDRECORD>\
 *  - Nome file: {TIPO_PRATICA}_{PRAT_NUM}_{ANNO}_{TIPO_DOCUMENTO}_{IDRECORD}.tif
 *  - Eccezioni:
 *      • "tavole" → se TIPO_DOCUMENTO contiene numeri
 *      • "titolo autorizzativo" → sostituisce con SIGLA_PRATICA
 *  - Protezione: nessuna modifica dentro Work
 */
class FileOperations
{
    private SafeFilesystem $fs;
    private LoggerService $logger;
    private Filesystem $nativeFs;

    private array $cols;

    public function __construct(LoggerService $logger)
    {
        $this->logger = $logger;
        $this->fs     = new SafeFilesystem();
        $this->nativeFs = new Filesystem();

        $this->cols = [
            'id'            => $_ENV['CSV_COL_ID'] ?? 'ID',
            'file'          => $_ENV['CSV_COL_FILE'] ?? 'IMMAGINE',
            'batch'         => $_ENV['CSV_COL_BATCH'] ?? 'BATCH',
            'practice'      => $_ENV['CSV_COL_PRACTICE_TYPE'] ?? 'TIPO_PRATICA',
            'document'      => $_ENV['CSV_COL_DOCUMENT_TYPE'] ?? 'TIPO_DOCUMENTO',
            'prat_num'      => 'PRAT_NUM',
            'protocol_date' => $_ENV['CSV_COL_PROTOCOL_DATE'] ?? 'PROT_PRAT_DATA',
            'sigla'         => $_ENV['CSV_COL_SIGLA'] ?? 'SIGLA_PRATICA',
            'date_acq'      => $_ENV['CSV_COL_DATE'] ?? 'DATA_ORA_ACQ',
            'idrecord'      => 'IDRECORD',
        ];
    }

    /**
     * Esegue la copia o la simulazione per un singolo record.
     */
    public function processRecord(array $r, bool $dryRun, int $numeroRelativo): void
    {
        $sourceBase = $_ENV['SOURCE_BASE_PATH'];
        $outputBase = $_ENV['OUTPUT_BASE_PATH'];
        $pattern    = $_ENV['OUTPUT_FILENAME_PATTERN'] ?? '{TIPO_PRATICA}_{PRAT_NUM}_{ANNO}_{TIPO_DOCUMENTO}_{IDRECORD}.tif';

        // --- parsing DATA_ORA_ACQ per Y/M/D (Work)
        $acq = preg_replace('/\D+/', '', (string)($r[$this->cols['date_acq']] ?? ''));
        $yyyy = strlen($acq) >= 4 ? substr($acq, 0, 4) : '';
        $mm   = strlen($acq) >= 6 ? substr($acq, 4, 2) : '';
        $dd   = strlen($acq) >= 8 ? substr($acq, 6, 2) : '';

        // --- costruzione percorso sorgente
        $batch = trim($r[$this->cols['batch']] ?? '');
        $filename = trim($r[$this->cols['file']] ?? '');
        $sourcePath = rtrim($sourceBase, '\\/') . "\\{$yyyy}\\{$mm}\\{$dd}\\{$batch}\\{$filename}";

        // --- Eccezione: TAVOLE (TIPO_DOCUMENTO contiene numeri)
        $tipoDocRaw = trim($r[$this->cols['document']] ?? '');
        if (preg_match('/\d+/', $tipoDocRaw)) {
            $tavoleBase = $_ENV['EXCEPTION_TAVOLE_PATH'] ?? '';
            if ($tavoleBase && $this->nativeFs->exists($tavoleBase)) {
                $sourcePath = rtrim($tavoleBase, '\\/') . "\\{$batch}\\{$filename}";
            }
        }

        // --- Eccezione: "titolo autorizzativo"
        $elaborato = (stripos($tipoDocRaw, 'titolo autorizzativo') !== false)
            ? trim($r[$this->cols['sigla']] ?? '')
            : $tipoDocRaw;

        // --- ANNO dal PROT_PRAT_DATA (ultime 4 cifre)
        $prot = preg_replace('/\D+/', '', (string)($r[$this->cols['protocol_date']] ?? ''));
        $anno = strlen($prot) >= 4 ? substr($prot, -4) : '';

        // --- IDRECORD effettivo (dal CSV)
        $idrecord = trim($r[$this->cols['idrecord']] ?? '');

        // --- costruzione nome file
        $name = strtr($pattern, [
            '{TIPO_PRATICA}'   => trim($r[$this->cols['practice']] ?? ''),
            '{PRAT_NUM}'       => trim($r[$this->cols['prat_num']] ?? ''),
            '{ANNO}'           => $anno,
            '{TIPO_DOCUMENTO}' => $elaborato,
            '{IDRECORD}'       => $idrecord,
            '{NUMERO_RELATIVO}'=> $numeroRelativo, // retrocompatibilità
        ]);

        // --- percorso destinazione (Output\<BATCH>\<IDRECORD>\)
        $destFolder = rtrim($outputBase, '\\/') . '\\' .
            $batch . '\\' . $idrecord;
        $destPath   = $destFolder . '\\' . $name;

        // --- DRY-RUN: simulazione
        if ($dryRun) {
            $this->logger->info("SIMULA copia: {$sourcePath} → {$destPath}");
            return;
        }

        // --- Sicurezza: mai modificare dentro Work
        if (str_starts_with(strtolower($destPath), strtolower($sourceBase))) {
            $this->logger->error("Tentativo di scrittura dentro Work bloccato: {$destPath}");
            return;
        }

        // --- Verifica file sorgente
        if (!$this->fs->exists($sourcePath)) {
            $this->logger->warning("File non trovato: {$sourcePath}");
            return;
        }

        // --- Crea la cartella di destinazione se mancante
        if (!$this->fs->exists($destFolder)) {
            $this->fs->mkdir($destFolder, 0775);
        }

        // --- Copia effettiva
        $this->fs->copy($sourcePath, $destPath, true);
        $this->logger->info("OK: {$filename} → {$name}");
    }

    public function mergeGroup(string $groupKey, array $records, bool $dryRun): void
    {
        // groupKey tipicamente "BATCH|ALTRA_CHIAVE"
        $parts = explode('|', $groupKey);
        $batch = $parts[0] ?? '';
        $groupLabel = $parts[1] ?? 'group';

        $outputBase = $_ENV['OUTPUT_BASE_PATH'];
        $pattern    = $_ENV['OUTPUT_FILENAME_PATTERN'] ?? '{TIPO_PRATICA}_{PRAT_NUM}_{ANNO}_{TIPO_DOCUMENTO}_{GROUP}.tif';
        $enablePdf  = filter_var($_ENV['ENABLE_PDF_CONVERSION'] ?? 'false', FILTER_VALIDATE_BOOLEAN);
        $pdfTool    = strtolower($_ENV['PDF_TOOL'] ?? 'imagemagick');
        $pdfOutPath = $_ENV['PDF_OUTPUT_PATH'] ?? null;
        $imPath     = $_ENV['IMAGEMAGICK_PATH'] ?? 'magick';

        // Cartella destinazione: Output\<BATCH>\<GROUP_LABEL>\
        $destFolder = rtrim($outputBase, '\\/') . '\\' . $batch . '\\' . $groupLabel;
        if (!$dryRun && !$this->fs->exists($destFolder)) {
            $this->fs->mkdir($destFolder, 0775);
        }

        // Ricava i metadati comuni per comporre il nome del file finale del gruppo
        // (usiamo la prima riga del gruppo come riferimento per TIPO_PRATICA, PRAT_NUM, ANNO e TIPO_DOCUMENTO/SIGLA)
        $first = $records[0];

        $tipoPratica = trim($first[$this->cols['practice']] ?? '');
        $pratNum     = trim($first[$this->cols['prat_num']] ?? '');
        $anno        = $this->yearFromProtocol($first[$this->cols['protocol_date']] ?? '');

        $tipoDocRaw  = trim($first[$this->cols['document']] ?? '');
        $elaborato   = (stripos($tipoDocRaw, 'titolo autorizzativo') !== false)
            ? trim($first[$this->cols['sigla']] ?? '')
            : $tipoDocRaw;

        // Costruisci il nome finale del TIFF del gruppo
        // Supporta {GROUP} come segnaposto per la seconda chiave (es. IDDOCUMENTO/PRAT_NUM/PROT_PRAT_NUMERO)
        $finalName = strtr($pattern, [
            '{TIPO_PRATICA}'   => $tipoPratica,
            '{PRAT_NUM}'       => $pratNum,
            '{ANNO}'           => $anno,
            '{TIPO_DOCUMENTO}' => $elaborato,
            '{IDRECORD}'       => $groupLabel,        // fallback compatibilità se nel pattern c'è {IDRECORD}
            '{GROUP}'          => $groupLabel,
            '{NUMERO_RELATIVO}'=> $groupLabel,        // fallback
        ]);

        $finalTif = $destFolder . '\\' . $finalName;
        $finalPdf = preg_replace('/\.tif(f)?$/i', '.pdf', $finalTif);

        // Costruisci la lista dei sorgenti (ordinati) con eccezioni
        $sources = [];
        foreach ($records as $r) {
            $src = $this->buildSourcePath($r);
            if ($src === null) {
                $t = $r[$this->cols['id']] ?? '?';
                $this->logger->warning("Sorgente mancante per ID {$t} (saltato)");
                continue;
            }
            $sources[] = $src;
        }

        if (empty($sources)) {
            $this->logger->warning("Nessuna sorgente valida per il gruppo {$groupKey}. Nessun file creato.");
            return;
        }

        if ($dryRun) {
            $this->logger->info("SIMULA MERGE gruppo {$groupKey}: " . count($sources) . " pagine → {$finalTif}" . ($enablePdf ? " + PDF" : ""));
            return;
        }

        // Sicurezza: non scrivere mai dentro Work
        $work = rtrim($_ENV['SOURCE_BASE_PATH'], '\\/');
        if (str_starts_with(strtolower($finalTif), strtolower($work))) {
            $this->logger->error("Tentativo di scrittura dentro Work bloccato: {$finalTif}");
            return;
        }

        // Esegui il merge multipagina con ImageMagick
        // magick convert page1.tif page2.tif ... -compress Group4 final.tif
        try {
            $cmd = $this->buildMagickMergeCommand($imPath, $sources, $finalTif);
            $this->exec($cmd);
            $this->logger->info("Creato TIFF multipagina: {$finalTif}");
        } catch (\Throwable $e) {
            $this->logger->error("Errore creazione TIFF multipagina: " . $e->getMessage());
            return;
        }

        if ($enablePdf) {
            try {
                // finalPdf è già calcolato come $finalTif con estensione .pdf
                $finalPdf = preg_replace('/\.tif(f)?$/i', '.pdf', $finalTif);

                $cmd = $this->buildMagickToPdfCommand($imPath, $finalTif, $finalPdf);
                $this->exec($cmd);
                $this->logger->info("Creato PDF: {$finalPdf}");
            } catch (\Throwable $e) {
                $this->logger->error("Errore creazione PDF: " . $e->getMessage());
            }
        }
    }

    /**
     * Costruisce il percorso sorgente, gestendo:
     *  - Work\YYYY\MM\DD\<BATCH>\<IMMAGINE>
     *  - eccezione "tavole" se TIPO_DOCUMENTO contiene numeri
     */
    public function buildSourcePath(array $r): ?string
    {
        $sourceBase = $_ENV['SOURCE_BASE_PATH'];
        $tavoleBase = $_ENV['EXCEPTION_TAVOLE_PATH'] ?? null;

        // Y/M/D da DATA_ORA_ACQ
        $acq = preg_replace('/\D+/', '', (string)($r[$this->cols['date_acq']] ?? ''));
        $yyyy = strlen($acq) >= 4 ? substr($acq, 0, 4) : '';
        $mm   = strlen($acq) >= 6 ? substr($acq, 4, 2) : '';
        $dd   = strlen($acq) >= 8 ? substr($acq, 6, 2) : '';

        $batch   = trim($r[$this->cols['batch']] ?? '');
        $file    = trim($r[$this->cols['file']] ?? '');
        $tipoDoc = trim($r[$this->cols['document']] ?? '');

        // default: Work\Y\M\D\BATCH\file
        $candidate = rtrim($sourceBase, '\\/') . "\\{$yyyy}\\{$mm}\\{$dd}\\{$batch}\\{$file}";

        // eccezione TAVOLE se TIPO_DOCUMENTO contiene numeri
        if (preg_match('/\d+/', $tipoDoc) && $tavoleBase) {
            $alt = rtrim($tavoleBase, '\\/') . "\\{$batch}\\{$file}";
            if ($this->fs->exists($alt)) {
                $candidate = $alt;
            }
        }

        return $this->fs->exists($candidate) ? $candidate : null;
    }

    private function yearFromProtocol(string $s): string
    {
        $digits = preg_replace('/\D+/', '', $s);
        return (strlen($digits) >= 4) ? substr($digits, -4) : '';
    }

    private function buildMagickMergeCommand(string $magick, array $sources, string $destTif): string
    {
        // Creiamo sempre una "file list" per sicurezza e performance
        $tmpDir = rtrim($_ENV['TEMP_PATH'] ?? 'var\\tmp', '\\/');
        if (!is_dir($tmpDir)) { @mkdir($tmpDir, 0775, true); }

        // File di lista senza spazi nel path per evitare problemi
        $listFile = $tmpDir . '\\im_sources_' . md5($destTif) . '.txt';

        // Scriviamo una sorgente per riga (NON mettere le virgolette dentro il file)
        // NB: il path può contenere spazi, IM lo gestisce dal file-lista.
        file_put_contents($listFile, implode(PHP_EOL, $sources));

        // Compressione parametrica (default: Group4)
        $compression = strtolower($_ENV['TIFF_COMPRESSION'] ?? 'group4');
        $compressArg = match ($compression) {
            'group4', 'g4'   => '-compress Group4',
            'lzw'            => '-compress LZW',
            'zip'            => '-compress Zip',
            default          => '-compress Group4',
        };

        // Limiti (opzionali) per proteggere il sistema in gruppi enormi
        $limits = [];
        if (!empty($_ENV['IM_LIMIT_MEMORY'])) $limits[] = '-limit memory ' . escapeshellarg($_ENV['IM_LIMIT_MEMORY']);
        if (!empty($_ENV['IM_LIMIT_MAP']))    $limits[] = '-limit map '    . escapeshellarg($_ENV['IM_LIMIT_MAP']);
        if (!empty($_ENV['IM_LIMIT_DISK']))   $limits[] = '-limit disk '   . escapeshellarg($_ENV['IM_LIMIT_DISK']);
        $limitArgs = implode(' ', $limits);

        // Comando finale: usa @filelist per passare tutte le pagine
        // IM v7: niente "convert"
        $dst = '"' . str_replace('"','\"',$destTif) . '"';
        $cmd = "{$magick} {$limitArgs} @{$listFile} {$compressArg} {$dst}";

        return trim($cmd);
    }

    private function buildMagickToPdfCommand(string $magick, string $srcTif, string $destPdf): string
    {
        $q = fn($p) => '"' . str_replace('"','\"',$p) . '"';
        // IM v7: niente "convert"
        return "{$magick} " . $q($srcTif) . " " . $q($destPdf);
    }


    /** Esegue un comando di shell su Windows con escaping basico */
    private function exec(string $cmd): void
    {
        // Usa proc_open per migliore controllo in futuro; qui semplice passthrough
        $out = [];
        $ret = 0;
        exec($cmd . ' 2>&1', $out, $ret);
        if ($ret !== 0) {
            throw new \RuntimeException("Comando fallito ({$ret}): {$cmd}\n" . implode("\n", $out));
        }
    }

}

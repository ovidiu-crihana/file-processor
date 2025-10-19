<?php
namespace App\Service;

class FileOperations
{
    private SafeFilesystem $fs;
    private LoggerService $logger;
    private array $env;
    private array $cols;

    public function __construct(LoggerService $logger)
    {
        $this->fs = new SafeFilesystem();
        $this->logger = $logger;
        $this->env = $_ENV;

        $this->cols = [
            'id' => $this->env['CSV_COL_ID'] ?? 'ID',
            'file' => $this->env['CSV_COL_FILE'] ?? 'IMMAGINE',
            'batch' => $this->env['CSV_COL_BATCH'] ?? 'BATCH',
            'practice' => $this->env['CSV_COL_PRACTICE_TYPE'] ?? 'TIPO_PRATICA',
            'document' => $this->env['CSV_COL_DOCUMENT_TYPE'] ?? 'TIPO_DOCUMENTO',
            'protocol_date' => $this->env['CSV_COL_PROTOCOL_DATE'] ?? 'PROT_PRAT_DATA',
            'sigla' => $this->env['CSV_COL_SIGLA'] ?? 'SIGLA_PRATICA',
        ];
    }

    public function processRecord(array $r, bool $dryRun): void
    {
        $id = $r[$this->cols['id']] ?? '';
        $batch = trim($r[$this->cols['batch']] ?? '');
        $filename = trim($r[$this->cols['file']] ?? '');
        $tipoDoc = trim($r[$this->cols['document']] ?? '');
        $annoProt = substr(trim($r[$this->cols['protocol_date']] ?? ''), -4);

        $sourceBase = $this->env['SOURCE_BASE_PATH'];
        $outputBase = $this->env['OUTPUT_BASE_PATH'];

        $sourcePath = $sourceBase . '\\' . $batch . '\\' . $filename;
        $destFolder = $outputBase . '\\' . $batch;

        $newName = sprintf(
            "%s_%s_%s_%s_%s.tif",
            $r[$this->cols['practice']] ?? '',
            $r['PRAT_NUM'] ?? '',
            $annoProt,
            $tipoDoc,
            $id
        );
        $destPath = $destFolder . '\\' . $newName;

        if ($dryRun) {
            $this->logger->info("Simulazione: $sourcePath → $destPath");
            return;
        }

        if (!$this->fs->exists($sourcePath)) {
            $this->logger->warning("File non trovato: $sourcePath");
            return;
        }

        try {
            if (!$this->fs->exists($destFolder)) {
                $this->fs->mkdir($destFolder, 0775);
            }
            $this->fs->copy($sourcePath, $destPath, true);
            $this->logger->info("OK: {$filename} copiato come {$newName}");
        } catch (\Throwable $e) {
            $this->logger->error("Errore su ID {$id}: " . $e->getMessage());
        }
    }
}

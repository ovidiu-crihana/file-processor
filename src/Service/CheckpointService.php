<?php
declare(strict_types=1);

namespace App\Service;

final class CheckpointService
{
    private string $path;
    private bool $autoSave;

    /** @var resource|null */
    private $fh = null;

    public function __construct(?string $path = null, ?bool $autoSave = null)
    {
        $this->path = $path ?: ($_ENV['CHECKPOINT_FILE'] ?? 'var/checkpoints/process.csv');
        $this->autoSave = $autoSave ?? (($_ENV['CHECKPOINT_AUTO_SAVE'] ?? 'true') === 'true');
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        if (!is_file($this->path)) {
            file_put_contents($this->path, "BATCH,IDDOCUMENTO,TIPO_DOCUMENTO,FOLDER_NUM,STATUS,UPDATED_AT\n");
        }
        $this->fh = fopen($this->path, 'ab');
    }

    public function __destruct()
    {
        if ($this->fh) fclose($this->fh);
    }

    public function markCompleted(string $batch, string $iddoc, string $tipoDoc, int $folderNum, string $status = 'OK'): void
    {
        if (!$this->fh) return;
        $line = sprintf(
            "%s,%s,%s,%d,%s,%s\n",
            $this->csvSafe($batch),
            $this->csvSafe($iddoc),
            $this->csvSafe($tipoDoc),
            $folderNum,
            $status,
            (new \DateTimeImmutable())->format('c')
        );
        fwrite($this->fh, $line);
        if ($this->autoSave) fflush($this->fh);
    }

    public function flush(): void
    {
        if ($this->fh) fflush($this->fh);
    }

    /** @return array<string,bool> chiavi completate: "batch|iddoc|tipo" => true */
    public function loadCompleted(): array
    {
        $done = [];
        if (!is_file($this->path)) return $done;
        $fh = fopen($this->path, 'rb');
        if (!$fh) return $done;
        // skip header
        fgets($fh);
        while (($line = fgets($fh)) !== false) {
            $parts = str_getcsv($line);
            if (count($parts) < 6) continue;
            [$b,$i,$t,$folder,$status] = $parts;
            if (trim($status) !== 'OK') continue;
            $key = trim($b).'|'.trim($i).'|'.trim($t);
            $done[$key] = true;
        }
        fclose($fh);
        return $done;
    }

    /** Ritorna l’ultima voce OK (se serve per logging) */
    public function lastCompleted(): ?array
    {
        if (!is_file($this->path)) return null;
        $lines = @file($this->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$lines || count($lines) < 2) return null;
        for ($i = count($lines)-1; $i >= 1; $i--) {
            $parts = str_getcsv($lines[$i]);
            if (count($parts) < 6) continue;
            if (trim($parts[4]) !== 'OK') continue;
            return [
                'BATCH' => $parts[0],
                'IDDOCUMENTO' => $parts[1],
                'TIPO_DOCUMENTO' => $parts[2],
                'FOLDER_NUM' => (int)$parts[3],
                'STATUS' => $parts[4],
                'UPDATED_AT' => $parts[5] ?? '',
            ];
        }
        return null;
    }

    private function csvSafe(string $v): string
    {
        $v = str_replace(["\r","\n"], ' ', $v);
        return $v;
    }
}

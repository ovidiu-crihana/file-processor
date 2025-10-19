<?php
namespace App\Service;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;

class SafeFilesystem extends Filesystem
{
    private string $protectedSegment = 'work';

    private function assertSafePath(string|array $paths, string $operation): void
    {
        $paths = is_array($paths) ? $paths : [$paths];

        foreach ($paths as $path) {
            if (!$path) continue;
            $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, strtolower($path));

            // blocca ogni operazione che tocca la cartella work
            if (preg_match('#[\\\\/]'.$this->protectedSegment.'[\\\\/]#', $normalized)) {
                throw new \LogicException("Operazione '$operation' vietata sulla directory protetta: $path");
            }
        }
    }

    public function remove($files): void
    {
        $this->assertSafePath($files, 'remove');
        parent::remove($files);
    }

    public function rename(string $origin, string $target, bool $overwrite = false): void
    {
        $this->assertSafePath([$origin, $target], 'rename');
        parent::rename($origin, $target, $overwrite);
    }

    public function mirror(string $originDir, string $targetDir, \Traversable $iterator = null, array $options = []): void
    {
        $this->assertSafePath([$originDir, $targetDir], 'mirror');
        parent::mirror($originDir, $targetDir, $iterator, $options);
    }
}

<?php
namespace App\Utils;

class PathUtils
{
    public static function normalize(string $path): string
    {
        return str_replace(['/', '\\\\'], '\\', $path);
    }
}

<?php
namespace App\Service;

use League\Csv\Exception;
use League\Csv\Reader;
use League\Csv\UnavailableStream;

class CsvReader
{
    /**
     * @throws UnavailableStream
     * @throws Exception
     */
    public static function read(string $path): array
    {
        $csv = Reader::from($path, 'r');
        $csv->setHeaderOffset(0);
        return iterator_to_array($csv->getRecords());
    }
}

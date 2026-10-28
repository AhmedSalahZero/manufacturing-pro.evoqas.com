<?php

namespace App\Excel;

// A tiny ZIP file writer (an .xlsx is a ZIP of XML files). No PHP extension is needed except zlib for compression;
// without zlib the files are stored uncompressed, which every program still opens.
final class ZipWriter
{
    /** @var array<string,array{data:string,crc:int,size:int,csize:int,method:int,offset:int}> */
    private array $entries = [];

    private string $body = '';

    public function add(string $name, string $data): void
    {
        $crc = crc32($data);
        $size = strlen($data);
        $method = 0;
        $packed = $data;

        if ($size > 64 && function_exists('gzdeflate')) {
            $deflated = gzdeflate($data, 6);
            if ($deflated !== false && strlen($deflated) < $size) {
                $packed = $deflated;
                $method = 8;
            }
        }

        $offset = strlen($this->body);
        $nameLength = strlen($name);
        // local file header: signature, version 20, flags 0 (names are plain ASCII), method, DOS time/date (1980-01-01), crc, sizes, name
        $this->body .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0, $method, 0, 0x21, $crc, strlen($packed), $size, $nameLength, 0).$name.$packed;
        $this->entries[$name] = ['crc' => $crc, 'size' => $size, 'csize' => strlen($packed), 'method' => $method, 'offset' => $offset];
    }

    public function toString(): string
    {
        $central = '';
        foreach ($this->entries as $name => $e) {
            $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, $e['method'], 0, 0x21, $e['crc'], $e['csize'], $e['size'], strlen($name), 0, 0, 0, 0, 0, $e['offset']).$name;
        }
        $count = count($this->entries);

        return $this->body.$central.pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, strlen($central), strlen($this->body), 0);
    }
}

<?php

namespace App\Excel;

// The words on the sheets, English and Arabic. Every key has both; a missing key stops the build so nothing is ever shown untranslated.
final class Labels
{
    /** @var array<string,array{0:string,1:string}> */
    private array $words;

    public function __construct(public readonly string $lang)
    {
        $this->words = require __DIR__.'/LabelsData.php';
    }

    public function get(string $key, string|int|float ...$args): string
    {
        $pair = $this->words[$key] ?? throw new \RuntimeException("Missing Excel label '$key'");
        $text = $pair[$this->lang === 'ar' ? 1 : 0];

        return $args === [] ? $text : sprintf($text, ...$args);
    }

    public function has(string $key): bool
    {
        return isset($this->words[$key]);
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->words);
    }

    /** @return array<string,array{0:string,1:string}> */
    public function all(): array
    {
        return $this->words;
    }
}

<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

// English and Arabic server messages must match. Run:  php artisan test --filter=TranslationsTest
// (The words on the screens are checked by `npm test`, tests/js/i18n.spec.js.)
class TranslationsTest extends TestCase
{
    private string $lang;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lang = dirname(__DIR__, 2).'/lang';
    }

    private function flatten(array $array, string $prefix = ''): array
    {
        $out = [];
        foreach ($array as $key => $value) {
            is_array($value) ? $out += $this->flatten($value, "{$prefix}{$key}.") : $out["{$prefix}{$key}"] = $value;
        }

        return $out;
    }

    public function test_both_languages_have_the_same_files(): void
    {
        $en = array_map('basename', glob("{$this->lang}/en/*.php"));
        $ar = array_map('basename', glob("{$this->lang}/ar/*.php"));
        sort($en);
        sort($ar);

        $this->assertNotEmpty($en);
        $this->assertSame($en, $ar);
    }

    public function test_every_file_has_the_same_keys_in_both_languages(): void
    {
        foreach (glob("{$this->lang}/en/*.php") as $file) {
            $name = basename($file);
            $en = $this->flatten(require $file);
            $ar = $this->flatten(require "{$this->lang}/ar/{$name}");

            $missingInArabic = array_diff(array_keys($en), array_keys($ar));
            $missingInEnglish = array_diff(array_keys($ar), array_keys($en));

            // Laravel ships its own complete English validation messages and merges them with ours,
            // so lang/en/validation.php is deliberately thin. Arabic has no such built-in, so it is complete.
            if ($name === 'validation.php') {
                $missingInEnglish = array_diff(
                    array_filter(array_keys($ar), fn ($k) => str_starts_with($k, 'attributes.')),
                    array_keys($en)
                );
            }

            $this->assertSame([], array_values($missingInArabic), "{$name}: missing in Arabic");
            $this->assertSame([], array_values($missingInEnglish), "{$name}: missing in English");
        }
    }

    public function test_no_message_is_empty_and_placeholders_match(): void
    {
        foreach (glob("{$this->lang}/en/*.php") as $file) {
            $name = basename($file);
            $en = $this->flatten(require $file);
            $ar = $this->flatten(require "{$this->lang}/ar/{$name}");

            foreach ($en as $key => $english) {
                $arabic = $ar[$key] ?? '';
                $this->assertNotSame('', trim((string) $english), "en {$name} {$key} is empty");
                $this->assertNotSame('', trim((string) $arabic), "ar {$name} {$key} is empty");

                preg_match_all('/:[a-z_]+/i', (string) $english, $a);
                preg_match_all('/:[a-z_]+/i', (string) $arabic, $b);
                $x = array_unique($a[0]);
                $y = array_unique($b[0]);
                sort($x);
                sort($y);
                $this->assertSame($x, $y, "{$name} {$key}: the :placeholders differ between languages");
            }
        }
    }

    public function test_the_arabic_json_file_is_valid_and_complete(): void
    {
        $json = json_decode(file_get_contents("{$this->lang}/ar.json"), true);

        $this->assertIsArray($json);
        $this->assertNotEmpty($json);
        foreach ($json as $english => $arabic) {
            $this->assertNotSame('', trim($arabic), "ar.json: '{$english}' is empty");
        }
        // The words of Laravel's reset-password e-mail.
        $this->assertArrayHasKey('Reset Password', $json);
        $this->assertArrayHasKey('Reset Password Notification', $json);
    }
}

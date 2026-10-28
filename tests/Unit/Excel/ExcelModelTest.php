<?php

namespace Tests\Unit\Excel;

use App\Excel\Labels;
use App\Excel\ModelBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ZipArchive;

// The Excel Model generator: words in both languages, sheets, formulas, protection and the names it uses.
// Run:  php artisan test --filter=Excel\\ExcelModelTest
class ExcelModelTest extends TestCase
{
    private function fixture(int $n): array
    {
        return require __DIR__.'/../../Fixtures/excel/fixture'.$n.'.php';
    }

    /** @return array<string,string> file name inside the xlsx => contents */
    private function parts(string $bytes): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'zx');
        file_put_contents($tmp, $bytes);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($tmp) === true, 'the workbook is a valid zip');
        $out = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $out[$zip->getNameIndex($i)] = $zip->getFromIndex($i);
        }
        $zip->close();
        @unlink($tmp);

        return $out;
    }

    public function test_every_label_has_english_and_arabic(): void
    {
        foreach ((new Labels('en'))->all() as $key => $pair) {
            $this->assertCount(2, $pair, $key);
            $this->assertNotSame('', trim((string) $pair[0]), "$key English");
            $this->assertNotSame('', trim((string) $pair[1]), "$key Arabic");
        }
    }

    public function test_a_missing_label_stops_the_build(): void
    {
        $this->expectException(\RuntimeException::class);
        (new Labels('en'))->get('no.such.label');
    }

    /** The bug: on a server in Cairo (UTC+2/+3) the first of January came out as 31 December, so the workbook started a month early. */
    public function test_a_month_is_the_same_day_in_every_time_zone(): void
    {
        $before = date_default_timezone_get();
        try {
            foreach (['UTC', 'Africa/Cairo', 'Asia/Riyadh', 'America/New_York', 'Pacific/Auckland'] as $zone) {
                date_default_timezone_set($zone);
                $this->assertSame(46023, \App\Excel\ModelData::serialOf('2026-01-01'), "$zone: 1 Jan 2026");
                $this->assertSame(46204, \App\Excel\ModelData::serialOf('2026-07-01'), "$zone: 1 Jul 2026 (summer time)");
                $this->assertSame(25569, \App\Excel\ModelData::serialOf('1970-01-01'), "$zone: 1 Jan 1970");
            }
        } finally {
            date_default_timezone_set($before);
        }
    }

    /** An existing company is already operating: its operation start IS the study start (a formula, not an input). A new company may start later. */
    public function test_operation_start_is_locked_for_an_existing_company_and_open_for_a_new_one(): void
    {
        $cell = function (int $fixture): string {
            $parts = $this->parts(ModelBuilder::build($this->fixture($fixture), 'en')->wb->toString());
            preg_match('/<c r="B9"[^>]*>(.*?)<\/c>/s', $parts['xl/worksheets/sheet3.xml'], $m);

            return $m[0] ?? '';
        };

        $this->assertStringContainsString('<f>Z_Start</f>', $cell(4), 'existing company: operation start = study start');
        $this->assertStringNotContainsString('<f>', $cell(1), 'new company: operation start is an input');
    }

    /** @return array<string,array{0:int,1:string}> */
    public static function cases(): array
    {
        $c = [];
        foreach ([1, 2, 3, 4] as $n) {
            foreach (['en', 'ar'] as $lang) {
                $c["fixture $n $lang"] = [$n, $lang];
            }
        }

        return $c;
    }

    #[DataProvider('cases')]
    public function test_the_workbook_builds_and_is_sound(int $n, string $lang): void
    {
        $ctx = ModelBuilder::build($this->fixture($n), $lang);
        $parts = $this->parts($ctx->wb->toString());
        $book = $parts['xl/workbook.xml'];

        // sheet names: at most 31 characters and no two the same
        preg_match_all('/<sheet name="([^"]*)"/u', $book, $m);
        $names = array_map(fn ($s) => html_entity_decode($s, ENT_QUOTES | ENT_XML1), $m[1]);
        $this->assertGreaterThan(10, count($names));
        $this->assertSame(count($names), count(array_unique(array_map('mb_strtolower', $names))), 'sheet names are unique');
        foreach ($names as $s) {
            $this->assertLessThanOrEqual(31, mb_strlen($s));
        }

        // the cover comes first and carries the brand
        $this->assertStringContainsString('fullCalcOnLoad="1"', $book);
        $sheets = array_filter(array_keys($parts), fn ($k) => str_starts_with($k, 'xl/worksheets/sheet'));
        $this->assertCount(count($names), $sheets);
        $this->assertStringContainsString('ZAVERO', $parts['xl/worksheets/sheet1.xml']);
        $this->assertStringNotContainsString('<sheetProtection', $parts['xl/worksheets/sheet1.xml'], 'the cover is open');

        // protected, never with a password; Arabic sheets run right to left
        $all = '';
        foreach ($sheets as $k) {
            $all .= $parts[$k];
        }
        $this->assertStringContainsString('<sheetProtection', $all);
        $this->assertStringNotContainsString('password=', strtolower($all));
        $this->assertStringNotContainsString('hashValue', $all);
        $this->assertSame($lang === 'ar', str_contains($all, 'rightToLeft="1"'));
        $this->assertStringContainsString('<protection locked="0"/>', $parts['xl/styles.xml'], 'input cells are open');

        // no PHP left over inside a formula, and every name used is defined
        preg_match_all('/<f>(.*?)<\/f>/su', $all, $f);
        $this->assertGreaterThan(1000, count($f[1]));
        $defined = [];
        preg_match_all('/<definedName name="([^"]+)"/', $book, $d);
        foreach ($d[1] as $name) {
            $defined[$name] = true;
        }
        foreach ($f[1] as $formula) {
            $formula = html_entity_decode($formula, ENT_QUOTES | ENT_XML1);
            $this->assertDoesNotMatchRegularExpression('/\{\$|\$[a-z]\w*\(|\$[a-z]+\s*[-+*\/]/', $formula, $formula);
            $this->assertStringNotContainsString('Array', $formula);
            preg_match_all('/\bZ_[A-Za-z0-9_]+/', $formula, $z);
            foreach ($z[0] as $name) {
                $this->assertArrayHasKey($name, $defined, "$name is used but not defined");
            }
        }
    }

    public function test_a_new_company_has_no_opening_sheet_and_an_existing_one_has(): void
    {
        $new = ModelBuilder::build($this->fixture(1), 'en');
        $old = ModelBuilder::build($this->fixture(4), 'en');

        $this->assertArrayNotHasKey('ob', $new->sheets);
        $this->assertArrayHasKey('ob', $old->sheets);
    }

    public function test_the_arabic_workbook_uses_arabic_text(): void
    {
        $parts = $this->parts(ModelBuilder::build($this->fixture(1), 'ar')->wb->toString());
        $cover = $parts['xl/worksheets/sheet1.xml'];

        $this->assertMatchesRegularExpression('/\p{Arabic}/u', $cover);
    }
}

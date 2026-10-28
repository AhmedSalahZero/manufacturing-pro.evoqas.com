<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Checks the design system CSS files (no database, no browser).
 * Run:  php artisan test --filter=DesignSystemCssTest
 */
class DesignSystemCssTest extends TestCase
{
    private const PARTS = [
        'tokens', 'base', 'helpers', 'layout', 'navigation', 'controls', 'tables',
        'cards', 'charts', 'widgets', 'dialogs', 'auth', 'rtl', 'print', 'utilities',
    ];

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 2);
    }

    private function css(string $part): string
    {
        $file = "{$this->root}/resources/css/design/{$part}.css";
        $this->assertFileExists($file, "Missing CSS file: {$part}.css");

        return file_get_contents($file);
    }

    /** Remove /* comments *\/ so the checks only look at real CSS. */
    private function strip(string $css): string
    {
        return preg_replace('~/\*.*?\*/~s', '', $css);
    }

    public function test_every_design_file_exists_and_is_imported_by_app_css(): void
    {
        $app = file_get_contents("{$this->root}/resources/css/app.css");
        $this->assertStringContainsString('@import "tailwindcss";', $app);

        foreach (self::PARTS as $part) {
            $this->assertNotSame('', trim($this->css($part)), "{$part}.css is empty");
            $this->assertStringContainsString("@import \"./design/{$part}.css\";", $app, "app.css does not import {$part}.css");
        }
    }

    public function test_braces_are_balanced_in_every_file(): void
    {
        foreach (self::PARTS as $part) {
            $css = $this->strip($this->css($part));
            $css = preg_replace('~url\([^)]*\)~', '', $css);
            $this->assertSame(substr_count($css, '{'), substr_count($css, '}'), "Unbalanced braces in {$part}.css");
        }
    }

    public function test_no_important_flags_anywhere(): void
    {
        foreach (self::PARTS as $part) {
            $this->assertStringNotContainsString('!important', $this->strip($this->css($part)), "{$part}.css uses !important");
        }
    }

    /** Left/right words break Arabic. Use start/end instead (or mark the line /* rtl-ok *\/). */
    public function test_no_left_right_properties_outside_rtl_file(): void
    {
        $bad = '/(margin|padding|border)-(left|right)\s*:|text-align\s*:\s*(left|right)\b|(^|[;{\s])(left|right)\s*:\s*[-\d]|float\s*:\s*(left|right)/';

        foreach (self::PARTS as $part) {
            if (in_array($part, ['rtl', 'fonts'], true)) {
                continue;
            }
            foreach (explode("\n", $this->css($part)) as $i => $line) {
                if (str_contains($line, 'rtl-ok')) {
                    continue;
                }
                $code = $this->strip($line);
                $this->assertSame(0, preg_match($bad, $code), "{$part}.css line " . ($i + 1) . ' uses a left/right property: ' . trim($code));
            }
        }
    }

    public function test_light_tokens_exist(): void
    {
        $tokens = $this->css('tokens');
        $required = [
            '--bg', '--card', '--ink', '--mute', '--line', '--blue', '--blue-soft', '--green', '--amber', '--red',
            '--input', '--input-ink', '--pos', '--neg', '--k1', '--k6', '--c1', '--c8', '--font', '--r-sm', '--r-md', '--r-lg',
            '--s1', '--s6', '--topH', '--sec',
        ];
        foreach ($required as $name) {
            $this->assertMatchesRegularExpression('/' . preg_quote($name, '/') . '\s*:/', $tokens, "Token {$name} is missing");
        }
    }

    /** Pull "--name: value;" pairs out of a block of CSS. */
    private function vars(string $block): array
    {
        preg_match_all('/(--[a-z0-9-]+)\s*:\s*((?:url\([^)]*\)|[^;])+);/i', $block, $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as $row) {
            $out[$row[1]] = trim($row[2]);
        }

        return $out;
    }

    private function lightTokens(): array
    {
        $css = $this->strip($this->css('tokens'));
        $this->assertSame(1, preg_match('/@layer base \{\s*:root \{(.*?)\n  \}/s', $css, $m), 'Light colour block not found');

        return $this->vars($m[1]);
    }

    private function darkBlocks(): array
    {
        $css = $this->strip($this->css('tokens'));
        $this->assertSame(1, preg_match('/:root:not\(\[data-theme="light"\]\)\s*\{(.*?)\n    \}/s', $css, $media), 'Device-dark block not found');
        $this->assertSame(1, preg_match('/:root\[data-theme="dark"\]\s*\{(.*?)\n  \}/s', $css, $explicit), 'Chosen-dark block not found');

        return [$this->vars($media[1]), $this->vars($explicit[1])];
    }

    public function test_the_two_dark_mode_copies_are_identical(): void
    {
        [$media, $explicit] = $this->darkBlocks();
        $this->assertGreaterThan(40, count($explicit));
        $this->assertSame($explicit, $media, 'The two dark-mode colour lists have drifted apart - update both.');
    }

    public function test_dark_mode_redefines_every_colour_token(): void
    {
        $light = $this->lightTokens();
        [, $dark] = $this->darkBlocks();
        foreach (['--bg', '--card', '--ink', '--mute', '--line', '--blue', '--green', '--amber', '--red', '--input', '--pos', '--neg', '--k1', '--k5', '--c1', '--c8'] as $name) {
            $this->assertArrayHasKey($name, $dark, "{$name} is not redefined for dark mode");
        }
        $this->assertNotSame($light['--bg'], $dark['--bg']);
        $this->assertNotSame($light['--ink'], $dark['--ink']);
        $this->assertSame('#F3F5F9', $light['--bg']);
    }

    public function test_every_variable_that_is_used_is_defined(): void
    {
        $defined = [];
        $used = [];
        foreach (self::PARTS as $part) {
            $css = $this->strip($this->css($part));
            preg_match_all('/(--[a-z0-9-]+)\s*:/i', $css, $d);
            preg_match_all('/var\(\s*(--[a-z0-9-]+)\s*([,)])/i', $css, $u, PREG_SET_ORDER);
            $defined = array_merge($defined, $d[1]);
            foreach ($u as $row) {
                $used[$row[1]] = $row[2] === ',' ? 'has-fallback' : 'no-fallback';
            }
        }
        $defined = array_flip($defined);
        $undefined = [];
        foreach ($used as $name => $kind) {
            if (! isset($defined[$name]) && $kind === 'no-fallback') {
                $undefined[] = $name;
            }
        }
        // --img (sign-in picture) and --twh/--edge are supplied per page; they have fallbacks or are defined above.
        $this->assertSame([], array_values(array_diff($undefined, ['--img'])), 'Variables used but never defined: ' . implode(', ', $undefined));
    }

    public function test_section_colours_exist(): void
    {
        $base = $this->css('base');
        foreach (['blue', 'violet', 'green', 'orange', 'cyan'] as $accent) {
            $this->assertStringContainsString("[data-accent=\"{$accent}\"]", $base, "Accent {$accent} missing");
        }
    }

    public function test_arabic_support_is_present(): void
    {
        $rtl = $this->css('rtl');
        $this->assertStringContainsString('letter-spacing:0', $rtl, 'Arabic needs letter-spacing: 0');
        $this->assertStringContainsString('direction:ltr', $rtl, 'Numbers must stay left-to-right inside Arabic pages');
        $this->assertStringContainsString('--edge:-1px', $rtl);
        $this->assertStringContainsString('IBM Plex Sans Arabic', $this->css('tokens'));
    }

    public function test_the_fonts_come_with_the_app_for_offline_use(): void
    {
        $app = file_get_contents("{$this->root}/resources/css/app.css");
        $package = json_decode(file_get_contents("{$this->root}/package.json"), true);

        foreach (['@fontsource/ibm-plex-sans', '@fontsource/ibm-plex-sans-arabic'] as $font) {
            $this->assertArrayHasKey($font, $package['dependencies'], "{$font} is not in package.json");
            $this->assertStringContainsString("@import \"{$font}/", $app, "app.css does not load {$font}");
        }
        foreach (['400', '500', '600', '700'] as $weight) {
            $this->assertStringContainsString("@fontsource/ibm-plex-sans/latin-{$weight}.css", $app);
            $this->assertStringContainsString("@fontsource/ibm-plex-sans-arabic/arabic-{$weight}.css", $app);
        }
        // No call to Google or any other site.
        $this->assertStringNotContainsString('fonts.googleapis', $app);
    }

    // ---- readability: text colours against the surface they sit on (WCAG AA = 4.5) ----

    private function luminance(string $hex): float
    {
        $hex = ltrim($hex, '#');
        $c = array_map(fn ($v) => hexdec($v) / 255, str_split($hex, 2));
        $c = array_map(fn ($v) => $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4, $c);

        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    }

    private function ratio(string $a, string $b): float
    {
        [$l1, $l2] = [$this->luminance($a), $this->luminance($b)];

        return (max($l1, $l2) + 0.05) / (min($l1, $l2) + 0.05);
    }

    public function test_text_colours_are_readable_in_light_and_dark(): void
    {
        $light = $this->lightTokens();
        [, $dark] = $this->darkBlocks();
        $pairs = [
            ['--ink', '--card'], ['--ink', '--bg'], ['--mute', '--card'], ['--mute', '--bg'],
            ['--blue-ink', '--blue-soft'], ['--green-ink', '--green-soft'], ['--amber-ink', '--amber-soft'],
            ['--red-ink', '--red-soft'], ['--pos', '--card'], ['--neg', '--card'], ['--input-ink', '--input'],
        ];
        foreach (['light' => $light, 'dark' => $dark] as $mode => $set) {
            foreach ($pairs as [$fg, $bg]) {
                $ratio = $this->ratio($set[$fg], $set[$bg]);
                $this->assertGreaterThanOrEqual(4.5, $ratio, sprintf('%s mode: %s on %s is hard to read (%.2f:1)', $mode, $fg, $bg, $ratio));
            }
        }
    }
}

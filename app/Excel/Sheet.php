<?php

namespace App\Excel;

// One worksheet. Cells are addressed by 1-based row and column. A value is a number, a string (stored inline) or a bool;
// a formula is written without the leading "=" and is recalculated when the workbook opens.
final class Sheet
{
    /** @var array<int,array<int,array{v:mixed,f:?string,s:int}>> */
    private array $cells = [];

    /** @var array<int,float> */
    private array $widths = [];

    /** @var array<int,float> */
    private array $heights = [];

    /** @var list<string> */
    private array $merges = [];

    /** @var list<array{ref:string,target:string}> */
    private array $links = [];

    /** @var list<array{ref:string,xml:string}> */
    private array $validations = [];

    /** @var list<array{ref:string,xml:string}> */
    private array $conditionals = [];

    private ?string $freeze = null;

    private bool $protect = true;

    private ?string $tab = null;

    private bool $gridlines = false;

    private int $zoom = 90;

    public function __construct(public readonly string $name, public readonly bool $rtl = false)
    {
    }

    public static function col(int $n): string
    {
        $s = '';
        while ($n > 0) {
            $n--;
            $s = chr(65 + $n % 26).$s;
            $n = intdiv($n, 26);
        }

        return $s;
    }

    public static function ref(int $row, int $col, bool $abs = false): string
    {
        return $abs ? '$'.self::col($col).'$'.$row : self::col($col).$row;
    }

    /** Reference to a cell of this sheet from another sheet. */
    public function xref(int $row, int $col, bool $abs = true): string
    {
        return "'".str_replace("'", "''", $this->name)."'!".self::ref($row, $col, $abs);
    }

    public function set(int $row, int $col, mixed $value, int $style = 0): self
    {
        $this->cells[$row][$col] = ['v' => $value, 'f' => null, 's' => $style];

        return $this;
    }

    public function formula(int $row, int $col, string $formula, int $style = 0): self
    {
        $this->cells[$row][$col] = ['v' => null, 'f' => ltrim($formula, '='), 's' => $style];

        return $this;
    }

    public function style(int $row, int $col, int $style): self
    {
        $this->cells[$row][$col] = ($this->cells[$row][$col] ?? ['v' => null, 'f' => null]) + ['s' => $style];
        $this->cells[$row][$col]['s'] = $style;

        return $this;
    }

    public function width(int $col, float $w): self
    {
        $this->widths[$col] = $w;

        return $this;
    }

    public function height(int $row, float $h): self
    {
        $this->heights[$row] = $h;

        return $this;
    }

    public function merge(int $r1, int $c1, int $r2, int $c2): self
    {
        $this->merges[] = self::ref($r1, $c1).':'.self::ref($r2, $c2);

        return $this;
    }

    /** Link a cell to a place in this workbook, e.g. "'Cover'!A1". */
    public function link(int $row, int $col, string $location): self
    {
        $this->links[] = ['ref' => self::ref($row, $col), 'target' => $location];

        return $this;
    }

    public function freeze(int $row, int $col): self
    {
        $this->freeze = self::ref($row, $col);

        return $this;
    }

    public function protect(bool $on): self
    {
        $this->protect = $on;

        return $this;
    }

    public function tab(string $argb): self
    {
        $this->tab = $argb;

        return $this;
    }

    public function gridlines(bool $on): self
    {
        $this->gridlines = $on;

        return $this;
    }

    /** @param list<string> $items */
    public function list(string $range, array $items): self
    {
        $this->validations[] = ['ref' => $range, 'xml' => '<dataValidation type="list" allowBlank="1" showErrorMessage="1" sqref="'.$range.'"><formula1>"'.htmlspecialchars(implode(',', $items), ENT_QUOTES | ENT_XML1).'"</formula1></dataValidation>'];

        return $this;
    }

    public function decimal(string $range, float $min, ?float $max = null): self
    {
        $this->validations[] = ['ref' => $range, 'xml' => '<dataValidation type="decimal" operator="'.($max === null ? 'greaterThanOrEqual' : 'between').'" allowBlank="1" showErrorMessage="1" sqref="'.$range.'"><formula1>'.$min.'</formula1>'.($max === null ? '' : '<formula2>'.$max.'</formula2>').'</dataValidation>'];

        return $this;
    }

    /** Colour a range red when the formula is true (dxf 0) or green (dxf 1). $formula is relative to the first cell of the range. */
    public function conditional(string $range, string $formula, int $dxf): self
    {
        $this->conditionals[] = ['ref' => $range, 'xml' => '<cfRule type="expression" dxfId="'.$dxf.'" priority="'.(count($this->conditionals) + 1).'"><formula>'.htmlspecialchars($formula, ENT_XML1).'</formula></cfRule>'];

        return $this;
    }

    public function maxRow(): int
    {
        return $this->cells ? max(array_keys($this->cells)) : 1;
    }

    public function xml(): string
    {
        $out = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n".'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
        $out .= '<sheetPr>'.($this->tab ? '<tabColor rgb="'.$this->tab.'"/>' : '').'</sheetPr>';

        $maxCol = 1;
        foreach ($this->cells as $cols) {
            $maxCol = max($maxCol, max(array_keys($cols)));
        }
        $out .= '<dimension ref="A1:'.self::ref($this->maxRow(), $maxCol).'"/>';

        $out .= '<sheetViews><sheetView workbookViewId="0"'.($this->rtl ? ' rightToLeft="1"' : '').($this->gridlines ? '' : ' showGridLines="0"').' zoomScale="'.$this->zoom.'" zoomScaleNormal="'.$this->zoom.'">';
        if ($this->freeze) {
            preg_match('/^([A-Z]+)(\d+)$/', $this->freeze, $m);
            $c = 0;
            foreach (str_split($m[1]) as $ch) {
                $c = $c * 26 + ord($ch) - 64;
            }
            $xs = $c - 1;
            $ys = (int) $m[2] - 1;
            $pane = ($xs && $ys) ? 'bottomRight' : ($ys ? 'bottomLeft' : 'topRight');
            $out .= '<pane'.($xs ? ' xSplit="'.$xs.'"' : '').($ys ? ' ySplit="'.$ys.'"' : '').' topLeftCell="'.$this->freeze.'" activePane="'.$pane.'" state="frozen"/><selection pane="'.$pane.'"/>';
        }
        $out .= '</sheetView></sheetViews><sheetFormatPr defaultRowHeight="15"/>';

        if ($this->widths) {
            ksort($this->widths);
            $out .= '<cols>';
            foreach ($this->widths as $c => $w) {
                $out .= '<col min="'.$c.'" max="'.$c.'" width="'.$w.'" customWidth="1"/>';
            }
            $out .= '</cols>';
        }

        $out .= '<sheetData>';
        $rows = array_unique(array_merge(array_keys($this->cells), array_keys($this->heights)));
        sort($rows);
        foreach ($rows as $r) {
            $out .= '<row r="'.$r.'"'.(isset($this->heights[$r]) ? ' ht="'.$this->heights[$r].'" customHeight="1"' : '').'>';
            $cols = $this->cells[$r] ?? [];
            ksort($cols);
            foreach ($cols as $c => $cell) {
                $out .= $this->cellXml($r, $c, $cell);
            }
            $out .= '</row>';
        }
        $out .= '</sheetData>';

        if ($this->protect) {
            $out .= '<sheetProtection sheet="1" objects="1" scenarios="1" formatColumns="0" formatRows="0"/>';
        }
        if ($this->merges) {
            $out .= '<mergeCells count="'.count($this->merges).'">'.implode('', array_map(fn ($m) => '<mergeCell ref="'.$m.'"/>', $this->merges)).'</mergeCells>';
        }
        foreach ($this->conditionals as $cf) {
            $out .= '<conditionalFormatting sqref="'.$cf['ref'].'">'.$cf['xml'].'</conditionalFormatting>';
        }
        if ($this->validations) {
            $out .= '<dataValidations count="'.count($this->validations).'">'.implode('', array_map(fn ($v) => $v['xml'], $this->validations)).'</dataValidations>';
        }
        if ($this->links) {
            $out .= '<hyperlinks>'.implode('', array_map(fn ($l) => '<hyperlink ref="'.$l['ref'].'" location="'.htmlspecialchars($l['target'], ENT_QUOTES | ENT_XML1).'" display="'.$l['ref'].'"/>', $this->links)).'</hyperlinks>';
        }
        $out .= '<pageMargins left="0.5" right="0.5" top="0.6" bottom="0.6" header="0.3" footer="0.3"/>';
        $out .= '<pageSetup orientation="landscape" fitToHeight="0"/>';

        return $out.'</worksheet>';
    }

    private static function num(float $v): string
    {
        $s = sprintf('%.15g', $v);

        return (str_contains($s, '.') && ! str_contains($s, 'e')) ? rtrim(rtrim($s, '0'), '.') : $s;
    }

    /** @param array{v:mixed,f:?string,s:int} $cell */
    private function cellXml(int $r, int $c, array $cell): string
    {
        $ref = self::ref($r, $c);
        $s = $cell['s'] ? ' s="'.$cell['s'].'"' : '';

        if ($cell['f'] !== null) {
            return '<c r="'.$ref.'"'.$s.'><f>'.htmlspecialchars($cell['f'], ENT_XML1).'</f></c>';
        }
        $v = $cell['v'];
        if ($v === null || $v === '') {
            return '<c r="'.$ref.'"'.$s.'/>';
        }
        if (is_bool($v)) {
            return '<c r="'.$ref.'"'.$s.' t="b"><v>'.($v ? 1 : 0).'</v></c>';
        }
        if (is_int($v) || is_float($v)) {
            return '<c r="'.$ref.'"'.$s.'><v>'.(is_float($v) ? self::num($v) : $v).'</v></c>';
        }

        return '<c r="'.$ref.'"'.$s.' t="inlineStr"><is><t xml:space="preserve">'.htmlspecialchars((string) $v, ENT_XML1 | ENT_SUBSTITUTE).'</t></is></c>';
    }
}

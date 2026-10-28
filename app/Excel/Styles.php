<?php

namespace App\Excel;

// Cell styles of a workbook. A style is described by a small array and turned into an index (the same description always gives
// the same index):
//   font:  ['b'=>true,'i'=>true,'sz'=>11,'color'=>'FF1F4E9E','name'=>'Calibri']
//   fill:  'FFFFF2CC' (ARGB)
//   border:'thin' (all sides) | 'bottom' | 'top' | 'topbottom' | 'none'    border_color: 'FFBFBFBF'
//   fmt:   a number format such as '#,##0.00' or '0.0%'
//   h / v: horizontal / vertical alignment, wrap: true, indent: n
//   locked: false for a cell the person may edit (the sheets are protected without a password)
final class Styles
{
    private const BUILTIN = ['General' => 0, '0' => 1, '0.00' => 2, '#,##0' => 3, '#,##0.00' => 4, '0%' => 9, '0.00%' => 10];

    /** @var list<array<string,mixed>> */
    private array $specs = [];

    /** @var array<string,int> */
    private array $index = [];

    public function __construct()
    {
        $this->id([]);   // style 0 = the default
    }

    /** @param array<string,mixed> $spec */
    public function id(array $spec): int
    {
        ksort($spec);
        $key = json_encode($spec);

        if (! isset($this->index[$key])) {
            $this->index[$key] = count($this->specs);
            $this->specs[] = $spec;
        }

        return $this->index[$key];
    }

    public function xml(): string
    {
        $fonts = [];
        $fills = ['<fill><patternFill patternType="none"/></fill>', '<fill><patternFill patternType="gray125"/></fill>'];
        $borders = [];
        $numFmts = [];
        $fontIndex = $fillIndex = $borderIndex = [];
        $xfs = [];

        $addUnique = function (array &$list, array &$map, string $xml): int {
            if (! isset($map[$xml])) {
                $map[$xml] = count($list);
                $list[] = $xml;
            }

            return $map[$xml];
        };
        // fonts[0] and borders[0] are the defaults
        $addUnique($fonts, $fontIndex, $this->fontXml([]));
        $addUnique($borders, $borderIndex, $this->borderXml('none', null));
        $fillIndex['<fill><patternFill patternType="none"/></fill>'] = 0;
        $fillIndex['<fill><patternFill patternType="gray125"/></fill>'] = 1;

        foreach ($this->specs as $spec) {
            $fontId = $addUnique($fonts, $fontIndex, $this->fontXml($spec['font'] ?? []));
            $fillId = 0;
            if (isset($spec['fill'])) {
                $fillId = $addUnique($fills, $fillIndex, '<fill><patternFill patternType="solid"><fgColor rgb="'.$spec['fill'].'"/><bgColor indexed="64"/></patternFill></fill>');
            }
            $borderId = $addUnique($borders, $borderIndex, $this->borderXml($spec['border'] ?? 'none', $spec['border_color'] ?? null));

            $fmt = $spec['fmt'] ?? 'General';
            if (isset(self::BUILTIN[$fmt])) {
                $numFmtId = self::BUILTIN[$fmt];
            } else {
                if (! isset($numFmts[$fmt])) {
                    $numFmts[$fmt] = 164 + count($numFmts);
                }
                $numFmtId = $numFmts[$fmt];
            }

            $align = '';
            if (isset($spec['h']) || isset($spec['v']) || ! empty($spec['wrap']) || isset($spec['indent'])) {
                $align = '<alignment'.(isset($spec['h']) ? ' horizontal="'.$spec['h'].'"' : '').(isset($spec['v']) ? ' vertical="'.$spec['v'].'"' : '')
                    .(! empty($spec['wrap']) ? ' wrapText="1"' : '').(isset($spec['indent']) ? ' indent="'.(int) $spec['indent'].'"' : '').'/>';
            }
            $locked = ($spec['locked'] ?? true) === false;
            $protection = $locked ? '<protection locked="0"/>' : '';

            $xfs[] = '<xf numFmtId="'.$numFmtId.'" fontId="'.$fontId.'" fillId="'.$fillId.'" borderId="'.$borderId.'" xfId="0"'
                .($numFmtId ? ' applyNumberFormat="1"' : '').($fontId ? ' applyFont="1"' : '').($fillId ? ' applyFill="1"' : '').($borderId ? ' applyBorder="1"' : '')
                .($align ? ' applyAlignment="1"' : '').($locked ? ' applyProtection="1"' : '')
                .($align || $protection ? '>'.$align.$protection.'</xf>' : '/>');
        }

        $out = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n".'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
        if ($numFmts) {
            $out .= '<numFmts count="'.count($numFmts).'">';
            foreach ($numFmts as $code => $id) {
                $out .= '<numFmt numFmtId="'.$id.'" formatCode="'.htmlspecialchars($code, ENT_QUOTES | ENT_XML1).'"/>';
            }
            $out .= '</numFmts>';
        }
        $out .= '<fonts count="'.count($fonts).'">'.implode('', $fonts).'</fonts>';
        $out .= '<fills count="'.count($fills).'">'.implode('', $fills).'</fills>';
        $out .= '<borders count="'.count($borders).'">'.implode('', $borders).'</borders>';
        $out .= '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>';
        $out .= '<cellXfs count="'.count($xfs).'">'.implode('', $xfs).'</cellXfs>';
        $out .= '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>';
        $out .= '<dxfs count="2">'
            .'<dxf><font><color rgb="FF9C0006"/></font><fill><patternFill><bgColor rgb="FFFFC7CE"/></patternFill></fill></dxf>'
            .'<dxf><font><color rgb="FF006100"/></font><fill><patternFill><bgColor rgb="FFC6EFCE"/></patternFill></fill></dxf>'
            .'</dxfs><tableStyles count="0" defaultTableStyle="TableStyleMedium2" defaultPivotStyle="PivotStyleLight16"/></styleSheet>';

        return $out;
    }

    /** @param array<string,mixed> $f */
    private function fontXml(array $f): string
    {
        return '<font>'.(! empty($f['b']) ? '<b/>' : '').(! empty($f['i']) ? '<i/>' : '').'<sz val="'.($f['sz'] ?? 11).'"/>'
            .'<color rgb="'.($f['color'] ?? 'FF000000').'"/><name val="'.($f['name'] ?? 'Calibri').'"/><family val="2"/></font>';
    }

    private function borderXml(string $kind, ?string $color): string
    {
        $c = '<color rgb="'.($color ?? 'FFBFBFBF').'"/>';
        $side = fn (string $name, bool $on) => $on ? '<'.$name.' style="thin">'.$c.'</'.$name.'>' : '<'.$name.'/>';

        return '<border>'.$side('left', $kind === 'thin').$side('right', $kind === 'thin').$side('top', in_array($kind, ['thin', 'top', 'topbottom'], true))
            .$side('bottom', in_array($kind, ['thin', 'bottom', 'topbottom'], true)).'<diagonal/></border>';
    }
}

<?php

namespace App\Excel;

// An .xlsx workbook: sheets, styles, named cells. toString() returns the file contents.
final class Workbook
{
    public readonly Styles $styles;

    /** @var list<Sheet> */
    private array $sheets = [];

    /** @var array<string,string> */
    private array $names = [];

    public function __construct(private readonly string $title = 'ZAVERO', private readonly string $creator = 'ZAVERO')
    {
        $this->styles = new Styles;
    }

    public function sheet(string $name, bool $rtl = false): Sheet
    {
        $name = self::safeName($name);
        $sheet = new Sheet($name, $rtl);
        $this->sheets[] = $sheet;

        return $sheet;
    }

    /** Puts the sheets in the given order (objects); sheets not listed keep their order after the listed ones. @param list<Sheet> $first */
    public function order(array $first): void
    {
        $rest = array_values(array_filter($this->sheets, fn (Sheet $s) => ! in_array($s, $first, true)));
        $this->sheets = array_merge(array_values($first), $rest);
    }

    /** Sheet names: at most 31 characters, none of  \ / ? * [ ] : */
    public static function safeName(string $name): string
    {
        return mb_substr(trim(str_replace(['\\', '/', '?', '*', '[', ']', ':'], ' ', $name)), 0, 31);
    }

    public function defineName(string $name, string $reference): void
    {
        $this->names[$name] = $reference;
    }

    public function toString(): string
    {
        $zip = new ZipWriter;
        $n = count($this->sheets);

        $types = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n".'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            .'<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>';
        for ($i = 1; $i <= $n; $i++) {
            $types .= '<Override PartName="/xl/worksheets/sheet'.$i.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        $zip->add('[Content_Types].xml', $types.'</Types>');

        $zip->add('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n".'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            .'<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/></Relationships>');

        $now = gmdate('Y-m-d\TH:i:s\Z');
        $zip->add('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n".'<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            .'<dc:title>'.htmlspecialchars($this->title, ENT_XML1).'</dc:title><dc:creator>'.htmlspecialchars($this->creator, ENT_XML1).'</dc:creator>'
            .'<dcterms:created xsi:type="dcterms:W3CDTF">'.$now.'</dcterms:created></cp:coreProperties>');
        $zip->add('docProps/app.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n".'<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"><Application>ZAVERO Manufacturing Pro</Application></Properties>');

        $wb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n".'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<workbookPr defaultThemeVersion="124226"/><bookViews><workbookView xWindow="0" yWindow="0" windowWidth="28800" windowHeight="15000" activeTab="0"/></bookViews><sheets>';
        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n".'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        foreach ($this->sheets as $i => $sheet) {
            $id = $i + 1;
            $wb .= '<sheet name="'.htmlspecialchars($sheet->name, ENT_QUOTES | ENT_XML1).'" sheetId="'.$id.'" r:id="rId'.$id.'"/>';
            $rels .= '<Relationship Id="rId'.$id.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$id.'.xml"/>';
            $zip->add('xl/worksheets/sheet'.$id.'.xml', $sheet->xml());
        }
        $rels .= '<Relationship Id="rId'.($n + 1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
        $wb .= '</sheets>';
        if ($this->names) {
            $wb .= '<definedNames>';
            ksort($this->names);
            foreach ($this->names as $name => $ref) {
                $wb .= '<definedName name="'.$name.'">'.htmlspecialchars($ref, ENT_XML1).'</definedName>';
            }
            $wb .= '</definedNames>';
        }
        $wb .= '<calcPr calcId="191029" fullCalcOnLoad="1"/></workbook>';

        $zip->add('xl/workbook.xml', $wb);
        $zip->add('xl/_rels/workbook.xml.rels', $rels);
        $zip->add('xl/styles.xml', $this->styles->xml());

        return $zip->toString();
    }
}

<?php

namespace App\Support;

/** Écrit un document Word (.docx) à partir du document composé (ReportComposer), sans dépendance externe. Nécessite l'extension PHP zip. */
final class DocxWriter
{
    private const FOREST = '102F2B';
    private const GREY = '6E7D78';

    public static function write(string $path, array $document): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Impossible de créer le fichier Word.');
        }

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/><Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/><Override PartName="/word/footer1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml"/><Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/></Relationships>');
        $zip->addFromString('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:title>'.self::esc($document['title'] ?? 'Rapport').'</dc:title><dc:creator>DOBI</dc:creator><dc:language>fr-FR</dc:language><dcterms:created xsi:type="dcterms:W3CDTF">'.gmdate('Y-m-d\TH:i:s\Z').'</dcterms:created></cp:coreProperties>');
        $zip->addFromString('word/_rels/document.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/footer" Target="footer1.xml"/></Relationships>');
        $zip->addFromString('word/styles.xml', self::styles());
        $zip->addFromString('word/footer1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:ftr xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:p><w:pPr><w:jc w:val="center"/></w:pPr><w:r><w:rPr><w:color w:val="'.self::GREY.'"/><w:sz w:val="16"/></w:rPr><w:t xml:space="preserve">DOBI — page </w:t></w:r><w:r><w:rPr><w:color w:val="'.self::GREY.'"/><w:sz w:val="16"/></w:rPr><w:fldChar w:fldCharType="begin"/></w:r><w:r><w:rPr><w:color w:val="'.self::GREY.'"/><w:sz w:val="16"/></w:rPr><w:instrText xml:space="preserve"> PAGE </w:instrText></w:r><w:r><w:rPr><w:color w:val="'.self::GREY.'"/><w:sz w:val="16"/></w:rPr><w:fldChar w:fldCharType="separate"/></w:r><w:r><w:rPr><w:color w:val="'.self::GREY.'"/><w:sz w:val="16"/></w:rPr><w:t>1</w:t></w:r><w:r><w:rPr><w:color w:val="'.self::GREY.'"/><w:sz w:val="16"/></w:rPr><w:fldChar w:fldCharType="end"/></w:r></w:p></w:ftr>');
        $zip->addFromString('word/document.xml', self::body($document));
        $zip->close();
    }

    private static function body(array $document): string
    {
        $xml = '';
        foreach ($document['sections'] as $section) {
            if ($section['number'] !== null) {
                $xml .= self::para($section['number'].'. '.$section['title'], 'Heading1');
            }
            foreach ($section['blocks'] as $b) {
                $xml .= self::block($b);
            }
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><w:body>'
            .$xml
            .'<w:sectPr><w:footerReference w:type="default" r:id="rId2"/><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1134" w:right="1134" w:bottom="1134" w:left="1134" w:header="567" w:footer="567" w:gutter="0"/></w:sectPr></w:body></w:document>';
    }

    private static function block(array $b): string
    {
        switch ($b['t']) {
            case 'title':
                return self::para($b['text'], 'Title');
            case 'h3':
                return self::para($b['text'], 'Heading2');
            case 'p':
                return self::para($b['text']);
            case 'meta':
                return self::para($b['text'], null, '<w:i/><w:color w:val="'.self::GREY.'"/><w:sz w:val="18"/>');
            case 'missing':
                return self::para($b['text'], null, '<w:i/><w:b/><w:color w:val="B4531F"/>');
            case 'pagebreak':
                return '<w:p><w:r><w:br w:type="page"/></w:r></w:p>';
            case 'bullets':
                return implode('', array_map(fn ($t) => self::para('•  '.$t, null, '', '<w:ind w:left="360" w:hanging="260"/><w:spacing w:after="60"/>'), $b['items']));
            case 'warn':
                $shade = '<w:shd w:val="clear" w:color="auto" w:fill="FBE8DF"/>';
                $xml = self::para($b['title'], null, '<w:b/>', $shade.'<w:spacing w:before="120" w:after="0"/>');
                foreach ($b['items'] as $t) {
                    $xml .= self::para('•  '.$t, null, '', $shade.'<w:ind w:left="360" w:hanging="260"/><w:spacing w:after="0"/>');
                }

                return $xml.self::para('', null, '', '<w:spacing w:after="60"/>');
            case 'kv':
                $rows = array_map(fn ($r) => [[$r[0], true, null], [$r[1] ?? null, false, $r[1] === null || $r[1] === '' ? 'missing' : null]], $b['rows']);

                return self::table([], $rows, [3000, 6638]);
            case 'table':
                $n = max(count($b['head']), 1);
                $w = (int) floor(9638 / $n);

                return self::table($b['head'], array_map(fn ($row) => array_map(fn ($c) => [(string) $c, false, null], $row), $b['rows']), array_fill(0, $n, $w));
            case 'bars':
                $max = max(array_merge([1], array_column($b['rows'], 'count')));
                $rows = array_map(function ($r) use ($max) {
                    $bar = str_repeat('█', (int) round($r['count'] / $max * 24));
                    $pct = $r['percent'] === null ? '' : ' · '.ReportText::num($r['percent']).' %';

                    return [[$r['label'], false, null], [$bar === '' ? '·' : $bar, false, 'bar'], [$r['count'].$pct, false, null]];
                }, $b['rows']);

                return self::table([], $rows, [3200, 4400, 2038]);
        }

        return '';
    }

    /** @param array<int, array{0: ?string, 1: bool, 2: ?string}> $cells */
    private static function table(array $head, array $rows, array $widths): string
    {
        $border = '<w:tblBorders><w:top w:val="single" w:sz="4" w:color="C9D2CF"/><w:left w:val="single" w:sz="4" w:color="C9D2CF"/><w:bottom w:val="single" w:sz="4" w:color="C9D2CF"/><w:right w:val="single" w:sz="4" w:color="C9D2CF"/><w:insideH w:val="single" w:sz="4" w:color="C9D2CF"/><w:insideV w:val="single" w:sz="4" w:color="C9D2CF"/></w:tblBorders>';
        $xml = '<w:tbl><w:tblPr><w:tblW w:w="'.array_sum($widths).'" w:type="dxa"/>'.$border.'<w:tblLayout w:type="fixed"/><w:tblCellMar><w:left w:w="80" w:type="dxa"/><w:right w:w="80" w:type="dxa"/></w:tblCellMar></w:tblPr><w:tblGrid>'
            .implode('', array_map(fn ($w) => '<w:gridCol w:w="'.$w.'"/>', $widths)).'</w:tblGrid>';

        if ($head) {
            $xml .= '<w:tr><w:trPr><w:tblHeader/></w:trPr>';
            foreach (array_values($head) as $i => $h) {
                $xml .= self::cell($widths[$i] ?? $widths[0], (string) $h, '<w:b/><w:color w:val="FFFFFF"/>', self::FOREST);
            }
            $xml .= '</w:tr>';
        }
        foreach ($rows as $row) {
            $xml .= '<w:tr><w:trPr><w:cantSplit/></w:trPr>';
            foreach (array_values($row) as $i => [$text, $bold, $kind]) {
                $run = ($bold ? '<w:b/>' : '').($kind === 'missing' ? '<w:i/><w:b/><w:color w:val="B4531F"/>' : '').($kind === 'bar' ? '<w:color w:val="'.self::FOREST.'"/><w:sz w:val="16"/>' : '');
                $xml .= self::cell($widths[$i] ?? $widths[0], $kind === 'missing' ? 'À compléter' : (string) $text, $run, null);
            }
            $xml .= '</w:tr>';
        }

        return $xml.'</w:tbl>'.self::para('', null, '', '<w:spacing w:after="60"/>');
    }

    private static function cell(int $width, string $text, string $runProps, ?string $fill): string
    {
        $shade = $fill ? '<w:shd w:val="clear" w:color="auto" w:fill="'.$fill.'"/>' : '';

        return '<w:tc><w:tcPr><w:tcW w:w="'.$width.'" w:type="dxa"/>'.$shade.'</w:tcPr>'.self::para($text, null, $runProps, '<w:spacing w:before="40" w:after="40"/>').'</w:tc>';
    }

    private static function para(string $text, ?string $style = null, string $runProps = '', string $paraProps = ''): string
    {
        $ppr = ($style ? '<w:pStyle w:val="'.$style.'"/>' : '').$paraProps;
        $rpr = $runProps ? '<w:rPr>'.$runProps.'</w:rPr>' : '';
        $runs = '';
        foreach (preg_split('/\R/u', self::clean($text)) as $i => $line) {
            $runs .= '<w:r>'.$rpr.($i > 0 ? '<w:br/>' : '').'<w:t xml:space="preserve">'.self::esc($line).'</w:t></w:r>';
        }

        return '<w:p>'.($ppr !== '' ? '<w:pPr>'.$ppr.'</w:pPr>' : '').$runs.'</w:p>';
    }

    private static function styles(): string
    {
        $f = self::FOREST;

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            .'<w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri" w:cs="Calibri"/><w:sz w:val="22"/><w:lang w:val="fr-FR"/></w:rPr></w:rPrDefault><w:pPrDefault><w:pPr><w:spacing w:after="120" w:line="276" w:lineRule="auto"/></w:pPr></w:pPrDefault></w:docDefaults>'
            .'<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/></w:style>'
            .'<w:style w:type="paragraph" w:styleId="Title"><w:name w:val="Title"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:pPr><w:spacing w:before="1800" w:after="240"/></w:pPr><w:rPr><w:b/><w:color w:val="'.$f.'"/><w:sz w:val="56"/></w:rPr></w:style>'
            .'<w:style w:type="paragraph" w:styleId="Heading1"><w:name w:val="heading 1"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:pPr><w:keepNext/><w:spacing w:before="360" w:after="120"/><w:outlineLvl w:val="0"/></w:pPr><w:rPr><w:b/><w:color w:val="'.$f.'"/><w:sz w:val="32"/></w:rPr></w:style>'
            .'<w:style w:type="paragraph" w:styleId="Heading2"><w:name w:val="heading 2"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:pPr><w:keepNext/><w:spacing w:before="240" w:after="60"/><w:outlineLvl w:val="1"/></w:pPr><w:rPr><w:b/><w:color w:val="'.$f.'"/><w:sz w:val="24"/></w:rPr></w:style>'
            .'</w:styles>';
    }

    /** Retire les caractères interdits en XML 1.0. */
    private static function clean(string $text): string
    {
        return (string) preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text);
    }

    private static function esc(string $text): string
    {
        return htmlspecialchars(self::clean($text), ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}

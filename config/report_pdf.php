<?php
// A small PDF writer for the administrator's reports. It uses plain PHP and the PDF standard fonts, so there is nothing to install.
//
// The reports already build a list of rows for their CSV download. The PDF is made from THE SAME ROWS, so the two downloads can never
// disagree. A few hints in that list tell the PDF what is a table:
//   - the first row is the report title (any extra cells are the period)
//   - the text '@table' (a plain string, which the CSV download skips) means "the next row is the heading of a table; every following
//     row with the same number of cells is a line of that table"
//   - a row with one cell followed by '@table' is a section heading; any other single cell is a note
//   - an empty row is a gap; any other row is a "label: value" line
// Allergy notes are never in the rows, so they can never be in the PDF.

final class ReportPdf
{
    // Width of each character (32 to 126) in thousandths of the font size: Helvetica and Helvetica-Bold.
    private const REG  = [278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278,
                          556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556,
                          1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778,
                          667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556,
                          333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556,
                          556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584];
    private const BOLD = [278, 333, 474, 556, 556, 889, 722, 238, 333, 333, 389, 584, 278, 333, 278, 278,
                          556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 333, 333, 584, 584, 584, 611,
                          975, 722, 722, 722, 722, 667, 611, 778, 722, 278, 556, 722, 611, 833, 722, 778,
                          667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 333, 278, 333, 584, 556,
                          333, 556, 611, 556, 611, 556, 333, 611, 611, 278, 278, 556, 278, 889, 611, 611,
                          611, 611, 389, 556, 333, 611, 556, 778, 556, 556, 500, 389, 280, 389, 584];

    private array $pages = [];
    private string $buf = '';
    private float $pw;
    private float $ph;
    private float $m = 40.0;
    private float $y = 0.0;
    private string $title;
    private string $generated;
    private bool $first = true;

    public function __construct(string $title, bool $landscape, string $generatedBy, DateTimeImmutable $when)
    {
        $this->pw = $landscape ? 841.89 : 595.28;
        $this->ph = $landscape ? 595.28 : 841.89;
        $this->title = self::enc($title);
        $this->generated = self::enc('Generated ' . $when->format('j F Y, H:i') . ' by ' . $generatedBy);
        $this->startPage();
    }

    // UTF-8 text to the Windows-1252 bytes that the PDF fonts use. A letter the fonts do not have becomes "?".
    public static function enc(string $utf8): string
    {
        $out = @mb_convert_encoding($utf8, 'Windows-1252', 'UTF-8');
        return is_string($out) ? $out : preg_replace('/[^\x20-\x7E]/', '?', $utf8);
    }

    public static function width(string $s, float $size, bool $bold = false): float
    {
        $t = $bold ? self::BOLD : self::REG;
        $w = 0;
        for ($i = 0, $n = strlen($s); $i < $n; $i++) {
            $c = ord($s[$i]);
            $w += ($c >= 32 && $c <= 126) ? $t[$c - 32] : 556;
        }
        return $w * $size / 1000.0;
    }

    private static function lit(string $s): string      // a PDF string, with everything outside plain ASCII written safely
    {
        $o = '';
        for ($i = 0, $n = strlen($s); $i < $n; $i++) {
            $c = ord($s[$i]);
            if ($c === 40 || $c === 41 || $c === 92) { $o .= '\\' . $s[$i]; }
            elseif ($c >= 32 && $c <= 126)           { $o .= $s[$i]; }
            else                                     { $o .= sprintf('\\%03o', $c); }
        }
        return '(' . $o . ')';
    }

    private static function num(float $v): string { return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.') ?: '0'; }

    private function text(float $x, float $y, string $s, float $size, string $font = 'F1', array $rgb = [0, 0, 0]): void
    {
        if ($s === '') { return; }
        $this->buf .= sprintf("BT /%s %s Tf %s %s %s rg %s %s Td %s Tj ET\n", $font, self::num($size), self::num($rgb[0]), self::num($rgb[1]), self::num($rgb[2]),
            self::num($x), self::num($y), self::lit($s));
    }

    private function rect(float $x, float $y, float $w, float $h, array $fill): void
    {
        $this->buf .= sprintf("%s %s %s rg %s %s %s %s re f\n", self::num($fill[0]), self::num($fill[1]), self::num($fill[2]), self::num($x), self::num($y), self::num($w), self::num($h));
    }

    private function line(float $x1, float $y1, float $x2, float $y2, array $rgb, float $width = 0.5): void
    {
        $this->buf .= sprintf("%s w %s %s %s RG %s %s m %s %s l S\n", self::num($width), self::num($rgb[0]), self::num($rgb[1]), self::num($rgb[2]),
            self::num($x1), self::num($y1), self::num($x2), self::num($y2));
    }

    private function startPage(): void
    {
        if ($this->buf !== '') { $this->pages[] = $this->buf; }
        $this->buf = '';
        $navy = [0.12, 0.22, 0.39];
        if ($this->first) {
            $this->text($this->m, $this->ph - 52, 'Executive Grooming Studio', 18, 'F2', $navy);
            $this->text($this->m, $this->ph - 74, $this->title, 14, 'F2');
            $this->text($this->m, $this->ph - 90, $this->generated, 8.5, 'F1', [0.35, 0.35, 0.35]);
            $this->line($this->m, $this->ph - 98, $this->pw - $this->m, $this->ph - 98, $navy, 1.2);
            $this->y = $this->ph - 116;
            $this->first = false;
        } else {
            $this->text($this->m, $this->ph - 34, 'Executive Grooming Studio  |  ' . $this->title, 8.5, 'F1', [0.35, 0.35, 0.35]);
            $this->line($this->m, $this->ph - 40, $this->pw - $this->m, $this->ph - 40, [0.75, 0.75, 0.75], 0.5);
            $this->y = $this->ph - 58;
        }
    }

    private function ensure(float $need): bool      // starts a new page if there is not room; returns true when it did
    {
        if ($this->y - $need < 48) { $this->startPage(); return true; }
        return false;
    }

    public function gap(float $h = 8): void { $this->y -= $h; }

    public function note(string $t): void
    {
        $size = 8.5;
        foreach ($this->wrap(self::enc($t), $this->pw - 2 * $this->m, $size, false) as $ln) {
            $this->ensure($size * 1.4);
            $this->y -= $size * 1.3;
            $this->text($this->m, $this->y, $ln, $size, 'F3', [0.3, 0.3, 0.3]);
        }
        $this->y -= 4;
    }

    public function heading(string $t): void
    {
        $this->ensure(110);      // keep the heading together with the start of its table
        $this->y -= 10;
        $this->text($this->m, $this->y - 11, self::enc($t), 11.5, 'F2', [0.12, 0.22, 0.39]);
        $this->y -= 18;
    }

    public function kv(array $row): void             // "label: value" (two pairs when the row has four cells)
    {
        $size = 10;
        $cells = array_map(static fn ($c) => self::enc(trim((string) $c)), $row);
        $pairs = [];
        if (count($cells) === 3 && strcasecmp($cells[0], 'Period') === 0) { $pairs[] = [$cells[0], $cells[1] . ' to ' . $cells[2]]; }
        elseif (count($cells) === 4)                                      { $pairs[] = [$cells[0], $cells[1]]; $pairs[] = [$cells[2], $cells[3]]; }
        else                                                              { $pairs[] = [$cells[0], implode(', ', array_slice($cells, 1))]; }
        $this->ensure($size * 1.6);
        $this->y -= $size * 1.4;
        $x = $this->m;
        foreach ($pairs as [$label, $value]) {
            $label .= ':';
            $this->text($x, $this->y, $label, $size, 'F2');
            $lw = self::width($label . ' ', $size, true);
            $value = $value === '' ? '-' : $value;
            $this->text($x + $lw, $this->y, $value, $size, 'F1');
            $x += $lw + self::width($value, $size, false) + 26;
        }
        $this->y -= 2;
    }

    private function wrap(string $s, float $maxW, float $size, bool $bold): array
    {
        $lines = [];
        foreach (explode("\n", $s) as $para) {
            $cur = '';
            foreach (preg_split('/\s+/', trim($para), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $w) {
                while ($w !== '' && self::width($w, $size, $bold) > $maxW && strlen($w) > 1) {    // a word wider than the cell is cut
                    $i = strlen($w);
                    while ($i > 1 && self::width(substr($w, 0, $i), $size, $bold) > $maxW) { $i--; }
                    if ($cur !== '') { $lines[] = $cur; $cur = ''; }
                    $lines[] = substr($w, 0, $i);
                    $w = substr($w, $i);
                }
                if ($w === '') { continue; }
                $try = $cur === '' ? $w : $cur . ' ' . $w;
                if (self::width($try, $size, $bold) <= $maxW) { $cur = $try; } else { $lines[] = $cur; $cur = $w; }
            }
            $lines[] = $cur;
        }
        return $lines ?: [''];
    }

    private static function isNumber(string $s): bool { return $s !== '' && preg_match('/^[+-]?R?\d[\d,]*(\.\d+)?%?$/', $s) === 1; }

    public function table(array $head, array $rows): void
    {
        $cols = count($head);
        $size = $cols >= 8 ? 8.0 : 8.5;
        $padX = 4.0;
        $padY = 3.0;
        $avail = $this->pw - 2 * $this->m;
        $enc = static fn (array $r): array => array_map(static fn ($c) => self::enc(trim((string) $c)), array_pad(array_values($r), $cols, ''));
        $head = $enc($head);
        $rows = array_map($enc, $rows);

        // which columns hold numbers (right aligned)?
        $right = [];
        for ($c = 0; $c < $cols; $c++) {
            $n = $hit = 0;
            foreach ($rows as $r) { if ($r[$c] !== '') { $n++; $hit += self::isNumber($r[$c]) ? 1 : 0; } }
            $right[$c] = $n > 0 && $hit / $n >= 0.7;
        }
        // column widths: as wide as the longest text if it fits, otherwise wrap the long columns
        $nat = $min = [];
        for ($c = 0; $c < $cols; $c++) {
            $nat[$c] = self::width($head[$c], $size, true) + 2 * $padX;
            $min[$c] = 0.0;
            foreach (explode(' ', $head[$c]) as $w) { $min[$c] = max($min[$c], self::width($w, $size, true)); }
            foreach ($rows as $r) {
                $nat[$c] = max($nat[$c], self::width($r[$c], $size, false) + 2 * $padX);
                foreach (explode(' ', $r[$c]) as $w) { $min[$c] = max($min[$c], self::width($w, $size, false)); }
            }
            $min[$c] = min($min[$c] + 2 * $padX, $nat[$c]);
        }
        if (array_sum($nat) <= $avail) {
            $f = min($avail / array_sum($nat), 2.5);      // a small table is not stretched across the whole page
            $w = array_map(static fn ($v) => $v * $f, $nat);
        } elseif (array_sum($min) >= $avail) {
            $f = $avail / array_sum($min);
            $w = array_map(static fn ($v) => $v * $f, $min);
        } else {
            $extra = $avail - array_sum($min);
            $need = array_map(static fn ($c) => $nat[$c] - $min[$c], array_keys($nat));
            $sum = max(1.0, array_sum($need));
            $w = [];
            foreach ($nat as $c => $_) { $w[$c] = $min[$c] + $extra * $need[$c] / $sum; }
        }

        $drawRow = function (array $cells, bool $bold, array $fill, ?array $textRgb, bool $zebra, bool $top) use ($w, $cols, $size, $padX, $padY, $right): void {
            $lines = [];
            $maxLines = 1;
            for ($c = 0; $c < $cols; $c++) {
                $lines[$c] = $this->wrap($cells[$c], $w[$c] - 2 * $padX, $size, $bold);
                $maxLines = max($maxLines, count($lines[$c]));
            }
            $h = $maxLines * $size * 1.2 + 2 * $padY;
            if ($this->y - $h < 48) { $this->startPage(); ($this->drawHead)(); }
            $top0 = $this->y;
            if ($fill !== []) { $this->rect($this->m, $top0 - $h, array_sum($w), $h, $fill); }
            $x = $this->m;
            for ($c = 0; $c < $cols; $c++) {
                $ty = $top0 - $padY - $size;
                foreach ($lines[$c] as $ln) {
                    $tw = self::width($ln, $size, $bold);
                    $tx = $right[$c] ? $x + $w[$c] - $padX - $tw : $x + $padX;
                    $this->text($tx, $ty, $ln, $size, $bold ? 'F2' : 'F1', $textRgb ?? [0, 0, 0]);
                    $ty -= $size * 1.2;
                }
                $x += $w[$c];
            }
            $this->line($this->m, $top0 - $h, $this->m + array_sum($w), $top0 - $h, [0.78, 0.82, 0.88], 0.5);
            if ($top) { $this->line($this->m, $top0, $this->m + array_sum($w), $top0, [0.2, 0.2, 0.2], 0.8); }
            $this->y = $top0 - $h;
        };
        $this->drawHead = function () use ($head, $drawRow): void { $drawRow($head, true, [0.18, 0.37, 0.64], [1, 1, 1], false, false); };

        $this->ensure(70);
        ($this->drawHead)();
        if ($rows === []) { $this->note('None in this period.'); return; }
        $i = 0;
        foreach ($rows as $r) {
            $isTotal = preg_match('/^total/i', $r[0]) === 1;
            $drawRow($r, $isTotal, $isTotal ? [0.93, 0.95, 0.98] : ($i % 2 ? [0.95, 0.97, 0.99] : []), null, false, $isTotal);
            $i++;
        }
        $this->y -= 6;
    }

    private $drawHead;

    public function output(): string
    {
        if ($this->buf !== '') { $this->pages[] = $this->buf; $this->buf = ''; }
        $total = count($this->pages);
        foreach ($this->pages as $i => &$content) {
            $content .= sprintf("0.4 0.4 0.4 rg BT /F1 8 Tf %s 26 Td %s Tj ET\n", self::num($this->m), self::lit('Executive Grooming Studio'));
            $label = 'Page ' . ($i + 1) . ' of ' . $total;
            $content .= sprintf("0.4 0.4 0.4 rg BT /F1 8 Tf %s 26 Td %s Tj ET\n", self::num($this->pw - $this->m - self::width($label, 8)), self::lit($label));
        }
        unset($content);

        $objs = [];
        $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $kids = [];
        $nObj = 7;
        $pageObjs = [];
        foreach ($this->pages as $i => $content) {
            $pageNo = $nObj++; $contNo = $nObj++; $kids[] = $pageNo . ' 0 R';
            $data = function_exists('gzcompress') ? gzcompress($content, 6) : $content;
            $filter = function_exists('gzcompress') ? ' /Filter /FlateDecode' : '';
            $pageObjs[$pageNo] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %s %s] /Resources << /Font << /F1 3 0 R /F2 4 0 R /F3 5 0 R >> >> /Contents %d 0 R >>', self::num($this->pw), self::num($this->ph), $contNo);
            $pageObjs[$contNo] = "<< /Length " . strlen($data) . $filter . " >>\nstream\n" . $data . "\nendstream";
        }
        $objs[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        foreach ([3 => 'Helvetica', 4 => 'Helvetica-Bold', 5 => 'Helvetica-Oblique'] as $n => $f) {
            $objs[$n] = '<< /Type /Font /Subtype /Type1 /BaseFont /' . $f . ' /Encoding /WinAnsiEncoding >>';
        }
        $objs[6] = '<< /Title ' . self::lit($this->title) . ' /Author (Executive Grooming Studio) /Creator (Executive Grooming Studio booking system) /CreationDate (D:' . date('YmdHis') . ') >>';
        foreach ($pageObjs as $n => $o) { $objs[$n] = $o; }
        ksort($objs);

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objs as $n => $o) { $offsets[$n] = strlen($pdf); $pdf .= $n . " 0 obj\n" . $o . "\nendobj\n"; }
        $xref = strlen($pdf);
        $max = max(array_keys($objs));
        $pdf .= "xref\n0 " . ($max + 1) . "\n0000000000 65535 f \n";
        for ($n = 1; $n <= $max; $n++) { $pdf .= sprintf("%010d 00000 n \n", $offsets[$n]); }
        $pdf .= "trailer\n<< /Size " . ($max + 1) . " /Root 1 0 R /Info 6 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
        return $pdf;
    }
}

// Turns the rows of a report into the parts of a document (see the notes at the top of this file).
function pdfParse(array $rows): array
{
    $title = 'Report';
    $items = [];
    $n = count($rows);
    $i = 0;
    if ($n > 0 && is_array($rows[0]) && isset($rows[0][0])) {
        $title = (string) $rows[0][0];
        if (count($rows[0]) > 1) { $items[] = ['kv', ['Period', (string) $rows[0][1], (string) ($rows[0][2] ?? '')]]; }
        $i = 1;
    }
    for (; $i < $n; $i++) {
        $r = $rows[$i];
        if ($r === '@h') {                    // the next row is a section heading
            $next = $rows[$i + 1] ?? null;
            if (is_array($next) && $next !== []) { $items[] = ['heading', (string) $next[0]]; $i++; }
            continue;
        }
        if ($r === '@table') {
            $head = $rows[$i + 1] ?? null;
            if (!is_array($head)) { continue; }
            $c = count($head);
            $data = [];
            $j = $i + 2;
            while ($j < $n && is_array($rows[$j]) && count($rows[$j]) === $c) { $data[] = $rows[$j]; $j++; }
            $items[] = ['table', $head, $data];
            $i = $j - 1;
            continue;
        }
        if (!is_array($r)) { continue; }
        $c = count($r);
        if ($c === 0)      { $items[] = ['gap']; }
        elseif ($c === 1)  { $items[] = [(($rows[$i + 1] ?? null) === '@table') ? 'heading' : 'note', (string) $r[0]]; }
        else               { $items[] = ['kv', $r]; }
    }
    return [$title, $items];
}

// Builds the whole PDF for a report and returns it as text.
function buildReportPdf(array $rows, string $generatedBy): string
{
    [$title, $items] = pdfParse($rows);
    $wide = false;
    foreach ($items as $it) { if ($it[0] === 'table' && count($it[1]) >= 7) { $wide = true; } }
    $pdf = new ReportPdf($title, $wide, $generatedBy, new DateTimeImmutable('now'));
    foreach ($items as $it) {
        switch ($it[0]) {
            case 'gap':     $pdf->gap(8); break;
            case 'note':    $pdf->note($it[1]); break;
            case 'heading': $pdf->heading($it[1]); break;
            case 'kv':      $pdf->kv($it[1]); break;
            case 'table':   $pdf->table($it[1], $it[2]); break;
        }
    }
    $pdf->gap(10);
    $pdf->note('Allergy and sensitivity notes never appear in a report or a download: they are health information, shown to the stylist only, '
        . 'and a report is something that gets printed and shared. Money is always the price the client was quoted when they booked.');
    return $pdf->output();
}

// Sends the PDF as a download and stops.
function sendPdf(string $filename, array $rows, string $generatedBy): void
{
    $pdf = buildReportPdf($rows, $generatedBy);
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '-', $filename) . '"');
    header('Content-Length: ' . strlen($pdf));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    echo $pdf;
    exit;
}

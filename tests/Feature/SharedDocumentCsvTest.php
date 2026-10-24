<?php

use Awove\Core\Services\Csv;
use Awove\Core\Services\DocumentLinks;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

it('validates document links without fetching them and preserves private redirect headers', function () {
    $links = app(DocumentLinks::class);
    expect($links->validate(null))->toBeNull()->and($links->validate('  '))->toBeNull()
        ->and($links->validate(' https://files.example.test/manual.pdf '))->toBe('https://files.example.test/manual.pdf');
    foreach (['http://files.example.test/manual.pdf', 'javascript:alert(1)', '//files.example.test/manual.pdf', 'https://noa:private@files.example.test/manual.pdf', "https://files.example.test/a\r\nb.pdf", 'https://files.example.test/a b.pdf', 'https://files.example.test/\\host', str_repeat('x', 2049)] as $url) {
        expect(fn () => $links->validate($url))->toThrow(ValidationException::class);
    }
    $response = $links->redirect('https://files.example.test/manual.pdf');
    expect($response->getTargetUrl())->toBe('https://files.example.test/manual.pdf')
        ->and($response->headers->get('Cache-Control'))->toContain('private', 'no-store')
        ->and($response->headers->get('Referrer-Policy'))->toBe('no-referrer')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
});

it('reuses strict UTF8 CSV parsing for module fields without importing or inferring records', function () {
    $csv = app(Csv::class);
    $file = UploadedFile::fake()->createWithContent('goals.csv', "\xEF\xBB\xBFname,unit\r\n\"Quarterly, target\",meetings\r\n");
    expect($csv->parse($file, ['name', 'unit'], ['name']))->toBe([
        ['line' => 2, 'data' => ['name' => 'Quarterly, target', 'unit' => 'meetings']],
    ]);
    foreach ([['name,name', 'Noa,Levi'], ['name,unknown', 'Noa,Levi'], ['unit', 'meetings'], ['name', '\"Open quote'], ['name', 'Noa,Levi']] as [$header, $row]) {
        $file = UploadedFile::fake()->createWithContent('goals.csv', $header."\n".$row."\n");
        expect(fn () => $csv->parse($file, ['name', 'unit'], ['name']))->toThrow(ValidationException::class);
    }
    expect(fn () => $csv->parse(UploadedFile::fake()->createWithContent('goals.xlsx', "name\nNoa\n"), ['name']))->toThrow(ValidationException::class);
});

it('protects CSV headers and cells from formulas and normalizes standard newlines', function () {
    $response = app(Csv::class)->download('module.csv', ['name', '=unsafe'], [['Yael Shalev', '=HYPERLINK(1)'], ['Noam Erez', "\xEF\xBB\xBF  +SUM(1)"]]);
    ob_start();
    $response->sendContent();
    $content = ob_get_clean();
    expect($content)->toContain("'=unsafe", "'=HYPERLINK(1)", "'\xEF\xBB\xBF  +SUM(1)", "\r\n")
        ->and($response->headers->get('Cache-Control'))->toContain('private', 'no-store');
});

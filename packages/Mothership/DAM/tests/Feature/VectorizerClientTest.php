<?php

use Illuminate\Support\Facades\Http;
use Mothership\DAM\Vectorization\Vectorizer;

beforeEach(function () {
    config([
        'vectorization.enabled'        => true,
        'vectorization.owner_admin_id' => 42,
        'vectorization.api_id'         => 'x',
        'vectorization.api_secret'     => 'y',
    ]);
    Http::preventStrayRequests();
});

it('accepts captured provider SVG and PDF without changing PDF bytes', function () {
    $svg = file_get_contents(__DIR__.'/../Fixtures/vectorization-provider/raw.svg');
    $pdf = file_get_contents(__DIR__.'/../Fixtures/vectorization-provider/raw.pdf');
    Http::fake([
        '*/vectorize' => Http::response($svg, 200, ['X-Image-Token' => 'h']),
        '*/download'  => Http::response($pdf, 200),
    ]);
    $path = tempnam(sys_get_temp_dir(), 'vector-input-');
    file_put_contents($path, 'raster fixture');

    try {
        $result = app(Vectorizer::class)->convert(path: $path);
    } finally {
        unlink($path);
    }

    $xml = new DOMDocument;
    expect($xml->loadXML($result['svg'], LIBXML_NONET))->toBeTrue()
        ->and($xml->documentElement->getAttribute('viewBox'))->toBe('0.00 0.00 197.00 256.00')
        ->and($xml->getElementsByTagName('path')->length)->toBe(43)
        ->and($result['svg'])->not->toContain('<!DOCTYPE', '<!ENTITY')
        ->and($result['pdf'])->toBe($pdf);
    Http::assertSentCount(2);
});

it('uses one tracing and exports Adobe-compatible SVG and PDF', function () {
    $pdf = "%PDF-1.4\n"
        ."1 0 obj\n"
        ."<< /Type /Catalog /Pages 2 0 R >>\n"
        ."endobj\n"
        ."2 0 obj\n"
        ."<< /Type /Pages /Count 0 >>\n"
        ."endobj\n"
        ."xref\n"
        ."0 3\n"
        ."0000000000 65535 f\x20\n"
        ."0000000009 00000 n\x20\n"
        ."0000000058 00000 n\x20\n"
        ."trailer\n"
        ."<< /Size 3 /Root 1 0 R >>\n"
        ."startxref\n"
        ."101\n"
        ."%%EOF\n";
    Http::fake([
        '*/vectorize' => Http::response(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><path d="M0 0H10V10Z"/></svg>',
            200,
            ['X-Image-Token' => 'h'],
        ),
        '*/download' => Http::response($pdf, 200),
    ]);
    $path = tempnam(sys_get_temp_dir(), 'vector-input-');
    file_put_contents($path, 'raster fixture');

    try {
        $result = app(Vectorizer::class)->convert(path: $path);
    } finally {
        unlink($path);
    }

    expect($result['svg'])->toContain('viewBox=')
        ->and($result['pdf'])->toStartWith('%PDF-');
    Http::assertSentCount(2);
    Http::assertSent(function ($request) {
        $parts = collect($request->data())->keyBy('name');

        return str_ends_with($request->url(), '/vectorize')
            && ($parts->get('output.svg.adobe_compatibility_mode')['contents'] ?? null) === 'true'
            && ($parts->get('policy.retention_days')['contents'] ?? null) === '1'
            && ($parts->get('mode')['contents'] ?? null) === 'production'
            && ($parts->get('input.max_pixels')['contents'] ?? null) === '3145828'
            && ($parts->get('output.svg.version')['contents'] ?? null) === 'svg_1_1'
            && ($parts->get('output.svg.fixed_size')['contents'] ?? null) === 'false'
            && ($parts->get('output.draw_style')['contents'] ?? null) === 'fill_shapes'
            && ($parts->get('output.group_by')['contents'] ?? null) === 'none'
            && ($parts->get('output.file_format')['contents'] ?? null) === 'svg';
    });
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/download')
        && $request['image.token'] === 'h'
        && $request['output.file_format'] === 'pdf');
});

it('accepts SVG primitive defaults and standards-valid length units', function (string $svg) {
    $pdf = "%PDF-1.4\n"
        ."1 0 obj\n"
        ."<< /Type /Catalog /Pages 2 0 R >>\n"
        ."endobj\n"
        ."2 0 obj\n"
        ."<< /Type /Pages /Count 0 >>\n"
        ."endobj\n"
        ."xref\n"
        ."0 3\n"
        ."0000000000 65535 f\x20\n"
        ."0000000009 00000 n\x20\n"
        ."0000000058 00000 n\x20\n"
        ."trailer\n"
        ."<< /Size 3 /Root 1 0 R >>\n"
        ."startxref\n"
        ."101\n"
        ."%%EOF\n";
    Http::fake([
        '*/vectorize' => Http::response($svg, 200, ['X-Image-Token' => 'h']),
        '*/download'  => Http::response($pdf, 200),
    ]);
    $path = tempnam(sys_get_temp_dir(), 'vector-input-');
    file_put_contents($path, 'raster fixture');

    try {
        expect(fn () => app(Vectorizer::class)->convert(path: $path))->not->toThrow(RuntimeException::class);
        Http::assertSentCount(2);
    } finally {
        unlink($path);
    }
})->with([
    'default line coordinates'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><g stroke="black"><line x2="10"/></g></svg>',
    'unit-bearing rectangle'     => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="10px" height="5mm"/></svg>',
    'leading viewBox whitespace' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox=" 0 0 10 10 "><path d="M0 0L10 10"/></svg>',
    'leading points whitespace'  => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><polygon points=" 0,0 10,0 10,10 "/></svg>',
]);

it('fails without leaking provider diagnostics or retrying', function () {
    Http::fake(['*' => Http::response('private provider diagnostic', 503)]);
    $path = tempnam(sys_get_temp_dir(), 'vector-input-');
    file_put_contents($path, 'x');

    try {
        expect(fn () => app(Vectorizer::class)->convert(path: $path))
            ->toThrow(RuntimeException::class, 'Vectorization provider unavailable.');
        Http::assertSentCount(1);
    } finally {
        unlink($path);
    }
});

it('rejects unusable vectors before fetching PDF', function (string $svg) {
    Http::fake(['*/vectorize' => Http::response($svg, 200, ['X-Image-Token' => 'h'])]);
    $path = tempnam(sys_get_temp_dir(), 'vector-input-');
    file_put_contents($path, 'x');

    try {
        expect(fn () => app(Vectorizer::class)->convert(path: $path))->toThrow(RuntimeException::class);
        Http::assertSentCount(1);
    } finally {
        unlink($path);
    }
})->with([
    'standard DTD internal entity' => fn () => vectorizerDeclaredSvgFixture('<!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd" [<!ENTITY x "value">]>'),
    'standalone entity'            => fn () => vectorizerDeclaredSvgFixture('<!ENTITY x SYSTEM "https://example.com/entity">'),
    'alternate public DTD'         => fn () => vectorizerDeclaredSvgFixture('<!DOCTYPE svg PUBLIC "-//EXAMPLE//DTD SVG//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd">'),
    'alternate system DTD'         => fn () => vectorizerDeclaredSvgFixture('<!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "https://example.com/svg11.dtd">'),
    'duplicate standard DTD'       => fn () => vectorizerDeclaredSvgFixture(str_repeat('<!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd">', 2)),
    'malformed standard DTD'       => fn () => vectorizerDeclaredSvgFixture('<!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd"'),
    'DTD after root'               => fn () => vectorizerDeclaredSvgFixture('').'<!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd">',
    'empty'                        => '',
    'html error'                   => '<html>error</html>',
    'missing viewBox'              => '<svg/>',
    'external image'               => '<svg><image href="https://example.com/a.png"/></svg>',
    'invalid path'                 => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"><path d="M0 0 nonsense"/></svg>',
    'path without move'            => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"><path d="L1 1"/></svg>',
    'invalid viewBox'              => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 0 1"><path d="M0 0L1 1"/></svg>',
    'doubled viewBox separator'    => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0,,0,1,1"><path d="M0 0L1 1"/></svg>',
    'empty path'                   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"><path d="M0 0"/></svg>',
]);

it('rejects a response without its multi-format handle', function () {
    Http::fake(['*/vectorize' => Http::response(
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"><path d="M0 0H1V1Z"/></svg>',
        200,
    )]);
    $path = tempnam(sys_get_temp_dir(), 'vector-input-');
    file_put_contents($path, 'x');

    try {
        expect(fn () => app(Vectorizer::class)->convert(path: $path))->toThrow(RuntimeException::class);
        Http::assertSentCount(1);
    } finally {
        unlink($path);
    }
});

it('rejects a format-download error after a successful SVG', function () {
    Http::fake([
        '*/vectorize' => Http::response(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"><path d="M0 0H1V1Z"/></svg>',
            200,
            ['X-Image-Token' => 'h'],
        ),
        '*/download' => Http::response('<html>failed</html>', 200),
    ]);
    $path = tempnam(sys_get_temp_dir(), 'vector-input-');
    file_put_contents($path, 'x');

    try {
        expect(fn () => app(Vectorizer::class)->convert(path: $path))->toThrow(RuntimeException::class);
        Http::assertSentCount(2);
    } finally {
        unlink($path);
    }
});

it('rejects a structurally malformed PDF after a valid SVG', function (string $pdf) {
    Http::fake([
        '*/vectorize' => Http::response(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"><path d="M0 0H1V1Z"/></svg>',
            200,
            ['X-Image-Token' => 'h'],
        ),
        '*/download' => Http::response($pdf, 200),
    ]);
    $path = tempnam(sys_get_temp_dir(), 'vector-input-');
    file_put_contents($path, 'x');

    try {
        expect(fn () => app(Vectorizer::class)->convert(path: $path))->toThrow(RuntimeException::class);
        Http::assertSentCount(2);
    } finally {
        unlink($path);
    }
})->with([
    'missing objects'           => "%PDF-1.7\nfixture\n%%EOF",
    'malformed xref subsection' => "%PDF-1.7\n1 0 obj\n<<>>\nendobj\nxref\nnot-a-subsection\ntrailer\n<< /Size 2 >>\nstartxref\n42\n%%EOF\n",
    'incorrect xref offset'     => "%PDF-1.7\n1 0 obj\n<<>>\nendobj\nxref\n0 2\n0000000000 65535 f\x20\n0000000009 00000 n\x20\ntrailer\n<< /Size 2 >>\nstartxref\n1\n%%EOF\n",
    'tab-delimited xref entry'  => "%PDF-1.4\n1 0 obj\n<<>>\nendobj\nxref\n0 2\n0000000000\t65535\tf\n0000000009\t00000\tn\ntrailer\n<< /Size 2 >>\nstartxref\n29\n%%EOF\n",
    'token-soup xref stream'    => "%PDF-1.5\n1 0 obj\n<< /Type /XRef /W [1 2 1] /Size 1 >>\nstream\ntoken soup\nendstream\nendobj\nstartxref\n9\n%%EOF\n",
]);

it('accepts standards-valid PDF xref data', function (string $pdf) {
    Http::fake([
        '*/vectorize' => Http::response(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"><path d="M0 0H1V1Z"/></svg>',
            200,
            ['X-Image-Token' => 'h'],
        ),
        '*/download' => Http::response($pdf, 200),
    ]);
    $path = tempnam(sys_get_temp_dir(), 'vector-input-');
    file_put_contents($path, 'x');

    try {
        expect(app(Vectorizer::class)->convert(path: $path)['pdf'])->toBe($pdf);
        Http::assertSentCount(2);
    } finally {
        unlink($path);
    }
})->with([
    'captured provider PDF'         => fn () => file_get_contents(__DIR__.'/../Fixtures/vectorization-provider/raw.pdf'),
    'classic trailer comment'       => fn () => vectorizerClassicPdfFixture(trailerGap: "\n% Trailer identifies number of objs, plus Root and Info objs\n"),
    'classic trailer whitespace'    => fn () => vectorizerClassicPdfFixture(trailerGap: " \t\r\n\f\0"),
    'classic trailer comment decoy' => fn () => vectorizerClassicPdfFixture(trailerGap: "\n% trailer << /Size 999 >>\n"),
    'classic padded LF'             => fn () => vectorizerClassicPdfFixture("\x20\n"),
    'classic padded CR'             => fn () => vectorizerClassicPdfFixture("\x20\r"),
    'classic unpadded CRLF'         => fn () => vectorizerClassicPdfFixture("\r\n"),
    'reordered stream dictionary'   => fn () => vectorizerXrefPdfFixture('/Size 5 /Length 25 /W [1 2 2] /Type /XRef'),
    'escaped names and comments'    => fn () => vectorizerXrefPdfFixture("/Ty#70e /X#52ef /W [1 % width\n2 2] /S#69ze 5 /Length 25"),
    'delimiters inside metadata'    => fn () => vectorizerXrefPdfFixture('/Note (nested (text) \\( /Type /Catalog >>) /ID [<ABCD> <1234>] /Type /XRef /W [1 2 2] /Size 5 /Length 25'),
    'incremental Index subset'      => fn () => vectorizerIndexedPdfFixture(),
    'FlateDecode stream'            => fn () => vectorizerFlatePdfFixture(),
    'FlateDecode Index subset'      => fn () => vectorizerIndexedPdfFixture(compressed: true),
    'single filter array'           => fn () => vectorizerFlatePdfFixture(filter: '[/FlateDecode]', parameters: '/DecodeParms [null]'),
    'direct FlateDecode parameters' => fn () => vectorizerFlatePdfFixture(parameters: '/DecodeParms << /Predictor 1 >>'),
]);

it('rejects invalid xref records and dictionaries without leaking or retrying', function (string $pdf) {
    Http::fake([
        '*/vectorize' => Http::response(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"><path d="M0 0L1 1"/></svg>',
            200,
            ['X-Image-Token' => 'h'],
        ),
        '*/download' => Http::response($pdf, 200),
    ]);
    $path = tempnam(sys_get_temp_dir(), 'vector-input-');
    file_put_contents($path, 'x');

    try {
        app(Vectorizer::class)->convert(path: $path);
        test()->fail('Malformed PDF was accepted.');
    } catch (RuntimeException $error) {
        expect($error->getMessage())->toBe('Vectorization provider unavailable.')
            ->and($error->getPrevious())->toBeNull();
        Http::assertSentCount(2);
    } finally {
        unlink($path);
    }
})->with([
    'garbage before trailer'        => fn () => vectorizerClassicPdfFixture(trailerGap: "\ninvalid\n"),
    'commented-out trailer'         => fn () => vectorizerClassicPdfFixture(trailerGap: "\n%"),
    '19-byte LF row'                => fn () => vectorizerClassicPdfFixture("\n"),
    '19-byte CR row'                => fn () => vectorizerClassicPdfFixture("\r"),
    '21-byte padded CRLF row'       => fn () => vectorizerClassicPdfFixture("\x20\r\n"),
    'Type inside string'            => fn () => vectorizerXrefPdfFixture('/Note (/Type /XRef) /W [1 2 2] /Size 5 /Length 25'),
    'W inside string'               => fn () => vectorizerXrefPdfFixture('/Type /XRef /Note (/W [1 2 2]) /Size 5 /Length 25'),
    'Size inside string'            => fn () => vectorizerXrefPdfFixture('/Type /XRef /W [1 2 2] /Note (/Size 5) /Length 25'),
    'Type inside comment'           => fn () => vectorizerXrefPdfFixture("% /Type /XRef\n/W [1 2 2] /Size 5 /Length 25"),
    'W inside comment'              => fn () => vectorizerXrefPdfFixture("/Type /XRef % /W [1 2 2]\n/Size 5 /Length 25"),
    'Size inside comment'           => fn () => vectorizerXrefPdfFixture("/Type /XRef /W [1 2 2] % /Size 5\n/Length 25"),
    'Type inside nested dictionary' => fn () => vectorizerXrefPdfFixture('/Note << /Type /XRef >> /W [1 2 2] /Size 5 /Length 25'),
    'wrong Type with string decoy'  => fn () => vectorizerXrefPdfFixture('/Type /Catalog /Note (/Type /XRef) /W [1 2 2] /Size 5 /Length 25'),
    'missing Length'                => fn () => vectorizerXrefPdfFixture('/Type /XRef /W [1 2 2] /Size 5'),
    'Length inside string'          => fn () => vectorizerXrefPdfFixture('/Type /XRef /W [1 2 2] /Size 5 /Note (/Length 25)'),
    'Length inside comment'         => fn () => vectorizerXrefPdfFixture("/Type /XRef /W [1 2 2] /Size 5 % /Length 25\n"),
    'indirect Length'               => fn () => vectorizerXrefPdfFixture('/Type /XRef /W [1 2 2] /Size 5 /Length 25 0 R'),
    'real Length'                   => fn () => vectorizerXrefPdfFixture('/Type /XRef /W [1 2 2] /Size 5 /Length 25.0'),
    'negative Length'               => fn () => vectorizerXrefPdfFixture('/Type /XRef /W [1 2 2] /Size 5 /Length -25'),
    'short Length'                  => fn () => vectorizerXrefPdfFixture('/Type /XRef /W [1 2 2] /Size 5 /Length 24'),
    'long Length'                   => fn () => vectorizerXrefPdfFixture('/Type /XRef /W [1 2 2] /Size 5 /Length 27'),
    'duplicate Length'              => fn () => vectorizerXrefPdfFixture('/Type /XRef /W [1 2 2] /Size 5 /Length 24 /Length 25'),
    'indirect width'                => fn () => vectorizerXrefPdfFixture('/Type /XRef /W [1 2 0 R 2] /Size 5 /Length 25'),
    'odd Index'                     => fn () => vectorizerXrefPdfFixture('/Type /XRef /W [1 2 2] /Size 5 /Length 25 /Index [0 5 4]'),
    'overlapping Index'             => fn () => vectorizerXrefPdfFixture('/Type /XRef /W [1 2 2] /Size 5 /Length 25 /Index [0 3 2 2]'),
    'out-of-bounds Index'           => fn () => vectorizerXrefPdfFixture('/Type /XRef /W [1 2 2] /Size 5 /Length 25 /Index [1 5]'),
    'negative Index'                => fn () => vectorizerXrefPdfFixture('/Type /XRef /W [1 2 2] /Size 5 /Length 25 /Index [-1 5]'),
    'noninteger Index'              => fn () => vectorizerXrefPdfFixture('/Type /XRef /W [1 2 2] /Size 5 /Length 25 /Index [0 5.0]'),
    'Index byte-count mismatch'     => fn () => vectorizerXrefPdfFixture('/Type /XRef /W [1 2 2] /Size 5 /Length 25 /Index [0 1]'),
    'unsupported filter'            => fn () => vectorizerXrefPdfFixture('/Type /XRef /W [1 2 2] /Size 5 /Length 25 /Filter /ASCIIHexDecode'),
    'string filter'                 => fn () => vectorizerXrefPdfFixture('/Type /XRef /W [1 2 2] /Size 5 /Length 25 /Filter (FlateDecode)'),
    'empty filter array'            => fn () => vectorizerXrefPdfFixture('/Type /XRef /W [1 2 2] /Size 5 /Length 25 /Filter []'),
    'invalid FlateDecode bytes'     => fn () => vectorizerXrefPdfFixture('/Type /XRef /W [1 2 2] /Size 5 /Length 25 /Filter /FlateDecode'),
    'short decoded records'         => fn () => vectorizerFlatePdfFixture(data: str_repeat('x', 24)),
    'excess decoded records'        => fn () => vectorizerFlatePdfFixture(data: str_repeat('x', 26)),
    'oversized decoded payload'     => fn () => vectorizerFlatePdfFixture(data: str_repeat('x', 100000)),
    'truncated FlateDecode data'    => fn () => vectorizerFlatePdfFixture(encoded: substr(gzcompress(vectorizerXrefDataFixture()), 0, -1)),
    'trailing compressed garbage'   => fn () => vectorizerFlatePdfFixture(encoded: gzcompress(vectorizerXrefDataFixture()).'extra'),
    'unsupported predictor'         => fn () => vectorizerFlatePdfFixture(parameters: '/DecodeParms << /Predictor 12 >>'),
    'malformed parameters'          => fn () => vectorizerFlatePdfFixture(parameters: '/DecodeParms (private provider diagnostic)'),
]);

function vectorizerDeclaredSvgFixture(string $declaration): string
{
    return $declaration.'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"><path d="M0 0L1 1"/></svg>';
}

function vectorizerPdfPrefixFixture(): string
{
    return "%PDF-1.5\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n"
        ."2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n"
        ."3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 10 10] >>\nendobj\n";
}

function vectorizerClassicPdfFixture(string $ending = "\x20\n", string $trailerGap = ''): string
{
    $pdf = vectorizerPdfPrefixFixture();
    $offset = strlen($pdf);
    $xref = "xref\n0 4\n0000000000 65535 f".$ending;

    foreach ([1, 2, 3] as $number) {
        $xref .= sprintf('%010d 00000 n', strpos($pdf, $number.' 0 obj')).$ending;
    }

    return $pdf.$xref.$trailerGap."trailer\n<< /Size 4 /Root 1 0 R >>\nstartxref\n".$offset."\n%%EOF\n";
}

function vectorizerXrefDataFixture(): string
{
    $pdf = vectorizerPdfPrefixFixture();
    $data = pack('Cnn', 0, 0, 65535);

    foreach ([1, 2, 3] as $number) {
        $data .= pack('Cnn', 1, strpos($pdf, $number.' 0 obj'), 0);
    }

    return $data.pack('Cnn', 1, strlen($pdf), 0);
}

function vectorizerXrefPdfFixture(string $dictionary, ?string $data = null, bool $incremental = false): string
{
    $pdf = $incremental ? vectorizerClassicPdfFixture() : vectorizerPdfPrefixFixture();
    $offset = strlen($pdf);

    return $pdf."4 0 obj\n<< /Root 1 0 R ".$dictionary." >>\nstream\n"
        .($data ?? vectorizerXrefDataFixture())."\nendstream\nendobj\nstartxref\n".$offset."\n%%EOF\n";
}

function vectorizerIndexedPdfFixture(bool $compressed = false): string
{
    $data = pack('Cnn', 1, strlen(vectorizerClassicPdfFixture()), 0);
    $data = $compressed ? gzcompress($data) : $data;
    $dictionary = '/Type /XRef /Size 5 /W [1 2 2] /Index [4 1] /Prev '.strlen(vectorizerPdfPrefixFixture())
        .' /Length '.strlen($data).($compressed ? ' /Filter /FlateDecode' : '');

    return vectorizerXrefPdfFixture(dictionary: $dictionary, data: $data, incremental: true);
}

function vectorizerFlatePdfFixture(?string $data = null, string $filter = '/FlateDecode', string $parameters = '', ?string $encoded = null): string
{
    $encoded ??= gzcompress($data ?? vectorizerXrefDataFixture());

    return vectorizerXrefPdfFixture(
        dictionary: '/Type /XRef /Size 5 /W [1 2 2] /Length '.strlen($encoded).' /Filter '.$filter.' '.$parameters,
        data: $encoded,
    );
}

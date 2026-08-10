<?php

namespace Tests\Support;

use ZipArchive;

/**
 * Builds tiny synthetic EPUBs for tests. All content is generated — no
 * copyrighted material may ever enter the repository or the fixtures.
 */
class SyntheticEpub
{
    /** 1x1 transparent PNG. */
    private const PNG = "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89\x00\x00\x00\x0aIDATx\x9cc\x00\x01\x00\x00\x05\x00\x01\x0d\x0a\x2d\xb4\x00\x00\x00\x00IEND\xaeB\x60\x82";

    public static function epub3(string $path, array $options = []): string
    {
        $title = $options['title'] ?? 'The Synthetic Chronicle';
        $uuid = $options['uuid'] ?? 'a1b2c3d4-0000-4000-8000-00000000e2e1';
        $coverBytes = $options['cover'] ?? self::PNG;
        $extraCss = $options['css'] ?? "body { font-family: serif; }\n";
        $chapterSuffix = $options['chapter_suffix'] ?? '';

        $zip = self::openZip($path);

        $zip->addFromString('META-INF/container.xml', <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container">
              <rootfiles>
                <rootfile full-path="OEBPS/content.opf" media-type="application/oebps-package+xml"/>
              </rootfiles>
            </container>
            XML);

        $zip->addFromString('OEBPS/content.opf', <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <package xmlns="http://www.idpf.org/2007/opf" version="3.0" unique-identifier="pub-id" xml:lang="en">
              <metadata xmlns:dc="http://purl.org/dc/elements/1.1/">
                <dc:identifier id="pub-id">urn:uuid:{$uuid}</dc:identifier>
                <dc:identifier>urn:isbn:9780316769488</dc:identifier>
                <dc:title id="t1">{$title}</dc:title>
                <dc:title id="t2">A Tale of Test Data</dc:title>
                <meta refines="#t2" property="title-type">subtitle</meta>
                <dc:creator id="creator1">Ada Example</dc:creator>
                <meta refines="#creator1" property="role" scheme="marc:relators">aut</meta>
                <meta refines="#creator1" property="file-as">Example, Ada</meta>
                <dc:creator id="creator2">Turing Translator</dc:creator>
                <meta refines="#creator2" property="role" scheme="marc:relators">trl</meta>
                <dc:language>en</dc:language>
                <dc:publisher>Mnemosyne Test Press</dc:publisher>
                <dc:date>2024-01-15</dc:date>
                <dc:description>A fully synthetic book generated for pipeline tests.</dc:description>
                <dc:subject>Testing</dc:subject>
                <dc:subject>Synthetic Data</dc:subject>
                <dc:rights>Public domain test data</dc:rights>
                <meta property="dcterms:modified">2024-02-01T00:00:00Z</meta>
              </metadata>
              <manifest>
                <item id="nav" href="nav.xhtml" media-type="application/xhtml+xml" properties="nav"/>
                <item id="cover-img" href="cover.png" media-type="image/png" properties="cover-image"/>
                <item id="css" href="style.css" media-type="text/css"/>
                <item id="ch1" href="chapter1.xhtml" media-type="application/xhtml+xml"/>
                <item id="ch2" href="chapter2.xhtml" media-type="application/xhtml+xml"/>
                <item id="ch3" href="chapter3.xhtml" media-type="application/xhtml+xml"/>
              </manifest>
              <spine>
                <itemref idref="ch1"/>
                <itemref idref="ch2"/>
                <itemref idref="ch3"/>
              </spine>
            </package>
            XML);

        $zip->addFromString('OEBPS/nav.xhtml', <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops">
            <head><title>Contents</title></head>
            <body>
              <nav epub:type="toc" id="toc">
                <h1>Table of Contents</h1>
                <ol>
                  <li><a href="chapter1.xhtml#c1">Chapter One: The Beginning</a>
                    <ol><li><a href="chapter1.xhtml#c1s1">A First Section</a></li></ol>
                  </li>
                  <li><a href="chapter2.xhtml#c2">Chapter Two: The Middle</a></li>
                  <li><a href="chapter3.xhtml#c3">Chapter Three: The End</a></li>
                </ol>
              </nav>
            </body>
            </html>
            XML);

        // Deliberately added to the zip AFTER later chapters would be —
        // spine order (1,2,3) must win over zip entry order.
        $zip->addFromString('OEBPS/chapter3.xhtml', self::chapter(3, 'The End', 'c3', $chapterSuffix));
        $zip->addFromString('OEBPS/chapter1.xhtml', <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en">
            <head><title>Chapter One</title><link rel="stylesheet" href="style.css"/></head>
            <body>
              <h1 id="c1">Chapter One: The Beginning</h1>
              <p>Every synthetic story starts with deterministic bytes.{$chapterSuffix}</p>
              <p>The archivist catalogued the first shelf of the endless library.</p>
              <h2 id="c1s1">A First Section</h2>
              <p>Sections give structure to otherwise flat narratives.</p>
              <blockquote><p>Structure is the memory of text.</p></blockquote>
              <ul><li>reading order</li><li>headings</li><li>citations</li></ul>
            </body>
            </html>
            XML);
        $zip->addFromString('OEBPS/chapter2.xhtml', self::chapter(2, 'The Middle', 'c2', $chapterSuffix));
        $zip->addFromString('OEBPS/cover.png', $coverBytes);
        $zip->addFromString('OEBPS/style.css', $extraCss);

        $zip->close();

        return $path;
    }

    public static function epub2(string $path): string
    {
        $zip = self::openZip($path);

        $zip->addFromString('META-INF/container.xml', <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container">
              <rootfiles>
                <rootfile full-path="OPS/content.opf" media-type="application/oebps-package+xml"/>
              </rootfiles>
            </container>
            XML);

        $zip->addFromString('OPS/content.opf', <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <package xmlns="http://www.idpf.org/2007/opf" xmlns:opf="http://www.idpf.org/2007/opf"
                     xmlns:dc="http://purl.org/dc/elements/1.1/" version="2.0" unique-identifier="bookid">
              <metadata>
                <dc:identifier id="bookid">urn:uuid:00000000-1111-4222-8333-444444444444</dc:identifier>
                <dc:title>An Older Synthetic Volume</dc:title>
                <dc:creator opf:role="aut" opf:file-as="Legacy, Author">Author Legacy</dc:creator>
                <dc:language>it</dc:language>
                <dc:publisher>Mnemosyne Test Press</dc:publisher>
                <dc:date>1999</dc:date>
              </metadata>
              <manifest>
                <item id="ncx" href="toc.ncx" media-type="application/x-dtbncx+xml"/>
                <item id="ch1" href="text/ch1.html" media-type="application/xhtml+xml"/>
                <item id="ch2" href="text/ch2.html" media-type="application/xhtml+xml"/>
              </manifest>
              <spine toc="ncx">
                <itemref idref="ch1"/>
                <itemref idref="ch2"/>
              </spine>
            </package>
            XML);

        $zip->addFromString('OPS/toc.ncx', <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <ncx xmlns="http://www.daisy.org/z3986/2005/ncx/" version="2005-1">
              <head><meta name="dtb:uid" content="urn:uuid:00000000-1111-4222-8333-444444444444"/></head>
              <docTitle><text>An Older Synthetic Volume</text></docTitle>
              <navMap>
                <navPoint id="n1" playOrder="1"><navLabel><text>Capitolo Uno</text></navLabel><content src="text/ch1.html"/></navPoint>
                <navPoint id="n2" playOrder="2"><navLabel><text>Capitolo Due</text></navLabel><content src="text/ch2.html"/></navPoint>
              </navMap>
            </ncx>
            XML);

        $zip->addFromString('OPS/text/ch1.html', <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <html xmlns="http://www.w3.org/1999/xhtml">
            <head><title>Capitolo Uno</title></head>
            <body><h1 id="cap1">Capitolo Uno</h1><p>Un libro sintetico in italiano per il test EPUB due.</p></body>
            </html>
            XML);
        $zip->addFromString('OPS/text/ch2.html', <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <html xmlns="http://www.w3.org/1999/xhtml">
            <head><title>Capitolo Due</title></head>
            <body><h1 id="cap2">Capitolo Due</h1><p>La seconda parte conclude la dimostrazione.</p></body>
            </html>
            XML);

        $zip->close();

        return $path;
    }

    /** Same chapter text as epub3(), different cover/css/uuid → same content fingerprint, different file hash. */
    public static function epub3DifferentCover(string $path): string
    {
        return self::epub3($path, [
            'uuid' => 'ffffffff-9999-4999-8999-eeeeeeeeeeee',
            'cover' => self::PNG."\x00\x00alternate-cover-padding",
            'css' => "body { font-family: sans-serif; color: #111; }\n",
        ]);
    }

    /** Deep h1→h4 hierarchy inside one doc plus a second doc restarting at h1. */
    public static function nestedHeadings(string $path): string
    {
        $chapterOne = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en">
            <head><title>Deep</title></head>
            <body>
              <h1 id="part1">Part One</h1>
              <p>Opening of part one.</p>
              <h2 id="ch1">Chapter One</h2>
              <p>Chapter text at depth two.</p>
              <h3 id="s11">Section 1.1</h3>
              <p>Section text at depth three.</p>
              <h4 id="s111">Subsection 1.1.1</h4>
              <p>Subsection text at depth four.</p>
              <h3 id="s12">Section 1.2</h3>
              <p>Back up to depth three.</p>
              <h2 id="ch2">Chapter Two</h2>
              <p>Second chapter of part one.</p>
            </body>
            </html>
            XML;
        $chapterTwo = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en">
            <head><title>Part Two</title></head>
            <body>
              <h1 id="part2">Part Two</h1>
              <p>The hierarchy restarts per document.</p>
              <h2 id="ch3">Chapter Three</h2>
              <p>Final chapter text.</p>
            </body>
            </html>
            XML;

        return self::customEpub3($path, 'Nested Headings Volume', [
            'ch1.xhtml' => $chapterOne,
            'ch2.xhtml' => $chapterTwo,
        ], [
            ['ch1.xhtml#part1', 'Part One'],
            ['ch2.xhtml#part2', 'Part Two'],
        ]);
    }

    /** Eight spine documents to exercise ordering + per-doc artifacts. */
    public static function manySpineDocuments(string $path): string
    {
        $docs = [];
        $toc = [];
        foreach (range(1, 8) as $number) {
            $docs["part{$number}.xhtml"] = <<<XML
                <?xml version="1.0" encoding="UTF-8"?>
                <html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en">
                <head><title>Part {$number}</title></head>
                <body>
                  <h1 id="p{$number}">Part {$number}</h1>
                  <p>Deterministic body of part {$number} in reading order.</p>
                </body>
                </html>
                XML;
            $toc[] = ["part{$number}.xhtml#p{$number}", "Part {$number}"];
        }

        return self::customEpub3($path, 'The Eight-Part Saga', $docs, $toc);
    }

    /** Four creators/contributors with distinct MARC roles + file-as. */
    public static function richContributors(string $path): string
    {
        $metadata = <<<'XML'
                <dc:creator id="cr1">Prima Author</dc:creator>
                <meta refines="#cr1" property="role" scheme="marc:relators">aut</meta>
                <meta refines="#cr1" property="file-as">Author, Prima</meta>
                <dc:creator id="cr2">Segunda Author</dc:creator>
                <meta refines="#cr2" property="role" scheme="marc:relators">aut</meta>
                <dc:contributor id="ct1">Eddie Editor</dc:contributor>
                <meta refines="#ct1" property="role" scheme="marc:relators">edt</meta>
                <dc:contributor id="ct2">Iva Illustrator</dc:contributor>
                <meta refines="#ct2" property="role" scheme="marc:relators">ill</meta>
            XML;

        return self::customEpub3($path, 'A Team Effort', [
            'text.xhtml' => self::simpleDoc('A Team Effort', 'Many hands made this synthetic book.'),
        ], [['text.xhtml', 'A Team Effort']], extraMetadata: $metadata);
    }

    /** Multiple languages: metadata + per-block xml:lang + non-Latin scripts. */
    public static function multilingual(string $path): string
    {
        $doc = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en">
            <head><title>Polyglot</title></head>
            <body>
              <h1 id="poly">A Polyglot Reader</h1>
              <p>English opening paragraph.</p>
              <p xml:lang="el">Η μνήμη είναι η μητέρα των Μουσών.</p>
              <p xml:lang="ru">Память — мать муз.</p>
              <p xml:lang="ja">記憶はミューズの母である。</p>
              <p xml:lang="it">La memoria è la madre delle Muse.</p>
            </body>
            </html>
            XML;

        return self::customEpub3($path, 'A Polyglot Reader', [
            'poly.xhtml' => $doc,
        ], [['poly.xhtml#poly', 'A Polyglot Reader']], extraMetadata: "<dc:language>el</dc:language>\n<dc:language>ru</dc:language>");
    }

    /** Footnote apparatus: noterefs + aside epub:type footnote + cross-doc link. */
    public static function footnotes(string $path): string
    {
        $body = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops" xml:lang="en">
            <head><title>Annotated</title></head>
            <body>
              <h1 id="annotated">An Annotated Chapter</h1>
              <p>The archive was catalogued<a epub:type="noteref" href="notes.xhtml#n1" id="ref1">1</a> long ago,
                 as others reported<a epub:type="noteref" href="#localnote" id="ref2">2</a>.</p>
              <p>See also the <a href="notes.xhtml#extra">appendix note</a> for context.</p>
              <aside epub:type="footnote" id="localnote"><p>A local footnote kept in the same file.</p></aside>
            </body>
            </html>
            XML;
        $notes = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops" xml:lang="en">
            <head><title>Notes</title></head>
            <body>
              <h1 id="notes">Notes</h1>
              <aside epub:type="footnote" id="n1"><p>First catalogue note, kept apart.</p></aside>
              <p id="extra">An appendix note that is plain prose.</p>
            </body>
            </html>
            XML;

        return self::customEpub3($path, 'An Annotated Chapter', [
            'main.xhtml' => $body,
            'notes.xhtml' => $notes,
        ], [['main.xhtml#annotated', 'An Annotated Chapter'], ['notes.xhtml#notes', 'Notes']]);
    }

    /** Table with caption/thead/th plus a captioned figure. */
    public static function tablesAndCaptions(string $path): string
    {
        $doc = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en">
            <head><title>Tabular</title></head>
            <body>
              <h1 id="tab">Tabular Matters</h1>
              <p>The inventory below is deterministic, and this chapter carries
                 enough narrative prose around it that the extractor treats the
                 document as textual content rather than an image-only page.</p>
              <p>Catalogues of reading rooms were kept by every archivist of
                 the synthetic library, and comparing their counts across the
                 years is exactly the kind of structured evidence this fixture
                 exists to preserve.</p>
              <table id="inventory">
                <caption>Inventory of the reading room</caption>
                <thead><tr><th>Item</th><th>Count</th></tr></thead>
                <tbody>
                  <tr><td>Chairs</td><td>12</td></tr>
                  <tr><td>Lecterns</td><td>3</td></tr>
                </tbody>
              </table>
              <figure id="fig1">
                <img src="cover.png" alt="A schematic drawing of the room"/>
                <figcaption>Figure 1: the reading room plan.</figcaption>
              </figure>
            </body>
            </html>
            XML;

        return self::customEpub3($path, 'Tabular Matters', [
            'tab.xhtml' => $doc,
        ], [['tab.xhtml#tab', 'Tabular Matters']]);
    }

    /** Inline SVG with title + img with alt inside meaningful prose. */
    public static function svgAndImages(string $path): string
    {
        $doc = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en">
            <head><title>Illustrated</title></head>
            <body>
              <h1 id="illus">An Illustrated Study</h1>
              <p>Before the diagram, some prose that must be extracted: the
                 study opens with a long descriptive passage so that the page
                 is unmistakably textual even though it also carries artwork,
                 keeping the image-only heuristic quiet for this fixture.</p>
              <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10" id="diagram">
                <title>A circle diagram</title>
                <circle cx="5" cy="5" r="4"/>
              </svg>
              <p>After the diagram, the prose continues with analysis that
                 compares the circle to the archival photograph below and adds
                 a further sentence of deterministic filler for good measure.</p>
              <figure id="photo"><img src="cover.png" alt="An archival photograph"/></figure>
            </body>
            </html>
            XML;

        return self::customEpub3($path, 'An Illustrated Study', [
            'illus.xhtml' => $doc,
        ], [['illus.xhtml#illus', 'An Illustrated Study']]);
    }

    /** HTML-style (non-XML) markup: recoverable via the fallback parser. */
    public static function recoverableXhtml(string $path): string
    {
        // Unclosed <br> and <img>, unquoted attribute — valid HTML, not XML.
        $doc = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en">
            <head><title>Legacy Markup</title></head>
            <body>
              <h1 id=legacy>Legacy Markup Survives</h1>
              <p>First line<br>second line of the same paragraph.</p>
              <p>An old-style image: <img src="cover.png" alt="old"> and text continues.</p>
            </body>
            </html>
            XML;

        return self::customEpub3($path, 'Legacy Markup Survives', [
            'legacy.xhtml' => $doc,
        ], [['legacy.xhtml', 'Legacy Markup Survives']]);
    }

    /** Spine references a manifest item whose file is absent from the zip. */
    public static function missingResource(string $path): string
    {
        $zip = self::openZip($path);
        $zip->addFromString('META-INF/container.xml', self::containerXml('OEBPS/content.opf'));
        $zip->addFromString('OEBPS/content.opf', <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <package xmlns="http://www.idpf.org/2007/opf" version="3.0" unique-identifier="pub-id">
              <metadata xmlns:dc="http://purl.org/dc/elements/1.1/">
                <dc:identifier id="pub-id">urn:uuid:aaaaaaaa-0000-4000-8000-000000000abc</dc:identifier>
                <dc:title>The Incomplete Volume</dc:title>
                <dc:language>en</dc:language>
                <meta property="dcterms:modified">2026-01-01T00:00:00Z</meta>
              </metadata>
              <manifest>
                <item id="nav" href="nav.xhtml" media-type="application/xhtml+xml" properties="nav"/>
                <item id="ch1" href="exists.xhtml" media-type="application/xhtml+xml"/>
                <item id="ch2" href="ghost.xhtml" media-type="application/xhtml+xml"/>
              </manifest>
              <spine><itemref idref="ch1"/><itemref idref="ch2"/></spine>
            </package>
            XML);
        $zip->addFromString('OEBPS/nav.xhtml', <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops">
            <head><title>c</title></head>
            <body><nav epub:type="toc"><ol><li><a href="exists.xhtml">One</a></li></ol></nav></body></html>
            XML);
        $zip->addFromString('OEBPS/exists.xhtml', self::simpleDoc('One', 'The only chapter that exists.'));
        $zip->close();

        return $path;
    }

    /** Remote resource references + a script element in content. */
    public static function remoteAndScript(string $path): string
    {
        $doc = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en">
            <head><title>Hostile-ish</title>
              <script type="text/javascript">alert('never');</script>
            </head>
            <body>
              <h1 id="h">A Chapter With Baggage</h1>
              <p onclick="alert('no')">Readable prose that must survive sanitization.</p>
              <p>A remote image follows: <img src="https://evil.example/track.png" alt="tracker"/></p>
              <p>And a remote link: <a href="https://evil.example/page">click me</a>.</p>
              <script>document.write('nope');</script>
            </body>
            </html>
            XML;

        return self::customEpub3($path, 'A Chapter With Baggage', [
            'baggage.xhtml' => $doc,
        ], [['baggage.xhtml#h', 'A Chapter With Baggage']]);
    }

    /** OPF that is not parseable XML at all → hard failure. */
    public static function invalidOpfXml(string $path): string
    {
        $zip = self::openZip($path);
        $zip->addFromString('META-INF/container.xml', self::containerXml('OEBPS/content.opf'));
        $zip->addFromString('OEBPS/content.opf', '<package><metadata><dc:title>broken');
        $zip->addFromString('OEBPS/ch1.xhtml', self::simpleDoc('x', 'y'));
        $zip->close();

        return $path;
    }

    public static function malformed(string $path): string
    {
        $zip = self::openZip($path);
        $zip->addFromString('META-INF/container.xml', '<container><not-closed>');
        $zip->addFromString('random.txt', 'no opf anywhere');
        $zip->close();

        return $path;
    }

    public static function pathTraversal(string $path): string
    {
        $zip = self::openZip($path);
        $zip->addFromString('META-INF/container.xml', '<?xml version="1.0"?><container/>');
        $zip->addFromString('../../evil.txt', 'escape attempt');
        $zip->close();

        return $path;
    }

    public static function encryptedContent(string $path): string
    {
        // Structurally valid EPUB3 whose chapter is declared encrypted.
        self::epub3($path);

        $zip = new ZipArchive;
        $zip->open($path);
        $zip->addFromString('META-INF/encryption.xml', <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <encryption xmlns="urn:oasis:names:tc:opendocument:xmlns:container"
                        xmlns:enc="http://www.w3.org/2001/04/xmlenc#">
              <enc:EncryptedData>
                <enc:EncryptionMethod Algorithm="http://www.w3.org/2001/04/xmlenc#aes128-cbc"/>
                <enc:CipherData>
                  <enc:CipherReference URI="OEBPS/chapter1.xhtml"/>
                </enc:CipherData>
              </enc:EncryptedData>
            </encryption>
            XML);
        $zip->close();

        return $path;
    }

    /**
     * Generic EPUB 3 builder for the compatibility fixtures: given content
     * documents (filename => xhtml) and a flat TOC, produces a valid
     * package with cover + nav.
     *
     * @param  array<string, string>  $documents
     * @param  list<array{0: string, 1: string}>  $toc  [href, label]
     */
    public static function customEpub3(
        string $path,
        string $title,
        array $documents,
        array $toc,
        string $extraMetadata = '',
    ): string {
        $zip = self::openZip($path);
        $zip->addFromString('META-INF/container.xml', self::containerXml('OEBPS/content.opf'));

        $manifestItems = ['<item id="nav" href="nav.xhtml" media-type="application/xhtml+xml" properties="nav"/>'];
        $manifestItems[] = '<item id="cover-img" href="cover.png" media-type="image/png" properties="cover-image"/>';
        $spineRefs = [];
        $index = 0;

        foreach (array_keys($documents) as $href) {
            $index++;
            $manifestItems[] = "<item id=\"doc{$index}\" href=\"{$href}\" media-type=\"application/xhtml+xml\"/>";
            $spineRefs[] = "<itemref idref=\"doc{$index}\"/>";
        }

        if ($extraMetadata === '') {
            $extraMetadata = '<dc:creator id="cr1">Fixture Author</dc:creator>'
                ."\n".'<meta refines="#cr1" property="role" scheme="marc:relators">aut</meta>';
        }

        $uuidTail = substr(md5($title), 0, 12);
        $manifest = implode("\n    ", $manifestItems);
        $spine = implode("\n    ", $spineRefs);

        $zip->addFromString('OEBPS/content.opf', <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <package xmlns="http://www.idpf.org/2007/opf" version="3.0" unique-identifier="pub-id" xml:lang="en">
              <metadata xmlns:dc="http://purl.org/dc/elements/1.1/">
                <dc:identifier id="pub-id">urn:uuid:00000000-f1x7-4000-8000-{$uuidTail}</dc:identifier>
                <dc:title>{$title}</dc:title>
                <dc:language>en</dc:language>
                <dc:publisher>Mnemosyne Test Press</dc:publisher>
                <dc:date>2026-02-02</dc:date>
                {$extraMetadata}
                <meta property="dcterms:modified">2026-02-02T00:00:00Z</meta>
              </metadata>
              <manifest>
                {$manifest}
              </manifest>
              <spine>
                {$spine}
              </spine>
            </package>
            XML);

        $tocItems = implode("\n", array_map(
            fn (array $entry) => '<li><a href="'.$entry[0].'">'.$entry[1].'</a></li>',
            $toc,
        ));
        $zip->addFromString('OEBPS/nav.xhtml', <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops">
            <head><title>Contents</title></head>
            <body><nav epub:type="toc" id="toc"><h1>Contents</h1><ol>{$tocItems}</ol></nav></body>
            </html>
            XML);

        foreach ($documents as $href => $content) {
            $zip->addFromString('OEBPS/'.$href, $content);
        }

        $zip->addFromString('OEBPS/cover.png', self::PNG);
        $zip->close();

        return $path;
    }

    private static function containerXml(string $opfPath): string
    {
        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container">
              <rootfiles>
                <rootfile full-path="{$opfPath}" media-type="application/oebps-package+xml"/>
              </rootfiles>
            </container>
            XML;
    }

    private static function simpleDoc(string $heading, string $paragraph): string
    {
        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en">
            <head><title>{$heading}</title></head>
            <body><h1>{$heading}</h1><p>{$paragraph}</p></body>
            </html>
            XML;
    }

    private static function chapter(int $number, string $name, string $anchor, string $suffix): string
    {
        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en">
            <head><title>Chapter {$number}</title></head>
            <body>
              <h1 id="{$anchor}">Chapter {$number}: {$name}</h1>
              <p>Deterministic paragraph one of chapter {$number}.{$suffix}</p>
              <p>Deterministic paragraph two of chapter {$number}, with an <em>emphasized</em> span.</p>
            </body>
            </html>
            XML;
    }

    private static function openZip(string $path): ZipArchive
    {
        @mkdir(dirname($path), 0750, true);
        @unlink($path);

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        // Spec: `mimetype` first and STORED (uncompressed).
        $zip->addFromString('mimetype', 'application/epub+zip');
        $zip->setCompressionName('mimetype', ZipArchive::CM_STORE);

        return $zip;
    }
}

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

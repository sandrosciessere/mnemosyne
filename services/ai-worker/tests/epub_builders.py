"""Synthetic EPUB builders for tests. All content is original and tiny."""

import io
import zipfile

PNG_A = b"\x89PNG\r\n\x1a\n" + b"tiny-test-png-payload-A"
PNG_B = b"\x89PNG\r\n\x1a\n" + b"tiny-test-png-payload-B"

CONTAINER_XML = """<?xml version="1.0" encoding="UTF-8"?>
<container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container">
  <rootfiles>
    <rootfile full-path="OEBPS/content.opf" media-type="application/oebps-package+xml"/>
  </rootfiles>
</container>
"""

UUID_A = "11111111-2222-3333-4444-555555555555"
UUID_B = "66666666-7777-8888-9999-aaaaaaaaaaaa"

ISBN13 = "9780306406157"  # valid check digit
ISBN13_BAD = "9780306406158"  # invalid check digit
ISBN10 = "0306406152"  # valid; derives to ISBN13 above


def _xhtml(body: str, lang: str = "en") -> str:
    return (
        '<?xml version="1.0" encoding="UTF-8"?>\n'
        '<html xmlns="http://www.w3.org/1999/xhtml" '
        'xmlns:epub="http://www.idpf.org/2007/ops" '
        f'xml:lang="{lang}"><head><title>t</title></head><body>\n{body}\n</body></html>'
    )


def chapter_one() -> str:
    return _xhtml(
        '<h1 id="ch1-title">Chapter One</h1>\n'
        "<p>First paragraph of chapter one.</p>\n"
        '<h2 id="sec1">Section One Point One</h2>\n'
        '<p id="p-key">A second   paragraph with <em>emphasis</em> and a '
        '<a href="ch2.xhtml">link</a>.</p>\n'
        "<blockquote><p>A quoted passage used for testing.</p></blockquote>\n"
        "<ul><li>First item</li><li>Second item</li></ul>"
    )


def chapter_two(word: str = "steady") -> str:
    return _xhtml(
        '<h1 id="ch2-title">Chapter Two</h1>\n'
        f"<p>The second chapter proceeds at a {word} pace.</p>\n"
        "<p>Another plain paragraph in chapter two.</p>"
    )


def chapter_three() -> str:
    return _xhtml(
        '<h1 id="ch3-title">Chapter Three</h1>\n'
        "<p>Final chapter text.</p>\n"
        "<table><tr><td>A</td><td>B</td></tr><tr><td>C</td><td>D</td></tr></table>"
    )


NAV_XHTML = (
    '<?xml version="1.0" encoding="UTF-8"?>\n'
    '<html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops">'
    "<head><title>Nav</title></head><body>"
    '<nav epub:type="toc"><ol>'
    '<li><a href="text/ch1.xhtml#ch1-title">Chapter One</a>'
    '<ol><li><a href="text/ch1.xhtml#sec1">Section One Point One</a></li></ol></li>'
    '<li><a href="text/ch2.xhtml">Chapter Two</a></li>'
    '<li><a href="text/ch3.xhtml">Chapter Three</a></li>'
    "</ol></nav>"
    '<nav epub:type="landmarks"><ol>'
    '<li><a epub:type="bodymatter" href="text/ch1.xhtml">Start</a></li>'
    "</ol></nav>"
    "</body></html>"
)


def _opf3(uuid: str = UUID_A, extra_manifest: str = "", extra_meta: str = "") -> str:
    return f"""<?xml version="1.0" encoding="UTF-8"?>
<package xmlns="http://www.idpf.org/2007/opf" version="3.0" unique-identifier="pub-id" xml:lang="en">
  <metadata xmlns:dc="http://purl.org/dc/elements/1.1/">
    <dc:title id="t1">The Synthetic Book</dc:title>
    <meta refines="#t1" property="title-type">main</meta>
    <dc:title id="t2">A Deterministic Subtitle</dc:title>
    <meta refines="#t2" property="title-type">subtitle</meta>
    <dc:creator id="cre1">Alice Author</dc:creator>
    <meta refines="#cre1" property="role" scheme="marc:relators">aut</meta>
    <meta refines="#cre1" property="file-as">Author, Alice</meta>
    <dc:creator id="cre2">Bob Renderer</dc:creator>
    <meta refines="#cre2" property="role" scheme="marc:relators">trl</meta>
    <dc:language>en</dc:language>
    <dc:identifier id="pub-id">urn:isbn:{ISBN13}</dc:identifier>
    <dc:identifier>urn:uuid:{uuid}</dc:identifier>
    <dc:publisher>Synthetic Press</dc:publisher>
    <dc:date>2020-01-01</dc:date>
    <meta property="dcterms:modified">2020-02-02T00:00:00Z</meta>
    <dc:description>A synthetic book used only for tests.</dc:description>
    <dc:subject>Testing</dc:subject>
    <dc:subject>Software</dc:subject>
    {extra_meta}
  </metadata>
  <manifest>
    <item id="nav" href="nav.xhtml" media-type="application/xhtml+xml" properties="nav"/>
    <item id="cover-img" href="images/cover.png" media-type="image/png" properties="cover-image"/>
    <item id="css" href="style.css" media-type="text/css"/>
    <item id="c1" href="text/ch1.xhtml" media-type="application/xhtml+xml"/>
    <item id="c2" href="text/ch2.xhtml" media-type="application/xhtml+xml"/>
    <item id="c3" href="text/ch3.xhtml" media-type="application/xhtml+xml"/>
    {extra_manifest}
  </manifest>
  <spine>
    <itemref idref="c1"/>
    <itemref idref="c2"/>
    <itemref idref="c3" linear="yes"/>
  </spine>
</package>
"""


NCX = f"""<?xml version="1.0" encoding="UTF-8"?>
<ncx xmlns="http://www.daisy.org/z3986/2005/ncx/" version="2005-1">
  <head><meta name="dtb:uid" content="{ISBN10}"/></head>
  <docTitle><text>The Synthetic Book Two</text></docTitle>
  <navMap>
    <navPoint id="np1" playOrder="1">
      <navLabel><text>Chapter One</text></navLabel>
      <content src="text/ch1.xhtml#ch1-title"/>
      <navPoint id="np1a" playOrder="2">
        <navLabel><text>Section One Point One</text></navLabel>
        <content src="text/ch1.xhtml#sec1"/>
      </navPoint>
    </navPoint>
    <navPoint id="np2" playOrder="3">
      <navLabel><text>Chapter Two</text></navLabel>
      <content src="text/ch2.xhtml"/>
    </navPoint>
  </navMap>
</ncx>
"""

OPF2 = f"""<?xml version="1.0" encoding="UTF-8"?>
<package xmlns="http://www.idpf.org/2007/opf" xmlns:opf="http://www.idpf.org/2007/opf"
         version="2.0" unique-identifier="bookid">
  <metadata xmlns:dc="http://purl.org/dc/elements/1.1/">
    <dc:title>The Synthetic Book Two</dc:title>
    <dc:creator opf:role="aut" opf:file-as="Writer, Wanda">Wanda Writer</dc:creator>
    <dc:creator opf:role="ill">Ivan Illustrator</dc:creator>
    <dc:language>it</dc:language>
    <dc:identifier id="bookid" opf:scheme="ISBN">{ISBN10}</dc:identifier>
    <dc:publisher>Synthetic Press Due</dc:publisher>
    <dc:date opf:event="publication">2010-05-05</dc:date>
    <dc:subject>Testing</dc:subject>
    <meta name="cover" content="cover-img"/>
  </metadata>
  <manifest>
    <item id="ncx" href="toc.ncx" media-type="application/x-dtbncx+xml"/>
    <item id="cover-img" href="images/cover.png" media-type="image/png"/>
    <item id="c1" href="text/ch1.xhtml" media-type="application/xhtml+xml"/>
    <item id="c2" href="text/ch2.xhtml" media-type="application/xhtml+xml"/>
  </manifest>
  <spine toc="ncx">
    <itemref idref="c1"/>
    <itemref idref="c2"/>
  </spine>
</package>
"""


def _zip_bytes(entries, *, mimetype: bytes | None = b"application/epub+zip", store_mimetype: bool = True) -> bytes:
    buf = io.BytesIO()
    with zipfile.ZipFile(buf, "w", zipfile.ZIP_DEFLATED) as zf:
        if mimetype is not None:
            compression = zipfile.ZIP_STORED if store_mimetype else zipfile.ZIP_DEFLATED
            zf.writestr(zipfile.ZipInfo("mimetype"), mimetype, compress_type=compression)
        for name, data in entries:
            zf.writestr(name, data)
    return buf.getvalue()


def _epub3_entries(
    uuid: str = UUID_A,
    cover: bytes = PNG_A,
    css: str = "p { margin: 0.4em; }",
    ch2_word: str = "steady",
):
    # Zip entry order intentionally differs from the spine order.
    return [
        ("META-INF/container.xml", CONTAINER_XML),
        ("OEBPS/text/ch3.xhtml", chapter_three()),
        ("OEBPS/images/cover.png", cover),
        ("OEBPS/text/ch2.xhtml", chapter_two(ch2_word)),
        ("OEBPS/nav.xhtml", NAV_XHTML),
        ("OEBPS/style.css", css),
        ("OEBPS/text/ch1.xhtml", chapter_one()),
        ("OEBPS/content.opf", _opf3(uuid=uuid)),
    ]


def build_epub3(**kwargs) -> bytes:
    return _zip_bytes(_epub3_entries(**kwargs))


def build_epub2() -> bytes:
    entries = [
        ("META-INF/container.xml", CONTAINER_XML),
        ("OEBPS/content.opf", OPF2),
        ("OEBPS/toc.ncx", NCX),
        ("OEBPS/images/cover.png", PNG_A),
        ("OEBPS/text/ch2.xhtml", chapter_two()),
        ("OEBPS/text/ch1.xhtml", chapter_one()),
    ]
    return _zip_bytes(entries)


def build_malformed_container() -> bytes:
    entries = _epub3_entries()
    entries[0] = ("META-INF/container.xml", "<container><rootfiles><rootfile")
    return _zip_bytes(entries)


def build_container_unreadable() -> bytes:
    """Malformed container AND two .opf files -> no safe fallback."""
    entries = _epub3_entries()
    entries[0] = ("META-INF/container.xml", "not xml at all <<<")
    entries.append(("OEBPS/other.opf", _opf3()))
    return _zip_bytes(entries)


def build_malformed_opf() -> bytes:
    entries = [e for e in _epub3_entries() if e[0] != "OEBPS/content.opf"]
    entries.append(("OEBPS/content.opf", "<package><metadata><broken"))
    return _zip_bytes(entries)


def build_wrong_mimetype() -> bytes:
    return _zip_bytes(_epub3_entries(), mimetype=b"text/plain")


def build_path_traversal_epub() -> bytes:
    return _zip_bytes(_epub3_entries() + [("../evil.txt", b"evil")])


def build_absolute_path_epub() -> bytes:
    return _zip_bytes(_epub3_entries() + [("/abs.txt", b"evil")])


def build_symlink_epub() -> bytes:
    buf = io.BytesIO()
    with zipfile.ZipFile(buf, "w", zipfile.ZIP_DEFLATED) as zf:
        zf.writestr(zipfile.ZipInfo("mimetype"), b"application/epub+zip", compress_type=zipfile.ZIP_STORED)
        for name, data in _epub3_entries():
            zf.writestr(name, data)
        info = zipfile.ZipInfo("OEBPS/evil-link")
        info.external_attr = (0o120777 << 16)
        zf.writestr(info, "/etc/passwd")
    return buf.getvalue()


def build_encrypted_zip_entry_epub() -> bytes:
    buf = io.BytesIO()
    with zipfile.ZipFile(buf, "w", zipfile.ZIP_DEFLATED) as zf:
        zf.writestr(zipfile.ZipInfo("mimetype"), b"application/epub+zip", compress_type=zipfile.ZIP_STORED)
        for name, data in _epub3_entries():
            zf.writestr(name, data)
        info = zipfile.ZipInfo("OEBPS/secret.bin")
        zf.writestr(info, b"sealed")
        info.flag_bits |= 0x1  # marks the central-directory record as encrypted
    return buf.getvalue()


def build_duplicate_entry_epub() -> bytes:
    return _zip_bytes(_epub3_entries() + [("OEBPS/style.css", "p { margin: 1em; }")])


def build_zip_bomb_like() -> bytes:
    """Highly compressible 4 MiB entry: ratio far above 200 for a >1MiB file."""
    return _zip_bytes(_epub3_entries() + [("OEBPS/huge.bin", b"\0" * (4 * 1024 * 1024))])


def build_encrypted_content_epub() -> bytes:
    encryption = """<?xml version="1.0" encoding="UTF-8"?>
<encryption xmlns="urn:oasis:names:tc:opendocument:xmlns:container"
            xmlns:enc="http://www.w3.org/2001/04/xmlenc#">
  <enc:EncryptedData>
    <enc:EncryptionMethod Algorithm="http://www.w3.org/2001/04/xmlenc#aes128-cbc"/>
    <enc:CipherData><enc:CipherReference URI="OEBPS/text/ch1.xhtml"/></enc:CipherData>
  </enc:EncryptedData>
</encryption>
"""
    return _zip_bytes(_epub3_entries() + [("META-INF/encryption.xml", encryption)])


def build_font_obfuscation_epub() -> bytes:
    encryption = """<?xml version="1.0" encoding="UTF-8"?>
<encryption xmlns="urn:oasis:names:tc:opendocument:xmlns:container"
            xmlns:enc="http://www.w3.org/2001/04/xmlenc#">
  <enc:EncryptedData>
    <enc:EncryptionMethod Algorithm="http://www.idpf.org/2008/embedding"/>
    <enc:CipherData><enc:CipherReference URI="OEBPS/fonts/font.otf"/></enc:CipherData>
  </enc:EncryptedData>
</encryption>
"""
    return _zip_bytes(_epub3_entries() + [("META-INF/encryption.xml", encryption)])


def build_malformed_xhtml_epub() -> bytes:
    entries = [e for e in _epub3_entries() if e[0] != "OEBPS/text/ch2.xhtml"]
    broken = (
        "<html><body><h1 id='ch2-title'>Chapter Two<p>The second chapter proceeds "
        "at a steady pace.<p>Another plain paragraph in chapter two.</body>"
    )
    entries.append(("OEBPS/text/ch2.xhtml", broken))
    return _zip_bytes(entries)


def build_remote_ref_epub() -> bytes:
    entries = [e for e in _epub3_entries() if e[0] != "OEBPS/text/ch3.xhtml"]
    body = (
        '<h1 id="ch3-title">Chapter Three</h1>\n'
        "<p>Final chapter text.</p>\n"
        '<p><img src="http://example.invalid/pic.png" alt="remote picture"/></p>'
    )
    entries.append(("OEBPS/text/ch3.xhtml", _xhtml(body)))
    return _zip_bytes(entries)


def build_same_text_different_cover() -> tuple[bytes, bytes]:
    a = build_epub3(uuid=UUID_A, cover=PNG_A, css="p { margin: 0.4em; }")
    b = build_epub3(uuid=UUID_B, cover=PNG_B, css="p { margin: 2em; color: teal; }")
    return a, b


def build_not_a_zip() -> bytes:
    return b"this is definitely not a zip archive"

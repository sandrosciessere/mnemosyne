"""Sanitized source XHTML artifacts (``sanitized/NNNN.xhtml``).

Starts from the ORIGINAL content-document markup (not the normalized
nodes) and produces a well-formed, inert XML document suitable for
citation display:

- removed entirely: script, style, template, iframe, object, embed,
  form, input, button, base, foreignObject subtrees; ``<meta
  http-equiv="refresh">``; audio/video/source elements that reference
  remote resources; comments, processing instructions and the DTD (only
  our own XML declaration is emitted);
- removed attributes: every ``on*`` event attribute and any attribute
  whose value starts with ``javascript:`` (or ``vbscript:``);
- remote resources: any src/href/xlink:href/poster/data/srcset whose
  value uses an http/https/ftp scheme or is protocol-relative (``//``)
  is dropped and the element is marked
  ``data-mnemosyne-removed-remote="1"`` (the URL itself is never kept);
- preserved: ``id`` attributes, ``epub:type``, ``xml:lang``/``lang``,
  structural markup, internal/relative hrefs and fragment anchors.

Parsing is defusedxml-only; when the document is not well-formed XML a
tolerant stdlib ``html.parser`` builder reconstructs a best-effort tree
which is then run through the very same sanitization pass, so the
security guarantees hold on the fallback path too.

The root element carries ``data-mnemosyne-source-href`` (zip-internal
spine href) and ``data-mnemosyne-spine-index`` for traceability.
"""

import re
from html.parser import HTMLParser

# Serialization only — all parsing of untrusted bytes goes through defusedxml.
from xml.etree import ElementTree as ET

from app.epub.xmlutils import XmlParseError, parse_xml

XHTML_NS = "http://www.w3.org/1999/xhtml"
EPUB_OPS_NS = "http://www.idpf.org/2007/ops"
SVG_NS = "http://www.w3.org/2000/svg"
XLINK_NS = "http://www.w3.org/1999/xlink"
MATHML_NS = "http://www.w3.org/1998/Math/MathML"

# Deterministic prefixes for serialization (module-level, applied once).
ET.register_namespace("", XHTML_NS)
ET.register_namespace("epub", EPUB_OPS_NS)
ET.register_namespace("svg", SVG_NS)
ET.register_namespace("xlink", XLINK_NS)
ET.register_namespace("m", MATHML_NS)

REMOVED_REMOTE_ATTR = "data-mnemosyne-removed-remote"
SOURCE_HREF_ATTR = "data-mnemosyne-source-href"
SPINE_INDEX_ATTR = "data-mnemosyne-spine-index"

_REMOVE_TAGS = {
    "script",
    "style",
    "template",
    "iframe",
    "object",
    "embed",
    "form",
    "input",
    "button",
    "base",
    "foreignobject",
}
_MEDIA_TAGS = {"audio", "video", "source"}
_URL_ATTRS = {"src", "href", "poster", "data", "srcset"}
_REMOTE_PREFIXES = ("http://", "https://", "ftp://", "//")
_SCRIPT_SCHEMES = ("javascript:", "vbscript:")

_VOID = {"area", "base", "br", "col", "embed", "hr", "img", "input", "link", "meta", "param", "source", "track", "wbr"}
_NAME_RE = re.compile(r"^[A-Za-z_][A-Za-z0-9_.-]*$")
# Prefixed names allowed on the fallback path; their xmlns declarations are
# added to the root when used ("xml" is predeclared by the XML spec).
_FALLBACK_PREFIXES = {"xml": None, "epub": EPUB_OPS_NS, "xlink": XLINK_NS, "svg": SVG_NS}


def _localname(name: str) -> str:
    if name.startswith("{"):
        return name.rsplit("}", 1)[-1]
    if ":" in name:
        return name.rsplit(":", 1)[-1]
    return name


def _is_remote(value: str) -> bool:
    return value.strip().lower().startswith(_REMOTE_PREFIXES)


def _srcset_has_remote(value: str) -> bool:
    return any(_is_remote(candidate.strip().split()[0]) for candidate in value.split(",") if candidate.strip())


def _sanitize_attrs(el: ET.Element) -> None:
    removed_remote = False
    for name in list(el.attrib):
        local = _localname(name).lower()
        value = el.attrib[name]
        if local.startswith("on"):
            del el.attrib[name]
            continue
        if value.strip().lower().startswith(_SCRIPT_SCHEMES):
            del el.attrib[name]
            continue
        if local in _URL_ATTRS:
            remote = _srcset_has_remote(value) if local == "srcset" else _is_remote(value)
            if remote:
                del el.attrib[name]
                removed_remote = True
    if removed_remote:
        el.set(REMOVED_REMOTE_ATTR, "1")


def _subtree_has_remote(el: ET.Element) -> bool:
    for node in el.iter():
        if not isinstance(node.tag, str):
            continue
        for name, value in node.attrib.items():
            local = _localname(name).lower()
            if local in _URL_ATTRS and (_srcset_has_remote(value) if local == "srcset" else _is_remote(value)):
                return True
    return False


def _should_remove(el: ET.Element) -> bool:
    if not isinstance(el.tag, str):  # comments / processing instructions
        return True
    local = _localname(el.tag).lower()
    if local in _REMOVE_TAGS:
        return True
    if local == "meta":
        for name, value in el.attrib.items():
            if _localname(name).lower() == "http-equiv" and value.strip().lower() == "refresh":
                return True
    if local in _MEDIA_TAGS and _subtree_has_remote(el):
        return True
    return False


def _drop_child(parent: ET.Element, index: int, child: ET.Element) -> None:
    """Remove a child element, preserving its tail text."""
    tail = child.tail
    if tail:
        if index > 0:
            prev = parent[index - 1]
            prev.tail = (prev.tail or "") + tail
        else:
            parent.text = (parent.text or "") + tail
    parent.remove(child)


def _sanitize_tree(el: ET.Element) -> None:
    _sanitize_attrs(el)
    index = 0
    while index < len(el):
        child = el[index]
        if _should_remove(child):
            _drop_child(el, index, child)
            continue
        _sanitize_tree(child)
        index += 1


class _FallbackBuilder(HTMLParser):
    """Best-effort ET tree from broken markup (attribute-name validated)."""

    def __init__(self) -> None:
        super().__init__(convert_charrefs=True)
        self._tb = ET.TreeBuilder()
        self._tb.start("mnemosyne-doc", {})
        self._open: list[str] = []

    @staticmethod
    def _safe_name(name: str) -> str | None:
        name = name.lower()
        if ":" in name:
            prefix, local = name.rsplit(":", 1)
            if prefix not in _FALLBACK_PREFIXES or not _NAME_RE.match(local):
                return None
            return name
        return name if _NAME_RE.match(name) else None

    def _start(self, tag: str, attrs) -> str:
        tag = self._safe_name(tag) or "span"
        attr_map: dict[str, str] = {}
        for key, value in attrs:
            key = self._safe_name(key)
            if key is not None:
                attr_map.setdefault(key, value or "")
        self._tb.start(tag, attr_map)
        return tag

    def handle_starttag(self, tag, attrs):
        built = self._start(tag, attrs)
        if _localname(built) in _VOID:
            self._tb.end(built)
        else:
            self._open.append(built)

    def handle_startendtag(self, tag, attrs):
        self._tb.end(self._start(tag, attrs))

    def handle_endtag(self, tag):
        tag = self._safe_name(tag) or "span"
        if tag in self._open:
            while self._open:
                open_tag = self._open.pop()
                self._tb.end(open_tag)
                if open_tag == tag:
                    break

    def handle_data(self, data):
        if data:
            self._tb.data(data)

    def build(self) -> ET.Element:
        self.close()
        while self._open:
            self._tb.end(self._open.pop())
        self._tb.end("mnemosyne-doc")
        return self._tb.close()


def _fallback_root(data: bytes) -> ET.Element:
    builder = _FallbackBuilder()
    builder.feed(data.decode("utf-8", errors="replace"))
    wrapper = builder.build()
    children = list(wrapper)
    if len(children) == 1 and children[0].tag == "html" and not (wrapper.text or "").strip():
        root = children[0]
    else:
        root = ET.Element("html")
        body = ET.SubElement(root, "body")
        body.text = wrapper.text
        for child in children:
            body.append(child)
    # Plain (unprefixed) tags: declare the XHTML default namespace plus any
    # prefixes actually used, so the output is namespace-well-formed.
    root.set("xmlns", XHTML_NS)
    used_prefixes: set[str] = set()
    for el in root.iter():
        names = [el.tag, *el.attrib]
        for name in names:
            if isinstance(name, str) and ":" in name and not name.startswith("{"):
                used_prefixes.add(name.split(":", 1)[0])
    for prefix in sorted(used_prefixes):
        namespace = _FALLBACK_PREFIXES.get(prefix)
        if namespace:
            root.set(f"xmlns:{prefix}", namespace)
    return root


def _serialize(root: ET.Element) -> bytes:
    return b'<?xml version="1.0" encoding="UTF-8"?>\n' + ET.tostring(root, encoding="unicode").encode("utf-8")


def sanitize_document(data: bytes, source_href: str, spine_index: int) -> bytes:
    """Sanitize original document bytes into well-formed, inert XHTML bytes."""
    root: ET.Element | None
    try:
        root = parse_xml(data)
    except XmlParseError:
        root = None
    if root is not None and _should_remove(root):
        root = ET.Element("html")  # pathological root (e.g. a bare <script/>)
    if root is None:
        root = _fallback_root(data)
    _sanitize_tree(root)
    root.set(SOURCE_HREF_ATTR, source_href)
    root.set(SPINE_INDEX_ATTR, str(spine_index))
    return _serialize(root)

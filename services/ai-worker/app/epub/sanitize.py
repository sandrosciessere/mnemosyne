"""Sanitized source XHTML artifacts (``sanitized/NNNN.xhtml``).

Starts from the ORIGINAL content-document markup (not the normalized
nodes) and produces a well-formed, inert XML document suitable for
citation display. The model is a strict **allowlist**:

- only a curated set of semantic/structural/inline XHTML elements is kept
  (see :data:`_ALLOWED_ELEMENTS`); any element NOT on the allowlist is
  *unwrapped* — its children and text are kept in place while the element
  itself is discarded — so no exotic/unknown markup ever survives.  Text
  content is preserved, which is harmless for canonical fidelity because
  ``canonical.txt``/node offsets come from ``normalize.py``, never from
  this artifact;
- a fixed set of elements is removed *with their whole subtree*: script,
  style, template, iframe, object, embed, applet, form, input, button,
  base, link, meta, noscript, foreignObject (see :data:`_REMOVE_TAGS`) —
  nothing that can execute or fetch remains;
- inline SVG is never embedded: any ``<svg>`` is replaced by an inert
  ``<span data-mnemosyne-svg="1">`` placeholder (its structural presence
  is already recorded in the JSONL ``image`` field), so no svg/use/
  xlink:href/foreignObject/animate markup can survive;
- only allowlisted attributes are kept (``id``, ``class``, ``lang``/
  ``xml:lang``, ``dir``, ``title``, ``epub:type``, the ``data-mnemosyne-*``
  trace attributes, table ``colspan``/``rowspan``/``headers``/``scope``,
  ``a@href`` and ``img@src``/``img@alt``).  Everything else — every
  ``on*`` handler, ``style`` (no CSS is preserved at all), and any other
  attribute — is dropped;
- URL-bearing attributes are scheme-checked after canonicalization
  (lower-cased, with every ASCII whitespace/C0 control char 0x00-0x20
  stripped, including embedded ones, defeating ``java\\tscript:`` style
  bypasses).  Only relative/internal references and fragment anchors are
  allowed.  Any ``javascript:``/``vbscript:``/``data:``/``blob:``/
  ``file:`` value (or any scheme at all) is dropped; ``http:``/``https:``/
  ``ftp:`` and protocol-relative ``//`` values are dropped and the element
  is marked ``data-mnemosyne-removed-remote="1"`` (the URL is never kept).

Parsing is defusedxml-only; when the document is not well-formed XML a
tolerant stdlib ``html.parser`` builder reconstructs a best-effort tree
which is then run through the very same allowlist pass, so the security
guarantees hold on the fallback path too.

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
SVG_PLACEHOLDER_ATTR = "data-mnemosyne-svg"

# Elements kept in the sanitized artifact. Everything not here is either
# removed with its subtree (_REMOVE_TAGS / svg) or unwrapped (children and
# text kept, element discarded).
_ALLOWED_ELEMENTS = frozenset(
    {
        "html",
        "head",
        "title",
        "body",
        "div",
        "p",
        "span",
        "h1",
        "h2",
        "h3",
        "h4",
        "h5",
        "h6",
        "ul",
        "ol",
        "li",
        "dl",
        "dt",
        "dd",
        "blockquote",
        "pre",
        "code",
        "em",
        "strong",
        "i",
        "b",
        "u",
        "s",
        "sub",
        "sup",
        "small",
        "mark",
        "br",
        "hr",
        "a",
        "img",
        "figure",
        "figcaption",
        "table",
        "thead",
        "tbody",
        "tfoot",
        "tr",
        "th",
        "td",
        "caption",
        "colgroup",
        "col",
        "section",
        "article",
        "aside",
        "nav",
        "header",
        "footer",
        "ruby",
        "rt",
        "rp",
    }
)

# Removed entirely, subtree and all: nothing that can execute, fetch, embed
# foreign content or carry CSS survives.
_REMOVE_TAGS = frozenset(
    {
        "script",
        "style",
        "template",
        "iframe",
        "object",
        "embed",
        "applet",
        "form",
        "input",
        "button",
        "base",
        "link",
        "meta",
        "noscript",
        "foreignobject",
    }
)

# Attributes allowed on every element.
_GLOBAL_ATTRS = frozenset({"id", "class", "lang", "dir", "title"})
# Attributes allowed only on specific elements (by lower-case local name).
_ELEMENT_ATTRS = {
    "a": frozenset({"href"}),
    "img": frozenset({"src", "alt"}),
    "th": frozenset({"colspan", "rowspan", "headers", "scope"}),
    "td": frozenset({"colspan", "rowspan", "headers", "scope"}),
}
_DATA_TRACE_PREFIX = "data-mnemosyne-"

# URL-bearing attribute local names that must pass the scheme check.
_URL_ATTRS = frozenset({"src", "href", "poster", "data", "srcset"})

_REMOTE_SCHEMES = frozenset({"http", "https", "ftp"})
_SCHEME_RE = re.compile(r"^([a-z][a-z0-9+.-]*):")

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


def _is_epub_type(name: str) -> bool:
    return name == f"{{{EPUB_OPS_NS}}}type" or name == "epub:type"


def _is_xmlns_decl(name: str) -> bool:
    """Namespace declaration attribute (kept verbatim on the fallback root)."""
    return name == "xmlns" or name.startswith("xmlns:") or name.startswith("{http://www.w3.org/2000/xmlns/}")


def _url_verdict(value: str) -> str:
    """Classify a URL-bearing attribute value: 'ok' | 'remote' | 'blocked'.

    Canonicalizes first: browsers ignore ASCII whitespace and C0 control
    chars (0x00-0x20) *anywhere* in a scheme, so they are stripped
    wholesale before the scheme is inspected (entities were already
    decoded by the parser). Only relative/internal references and fragment
    anchors are 'ok'.
    """
    cleaned = "".join(ch for ch in value if ord(ch) > 0x20).lower()
    if cleaned.startswith("//"):  # protocol-relative -> remote
        return "remote"
    match = _SCHEME_RE.match(cleaned)
    if match:
        return "remote" if match.group(1) in _REMOTE_SCHEMES else "blocked"
    return "ok"


def _sanitize_attrs(el: ET.Element) -> None:
    """Drop every attribute not on the allowlist; scheme-check URL values."""
    local_el = _localname(el.tag).lower()
    allowed = _ELEMENT_ATTRS.get(local_el, frozenset())
    removed_remote = False
    for name in list(el.attrib):
        if _is_xmlns_decl(name):
            continue
        local = _localname(name).lower()
        keep = (
            _is_epub_type(name)
            or local.startswith(_DATA_TRACE_PREFIX)
            or local in _GLOBAL_ATTRS
            or local in allowed
        )
        if not keep:
            del el.attrib[name]
            continue
        if local in _URL_ATTRS:
            verdict = _url_verdict(el.attrib[name])
            if verdict == "remote":
                del el.attrib[name]
                removed_remote = True
            elif verdict == "blocked":
                del el.attrib[name]
    if removed_remote:
        el.set(REMOVED_REMOTE_ATTR, "1")


def _classify(el: ET.Element) -> str:
    """One of 'drop' | 'svg' | 'keep' | 'unwrap' for a child element."""
    if not isinstance(el.tag, str):  # comment / processing instruction
        return "drop"
    local = _localname(el.tag).lower()
    if local in _REMOVE_TAGS:
        return "drop"
    if local == "svg":
        return "svg"
    if local in _ALLOWED_ELEMENTS:
        return "keep"
    return "unwrap"


def _append_before(parent: ET.Element, index: int, text: str | None) -> None:
    """Append ``text`` to the flow position just before ``parent[index]``."""
    if not text:
        return
    if index > 0:
        prev = parent[index - 1]
        prev.tail = (prev.tail or "") + text
    else:
        parent.text = (parent.text or "") + text


def _drop_child(parent: ET.Element, index: int, child: ET.Element) -> None:
    """Remove a child element entirely, preserving its tail text."""
    _append_before(parent, index, child.tail)
    parent.remove(child)


def _replace_with_svg_placeholder(parent: ET.Element, index: int, child: ET.Element, span_tag: str) -> None:
    """Swap an ``<svg>`` subtree for an inert placeholder span (tail kept)."""
    placeholder = ET.Element(span_tag)
    placeholder.set(SVG_PLACEHOLDER_ATTR, "1")
    placeholder.tail = child.tail
    parent[index] = placeholder


def _unwrap_child(parent: ET.Element, index: int, child: ET.Element) -> None:
    """Discard ``child`` but splice its text and children into ``parent``.

    The promoted children are left un-advanced so the caller re-examines
    them (and applies the allowlist to each) on the next iteration.
    """
    pre = child.text or ""
    tail = child.tail or ""
    grandchildren = list(child)
    if grandchildren:
        _append_before(parent, index, pre)
        last = grandchildren[-1]
        last.tail = (last.tail or "") + tail
        parent[index : index + 1] = grandchildren
    else:  # leaf: its inner text and tail collapse into the surrounding flow
        _append_before(parent, index, pre + tail)
        parent.remove(child)


def _sanitize_tree(el: ET.Element, span_tag: str) -> None:
    """Apply the allowlist in place to ``el`` (already known to be kept)."""
    _sanitize_attrs(el)
    index = 0
    while index < len(el):
        child = el[index]
        action = _classify(child)
        if action == "drop":
            _drop_child(el, index, child)
        elif action == "svg":
            _replace_with_svg_placeholder(el, index, child, span_tag)
            index += 1
        elif action == "keep":
            _sanitize_tree(child, span_tag)
            index += 1
        else:  # unwrap: promoted children reprocessed without advancing
            _unwrap_child(el, index, child)


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
    if root is not None and _classify(root) != "keep":
        root = ET.Element("html")  # pathological root (e.g. a bare <script/>)
    if root is None:
        root = _fallback_root(data)
    # The placeholder span must match the tree's tag style (namespaced tags
    # on the defusedxml path, plain tags on the fallback path).
    span_tag = f"{{{XHTML_NS}}}span" if isinstance(root.tag, str) and root.tag.startswith("{") else "span"
    _sanitize_tree(root, span_tag)
    root.set(SOURCE_HREF_ATTR, source_href)
    root.set(SPINE_INDEX_ATTR, str(spine_index))
    return _serialize(root)

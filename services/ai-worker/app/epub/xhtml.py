"""Content-document block extractor.

Strategy: parse XHTML as XML via defusedxml first; on XML parse failure
fall back to a tolerant tree builder based on stdlib ``html.parser`` (the
caller records an ``XHTML_NOT_WELL_FORMED`` warning). Both paths produce
the same lightweight tree so a single walker extracts blocks.

Security: script/style/template/iframe/object/embed subtrees are stripped
entirely; remote (http/https) resource references are never fetched and
are reported once per document.
"""

import unicodedata
from dataclasses import dataclass, field
from html.parser import HTMLParser

from app.epub.issues import Issue, warning
from app.epub.xmlutils import XML_LANG, XmlParseError, parse_xml

_HEADING_LEVELS = {"h1": 1, "h2": 2, "h3": 3, "h4": 4, "h5": 5, "h6": 6}
_STRIP = {"script", "style", "template", "iframe", "object", "embed", "head", "noscript", "base"}
_VOID = {"area", "base", "br", "col", "embed", "hr", "img", "input", "link", "meta", "param", "source", "track", "wbr"}
_LIST = {"ul", "ol", "dl"}
_LIST_ITEM = {"li", "dt", "dd"}
_CONTAINERS = {
    "html",
    "body",
    "div",
    "section",
    "article",
    "aside",
    "main",
    "header",
    "footer",
    "nav",
    "hgroup",
    "details",
    "summary",
    "address",
    "form",
    "fieldset",
}
_INLINE = {
    "a",
    "abbr",
    "b",
    "bdi",
    "bdo",
    "big",
    "br",
    "cite",
    "code",
    "data",
    "del",
    "dfn",
    "em",
    "font",
    "i",
    "image",
    "img",
    "ins",
    "kbd",
    "mark",
    "q",
    "rp",
    "rt",
    "ruby",
    "s",
    "samp",
    "small",
    "span",
    "strike",
    "strong",
    "sub",
    "sup",
    "svg",
    "time",
    "tt",
    "u",
    "var",
    "wbr",
}
_IMAGE_TAGS = {"img", "image"}
_REMOTE_ATTRS = ("src", "poster", "data")


@dataclass
class Node:
    tag: str
    attrs: dict[str, str]
    children: list = field(default_factory=list)  # list[Node | str]


@dataclass
class Block:
    type: str
    text: str
    level: int | None = None
    fragment: str | None = None
    lang: str | None = None
    has_image: bool = False


def _norm(text: str) -> str:
    return unicodedata.normalize("NFC", " ".join(text.split()))


# --- tree building -----------------------------------------------------------


def _attr_key(name: str) -> str:
    if name == XML_LANG:
        return "lang"
    if name.startswith("{"):
        return name.rsplit("}", 1)[-1]
    if ":" in name:  # fallback-parser prefixed attrs, e.g. xml:lang, epub:type
        return name.rsplit(":", 1)[-1]
    return name


def _from_etree(el) -> Node | None:
    tag = el.tag
    if not isinstance(tag, str):  # comments / processing instructions
        return None
    local = tag.rsplit("}", 1)[-1] if tag.startswith("{") else tag
    attrs: dict[str, str] = {}
    for key, value in el.attrib.items():
        attrs.setdefault(_attr_key(key), value)
    node = Node(local.lower(), attrs)
    if el.text:
        node.children.append(el.text)
    for child in el:
        converted = _from_etree(child)
        if converted is not None:
            node.children.append(converted)
        if child.tail:
            node.children.append(child.tail)
    return node


class _FallbackTreeBuilder(HTMLParser):
    def __init__(self) -> None:
        super().__init__(convert_charrefs=True)
        self.root = Node("document", {})
        self.stack = [self.root]

    def _make(self, tag: str, attrs) -> Node:
        attr_map: dict[str, str] = {}
        for key, value in attrs:
            attr_map.setdefault(_attr_key(key.lower()), value or "")
        return Node(tag.lower(), attr_map)

    def handle_starttag(self, tag, attrs):
        node = self._make(tag, attrs)
        self.stack[-1].children.append(node)
        if node.tag not in _VOID:
            self.stack.append(node)

    def handle_startendtag(self, tag, attrs):
        self.stack[-1].children.append(self._make(tag, attrs))

    def handle_endtag(self, tag):
        tag = tag.lower()
        for index in range(len(self.stack) - 1, 0, -1):
            if self.stack[index].tag == tag:
                del self.stack[index:]
                return

    def handle_data(self, data):
        if data:
            self.stack[-1].children.append(data)


def parse_content_document(data: bytes) -> tuple[Node, bool]:
    """Parse bytes into a tree; returns (root, used_fallback)."""
    try:
        root = _from_etree(parse_xml(data))
        if root is not None:
            return root, False
    except XmlParseError:
        pass
    builder = _FallbackTreeBuilder()
    builder.feed(data.decode("utf-8", errors="replace"))
    builder.close()
    return builder.root, True


def _find_tag(node: Node, tag: str) -> Node | None:
    if node.tag == tag:
        return node
    for child in node.children:
        if isinstance(child, Node):
            found = _find_tag(child, tag)
            if found is not None:
                return found
    return None


def _has_remote_refs(node: Node) -> bool:
    for attr in _REMOTE_ATTRS:
        value = node.attrs.get(attr, "")
        if value.startswith(("http://", "https://")):
            return True
    if node.tag in ("link", "image", "use") and node.attrs.get("href", "").startswith(("http://", "https://")):
        return True
    return any(_has_remote_refs(child) for child in node.children if isinstance(child, Node))


# --- extraction --------------------------------------------------------------


@dataclass
class _Inline:
    parts: list[str] = field(default_factory=list)
    alts: list[str] = field(default_factory=list)
    has_image: bool = False
    first_id: str | None = None


def _collect_inline(node: Node, acc: _Inline, skip_tags: frozenset = frozenset()) -> None:
    if node.tag in _STRIP or node.tag in skip_tags:
        return
    if acc.first_id is None and node.attrs.get("id"):
        acc.first_id = node.attrs["id"]
    if node.tag in _IMAGE_TAGS:
        acc.has_image = True
        alt = node.attrs.get("alt", "").strip()
        if alt:
            acc.alts.append(alt)
        return
    if node.tag == "br":
        acc.parts.append(" ")
        return
    for child in node.children:
        if isinstance(child, str):
            acc.parts.append(child)
        else:
            _collect_inline(child, acc, skip_tags)


def _has_block_descendant(node: Node) -> bool:
    for child in node.children:
        if isinstance(child, Node):
            if child.tag not in _INLINE and child.tag not in _STRIP:
                return True
            if _has_block_descendant(child):
                return True
    return False


@dataclass
class _Ctx:
    lang: str | None = None
    ancestor_id: str | None = None
    in_blockquote: bool = False


class _Extractor:
    def __init__(self) -> None:
        self.blocks: list[Block] = []
        self.last_heading_id: str | None = None

    # fragment precedence: own id -> first descendant id -> nearest ancestor
    # id -> nearest preceding heading id -> None
    def _fragment(self, node: Node, acc: _Inline, ctx: _Ctx) -> str | None:
        return node.attrs.get("id") or acc.first_id or ctx.ancestor_id or self.last_heading_id

    def _emit(
        self,
        block_type: str,
        text: str,
        ctx: _Ctx,
        *,
        level: int | None = None,
        fragment: str | None = None,
        has_image: bool = False,
        alts: tuple = (),
        pre_normalized: bool = False,
    ) -> None:
        if not pre_normalized:
            text = _norm(text)
        if not text and has_image:
            # image-only block: promote to figure, keep alt text as the text
            block_type = "figure"
        if not text and alts:
            text = _norm(" ".join(alts))
        if not text and not has_image:
            return
        self.blocks.append(
            Block(type=block_type, text=text, level=level, fragment=fragment, lang=ctx.lang, has_image=has_image)
        )

    def _child_ctx(self, node: Node, ctx: _Ctx, **overrides) -> _Ctx:
        return _Ctx(
            lang=node.attrs.get("lang") or ctx.lang,
            ancestor_id=node.attrs.get("id") or ctx.ancestor_id,
            in_blockquote=overrides.get("in_blockquote", ctx.in_blockquote),
        )

    def walk_container(self, node: Node, ctx: _Ctx) -> None:
        pending = _Inline()

        def flush() -> None:
            nonlocal pending
            if pending.parts or pending.has_image or pending.alts:
                text = "".join(pending.parts)
                block_type = "blockquote" if ctx.in_blockquote else "other"
                fragment = pending.first_id or ctx.ancestor_id or self.last_heading_id
                self._emit(
                    block_type,
                    text,
                    ctx,
                    fragment=fragment,
                    has_image=pending.has_image,
                    alts=tuple(pending.alts),
                )
            pending = _Inline()

        for child in node.children:
            if isinstance(child, str):
                pending.parts.append(child)
                continue
            if child.tag in _STRIP:
                continue
            if child.tag in _INLINE:
                _collect_inline(child, pending)
                continue
            flush()
            self.dispatch(child, ctx)
        flush()

    def dispatch(self, node: Node, ctx: _Ctx) -> None:
        tag = node.tag
        ctx_here = _Ctx(
            lang=node.attrs.get("lang") or ctx.lang,
            ancestor_id=ctx.ancestor_id,
            in_blockquote=ctx.in_blockquote,
        )

        if tag in _HEADING_LEVELS:
            acc = _Inline()
            _collect_inline(node, acc)
            fragment = self._fragment(node, acc, ctx_here)
            own_id = node.attrs.get("id") or acc.first_id
            self._emit(
                "heading",
                "".join(acc.parts),
                ctx_here,
                level=_HEADING_LEVELS[tag],
                fragment=fragment,
                has_image=acc.has_image,
                alts=tuple(acc.alts),
            )
            if own_id:
                self.last_heading_id = own_id
            return

        if tag == "p":
            acc = _Inline()
            _collect_inline(node, acc)
            block_type = "blockquote" if ctx_here.in_blockquote else "paragraph"
            self._emit(
                block_type,
                "".join(acc.parts),
                ctx_here,
                fragment=self._fragment(node, acc, ctx_here),
                has_image=acc.has_image,
                alts=tuple(acc.alts),
            )
            return

        if tag == "pre":
            acc = _Inline()
            _collect_inline(node, acc)
            self._emit("code", "".join(acc.parts), ctx_here, fragment=self._fragment(node, acc, ctx_here))
            return

        if tag == "figcaption":
            acc = _Inline()
            _collect_inline(node, acc)
            self._emit("caption", "".join(acc.parts), ctx_here, fragment=self._fragment(node, acc, ctx_here))
            return

        if tag == "blockquote":
            if _has_block_descendant(node):
                self.walk_container(node, self._child_ctx(node, ctx_here, in_blockquote=True))
            else:
                acc = _Inline()
                _collect_inline(node, acc)
                self._emit(
                    "blockquote",
                    "".join(acc.parts),
                    ctx_here,
                    fragment=self._fragment(node, acc, ctx_here),
                    has_image=acc.has_image,
                )
            return

        if tag in _LIST:
            self._walk_list(node, self._child_ctx(node, ctx_here))
            return

        if tag == "table":
            self._walk_table(node, ctx_here)
            return

        if tag == "figure":
            self._walk_figure(node, ctx_here)
            return

        if tag in ("hr", "br"):
            return

        if tag in _CONTAINERS or tag in _LIST_ITEM:
            self.walk_container(node, self._child_ctx(node, ctx_here))
            return

        # Unknown tag: recurse when it holds block structure, else emit 'other'.
        if _has_block_descendant(node):
            self.walk_container(node, self._child_ctx(node, ctx_here))
            return
        acc = _Inline()
        _collect_inline(node, acc)
        self._emit(
            "other",
            "".join(acc.parts),
            ctx_here,
            fragment=self._fragment(node, acc, ctx_here),
            has_image=acc.has_image,
            alts=tuple(acc.alts),
        )

    def _walk_list(self, node: Node, ctx: _Ctx) -> None:
        pending = _Inline()

        def flush() -> None:
            nonlocal pending
            if pending.parts or pending.has_image:
                fragment = pending.first_id or ctx.ancestor_id or self.last_heading_id
                self._emit("list", "".join(pending.parts), ctx, fragment=fragment, has_image=pending.has_image)
            pending = _Inline()

        for child in node.children:
            if isinstance(child, str):
                pending.parts.append(child)
                continue
            if child.tag in _STRIP:
                continue
            if child.tag in _LIST_ITEM:
                flush()
                self._walk_list_item(child, ctx)
            elif child.tag in _INLINE:
                _collect_inline(child, pending)
            else:
                flush()
                self.dispatch(child, ctx)
        flush()

    def _walk_list_item(self, node: Node, ctx: _Ctx) -> None:
        ctx_here = self._child_ctx(node, ctx)
        acc = _Inline()
        nested: list[Node] = []
        for child in node.children:
            if isinstance(child, str):
                acc.parts.append(child)
            elif child.tag in _STRIP:
                continue
            elif child.tag in _INLINE:
                _collect_inline(child, acc)
            elif child.tag == "p":
                _collect_inline(child, acc)
            else:
                nested.append(child)
        self._emit(
            "list_item",
            "".join(acc.parts),
            ctx_here,
            fragment=self._fragment(node, acc, ctx_here),
            has_image=acc.has_image,
            alts=tuple(acc.alts),
        )
        for child in nested:
            self.dispatch(child, ctx_here)

    def _walk_table(self, node: Node, ctx: _Ctx) -> None:
        rows: list[str] = []
        has_image = False
        first_id: str | None = None

        def find_rows(el: Node) -> None:
            nonlocal has_image, first_id
            if el.tag == "tr":
                cells: list[str] = []
                for cell in el.children:
                    if isinstance(cell, Node) and cell.tag in ("td", "th"):
                        acc = _Inline()
                        _collect_inline(cell, acc)
                        for child in cell.children:  # block content inside cells
                            if isinstance(child, Node) and child.tag not in _INLINE and child.tag not in _STRIP:
                                _collect_inline(child, acc)
                        if first_id is None and (cell.attrs.get("id") or acc.first_id):
                            first_id = cell.attrs.get("id") or acc.first_id
                        has_image = has_image or acc.has_image
                        cells.append(_norm("".join(acc.parts)))
                if cells:
                    rows.append("\t".join(cells))
                return
            for child in el.children:
                if isinstance(child, Node) and child.tag not in _STRIP:
                    find_rows(child)

        find_rows(node)
        text = "\n".join(row for row in rows if row.strip())
        fragment = node.attrs.get("id") or first_id or ctx.ancestor_id or self.last_heading_id
        if text.strip() or has_image:
            self._emit("table", text, ctx, fragment=fragment, has_image=has_image, pre_normalized=True)

    def _walk_figure(self, node: Node, ctx: _Ctx) -> None:
        ctx_here = self._child_ctx(node, ctx)
        acc = _Inline()
        captions: list[Node] = []

        def collect(el: Node) -> None:
            for child in el.children:
                if isinstance(child, str):
                    acc.parts.append(child)
                    continue
                if child.tag in _STRIP:
                    continue
                if child.tag == "figcaption":
                    captions.append(child)
                    continue
                _collect_inline(child, acc)

        collect(node)
        fragment = node.attrs.get("id") or acc.first_id or ctx.ancestor_id or self.last_heading_id
        text = _norm("".join(acc.parts))
        if not text and acc.alts:
            text = _norm(" ".join(acc.alts))
        if text or acc.has_image:
            self.blocks.append(
                Block(
                    type="figure",
                    text=text,
                    fragment=fragment,
                    lang=ctx_here.lang,
                    has_image=acc.has_image,
                )
            )
        for caption in captions:
            cap_acc = _Inline()
            _collect_inline(caption, cap_acc)
            self._emit(
                "caption",
                "".join(cap_acc.parts),
                ctx_here,
                fragment=caption.attrs.get("id") or cap_acc.first_id or fragment,
            )


def extract_blocks(data: bytes, href: str, issues: list[Issue]) -> list[Block]:
    """Extract ordered blocks from a content document (never raises on bad markup)."""
    root, used_fallback = parse_content_document(data)
    if used_fallback:
        issues.append(
            warning("XHTML_NOT_WELL_FORMED", "content document is not well-formed XML; used HTML fallback", href=href)
        )
    if _has_remote_refs(root):
        issues.append(
            warning("REMOTE_RESOURCE_REFERENCE", "content document references remote resources", href=href)
        )

    html_el = _find_tag(root, "html") or root
    body = _find_tag(root, "body") or root
    extractor = _Extractor()
    ctx = _Ctx(lang=html_el.attrs.get("lang") or body.attrs.get("lang"))
    extractor.walk_container(body, _Ctx(lang=body.attrs.get("lang") or ctx.lang, ancestor_id=body.attrs.get("id")))
    return extractor.blocks

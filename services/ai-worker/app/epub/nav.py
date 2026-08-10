"""Table-of-contents extraction: EPUB 3 nav document and EPUB 2 NCX."""

import zipfile
from dataclasses import dataclass, field

from app.config import Limits
from app.epub.issues import EpubFailure, Issue, reviewable
from app.epub.opf import PackageDoc, resolve_href, split_fragment
from app.epub.safety import DecompressionBudget, read_member
from app.epub.xmlutils import XmlParseError, localname, parse_xml

OPS_TYPE = "{http://www.idpf.org/2007/ops}type"


@dataclass
class TocResult:
    toc: list[dict] = field(default_factory=list)
    landmarks: list[dict] = field(default_factory=list)
    source: str | None = None  # 'nav' | 'ncx' | None


def _entry(title: str, href_raw: str | None, base_path: str, children: list[dict]) -> dict:
    href = None
    fragment = None
    if href_raw:
        path_part, fragment = split_fragment(href_raw)
        href = resolve_href(base_path, path_part) if path_part else base_path
    return {"title": title, "href": href, "fragment": fragment, "children": children}


def _text_of(el) -> str:
    return " ".join("".join(el.itertext()).split())


def _parse_nav_ol(ol, base_path: str) -> list[dict]:
    entries: list[dict] = []
    for li in ol:
        if localname(li.tag) != "li":
            continue
        title = ""
        href_raw = None
        children: list[dict] = []
        for child in li:
            name = localname(child.tag)
            if name in ("a", "span") and not title:
                title = _text_of(child)
                if name == "a":
                    href_raw = child.get("href")
            elif name == "ol":
                children = _parse_nav_ol(child, base_path)
        if title or href_raw or children:
            entries.append(_entry(title, href_raw, base_path, children))
    return entries


def _parse_nav_doc(data: bytes, nav_path: str) -> TocResult:
    root = parse_xml(data)
    result = TocResult(source="nav")
    for nav_el in root.iter():
        if localname(nav_el.tag) != "nav":
            continue
        nav_types = (nav_el.get(OPS_TYPE) or nav_el.get("epub:type") or "").split()
        ol = next((child for child in nav_el if localname(child.tag) == "ol"), None)
        if ol is None:
            continue
        if "toc" in nav_types and not result.toc:
            result.toc = _parse_nav_ol(ol, nav_path)
        elif "landmarks" in nav_types and not result.landmarks:
            for entry in _parse_nav_ol(ol, nav_path):
                result.landmarks.append(entry)
    return result


def _parse_ncx_navpoint(nav_point, ncx_path: str) -> dict:
    title = ""
    href_raw = None
    children: list[dict] = []
    for child in nav_point:
        name = localname(child.tag)
        if name == "navLabel":
            title = _text_of(child)
        elif name == "content":
            href_raw = child.get("src")
        elif name == "navPoint":
            children.append(_parse_ncx_navpoint(child, ncx_path))
    return _entry(title, href_raw, ncx_path, children)


def _parse_ncx(data: bytes, ncx_path: str) -> TocResult:
    root = parse_xml(data)
    result = TocResult(source="ncx")
    nav_map = next((el for el in root.iter() if localname(el.tag) == "navMap"), None)
    if nav_map is not None:
        for child in nav_map:
            if localname(child.tag) == "navPoint":
                result.toc.append(_parse_ncx_navpoint(child, ncx_path))
    return result


def extract_toc(
    zf: zipfile.ZipFile,
    package: PackageDoc,
    limits: Limits,
    issues: list[Issue],
    budget: DecompressionBudget | None = None,
) -> TocResult:
    """Extract the TOC (nav preferred for EPUB 3, NCX otherwise).

    A malformed/missing TOC with a readable spine yields a reviewable
    NAV_MALFORMED issue (overrideable) and an empty tree — never a failure.
    """
    errors: list[str] = []
    candidates: list[tuple[str, str]] = []  # (kind, item_id)
    if package.nav_id and package.nav_id in package.manifest:
        candidates.append(("nav", package.nav_id))
    if package.ncx_id and package.ncx_id in package.manifest:
        candidates.append(("ncx", package.ncx_id))

    for kind, item_id in candidates:
        item = package.manifest[item_id]
        path = resolve_href(package.opf_path, item.href)
        if path is None or path not in zf.namelist():
            errors.append(f"{kind} document {item.href!r} not found in archive")
            continue
        try:
            data = read_member(zf, path, limits, budget=budget)
            result = _parse_nav_doc(data, path) if kind == "nav" else _parse_ncx(data, path)
        except (XmlParseError, EpubFailure) as exc:
            errors.append(f"{kind} document {path!r} unreadable: {exc}")
            continue
        if result.toc:
            return result
        errors.append(f"{kind} document {path!r} contains no toc entries")

    if candidates or errors:
        issues.append(
            reviewable(
                "NAV_MALFORMED",
                "table of contents is missing or malformed; spine remains readable",
                overrideable=True,
                errors=errors,
            )
        )
    else:
        issues.append(
            reviewable(
                "NAV_MALFORMED",
                "EPUB declares no navigation document (nav or NCX)",
                overrideable=True,
                errors=[],
            )
        )
    return TocResult(source=None)

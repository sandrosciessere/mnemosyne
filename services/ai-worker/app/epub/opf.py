"""OPF package-document parsing: metadata (full fidelity), manifest, spine.

Supports EPUB 2 (attribute refinements: ``opf:role``, ``opf:file-as``,
``opf:scheme``, ``opf:event``) and EPUB 3 (``<meta refines="#id">``
property refinements, ``dcterms:modified``, manifest ``properties``).
"""

import posixpath
import re
import uuid as uuid_module
from dataclasses import dataclass, field
from urllib.parse import unquote

from app.config import Limits
from app.epub.issues import EpubFailure, Issue, hard_block, warning
from app.epub.xmlutils import XML_LANG, XmlParseError, localname, parse_xml

OPF_NS = "http://www.idpf.org/2007/opf"
DC_NS = "http://purl.org/dc/elements/1.1/"

_NS_PREFIXES = {
    OPF_NS: "opf",
    "http://www.w3.org/XML/1998/namespace": "xml",
    "http://www.idpf.org/2007/ops": "epub",
}

_UUID_RE = re.compile(r"^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$")
_DOI_RE = re.compile(r"(10\.\d{4,9}/\S+)")


@dataclass
class ManifestItem:
    id: str
    href: str
    media_type: str
    properties: list[str] = field(default_factory=list)


@dataclass
class SpineRef:
    idref: str
    linear: bool = True


@dataclass
class PackageDoc:
    opf_path: str
    version: str
    epub_major: int
    unique_identifier_id: str | None
    metadata: dict
    raw_metadata: list[dict]
    manifest: dict[str, ManifestItem]
    spine: list[SpineRef]
    spine_toc_idref: str | None
    cover_id: str | None
    nav_id: str | None
    ncx_id: str | None


def resolve_href(base_path: str, href: str) -> str | None:
    """Resolve an OPF/nav href to a zip-internal path (fragment stripped)."""
    if not href:
        return None
    href = unquote(href.split("#", 1)[0])
    if not href or "://" in href or href.startswith("/") or "\\" in href:
        return None
    joined = posixpath.normpath(posixpath.join(posixpath.dirname(base_path), href))
    if joined.startswith("..") or joined == ".":
        return None
    return joined


def split_fragment(href: str) -> tuple[str, str | None]:
    if "#" in href:
        path, frag = href.split("#", 1)
        return path, frag or None
    return href, None


def _isbn13_valid(digits: str) -> bool:
    if len(digits) != 13 or not digits.isdigit():
        return False
    total = sum(int(d) * (1 if i % 2 == 0 else 3) for i, d in enumerate(digits))
    return total % 10 == 0


def _isbn10_valid(chars: str) -> bool:
    if len(chars) != 10 or not chars[:9].isdigit() or not (chars[9].isdigit() or chars[9] in "Xx"):
        return False
    total = sum((10 - i) * (10 if c in "Xx" else int(c)) for i, c in enumerate(chars))
    return total % 11 == 0


def _isbn10_to_isbn13(chars: str) -> str:
    base = "978" + chars[:9]
    total = sum(int(d) * (1 if i % 2 == 0 else 3) for i, d in enumerate(base))
    return base + str((10 - total % 10) % 10)


def normalize_identifier(raw: str, scheme_hint: str | None = None) -> dict:
    """Classify an identifier; always keeps the raw original."""
    value = raw.strip()
    low = value.lower()
    hint = (scheme_hint or "").strip().lower()
    entry = {"raw": raw, "scheme": "other", "value": None, "isbn13": None, "valid": None}

    candidate = value
    if low.startswith("urn:uuid:"):
        candidate = value[len("urn:uuid:") :]
        try:
            entry.update(scheme="uuid", value=str(uuid_module.UUID(candidate)), valid=True)
        except ValueError:
            entry["valid"] = False
        return entry
    if hint == "uuid" or _UUID_RE.match(candidate):
        try:
            entry.update(scheme="uuid", value=str(uuid_module.UUID(candidate)), valid=True)
        except ValueError:
            entry["valid"] = False
        return entry

    if low.startswith("doi:") or "doi.org/" in low or (low.startswith("10.") and "/" in low) or hint == "doi":
        match = _DOI_RE.search(value)
        if match:
            entry.update(scheme="doi", value=match.group(1), valid=True)
            return entry

    isbn_hinted = low.startswith("urn:isbn:") or hint == "isbn"
    if low.startswith("urn:isbn:"):
        candidate = value[len("urn:isbn:") :]
    cleaned = re.sub(r"[\s-]", "", candidate)
    if len(cleaned) == 13 and cleaned.isdigit():
        if _isbn13_valid(cleaned):
            entry.update(scheme="isbn13", value=cleaned, isbn13=cleaned, valid=True)
        else:
            entry["valid"] = False
        return entry
    if len(cleaned) == 10 and (isbn_hinted or _isbn10_valid(cleaned)):
        if _isbn10_valid(cleaned):
            derived = _isbn10_to_isbn13(cleaned)
            entry.update(scheme="isbn10", value=cleaned.upper(), isbn13=derived, valid=True)
        else:
            entry["valid"] = False
        return entry
    if isbn_hinted:
        entry["valid"] = False
        return entry

    if low.startswith(("http://", "https://", "urn:")):
        entry.update(scheme="uri", value=value, valid=True)
    return entry


def _attr_display_name(name: str) -> str:
    if name.startswith("{"):
        ns, local = name[1:].split("}", 1)
        prefix = _NS_PREFIXES.get(ns)
        return f"{prefix}:{local}" if prefix else local
    return name


def _cap_text(text: str | None, limits: Limits, truncated: set[str], fieldname: str) -> str:
    if text is None:
        return ""
    text = text.strip()
    encoded = text.encode("utf-8")
    if len(encoded) > limits.max_metadata_field_bytes:
        truncated.add(fieldname)
        return encoded[: limits.max_metadata_field_bytes].decode("utf-8", errors="ignore")
    return text


def parse_opf(data: bytes, opf_path: str, limits: Limits, issues: list[Issue]) -> PackageDoc:
    try:
        root = parse_xml(data)
    except XmlParseError as exc:
        raise EpubFailure(
            hard_block("EPUB_OPF_UNREADABLE", "OPF package document is not readable", opf_path=opf_path)
        ) from exc
    if localname(root.tag) != "package":
        raise EpubFailure(
            hard_block("EPUB_OPF_UNREADABLE", "OPF root element is not <package>", opf_path=opf_path)
        )

    version = root.get("version", "2.0")
    try:
        epub_major = int(version.split(".", 1)[0])
    except ValueError:
        epub_major = 2
    unique_identifier_id = root.get("unique-identifier")

    metadata_el = None
    manifest_el = None
    spine_el = None
    for child in root:
        name = localname(child.tag)
        if name == "metadata":
            metadata_el = child
        elif name == "manifest":
            manifest_el = child
        elif name == "spine":
            spine_el = child

    truncated: set[str] = set()
    raw_metadata: list[dict] = []
    dc_entries: list[dict] = []
    refines: dict[str, list[tuple[str, str | None, str]]] = {}
    meta_properties: dict[str, list[str]] = {}
    legacy_meta: dict[str, str] = {}

    if metadata_el is not None:
        for el in metadata_el:
            name = localname(el.tag)
            if name is None:
                continue
            ns = el.tag[1:].split("}", 1)[0] if el.tag.startswith("{") else ""
            attrs = {_attr_display_name(k): v for k, v in sorted(el.attrib.items())}
            text = _cap_text(el.text, limits, truncated, name)
            tag_display = f"dc:{name}" if ns == DC_NS else name
            raw_metadata.append({"tag": tag_display, "attrs": attrs, "text": text})

            if ns == DC_NS:
                dc_entries.append({"local": name, "text": text, "el": el})
            elif name == "meta":
                prop = el.get("property")
                refines_target = el.get("refines", "").lstrip("#")
                if prop and refines_target:
                    refines.setdefault(refines_target, []).append((prop, el.get("scheme"), text))
                elif prop:
                    meta_properties.setdefault(prop, []).append(text)
                elif el.get("name"):
                    legacy_meta[el.get("name", "")] = el.get("content", "")

    def _refined(el, prop: str) -> list[str]:
        el_id = el.get("id")
        if not el_id:
            return []
        return [text for p, _scheme, text in refines.get(el_id, []) if p == prop and text]

    titles: list[dict] = []
    creators: list[dict] = []
    contributors: list[dict] = []
    languages: list[str] = []
    identifiers: list[dict] = []
    subjects: list[str] = []
    dates: list[dict] = []
    singles: dict[str, str | None] = dict.fromkeys(
        ["publisher", "description", "rights", "type", "source", "relation", "coverage"]
    )
    modified: str | None = None

    for entry in dc_entries:
        local, text, el = entry["local"], entry["text"], entry["el"]
        if local == "title":
            title_types = _refined(el, "title-type")
            titles.append(
                {"text": text, "type": title_types[0] if title_types else None, "lang": el.get(XML_LANG)}
            )
        elif local in ("creator", "contributor"):
            roles = [role for role in _refined(el, "role")]
            opf_role = el.get(f"{{{OPF_NS}}}role")
            if opf_role:
                roles.append(opf_role)
            file_as_values = _refined(el, "file-as")
            file_as = file_as_values[0] if file_as_values else el.get(f"{{{OPF_NS}}}file-as")
            person = {"name": text, "roles": roles, "file_as": file_as, "lang": el.get(XML_LANG)}
            (creators if local == "creator" else contributors).append(person)
        elif local == "language":
            if text:
                languages.append(text)
        elif local == "identifier":
            normalized = normalize_identifier(text, el.get(f"{{{OPF_NS}}}scheme"))
            normalized["unique"] = bool(unique_identifier_id) and el.get("id") == unique_identifier_id
            identifiers.append(normalized)
        elif local == "subject":
            if text:
                subjects.append(text)
        elif local == "date":
            event = el.get(f"{{{OPF_NS}}}event")
            dates.append({"value": text, "event": event})
            if event == "modification" and modified is None:
                modified = text
        elif local in singles:
            if singles[local] is None and text:
                singles[local] = text

    if "dcterms:modified" in meta_properties and meta_properties["dcterms:modified"]:
        modified = meta_properties["dcterms:modified"][0]

    main_title = next((t["text"] for t in titles if t["type"] == "main"), None)
    if main_title is None and titles:
        main_title = titles[0]["text"]
    subtitle = next((t["text"] for t in titles if t["type"] == "subtitle"), None)

    manifest: dict[str, ManifestItem] = {}
    if manifest_el is not None:
        for el in manifest_el:
            if localname(el.tag) != "item":
                continue
            item_id = el.get("id")
            href = el.get("href")
            if not item_id or not href:
                continue
            manifest[item_id] = ManifestItem(
                id=item_id,
                href=href,
                media_type=el.get("media-type", ""),
                properties=(el.get("properties") or "").split(),
            )

    spine: list[SpineRef] = []
    spine_toc_idref: str | None = None
    if spine_el is not None:
        spine_toc_idref = spine_el.get("toc")
        for el in spine_el:
            if localname(el.tag) != "itemref":
                continue
            idref = el.get("idref")
            if idref:
                spine.append(SpineRef(idref=idref, linear=el.get("linear", "yes").lower() != "no"))

    cover_id = next((item.id for item in manifest.values() if "cover-image" in item.properties), None)
    if cover_id is None:
        legacy_cover = legacy_meta.get("cover")
        if legacy_cover and legacy_cover in manifest:
            cover_id = legacy_cover

    nav_id = next((item.id for item in manifest.values() if "nav" in item.properties), None)
    ncx_id = spine_toc_idref if spine_toc_idref in manifest else None
    if ncx_id is None:
        ncx_id = next(
            (item.id for item in manifest.values() if item.media_type == "application/x-dtbncx+xml"), None
        )

    if truncated:
        issues.append(
            warning(
                "METADATA_FIELD_TRUNCATED",
                "metadata fields exceeded the per-field byte cap and were truncated",
                fields=sorted(truncated),
                limit=limits.max_metadata_field_bytes,
            )
        )
    missing = [
        name
        for name, ok in (
            ("title", bool(main_title)),
            ("language", bool(languages)),
            ("identifier", bool(identifiers)),
        )
        if not ok
    ]
    if missing:
        issues.append(warning("METADATA_INCOMPLETE", "required metadata fields are missing or empty", missing=missing))

    metadata = {
        "title": main_title,
        "subtitle": subtitle,
        "titles": titles,
        "creators": creators,
        "contributors": contributors,
        "languages": languages,
        "identifiers": identifiers,
        "publisher": singles["publisher"],
        "dates": dates,
        "modified": modified,
        "description": singles["description"],
        "subjects": subjects,
        "rights": singles["rights"],
        "type": singles["type"],
        "source": singles["source"],
        "relation": singles["relation"],
        "coverage": singles["coverage"],
        "meta_properties": {k: v for k, v in sorted(meta_properties.items())},
    }

    return PackageDoc(
        opf_path=opf_path,
        version=version,
        epub_major=epub_major,
        unique_identifier_id=unique_identifier_id,
        metadata=metadata,
        raw_metadata=raw_metadata,
        manifest=manifest,
        spine=spine,
        spine_toc_idref=spine_toc_idref,
        cover_id=cover_id,
        nav_id=nav_id,
        ncx_id=ncx_id,
    )

"""OCF container handling: mimetype, container.xml, encryption.xml."""

import posixpath
import zipfile

from app.config import Limits
from app.epub.issues import EpubFailure, Issue, hard_block, reviewable, warning
from app.epub.safety import read_member
from app.epub.xmlutils import XmlParseError, localname, parse_xml

EPUB_MIMETYPE = b"application/epub+zip"

# Font (de)obfuscation is not DRM: these algorithms only mangle embedded
# fonts and never affect spine/content documents.
FONT_OBFUSCATION_ALGORITHMS = {
    "http://www.idpf.org/2008/embedding",
    "http://ns.adobe.com/pdf/enc#RC",
}


def check_mimetype(zf: zipfile.ZipFile, limits: Limits) -> list[Issue]:
    """The ``mimetype`` entry should be first, stored and equal to the EPUB media type."""
    problems: list[str] = []
    infos = zf.infolist()
    names = [info.filename for info in infos]

    if "mimetype" not in names:
        problems.append("missing mimetype entry")
    else:
        if not infos or infos[0].filename != "mimetype":
            problems.append("mimetype entry is not the first zip entry")
        info = zf.getinfo("mimetype")
        if info.compress_type != zipfile.ZIP_STORED:
            problems.append("mimetype entry is compressed (must be stored)")
        content = read_member(zf, "mimetype", limits, max_bytes=1024).strip()
        if content != EPUB_MIMETYPE:
            problems.append(f"mimetype content is {content[:64]!r}, expected 'application/epub+zip'")

    if problems:
        return [
            reviewable(
                "MIMETYPE_INVALID",
                "EPUB mimetype entry is missing or invalid",
                overrideable=True,
                problems=problems,
            )
        ]
    return []


def _internal_relative(path: str) -> str | None:
    """Validate a container-internal relative path; return normalized or None."""
    if not path or "\\" in path or path.startswith("/") or "://" in path:
        return None
    normalized = posixpath.normpath(path)
    if normalized.startswith("..") or normalized == ".":
        return None
    return normalized


def locate_opf(zf: zipfile.ZipFile, limits: Limits, issues: list[Issue]) -> str:
    """Return the OPF package-document path from META-INF/container.xml.

    Malformed/missing container.xml falls back to a single ``*.opf`` in the
    archive (reviewable CONTAINER_XML_MALFORMED); anything else is a hard
    EPUB_CONTAINER_UNREADABLE failure.
    """
    names = set(zf.namelist())
    opf_path: str | None = None
    reason = ""

    if "META-INF/container.xml" in names:
        try:
            root = parse_xml(read_member(zf, "META-INF/container.xml", limits))
            for el in root.iter():
                if localname(el.tag) == "rootfile":
                    candidate = _internal_relative(el.get("full-path", ""))
                    if candidate and candidate in names:
                        opf_path = candidate
                        break
            if opf_path is None:
                reason = "container.xml has no usable rootfile entry"
        except XmlParseError as exc:
            reason = f"container.xml is not well-formed XML: {exc}"
    else:
        reason = "META-INF/container.xml is missing"

    if opf_path is not None:
        return opf_path

    opf_candidates = sorted(name for name in names if name.lower().endswith(".opf"))
    if len(opf_candidates) == 1:
        issues.append(
            reviewable(
                "CONTAINER_XML_MALFORMED",
                "container.xml unusable; fell back to the only .opf in the archive",
                overrideable=True,
                reason=reason,
                opf_path=opf_candidates[0],
            )
        )
        return opf_candidates[0]

    raise EpubFailure(
        hard_block(
            "EPUB_CONTAINER_UNREADABLE",
            "cannot locate the OPF package document",
            reason=reason,
            opf_candidates=opf_candidates,
        )
    )


def check_encryption(zf: zipfile.ZipFile, limits: Limits) -> list[Issue]:
    """Classify META-INF/encryption.xml: font obfuscation vs real DRM."""
    if "META-INF/encryption.xml" not in zf.namelist():
        return []

    try:
        root = parse_xml(read_member(zf, "META-INF/encryption.xml", limits))
    except XmlParseError as exc:
        # Fail closed: an unreadable encryption manifest is treated as DRM.
        return [
            reviewable(
                "DRM_ENCRYPTED_CONTENT",
                "encryption.xml is present but unreadable; treating content as encrypted",
                overrideable=False,
                parse_error=str(exc),
            )
        ]

    font_uris: list[str] = []
    drm_uris: list[str] = []
    for enc_data in root.iter():
        if localname(enc_data.tag) != "EncryptedData":
            continue
        algorithm = ""
        uri = ""
        for el in enc_data.iter():
            name = localname(el.tag)
            if name == "EncryptionMethod":
                algorithm = el.get("Algorithm", "")
            elif name == "CipherReference":
                uri = el.get("URI", "")
        if algorithm in FONT_OBFUSCATION_ALGORITHMS:
            font_uris.append(uri)
        else:
            drm_uris.append(uri)

    issues: list[Issue] = []
    if drm_uris:
        issues.append(
            reviewable(
                "DRM_ENCRYPTED_CONTENT",
                "EPUB declares encrypted content resources (DRM)",
                overrideable=False,
                uris=sorted(drm_uris),
            )
        )
    elif font_uris:
        issues.append(
            warning("FONT_OBFUSCATION", "EPUB uses font obfuscation only (not DRM)", uris=sorted(font_uris))
        )
    return issues

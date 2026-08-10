"""Shared defusedxml parsing helpers.

All XML parsing in the EPUB pipeline MUST go through :func:`parse_xml`
(defusedxml: entity expansion, external entities and DTD retrieval are
forbidden). Never import xml.etree directly for untrusted input.
"""

from xml.etree.ElementTree import Element  # noqa: F401 — type only; parsing goes through defusedxml

from defusedxml import ElementTree as DET

XML_NS = "http://www.w3.org/XML/1998/namespace"
XML_LANG = f"{{{XML_NS}}}lang"


class XmlParseError(Exception):
    """Untrusted XML could not be parsed safely (malformed or defused)."""


def parse_xml(data: bytes) -> Element:
    try:
        return DET.fromstring(data)
    except Exception as exc:  # malformed XML, forbidden entities, DTDs, ...
        raise XmlParseError(str(exc)) from exc


def localname(tag: object) -> str | None:
    """Namespace-agnostic element name; None for comments/PIs."""
    if not isinstance(tag, str):
        return None
    if tag.startswith("{"):
        return tag.rsplit("}", 1)[-1]
    return tag

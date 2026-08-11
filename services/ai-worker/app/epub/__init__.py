"""EPUB validation, parsing, normalization and structure extraction.

Every EPUB is treated as untrusted input: zip members are size-capped
while reading, all XML goes through defusedxml, and nothing is ever
extracted to disk.
"""

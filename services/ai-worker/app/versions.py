"""Handler version constants for the EPUB processing stages.

Bump a version whenever the corresponding stage changes its observable
output (artifact bytes, issue codes, envelope result shape). Artifacts are
only comparable across runs executed with identical handler versions.
"""

VALIDATOR_VERSION = "1.0.0"
PARSER_VERSION = "1.0.0"
NORMALIZER_VERSION = "1.0.0"
STRUCTURER_VERSION = "1.0.0"

# Make the state lookup never match: every course reads as listed, so the whole
# plugin becomes a no-op while still being called.
s/\(\$states\[\$courseid\] === discoverability::STATE_UNLISTED\)/(\$states[\$courseid] === -1)/;

# Make the flag lookup find nothing: every course reads as listed, so the whole
# plugin becomes a no-op while still being called.
s/AND d\.intvalue = 1/AND d.intvalue = -1/;

# Read the stored state raw: an unknown value then shows as itself and is not found under listed.
s/IN \(\{\$known\}\)/IS NOT NULL/;

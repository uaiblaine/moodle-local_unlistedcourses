# Read an unknown tree as met: only a tree the engine says is not met refuses.
s/\$result !== null && \$result->is_met\(\) !== true/\$result !== null \&\& \$result->is_not_met()/;

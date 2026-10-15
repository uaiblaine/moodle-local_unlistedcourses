# Make the empty-set fast path unconditional: the predicate then answers
# "discoverable" for everything even while categories ARE unlisted.
s/        if \(!\$unlisted\) \{/        if (true) {/;

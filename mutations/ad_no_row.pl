# Count and write courses that already have a stored state, not only those without one.
s/WHERE s\.id IS NULL\n/WHERE 1 = 1\n/;

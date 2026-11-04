# Let the site course through (the parameter stays in use, so the statement still runs).
s/AND c\.id <> :siteid\n/AND c.id <> :siteid * 0\n/;

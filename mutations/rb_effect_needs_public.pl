# Drop the state branch of the effect: a course that is not public then reports an effect.
s/WHEN \{\$statesql\} <> /WHEN 1 = 0 AND {\$statesql} <> /;

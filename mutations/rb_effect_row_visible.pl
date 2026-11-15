# Drop the own-visibility branch: a hidden public course then reads as in effect.
s/WHEN \{\$visiblesql\} = 0/WHEN 1 = 0 AND {\$visiblesql} = 0/;

# Drop the path-visibility branch: a public state below a hidden category then reads as in effect.
s/WHEN NOT \{\$pathvisible\}/WHEN 1 = 0 AND NOT {\$pathvisible}/;

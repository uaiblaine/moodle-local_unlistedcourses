# Drop the unlisted-category branch: a public state below an unlisted category then reads as in effect.
s/WHEN \{\$unlistedonpath\}/WHEN 1 = 0 AND {\$unlistedonpath}/;

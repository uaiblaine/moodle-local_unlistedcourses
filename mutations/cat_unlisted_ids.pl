# Make the unlisted-id lookup answer "nothing is unlisted": the fast path of the
# whole feature then disables it silently.
s/return self::\$unlistedids;/return [];/;

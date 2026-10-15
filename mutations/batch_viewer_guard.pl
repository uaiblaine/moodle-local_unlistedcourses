# Let the batch read the rows of a visitor or of the guest account.
s/if \(!\$asked \|\| self::refuses_viewer\(\)\) \{/if (!\$asked) {/;

# Settle a record on a deletion without a move: what an aborted delete_move() left recorded is then acted
# on by a later delete_full() of the same category, against an ordinary move made in between.
s/if \(!\$moved \|\| !\$recorded\) \{/if (!\$recorded) {/;

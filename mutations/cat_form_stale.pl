# Drop the guard on an absent state: a value the rebuilt select no longer offers is cast to 0, and
# a stale form un-hides a category nobody chose to un-hide.
s/if \(!isset\(\$data->state\)\) \{\n            return false;\n        \}\n//;

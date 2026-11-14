# The delete_move callback records nothing: the observer has nothing to settle, and a public row the
# move makes effective stays public.
s/    delete_move_guard::record\(\$category\);\n//;

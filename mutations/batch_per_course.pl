# Answer the batch one course at a time: a statement or more per course on every listing.
s/(        \$answers = array_fill_keys\(\$courseids, self::NO_NEXT_ACTION\);\n)/$1        foreach (\$courseids as \$id) {\n            \$answers[\$id] = self::get_next_action(\$id);\n        }\n        return \$answers;\n/;

# Let the conditional offer beat guest access.
s/\} else if \(\$guest !== null\) \{\n            \$type = self::NEXT_GUEST;\n        \} else if \(\$conditional !== null\) \{\n            \$type = self::NEXT_CONDITIONAL;/} else if (\$conditional !== null) {\n            \$type = self::NEXT_CONDITIONAL;\n        } else if (\$guest !== null) {\n            \$type = self::NEXT_GUEST;/;

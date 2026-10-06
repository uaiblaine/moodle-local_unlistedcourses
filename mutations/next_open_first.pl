# Let guest access beat a real route.
s/if \(\$routes\) \{\n            \$type = self::NEXT_OPEN;\n        \} else if \(\$guest !== null\) \{\n            \$type = self::NEXT_GUEST;/if (\$guest !== null) {\n            \$type = self::NEXT_GUEST;\n        } else if (\$routes) {\n            \$type = self::NEXT_OPEN;/;

# Let the capability-free path write a category that is not public: it then lists an unlisted category.
s/            if \(\$current !== self::STATE_PUBLIC\) \{\n                return;\n            \}\n//;

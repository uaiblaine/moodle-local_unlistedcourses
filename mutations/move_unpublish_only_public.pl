# Let the capability-free path write a course that is not public: it then lists an unlisted course.
s/            if \(\$current !== self::STATE_PUBLIC\) \{\n                return;\n            \}\n//;

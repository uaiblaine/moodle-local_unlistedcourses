# Remove the frontpage exemption.
s/if \(\$courseid == SITEID\) \{\n            \/\/ Everybody participates on the frontpage\.\n            return true;\n        \}/if (false) {\n            return true;\n        }/;

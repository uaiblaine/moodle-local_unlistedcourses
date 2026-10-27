# Treat a submission without the element as a submission of the default:
# saving anything else then resets the state.
s/            if \(!\$isnewcourse\) \{\n                return;\n            \}\n//;

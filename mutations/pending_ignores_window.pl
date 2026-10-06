# Judge an application by the end-before-start rule of active rows: an application whose dates are
# inconsistent drops out, although enrol_apply's queue, which reads only the end date, still lists it.
s/(        \/\/ Must agree with \\enrol_apply\\local\\queue::is_awaiting_decision\(\), which is the same test\.\n)/        if (\$timeend !== 0 && \$timeend < \$timestart) {\n            return \$none;\n        }\n$1/;

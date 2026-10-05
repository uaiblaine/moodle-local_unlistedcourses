# Judge an application by the end-before-start rule of active rows: an application whose dates are
# inconsistent drops out, although enrol_apply's queue, which reads only the end date, still lists it.
s/        if \(\(int\) \$row->status !== ENROL_USER_ACTIVE\) \{\n/        if (\$timeend !== 0 && \$timeend < \$timestart) {\n            return \$none;\n        }\n        if ((int) \$row->status !== ENROL_USER_ACTIVE) {\n/;

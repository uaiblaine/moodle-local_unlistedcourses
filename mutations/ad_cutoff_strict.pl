# Count a course created at the very moment the default took effect as older than it.
s/AND c\.timecreated < :cutoff/AND c.timecreated <= :cutoff/;

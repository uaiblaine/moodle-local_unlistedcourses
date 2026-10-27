# Forget which courses were probed: filter_courses() and are_courses_discoverable() then both read.
s/if \(isset\(self::\$probed\[\$key\]\) \|\| /if (/;

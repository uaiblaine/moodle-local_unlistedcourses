# Drop the viewer from the self enrolment facts' key, at both ends.
s/self::\$selffacts\[self::memo_key\(\$viewer, \$id\)\]/self::\$selffacts[':' . \$id]/;
s/self::\$selffacts\[self::memo_key\(\(int\) \$USER->id, \(int\) \$instance->id\)\]/self::\$selffacts[':' . (int) \$instance->id]/;

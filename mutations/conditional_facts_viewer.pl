# Drop the viewer from the enrol_conditional facts' key, at both ends: one viewer's row answers for the next.
s/self::\$selffacts\[self::memo_key\(\$viewer, \$conditionalid\)\]/self::\$selffacts[':' . \$conditionalid]/;
s/\$factskey = self::memo_key\(\(int\) \$USER->id, \(int\) \$instance->id\);/\$factskey = ':' . (int) \$instance->id;/;

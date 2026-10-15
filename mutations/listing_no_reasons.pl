# Make the listing pay to explain every refusal it probes.
s/\$answer = self::\$nextaction\[\$key\] \?\? self::evaluate_next_action\(\$courseid, false\);/\$answer = self::\$nextaction[\$key] ?? self::evaluate_next_action(\$courseid, true);/;

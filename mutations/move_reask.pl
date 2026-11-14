# Demote every recorded course without asking again after the move: a course the move left inert, under
# another unlisted category or still hidden, loses its public state for nothing.
s/\$courses = self::activated\(discoverability::are_public\(\$recorded\['courses'\]\)\);/\$courses = \$recorded['courses'];/;

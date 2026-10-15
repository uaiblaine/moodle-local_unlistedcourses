# Keep a lapsed waiting-list row waitlisted: the queue no longer counts it, yet an unlisted course
# it belongs to becomes discoverable again.
s/(if \(\$status === self::APPLY_WAITLIST && \$row->enrol === 'apply'\) \{\n(?:            [^\n]*\n)+?            return \['type' => self::)RELATIONSHIP_EXPIRED/$1RELATIONSHIP_WAITLISTED/;

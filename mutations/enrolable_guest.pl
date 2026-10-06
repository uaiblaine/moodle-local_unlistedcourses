# Stop counting guest access as a way in: an unlisted course open to guests is hidden again.
s/private const ENROLABLE_ACTIONS = \[self::NEXT_OPEN, self::NEXT_GUEST, self::NEXT_CONDITIONAL\];/private const ENROLABLE_ACTIONS = [self::NEXT_OPEN, self::NEXT_CONDITIONAL];/;

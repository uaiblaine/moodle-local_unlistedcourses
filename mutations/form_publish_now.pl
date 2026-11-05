# Offer public on what the creator will hold once the course exists: set_state()
# then refuses the offered choice after the course row is inserted.
s/\$canpublish = has_capability\(discoverability::CAPABILITY_PUBLISH, \$context\);/\$canpublish = self::will_hold(discoverability::CAPABILITY_PUBLISH, \$context, \$creating);/;

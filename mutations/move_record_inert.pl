# Record every public course below the category, not only the inert ones: a course that was public
# before the deletion is then taken back to listed too.
s/'courses' => self::inert\(discoverability::are_public\(array_map\('intval', \$courseids\)\)\),/'courses' => array_map('intval', \$courseids),/;

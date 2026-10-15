# Let an unknown stored value through unchanged instead of reading it as listed.
s/\? \$state : self::STATE_DEFAULT;/? \$state : \$state;/;

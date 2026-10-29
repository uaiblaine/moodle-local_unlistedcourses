# Ask what the creator holds NOW instead of what they will hold once the course
# exists: a course creator's choice on a new course is then dropped for the default.
s/return self::will_hold\(self::CAPABILITY_VISIBILITY, \$context, \$isnewcourse\);/return self::will_hold(self::CAPABILITY_VISIBILITY, \$context, false);/;

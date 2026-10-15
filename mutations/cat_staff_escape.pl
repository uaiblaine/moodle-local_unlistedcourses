# Remove the viewhiddencategories escape: managers and course creators then lose
# the categories they administer unless a cohort or a role happens to cover them.
s/\|\| self::staff_escape\(\$unlistedid\);/|| false;/;

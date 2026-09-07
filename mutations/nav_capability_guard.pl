# Drop the manage-capability guard from the settings-navigation callback: the
# entry is then offered to anybody who can open a category page, and it leads to
# a form their save will refuse.
s|if \(!has_capability\(category_discoverability::CAPABILITY_MANAGE, \$context\)\) \{|if (false) {|;

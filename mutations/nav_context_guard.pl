# Name the wrong context class in the settings-navigation guard, which is the
# shape a copy of another plugin's course-scoped callback produces. The node is
# then never offered on the category page it belongs to - and offered on course
# pages, where no 'categorysettings' container exists to receive it.
s|if \(!\$context instanceof \\core\\context\\coursecat\) \{|if (!\$context instanceof \\core\\context\\course) {|;

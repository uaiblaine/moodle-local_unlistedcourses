# Rename the callback core looks up when a category is deleted and its contents
# moved, so the row outlives the category.
s/function local_unlistedcourses_pre_course_category_delete_move\(/function local_unlistedcourses_pre_course_category_delete_move_disabled(/;

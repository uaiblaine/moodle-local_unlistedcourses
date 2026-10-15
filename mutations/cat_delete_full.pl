# Rename the callback core looks up when a category is deleted with its contents,
# so the row outlives the category.
s/function local_unlistedcourses_pre_course_category_delete\(/function local_unlistedcourses_pre_course_category_delete_disabled(/;

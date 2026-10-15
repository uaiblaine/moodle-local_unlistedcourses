# List the PARENT category's cohorts instead of this category's. Only a cohort
# whose context IS this category's own opens it, so the preview would name
# cohorts that grant nothing here and omit the ones that do.
s|cohort_get_cohorts\(\$this->context->id, 0, self::PERPAGE\)|cohort_get_cohorts(\$this->context->get_parent_context()->id, 0, self::PERPAGE)|;

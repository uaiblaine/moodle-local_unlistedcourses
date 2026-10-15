# Treat every viewer as able to read cohort names: the preview then lists the
# cohorts of the category to somebody without moodle/cohort:view.
s|\$cohortsviewable = has_capability\('moodle/cohort:view', \$this->context\);|\$cohortsviewable = true;|;

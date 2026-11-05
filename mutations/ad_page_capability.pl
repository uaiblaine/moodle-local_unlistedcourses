# Open the page to anybody who may publish a course, which a manager may, instead of site administrators.
s/new moodle_url\('\/local\/unlistedcourses\/applydefault\.php'\),\n        'moodle\/site:config',/new moodle_url('\/local\/unlistedcourses\/applydefault.php'),\n        'local\/unlistedcourses:publish',/;

# Leave a course whose staff answer waits for the enrolment state out of statement 2: it is then read course by course.
s/self::staff_without_enrolment\(\$context\) !== true\) \{/self::staff_without_enrolment(\$context) === false) {/;

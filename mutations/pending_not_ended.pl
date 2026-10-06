# Drop enrol_apply's end-date clause: an approved enrolment that the expiry sweep suspended after
# its end date reads as an application awaiting a decision again, and keeps an unlisted course.
s/if \(\$row->enrol === 'apply' && \(\$timeend === 0 \|\| \$timeend > \$now\)\) \{/if (\$row->enrol === 'apply') {/;

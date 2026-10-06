# Count an application whose end date is exactly now as still pending; enrol_apply's queue uses a
# strict comparison, so the two would disagree for that second.
s/\(\$timeend === 0 \|\| \$timeend > \$now\)\) \{/(\$timeend === 0 || \$timeend >= \$now)) {/;

# Count a row somebody wrote meanwhile as a failure rather than as their state, kept.
s/if \(\$DB->record_exists\(discoverability::TABLE, \['courseid' => \$courseid\]\)\) \{/if (false) {/;

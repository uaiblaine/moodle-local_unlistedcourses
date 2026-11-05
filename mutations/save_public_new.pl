# Hand a new course's public value to set_state() from a user who may not publish
# yet: the refusal then leaves the inserted course listed, whatever the default.
s/if \(\$isnewcourse && \$public && !self::may_publish\(\(int\) \$data->id\)\) \{/if (false) {/;

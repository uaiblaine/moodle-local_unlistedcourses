# Let a first insert that collides with a row the backfill wrote meanwhile fail with the database's
# error instead of "busy, try again".
s/if \(!\$DB->is_transaction_started\(\) && \$DB->record_exists\(self::TABLE, \['courseid' => \$courseid\]\)\) \{/if (false) {/;

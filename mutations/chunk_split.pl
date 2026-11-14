# Never split: every list goes to the database in one statement, past the driver's parameter limit.
s/if \(count\(\$ids\) <= self::SIZE\) \{/if (true) {/;

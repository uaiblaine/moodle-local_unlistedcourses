# Stop catching a busy state on restore: the lock's timeout then fails the whole restore of the
# course over its discoverability.
s/\} catch \(state_busy_exception \$e\) \{/} catch (\\coding_exception \$e) {/;

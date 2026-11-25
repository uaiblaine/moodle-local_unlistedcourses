# Take the site-wide lock for a change to unlisted too: unlisting then waits on every relist.
s/\$sharedlock = \$state === self::STATE_UNLISTED \? null : state_lock::category_relist\(\);/\$sharedlock = state_lock::category_relist();/;

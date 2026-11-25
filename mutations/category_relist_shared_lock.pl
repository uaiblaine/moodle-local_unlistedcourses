# Never take the site-wide lock: two relists of nested categories then predict side by side.
s/\$sharedlock = \$state === self::STATE_UNLISTED \? null : state_lock::category_relist\(\);/\$sharedlock = null;/;

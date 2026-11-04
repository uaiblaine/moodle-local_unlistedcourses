# Treat a listed default as something to apply.
s/return \$state === discoverability::STATE_DEFAULT \? null : \$state;/return \$state;/;

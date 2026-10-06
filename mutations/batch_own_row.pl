# Let the batch ignore the viewer's own row on an instance.
s/                isset\(\$held\[\$id\]\),/                false,/;

# Leave the courses a withheld category puts in front of the probe out of the bulk read.
s/if \(\$withheld \|\| \$states\[\$courseid\]/if (\$states[\$courseid]/;

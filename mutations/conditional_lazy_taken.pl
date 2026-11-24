# Without primed facts, never count the rows on a capped enrol_conditional instance.
s/\$facts\['taken'\] : self::places_taken\(\$instance, \$places\);/\$facts['taken'] : 0;/;

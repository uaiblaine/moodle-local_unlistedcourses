# Select only the courses below the category, not those directly in it.
s/WHERE cc\.id = :categoryid OR \{\$below\}",\n(\s+\[.courselevel. => CONTEXT_COURSE, ).categoryid. => \$categoryid, /WHERE {\$below}",\n$1/;

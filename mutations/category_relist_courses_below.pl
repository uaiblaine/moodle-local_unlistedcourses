# Select only the courses directly in the category, not those in its subcategories.
s/WHERE cc\.id = :categoryid OR \{\$below\}",\n(\s+)\[(.courselevel. => CONTEXT_COURSE, .categoryid. => \$categoryid), .below. => \$path \. .\/%.\]/WHERE cc.id = :categoryid",\n${1}[${2}]/;

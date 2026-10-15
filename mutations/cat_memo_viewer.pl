# Drop the viewer from the memo key: the first user to ask about a category then
# answers for every user who asks after them in the same request.
s/return \$viewer \. ':' \. \$categoryid;/return ':' . \$categoryid;/;

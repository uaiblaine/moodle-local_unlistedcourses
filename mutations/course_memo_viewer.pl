# Drop the viewer from the course memo key: the first user to ask about an
# unlisted course then answers for every user who asks after them in the same
# request.
s/return \$viewer \. ':' \. \$courseid;/return ':' . \$courseid;/;

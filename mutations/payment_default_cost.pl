# Drop the site default price: an instance with no price of its own reads as free of charge, and
# its enrolment page, which does charge the default, stops being a route in.
s/return \(float\) \$plugin->get_config\('cost'\);/return 0.0;/;

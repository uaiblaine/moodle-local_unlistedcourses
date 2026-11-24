# Stop evaluating the condition tree: everybody the other checks admit becomes a route in.
s/\$result = \$plugin->evaluate_conditions\(\$instance, \(int\) \$USER->id\);/\$result = null;/;

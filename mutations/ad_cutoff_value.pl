# Trust the latest config_log record even when it no longer holds the current value.
s/\(string\) \$latest->value === \(string\) \$current/true/;

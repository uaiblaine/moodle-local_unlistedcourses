# Build a string with code: a language file must be data only.
s/\$string\['pluginname'\] = 'Course discoverability';/\$string['pluginname'] = 'Course ' . 'discoverability';/;

# Keep instances of a plugin the site has disabled, which enrol_get_instances() drops.
s/if \(!in_array\(\$record->enrol, \$enabled\) \|\| /if (/;

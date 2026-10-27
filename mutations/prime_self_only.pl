# Read facts for every instance, not only enrol_self's: another plugin's capped instance costs a count.
s/if \(\$instance->enrol === 'self'\) \{\n                    \$selfinstances\[\] = \$instance;/if (true) {\n                    \$selfinstances[] = \$instance;/;

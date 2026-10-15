# Stop asking enrol_autoenrol's own rule: any enabled autoenrol instance becomes a route in, also
# one that admits nobody.
s/if \(\$plugin->enrol_allowed\(\$instance, \$USER\) === true\) \{/if (true) {/;

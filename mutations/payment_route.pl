# Forget fee and paypal altogether: an unlisted course whose only route is a payment is hidden
# from the viewers who could pay.
s/if \(\$instance->enrol === 'fee' \|\| \$instance->enrol === 'paypal'\) \{/if (false) {/;

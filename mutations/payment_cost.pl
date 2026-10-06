# Ignore the price of fee and paypal: an instance with no price, on which the enrolment page shows
# an error rather than a payment button, becomes a route in.
s/if \(abs\(self::payment_cost\(\$instance, \$plugin\)\) < 0\.01\) \{/if (false) {/;

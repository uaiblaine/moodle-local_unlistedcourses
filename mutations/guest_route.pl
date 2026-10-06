# Forget guest access: an unlisted course with an enabled guest instance is hidden from the
# logged-in users core would let in as guests.
s/if \(\$instance->enrol === 'guest'\) \{\n            return self::guest_outcome\(\$instance\);/if (\$instance->enrol === 'guest') {\n            return null;/;

# Drop the unlisted-category clamp from is_public(): a course inside an unlisted
# category then serves its landing page to visitors who are not logged in.
s/if \(array_intersect\(\$ids, category_discoverability::unlisted_ids\(\)\)\) \{/if (false) {/;

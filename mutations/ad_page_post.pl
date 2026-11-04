# Queue the task from a GET as well as from a POST.
s/if \(optional_param\('confirm', 0, PARAM_BOOL\) && data_submitted\(\)\) \{/if (optional_param('confirm', 0, PARAM_BOOL)) {/;

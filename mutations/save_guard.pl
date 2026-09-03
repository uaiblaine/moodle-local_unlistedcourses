# Treat a submission without the element as a submission of the default:
# saving anything else then resets the state.
s/if \(\$state === null \|\| empty\(\$data->id\)/if (empty(\$data->id)/;

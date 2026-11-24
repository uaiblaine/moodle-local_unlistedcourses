# Ignore the viewer's own row on an enrol_conditional instance, on every path.
s/        if \(\$ownrow\) \{/        if (false) {/;

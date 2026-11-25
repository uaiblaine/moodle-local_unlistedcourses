# Never release the site-wide lock: every later change that may leave the unlisted state is busy.
s/\n\s+\$sharedlock\?->release\(\);\n(\s+\}\n\s+\}\n)/\n$1/;

# Queue a second task beside one already queued.
s/if \(\$state === null \|\| self::queued_task\(\) \|\| /if (\$state === null || /;

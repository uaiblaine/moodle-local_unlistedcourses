# Never warn that an unlisted category is visible to nobody. The one outcome an
# administrator must not reach by accident then arrives with no warning at all.
s|'nobodywarning' => \$unlisted && \$visiblecount === 0,|'nobodywarning' => false,|;

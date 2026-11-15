# Stop requiring every category of the path to be found: a missing ancestor no longer fails closed.
s/\.depth = \(/.depth >= (/;

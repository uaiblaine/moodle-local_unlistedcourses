# Leave LICENSE out of the release zip: the package then carries no licence file the directory reads.
s/(\/\.mdlshield\s+export-ignore\n)/$1\/LICENSE              export-ignore\n/;

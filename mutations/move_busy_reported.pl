# Skip a row whose lock another writer holds without a word: the row stays public and nothing tells
# whoever deleted the category that it was left behind.
s/\n\s*debugging\(\n\s*"local_unlistedcourses: (?:course|category) .*?DEBUG_NORMAL\n\s*\);//gs;

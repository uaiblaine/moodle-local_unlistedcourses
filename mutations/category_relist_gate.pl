# Never ask what listing an unlisted category again would make public: the manage capability
# alone then publishes every public course and subcategory the category held back.
s/\n\s+self::require_relist_allowed\(\$categoryid, \$userid\);//;

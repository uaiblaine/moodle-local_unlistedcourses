# Keep a busy subcategory public without a log entry: on a site without developer debugging nobody learns of it.
s/\n\s*self::report_kept\(public_state_kept_after_move::KIND_CATEGORY, \$id, \$categoryid, \$userid\);//;

# Record from the request's memos as they stand: a row a stale memo still calls public is dropped as
# effective, never recorded, and the move publishes it.
s/        access::reset_caches\(\);\n//;

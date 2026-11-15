# Match only the first id of the path: the categories below it are no longer read.
s/sql_concat\("'%\/'"/sql_concat("'\/'"/;

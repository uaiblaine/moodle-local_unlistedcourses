# Answer busy inside a caller's transaction too: on MariaDB the re-read finds the row and the owner of the
# transaction is told to try again; on PostgreSQL the re-read fails in the aborted transaction.
s/!\$DB->is_transaction_started\(\) && //;

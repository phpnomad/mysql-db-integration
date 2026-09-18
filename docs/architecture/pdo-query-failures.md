# PDO query failure contract

Status: acceptance contract defined. Production failure handling is unchanged.

PdoDatabaseStrategy already converts PDOException into DatastoreErrorException
with the stable public message `Failed to execute query.` and the original
driver exception as its cause. It currently assumes PDO::query always returns
a statement when no exception was thrown. In silent error mode, PDO returns
false instead. The method then fails while accessing a nonexistent statement,
losing the useful driver classification.

The query method must expose the same failure contract for exception and silent
mode. It throws DatastoreErrorException with the existing public message and
code 500. Its PDOException cause retains the SQLSTATE, vendor error number,
and diagnostic text in errorInfo. For a caught driver exception, preserve that
original exception. For a false return, construct a driver exception carrying
the reported error information. Do not include SQL or driver details in the
public message. Do not change the connection's error mode or retry a query.
Retain the existing PDO::query execution seam, including overrides on an
injected PDO instance. Do not add alternate exec or prepared-statement IO.

Warning mode remains available to ordinary query callers. It retains the host's
PHP warning behavior, including any driver diagnostics that handler receives.
If the warning handler allows PDO to return false, normalize that result just
like silent mode. Do not suppress warnings, replace the host handler, or change
the configured mode. A host handler that throws retains its existing behavior.
This mode is outside the coordination-safe failure contract. The following
optional coordination adapter must reject it before its callback or any write,
without removing ordinary CRUD from a host that uses it.

Successful row results retain their existing shape. Empty SELECT results remain
an empty array. Writes still return their affected-row count, including zero
for a valid unchanged update or unmatched delete. No schema, connection, or
transaction interface changes are needed.

One implementer changes only PdoDatabaseStrategy and its own internal tests.
The acceptance assertions are fixed. Only their incomplete marker may be
removed. The existing production method remains in place during architecture
review so ordinary query behavior continues to work.

The acceptance cases run the real PDO driver in exception and silent modes.
Separate tests cover warning-mode compatibility. They cover a
duplicate-key write, malformed SQL whose driver message contains a sensitive
identifier, successful writes and reads, empty results, and zero affected rows.
A recording PDO subclass forwards every query to the real driver and captures
its actual exception and attempt count. The tests therefore prove original
cause identity, unchanged driver details, and no query retry at the driver
boundary. The recording subclass also observes exec and prepare calls so a
replay cannot escape observation through another driver method. It forwards
both methods to the real driver. A native PDOStatement subclass observes any
explicit execute call on a returned statement and forwards that call as well.
The warning-mode test installs a recording host handler and restores it in a
finally block. It must observe the actual warning, not merely prove that the
strategy constructed a safe exception after diagnostics had escaped elsewhere.
The test uses an explicitly configured, owned schema and connection-local
temporary tables, so it never touches shared application records. Missing
configuration or an unavailable database reports a skip, not a passing driver
proof. The package has no application entry point. The later coordinator and
Siren handler tests must prove this error behavior through their real bindings.

The boundary that handles the failed operation owns logging and uses
LoggerStrategy with safe context. This leaf method preserves the cause and
adds neither logging transport nor retry. Confirmed coordination rollback,
contention, and uncertain commit classification belong to the following adapter
change. They must consume this driver cause without replaying callbacks.

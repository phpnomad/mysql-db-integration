# PDO coordinated operations

Status: backend architecture in progress. No coordinated runtime capability is
enabled by this document. The query wrapper and initializer follow separately.

The MySQL integration will implement the optional database coordination contract.
Siren continues to request complete effects through its datastore handlers. The
handler bridge calls the database contract, and this adapter owns the MySQL
commands and driver state. No SQL or database object enters Siren Core.

## Classes and bindings

CoordinatedDatabaseStrategy extends the integration's existing DatabaseStrategy
as a separate optional interface. Its coordinate method receives the existing
coordination table, complete identity, all participant tables, and a callback.
The callback receives the operation's database strategy. This lower-level seam
is for the integration's query coordinator, not application datastore callers.
Ordinary DatabaseStrategy implementations retain their current obligations.

PdoCoordinatedDatabaseStrategy extends PdoDatabaseStrategy and implements that
optional interface. It owns one attempt on its injected PdoConnection. The same
connection performs eligibility checks, coordination, callback queries, commit,
and rollback. LoggerStrategy is injected for classified failure reporting. This
backend and its optional interface form the first implementation assignment.

### Following query and application bindings

CoordinatedQueryStrategy extends the ordinary MySQL QueryStrategy and implements
the shared CoordinatedQueryStrategy interface. Its constructor requires the
optional coordinated backend. During coordinate, it constructs an ordinary
QueryStrategy directly from the supplied operation backend, TableSchemaService,
and an independent reset ClauseBuilder. It wraps that delegate in
OperationQueryStrategy and closes the wrapper in a finally block. The wrapper
enforces participant tables and lifetime. No global query-strategy resolution
occurs inside the operation.

PdoCoordinationInitializer supplies opt-in query bindings after MySqlInitializer.
Before bootstrap resolves database consumers, the application supplies one
PdoCoordinatedDatabaseStrategy instance under both ordinary and coordinated
backend interfaces. Both aliases must resolve to that same instance. A late
factory replacement is unsafe because the container can retain an earlier
instance. The initializer does not silently replace an established connection.
Existing applications and the SafeMySQL integration keep ordinary CRUD unless
they explicitly choose a supported coordinated backend.

Each production class is a separate implementation assignment. Assertions and
public signatures belong to the architect. The initializer uses PHPNomad's
existing initializer generator.

## Eligibility and record scope

The first supported adapter uses MySQL InnoDB tables in the connection's selected
database. It rejects unsupported participants before the callback or any write.
Validation covers every participant, a complete nonempty primary identity,
int|string identity values, valid identifiers, and the coordination table's
membership. The adapter verifies the actual schema instead of trusting a table
descriptor's claim that an arbitrary condition identifies one record.

The adapter must hold stable table definitions while it checks eligibility and
executes. Preflight alone cannot authorize a table whose engine or definition
changes before use. The adapter establishes table metadata locks within its own
transaction before authorizing the callback. It takes the parent record guard
before querying INFORMATION_SCHEMA, then verifies actual table engines, primary
keys, and triggers while those definitions remain stable. The first implementation
refuses direct participants with triggers, since a trigger can write storage that
cannot roll back. Empty metadata is not proof when the account lacks visibility.

The adapter must prove TRIGGER visibility for each participant before accepting
the trigger query's result. The first supported profile uses a direct grant on
that table, the selected schema, or the whole server, read from
SHOW GRANTS FOR CURRENT_USER(). It accepts TRIGGER or scope-level ALL PRIVILEGES.
MySQL expands global grants into named privileges. Exact decoded schema and
table names must match the selected resource. Wildcard-pattern interpretation
and escaped-pattern spellings are outside the first profile. It does not require
global privileges, change grants, evaluate role-only coverage, or accept partial
revokes and unrecognized grant forms. An account outside the supported profile
keeps ordinary CRUD and receives UnsupportedCoordinationException for the
stronger operation. The grants used as evidence must have been provisioned before
the PDO connection opened. MySQL caches some privileges for the connection or
selected database, so a newly assigned grant is not proof that an existing
session can see the metadata. Account grants, roles, partial-revoke settings,
and privilege tables are trusted deployment state and must remain stable for
that connection's use of coordination. Administrative changes require a new
connection before this capability can be used again. Runtime eligibility does
not lock administrative privilege state.

Native InnoDB foreign-key cascades and SET NULL remain part of the transaction.
MySQL requires related tables to use the same engine and does not invoke triggers
for cascaded changes. The adapter need not enumerate invisible child tables to
establish rollback completeness. Such constraints can add contention and change
rows outside the directly queried participant list. This database contract does
not synthesize datastore cache invalidations or notifications for those native
implicit changes. The handler bridge must state that publication boundary, and
Siren's owned schema must not depend on untracked native changes for its complete
scoring publication. Declared participants constrain direct framework queries,
not every internal effect of database constraints.

Views and session-local temporary tables are unsupported. A temporary table can
shadow an eligible permanent table while INFORMATION_SCHEMA still describes the
permanent object. Eligibility must therefore detect that shadow on the actual
operation connection, not accept a matching catalog name alone.

Pure input checks run first, including all identity fields required by the
descriptor. Actual primary-key verification then runs under stable metadata
locks. An inaccurate descriptor may therefore cause a temporary locking read
before rejection, but it cannot authorize the callback or any write. The
contract promises pre-callback, pre-write refusal, not zero locks on invalid
requests. Valid scopes use every component of the actual primary key.

The adapter owns its transaction. Nested or foreign ambient transactions and
disabled autocommit are unsupported and remain unchanged. The existing atomic
operation strategy is not composed into this path. A missing parent fails
without invoking the callback. Existing parent records coordinate both updates
and creation of children that do not exist yet.

The record guard includes all primary-key fields. Different logical identities
must not share an application-level guard. This adapter must prove independent
progress for distinct records as well as serialization for the same record.
The callback's first data read must see at least the preceding owner's commit.
Eligibility queries must not accidentally establish an older data snapshot.
The first adapter accepts READ COMMITTED and REPEATABLE READ sessions. It does
not change session isolation. In REPEATABLE READ, the record guard must precede
the first ordinary read that could establish a data snapshot. The acceptance
tests must establish that order through observed concurrent committed state,
not by matching an SQL string.

INFORMATION_SCHEMA eligibility reads are treated as capable of establishing a
consistent-read snapshot. The adapter does not rely on those reads being
isolated from the user transaction. Locking the parent before that inspection
keeps the first possible snapshot after the preceding owner's commit.

## Outcomes and cleanup

There is no callback retry. A successful return confirms commit and returns the
callback value unchanged. A callback failure is rethrown unchanged only after
rollback is confirmed. Contention is a distinct retry-eligible failure only
after the whole operation is known to be rolled back. An uncertain commit or
rollback produces CoordinatedOperationOutcomeUnknownException and preserves its
cause. A failed commit must never be reported as a harmless callback failure.

If a failed commit leaves the owned transaction active and a subsequent rollback
is confirmed, report an ordinary datastore failure with the commit failure as
its cause. If commit may already have completed, report an unknown outcome even
when the connection no longer reports an active transaction. A failed rollback
also reports an unknown outcome, whether or not the driver still shows the
transaction. The callback must not end the transaction or issue commands that
implicitly commit. Losing ownership before the commit boundary cannot produce
a successful return.

Driver false returns need the same classification as driver exceptions. The
existing PdoDatabaseStrategy now preserves silent-mode driver error information
through DatastoreErrorException, with separate real-driver acceptance proof.
A zero affected
row count remains a valid no-op, not a query error. Exception and silent PDO
error modes are supported. Warning mode is unsupported for coordination until
its interaction with host error handlers has a proved contract. Ordinary query
behavior outside coordination retains its existing mode.

The adapter logs each failed attempt once through LoggerStrategy::error with
the message `Coordinated database operation failed.`. Context contains `phase`
(validation, coordination, callback, commit, or rollback), `tables` in declared
order, `outcome` (unchanged, rolled_back, or unknown), `retryable`, `causeClass`,
`sqlState`, and `driverCode`. Only a confirmed-aborted contention failure is
retryable. Driver fields are null when the cause chain has no PDOException.
Cause objects, raw messages, identity values, SQL, and connection configuration
do not enter the log context. The thrown failure retains its original cause.
Logger failure must not change a known database outcome into another outcome.
The database failure still reaches the caller even when logging fails. A
successful operation does not depend on a logging call after commit.
The scoped query handle expires before coordinate returns or throws, including
commit failure. No event, shared cache mutation, or other external publication
occurs inside the callback.

## Required proof

Tests use an explicitly owned schema and independent real clients. The package
has no application entry point of its own, so driver behavior and production
initializer wiring are separate checks. Siren later proves its actual Application
boot, handler bridge, events, cache, and complete request or job path.

Single-attempt cases cover complete success, original callback errors, later
write failure, missing records, malformed and incomplete identity, unsupported
tables, foreign and nested operations, disabled autocommit, strict and silent
driver failures, zero affected rows, expired handles, and classified logging.
Fault injection at the driver boundary must distinguish confirmed rollback,
failed rollback, failed commit before persistence, and commit success followed
by a lost acknowledgement. It must not replace the real persistence proof.

Concurrent cases use process messages and server-observed lock state to establish
overlap. The test must observe either the required wait or an incorrectly entered
callback, not assume waiting because time passed. A bounded timeout reports a
broken test environment. Cases cover additive updates, duplicate claims,
absent-child creation, fresh reads after a predecessor, compound identities,
and independent progress on another record. Real deadlocks and lock-wait timeouts
must report confirmed whole-attempt rollback without callback replay. A timeout
after an earlier write must remove that write and its claim. A separate DDL
client must wait to change a participant's engine before the callback first uses
it, then progress after commit. Final assertions read committed database state
through an independent client.

Account-visibility cases create and remove exact test-owned accounts on the
explicitly assigned test server. They prove schema and table grants work without
global privileges, incomplete and role-only visibility refuses, and an actually
invisible trigger cannot leak a nontransactional write. These cases require an
administrative test account on an isolated server, not merely a shared schema.
The partial-revoke case temporarily changes that owned server's partial_revokes
setting and restores its original value in teardown. Database proof runs serially.
Runtime code never provisions accounts, changes server settings, or alters grants.

Production-binding cases resolve both backend aliases and both query aliases,
exercise an ordinary query before coordination, and then prove real coordinated
queries still use that resource. A declared join returns seeded rows. An
undeclared root, join, or write fails before execution. SafeMySQL's unchanged
base interfaces remain usable. No release is ready on skipped database tests.

MySQL's documentation distinguishes a transaction-wide deadlock rollback from
the usual statement-only lock-wait timeout. The adapter must confirm the whole
outcome itself and must not retry either callback automatically.
[InnoDB error handling](https://dev.mysql.com/doc/refman/8.0/en/innodb-error-handling.html)
documents that distinction.

Snapshot timing follows the database's actual isolation behavior. The acceptance
test must prove fresh committed state after waiting for the parent, since a
repeatable-read snapshot can otherwise outlive a newer commit.
[Consistent nonlocking reads](https://dev.mysql.com/doc/refman/8.0/en/innodb-consistent-read.html)
describes that behavior. The concurrency harness can use
[InnoDB lock-wait information](https://dev.mysql.com/doc/refman/8.0/en/innodb-information-schema-understanding-innodb-locking.html)
to observe an actual wait instead of inferring one from a delay.

[Metadata locking](https://dev.mysql.com/doc/refman/8.0/en/metadata-locking.html)
defines the table-definition boundary.
[Foreign-key constraints](https://dev.mysql.com/doc/refman/8.0/en/create-table-foreign-keys.html)
describes same-engine requirements and trigger-free cascades.
[SHOW TRIGGERS](https://dev.mysql.com/doc/refman/8.0/en/show-triggers.html)
documents metadata filtering by privilege.
[SHOW GRANTS](https://dev.mysql.com/doc/refman/8.0/en/show-grants.html)
and [partial revokes](https://dev.mysql.com/doc/refman/8.0/en/partial-revokes.html)
define the grant evidence and the first profile's refusal boundary.

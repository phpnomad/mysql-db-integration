# Query table metadata

Status: metadata implemented. Ordinary query behavior remains compatible.

The MySQL QueryBuilder opts into PHPNomad's HasQueryTables interface. Its new
method reports the root FROM table followed by each joined table, in query
order, including repeated physical tables under distinct aliases. This enables
an operation-local query strategy to validate every participant before build
and execution. It adds no raw query entry point or transaction behavior.

Metadata describes the current query clauses. useTable alone supplies field
context and does not create a FROM source. Replacing FROM changes the reported
root while retaining joins that remain in the SQL. Both left and right joins
appear. A query without FROM reports no sources because build cannot execute
it. Reading metadata neither builds nor resets the query.

reset removes all sources. resetClauses updates metadata for every removed
source clause, including mixed reset requests. Removing select, order, limit,
or another non-source clause does not remove sources. An unknown clause name
must not clear metadata independently of the SQL. Successful build and existing
failed-build resets leave no sources. A cloned builder has independent clause
and metadata state. Table descriptors remain stable for an operation as required
by the shared contract. The class compares each root and join descriptor's name
and alias with the values captured in the actual SQL clause. If either changed,
getReferencedTables throws QueryBuilderException before build or execution.
This refusal avoids reporting a changed descriptor for unchanged SQL. It applies
to the root and both join directions.

The public acceptance tests verify metadata against built SQL and every lifecycle
above. These pure
builder tests need no database. Adapter integration tests must separately run
two cases through the application's real bindings and MySQL: a declared root and
join return seeded rows, while the same flow with an undeclared join fails
before SQL execution. The harness must use an owned schema and reset database
and cache state. This packet is not that integration proof and does not enable
coordinated writes.

The Composer branch alias is a temporary cross-repository review dependency.
Release requires a compatible published database package and an ordinary version
constraint. The feature branch and its locked commit are not release-ready.

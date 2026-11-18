<?php

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Mothership\Core\Database\SqlServer\SqlServerConnection;
use Mothership\Core\Database\SqlServer\SqlServerQueryBuilder;

/**
 * SQL Server is an advisory CI leg, so the merge gate never runs a real server. These pin
 * what the sqlsrv connection compiles without one: a unique index with a nullable key
 * column ignores NULLs (SQL Server otherwise takes one NULL and refuses the second, which
 * no other driver does), and a NOT NULL unique index stays as it was.
 */
function sqlServerUniqueIndexSql(Closure $columns): string
{
    $connection = Connection::getResolver('sqlsrv')(
        fn () => throw new RuntimeException('no SQL Server in this test'), 'db', '', ['driver' => 'sqlsrv']
    );
    $connection->useDefaultSchemaGrammar();

    $blueprint = new Blueprint($connection, 'admins', $columns);
    $blueprint->create();

    return collect($blueprint->toSql())->first(fn (string $sql) => str_starts_with($sql, 'create unique index'));
}

it('resolves the sqlsrv driver to a connection whose builder can insertOrIgnore', function () {
    $connection = Connection::getResolver('sqlsrv')(fn () => null, 'db', '', ['driver' => 'sqlsrv']);

    expect($connection)->toBeInstanceOf(SqlServerConnection::class)
        ->and($connection->query())->toBeInstanceOf(SqlServerQueryBuilder::class);
});

it('filters a unique index on a nullable column to rows where it is not null', function () {
    $sql = sqlServerUniqueIndexSql(fn (Blueprint $table) => $table->string('api_token', 80)->nullable()->unique());

    expect($sql)->toEndWith('("api_token") where "api_token" is not null');
});

it('filters only the nullable columns of a composite unique index', function () {
    $sql = sqlServerUniqueIndexSql(function (Blueprint $table) {
        $table->unsignedInteger('parent_id')->nullable();
        $table->string('name');
        $table->unique(['parent_id', 'name']);
    });

    expect($sql)->toEndWith('("parent_id", "name") where "parent_id" is not null');
});

it('leaves a unique index on NOT NULL columns unfiltered', function () {
    $sql = sqlServerUniqueIndexSql(fn (Blueprint $table) => $table->string('email')->unique());

    expect($sql)->toEndWith('("email")');
});

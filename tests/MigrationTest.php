<?php

namespace MadWeb\SocialAuth\Test;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class MigrationTest extends TestCase
{
    public function test_fresh_schema_has_named_provider_subject_invariant(): void
    {
        $this->assertTrue($this->hasIndex(config('social-auth.table_names.user_has_social_provider'), 'social_auth_provider_subject_unique'));
    }

    public function test_upgrade_uses_configured_table_and_subject_column(): void
    {
        $this->createUpgradeTable('upgrade_pivot', 'provider_subject');
        $migration = $this->upgradeMigration('upgrade_pivot', 'provider_subject');

        $migration->up();

        $this->assertTrue($this->hasIndex('upgrade_pivot', 'social_auth_provider_subject_unique'));
    }

    public function test_upgrade_is_idempotent_and_preserves_rows(): void
    {
        $this->createUpgradeTable('idempotent_pivot', 'provider_subject');
        app('db')->table('idempotent_pivot')->insert([
            'user_id' => 1,
            'social_provider_id' => 1,
            'provider_subject' => 'same',
            'token' => 'token',
        ]);
        $migration = $this->upgradeMigration('idempotent_pivot', 'provider_subject');

        $migration->up();
        $migration->up();

        $this->assertSame(1, app('db')->table('idempotent_pivot')->count());
        $this->assertTrue($this->hasIndex('idempotent_pivot', 'social_auth_provider_subject_unique'));
    }

    public function test_upgrade_is_noop_for_fresh_install_index(): void
    {
        $migration = $this->upgradeMigration(
            config('social-auth.table_names.user_has_social_provider'),
            config('social-auth.foreign_keys.social_subject')
        );

        $migration->up();

        $this->assertTrue($this->hasIndex('user_has_social_provider', 'social_auth_provider_subject_unique'));
    }

    public function test_upgrade_rejects_duplicates_without_changing_rows_or_indexes(): void
    {
        $this->createUpgradeTable('duplicate_pivot', 'provider_subject');
        app('db')->table('duplicate_pivot')->insert([
            ['user_id' => 1, 'social_provider_id' => 1, 'provider_subject' => 'same', 'token' => 'a'],
            ['user_id' => 2, 'social_provider_id' => 1, 'provider_subject' => 'same', 'token' => 'b'],
        ]);
        $migration = $this->upgradeMigration('duplicate_pivot', 'provider_subject');

        $this->expectException(RuntimeException::class);
        try {
            $migration->up();
        } finally {
            $this->assertSame(2, app('db')->table('duplicate_pivot')->count());
            $this->assertFalse($this->hasIndex('duplicate_pivot', 'social_auth_provider_subject_unique'));
        }
    }

    public function test_upgrade_down_removes_only_named_index(): void
    {
        $this->createUpgradeTable('rollback_pivot', 'provider_subject');
        $migration = $this->upgradeMigration('rollback_pivot', 'provider_subject');
        $migration->up();
        $migration->down();
        $migration->down();

        $this->assertFalse($this->hasIndex('rollback_pivot', 'social_auth_provider_subject_unique'));
        $this->assertTrue(Schema::hasColumn('rollback_pivot', 'provider_subject'));
    }

    public function test_ordinary_same_named_index_is_preserved_and_blocks_upgrade(): void
    {
        $this->createUpgradeTable('ordinary_index_pivot', 'provider_subject');
        Schema::table('ordinary_index_pivot', function (Blueprint $blueprint): void {
            $blueprint->index('provider_subject', 'social_auth_provider_subject_unique');
        });
        app('db')->table('ordinary_index_pivot')->insert([
            'user_id' => 1,
            'social_provider_id' => 1,
            'provider_subject' => 'same',
            'token' => 'token',
        ]);
        $migration = $this->upgradeMigration('ordinary_index_pivot', 'provider_subject');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('index name is already in use');
        try {
            $migration->up();
        } finally {
            $migration->down();
            $this->assertTrue(Schema::hasIndex('ordinary_index_pivot', 'social_auth_provider_subject_unique'));
            $this->assertFalse(Schema::hasIndex('ordinary_index_pivot', 'social_auth_provider_subject_unique', 'unique'));
            $this->assertSame(1, app('db')->table('ordinary_index_pivot')->count());
        }
    }

    public function test_unique_constraint_preserves_first_owner(): void
    {
        $provider = config('social-auth.models.social')::whereSlug('facebook')->first();
        $first = $this->getTestUser();
        $second = User::create(['email' => 'second@example.com', 'avatar' => '']);
        $first->attachSocial($provider, 'same-subject', 'first-token');

        $this->expectException(QueryException::class);
        try {
            $second->attachSocial($provider, 'same-subject', 'second-token');
        } finally {
            $this->assertDatabaseHas('user_has_social_provider', [
                'user_id' => $first->getKey(),
                'social_provider_id' => $provider->getKey(),
                'social_id' => 'same-subject',
                'token' => 'first-token',
            ]);
        }
    }

    private function createUpgradeTable(string $table, string $subject): void
    {
        // SQLite scopes index names globally; free the fresh-install fixture's
        // name so the independent upgrade fixture can exercise the contract.
        if ($this->hasIndex(config('social-auth.table_names.user_has_social_provider'), 'social_auth_provider_subject_unique')) {
            Schema::table(config('social-auth.table_names.user_has_social_provider'), function (Blueprint $blueprint): void {
                $blueprint->dropUnique('social_auth_provider_subject_unique');
            });
        }

        $defaultTable = config('social-auth.table_names.user_has_social_provider');
        if ($this->hasIndex($defaultTable, 'social_auth_provider_subject_unique')) {
            Schema::table($defaultTable, function ($blueprint): void {
                $blueprint->dropUnique('social_auth_provider_subject_unique');
            });
        }

        Schema::create($table, function (Blueprint $blueprint) use ($subject): void {
            $blueprint->unsignedBigInteger('user_id');
            $blueprint->unsignedInteger('social_provider_id');
            $blueprint->string($subject);
            $blueprint->string('token');
        });
    }

    private function upgradeMigration(string $table, string $subject): object
    {
        config([
            'social-auth.table_names.user_has_social_provider' => $table,
            'social-auth.foreign_keys.social_subject' => $subject,
        ]);

        return require __DIR__.'/../database/migrations/add_social_auth_v5_provider_subject_unique_index.php.stub';
    }

    private function hasIndex(string $table, string $name): bool
    {
        return collect(app('db')->select('PRAGMA index_list("'.$table.'")'))
            ->contains(fn ($index) => ($index->name ?? null) === $name);
    }
}

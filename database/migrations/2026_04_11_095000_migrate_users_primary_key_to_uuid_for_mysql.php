<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Sur des bases déjà migrées avec users.id en BIGINT, les FK foreignUuid()
 * (groupes, inspirations, …) échouent avec errno 150.
 * Cette migration convertit users.id en CHAR(36) et réaligne sessions + Sanctum.
 *
 * SQLite : no-op (schéma déjà UUID depuis 0001).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        if (! Schema::hasTable('users')) {
            return;
        }

        $idColumn = DB::selectOne('SHOW COLUMNS FROM `users` WHERE Field = ?', ['id']);
        if ($idColumn === null || $this->typeIsUuidLike($idColumn->Type)) {
            return;
        }

        Schema::disableForeignKeyConstraints();

        try {
            $this->dropForeignKeysReferencingUsersTable();

            Schema::dropIfExists('groupe_items');
            Schema::dropIfExists('inspirations');
            Schema::dropIfExists('groupes');

            DB::statement('ALTER TABLE `users` ADD COLUMN `new_id` CHAR(36) NULL');

            $oldIds = DB::table('users')->pluck('id');
            $map = [];
            foreach ($oldIds as $oldId) {
                $map[(string) $oldId] = Str::uuid()->toString();
                DB::table('users')->where('id', $oldId)->update(['new_id' => $map[(string) $oldId]]);
            }

            $this->rewriteSessionsUserIds();
            $this->rewriteSanctumTokenableIds($map);

            DB::statement('ALTER TABLE `users` DROP PRIMARY KEY');
            DB::statement('ALTER TABLE `users` DROP COLUMN `id`');
            DB::statement('ALTER TABLE `users` CHANGE `new_id` `id` CHAR(36) NOT NULL');
            DB::statement('ALTER TABLE `users` ADD PRIMARY KEY (`id`)');

            if (Schema::hasTable('sessions')) {
                DB::statement('ALTER TABLE `sessions` ADD CONSTRAINT `sessions_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL');
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    public function down(): void
    {
        // Irréversible sans perte d’identifiants.
    }

    private function typeIsUuidLike(string $mysqlType): bool
    {
        $t = strtolower($mysqlType);

        return str_contains($t, 'char') || str_contains($t, 'binary');
    }

    private function rewriteSessionsUserIds(): void
    {
        if (! Schema::hasTable('sessions')) {
            return;
        }

        DB::statement('ALTER TABLE `sessions` ADD COLUMN `new_user_id` CHAR(36) NULL');

        DB::statement('
            UPDATE `sessions` s
            INNER JOIN `users` u ON s.`user_id` = u.`id`
            SET s.`new_user_id` = u.`new_id`
        ');

        DB::statement('ALTER TABLE `sessions` DROP COLUMN `user_id`');
        DB::statement('ALTER TABLE `sessions` CHANGE `new_user_id` `user_id` CHAR(36) NULL');
    }

    /**
     * @param  array<string, string>  $oldIdToUuid
     */
    private function rewriteSanctumTokenableIds(array $oldIdToUuid): void
    {
        if (! Schema::hasTable('personal_access_tokens')) {
            return;
        }

        $userClass = User::class;

        foreach ($oldIdToUuid as $old => $uuid) {
            DB::table('personal_access_tokens')
                ->where('tokenable_type', $userClass)
                ->where(function ($q) use ($old): void {
                    $q->where('tokenable_id', $old)->orWhere('tokenable_id', (string) $old);
                })
                ->update(['tokenable_id' => $uuid]);
        }

        $col = DB::selectOne('SHOW COLUMNS FROM `personal_access_tokens` WHERE Field = ?', ['tokenable_id']);
        if ($col !== null && str_contains(strtolower((string) $col->Type), 'bigint')) {
            DB::statement('ALTER TABLE `personal_access_tokens` MODIFY `tokenable_id` CHAR(36) NOT NULL');
        }
    }

    private function dropForeignKeysReferencingUsersTable(): void
    {
        $database = DB::getDatabaseName();
        if ($database === '') {
            return;
        }

        $constraints = DB::select(
            'SELECT DISTINCT TABLE_NAME, CONSTRAINT_NAME
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = ?
               AND REFERENCED_TABLE_NAME = ?
               AND TABLE_NAME != ?',
            [$database, 'users', 'users'],
        );

        foreach ($constraints as $row) {
            $table = $row->TABLE_NAME;
            $name = $row->CONSTRAINT_NAME;
            if (! is_string($table) || ! is_string($name)) {
                continue;
            }
            DB::statement('ALTER TABLE `'.$table.'` DROP FOREIGN KEY `'.$name.'`');
        }
    }
};

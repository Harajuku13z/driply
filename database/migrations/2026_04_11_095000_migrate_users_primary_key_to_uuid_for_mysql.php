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

            if (! Schema::hasColumn('users', 'new_id')) {
                DB::statement('ALTER TABLE `users` ADD COLUMN `new_id` CHAR(36) NULL');
            }

            $oldIds = DB::table('users')->whereNull('new_id')->pluck('id');
            foreach ($oldIds as $oldId) {
                DB::table('users')->where('id', $oldId)->update([
                    'new_id' => Str::uuid()->toString(),
                ]);
            }

            $this->rewriteSessionsUserIds();
            $this->rewriteSanctumTokenableIds();

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

        $userIdCol = DB::selectOne('SHOW COLUMNS FROM `sessions` WHERE Field = ?', ['user_id']);
        if ($userIdCol !== null && $this->typeIsUuidLike($userIdCol->Type)) {
            return;
        }

        if (! Schema::hasColumn('sessions', 'new_user_id')) {
            DB::statement('ALTER TABLE `sessions` ADD COLUMN `new_user_id` CHAR(36) NULL');
        }

        DB::statement('
            UPDATE `sessions` s
            INNER JOIN `users` u ON s.`user_id` = u.`id`
            SET s.`new_user_id` = u.`new_id`
        ');

        if (Schema::hasColumn('sessions', 'user_id')) {
            DB::statement('ALTER TABLE `sessions` DROP COLUMN `user_id`');
        }

        if (Schema::hasColumn('sessions', 'new_user_id')) {
            DB::statement('ALTER TABLE `sessions` CHANGE `new_user_id` `user_id` CHAR(36) NULL');
        }
    }

    /**
     * tokenable_id en BIGINT : impossible d’y écrire un UUID avant changement de type.
     * On utilise une colonne CHAR temporaire, comme pour sessions.user_id.
     */
    private function rewriteSanctumTokenableIds(): void
    {
        if (! Schema::hasTable('personal_access_tokens')) {
            return;
        }

        $col = DB::selectOne('SHOW COLUMNS FROM `personal_access_tokens` WHERE Field = ?', ['tokenable_id']);
        if ($col === null) {
            return;
        }

        $typeLower = strtolower((string) $col->Type);
        $isIntegerMorph = str_contains($typeLower, 'int');

        $userClass = User::class;

        if ($isIntegerMorph) {
            if (! Schema::hasColumn('personal_access_tokens', 'new_tokenable_id')) {
                DB::statement('ALTER TABLE `personal_access_tokens` ADD COLUMN `new_tokenable_id` CHAR(36) NULL');
            }

            DB::statement(
                'UPDATE `personal_access_tokens` t
                 INNER JOIN `users` u ON t.`tokenable_type` = ? AND t.`tokenable_id` = u.`id`
                 SET t.`new_tokenable_id` = u.`new_id`
                 WHERE t.`new_tokenable_id` IS NULL',
                [$userClass],
            );

            DB::table('personal_access_tokens')
                ->where('tokenable_type', $userClass)
                ->whereNull('new_tokenable_id')
                ->delete();

            DB::statement(
                'UPDATE `personal_access_tokens`
                 SET `new_tokenable_id` = CAST(`tokenable_id` AS CHAR(36))
                 WHERE `new_tokenable_id` IS NULL',
            );

            if (Schema::hasColumn('personal_access_tokens', 'tokenable_id')) {
                DB::statement('ALTER TABLE `personal_access_tokens` DROP COLUMN `tokenable_id`');
            }

            if (Schema::hasColumn('personal_access_tokens', 'new_tokenable_id')) {
                DB::statement('ALTER TABLE `personal_access_tokens` CHANGE `new_tokenable_id` `tokenable_id` CHAR(36) NOT NULL');
            }

            return;
        }

        DB::statement(
            'UPDATE `personal_access_tokens` t
             INNER JOIN `users` u ON t.`tokenable_type` = ? AND (t.`tokenable_id` = CAST(u.`id` AS CHAR) OR t.`tokenable_id` = u.`id`)
             SET t.`tokenable_id` = u.`new_id`',
            [$userClass],
        );
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

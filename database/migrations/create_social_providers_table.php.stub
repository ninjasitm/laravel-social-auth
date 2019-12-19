<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateSocialProvidersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $table_names = config('social-auth.table_names');
        $foreign_keys = config('social-auth.foreign_keys');
        $model_name = config('social-auth.models.user');

        // Get users table name
        $userModel = new $model_name;
        $userTable = $userModel->getTable();

        Schema::create($table_names['social_providers'], function (Blueprint $table) use ($userTable, $table_names) {
            $table->increments('id');
            $table->string('label');
            $table->string('slug')->unique();
            $table->json('scopes')->nullable();
            $table->json('parameters')->nullable();
            $table->boolean('override_scopes')->default(false);
            $table->boolean('stateless')->default(false);
            $table->timestamps();
        });

        Schema::create(
            $table_names['user_has_social_provider'],
            function (Blueprint $table) use ($userTable, $table_names, $foreign_keys) {
                $table->bigInteger($foreign_keys['users'])->unsigned();
                $table->integer('social_provider_id')->unsigned();
                $table->string('token');
                $table->string($foreign_keys['socials']);
                $table->timestamp('expires_in')->nullable();

                $table->foreign($foreign_keys['users'])
                    ->references('id')
                    ->on($userTable)
                    ->onDelete('cascade');

                $table->foreign('social_provider_id')
                    ->references('id')
                    ->on($table_names['social_providers'])
                    ->onDelete('cascade');

                $table->primary([$foreign_keys['users'], 'social_provider_id']);
            }
        );
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        $table_names = config('social-auth.table_names');

        Schema::dropIfExists($table_names['user_has_social_provider']);
        Schema::dropIfExists($table_names['social_providers']);
    }
}

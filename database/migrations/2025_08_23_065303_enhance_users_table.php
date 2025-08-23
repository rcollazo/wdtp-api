<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username')->unique()->after('email');
            $table->enum('role', ['admin', 'moderator', 'contributor', 'viewer'])->default('viewer')->after('username');
            $table->string('phone')->nullable()->after('role');
            $table->date('birthday')->nullable()->after('phone');
            $table->string('city')->after('birthday');
            $table->string('state')->after('city');
            $table->string('country')->after('state');
            $table->string('zipcode')->after('country');
            $table->boolean('enabled')->default(false)->after('zipcode');
            
            // Add indexes for location fields
            $table->index('city');
            $table->index('state');
            $table->index('country');
            $table->index('zipcode');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['city']);
            $table->dropIndex(['state']);
            $table->dropIndex(['country']);
            $table->dropIndex(['zipcode']);
            
            $table->dropColumn([
                'username',
                'role',
                'phone',
                'birthday',
                'city',
                'state',
                'country',
                'zipcode',
                'enabled'
            ]);
        });
    }
};

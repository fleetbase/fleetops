<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCommentsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('comments', function (Blueprint $table) {
            $table->increments('id');
            $table->string('uuid')->nullable()->index();
            $table->string('public_id')->nullable()->unique();
            $table->foreignUuid('commenter_uuid');
            $table->text('comment');
            $table->foreignUuid('order_uuid');
            $table->unsignedInteger('parent_comment_id')->nullable();
            $table->timestamp('created_at')->nullable()->index();
            $table->timestamp('updated_at')->nullable();
            $table->softDeletes();

            $table->foreign('commenter_uuid')->references('uuid')->on('users')->onUpdate('CASCADE')->onDelete('CASCADE');
            $table->foreign('order_uuid')->references('uuid')->on('orders')->onUpdate('CASCADE')->onDelete('CASCADE');
            $table->foreign('parent_comment_id')->references('id')->on('comments')->onUpdate('CASCADE')->onDelete('CASCADE');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('comments');
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per Postilio inbound message: a delivery claims it before importing, so a repeat never imports it twice.
 */
class CreatePostilioInboundMessagesTable extends Migration
{
    public function up()
    {
        Schema::create('postilio_inbound_messages', function (Blueprint $table) {
            $table->increments('id');
            $table->string('inbound_id', 36)->unique();
            $table->unsignedInteger('mailbox_id');
            // processing, done or gone (Postilio deleted the content before it was imported).
            $table->string('status', 16);
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('postilio_inbound_messages');
    }
}

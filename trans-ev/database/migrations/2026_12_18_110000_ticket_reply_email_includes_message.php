<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The "reply from support" email now carries the reply text ({{message}}) so customers can answer by email.
 * Only the untouched built-in wording is updated; a template an admin has edited is left as it is.
 */
return new class extends Migration
{
    private const OLD = "# Hello {{name}},\n\nWe have replied to your ticket.\n\nTicket: {{number}} · {{subject}}\n\nStatus: {{status}}.";

    private const NEW = "# Hello {{name}},\n\nWe have replied to your ticket {{number}} · {{subject}}:\n\n{{message}}\n\nStatus: {{status}}. Reply to this email or open the ticket to answer.";

    public function up(): void
    {
        DB::table('message_templates')->where('key', 'ticket.agent_reply')->where('channel', 'mail')->where('body', self::OLD)->update(['body' => self::NEW]);
    }

    public function down(): void
    {
        DB::table('message_templates')->where('key', 'ticket.agent_reply')->where('channel', 'mail')->where('body', self::NEW)->update(['body' => self::OLD]);
    }
};

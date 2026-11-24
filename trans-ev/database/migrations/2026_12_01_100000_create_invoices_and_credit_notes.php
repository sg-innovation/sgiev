<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * GST tax invoices and credit notes (CGST Act s.31/s.34, CGST Rules 46 and 53).
 * - Numbering is gapless and consecutive per supplier GSTIN, per financial year, per document type.
 * - Documents are immutable: supplier/recipient details and amounts are snapshotted, a SHA-256 of the
 *   canonical content is stored, and MySQL refuses deletes. Corrections are made with credit notes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('gstin', 15);
            $table->string('financial_year', 7);            // 2026-27
            $table->string('doc_type', 3);                  // INV | CRN
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
            $table->unique(['gstin', 'financial_year', 'doc_type']);
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('booking_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('station_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('number', 16);
            $table->unsignedInteger('sequence');
            $table->string('financial_year', 7);
            $table->timestamp('issued_at');
            $table->string('supply_type', 3);               // B2B | B2C
            $table->string('supplier_gstin', 15);
            $table->string('supplier_state_code', 2);
            $table->string('recipient_gstin', 15)->nullable();
            $table->string('place_of_supply', 2);
            $table->boolean('reverse_charge')->default(false);
            $table->json('supplier');                       // legal name, trade name, address, PAN, contact
            $table->json('recipient');                      // name, email, phone, address/state, GSTIN
            $table->json('site');                           // station, charge point, connector, slot, vehicle
            $table->json('lines');
            $table->string('sac_code', 8);
            $table->decimal('subtotal', 12, 2);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('taxable_amount', 12, 2);
            $table->decimal('gst_rate', 5, 2);
            $table->decimal('cgst', 12, 2)->default(0);
            $table->decimal('sgst', 12, 2)->default(0);
            $table->decimal('igst', 12, 2)->default(0);
            $table->decimal('total_tax', 12, 2);
            $table->decimal('round_off', 6, 2)->default(0);
            $table->decimal('total', 12, 2);
            $table->string('amount_in_words', 255);
            $table->string('content_hash', 64);
            $table->timestamp('emailed_at')->nullable();
            $table->timestamps();
            $table->unique(['supplier_gstin', 'number']);
            $table->index(['company_id', 'issued_at']);
            $table->index(['supplier_gstin', 'financial_year']);
        });

        Schema::create('credit_notes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('refund_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->foreignId('booking_id')->constrained()->restrictOnDelete();
            $table->string('number', 16);
            $table->unsignedInteger('sequence');
            $table->string('financial_year', 7);
            $table->timestamp('issued_at');
            $table->string('supplier_gstin', 15);
            $table->string('recipient_gstin', 15)->nullable();
            $table->string('place_of_supply', 2);
            $table->string('reason', 255);
            $table->decimal('taxable_amount', 12, 2);
            $table->decimal('gst_rate', 5, 2);
            $table->decimal('cgst', 12, 2)->default(0);
            $table->decimal('sgst', 12, 2)->default(0);
            $table->decimal('igst', 12, 2)->default(0);
            $table->decimal('total_tax', 12, 2);
            $table->decimal('total', 12, 2);
            $table->string('amount_in_words', 255);
            $table->string('content_hash', 64);
            $table->timestamp('emailed_at')->nullable();
            $table->timestamps();
            $table->unique(['supplier_gstin', 'number']);
            $table->index(['company_id', 'issued_at']);
        });

        if (DB::getDriverName() === 'mysql') {
            foreach (['invoices', 'credit_notes'] as $table) {
                DB::unprepared("DROP TRIGGER IF EXISTS `{$table}_no_delete`");
                DB::unprepared("CREATE TRIGGER `{$table}_no_delete` BEFORE DELETE ON `{$table}` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$table} cannot be deleted; issue a credit note instead'");
                // Only delivery tracking may change after issue.
                DB::unprepared("DROP TRIGGER IF EXISTS `{$table}_no_update`");
                DB::unprepared(<<<SQL
                    CREATE TRIGGER `{$table}_no_update` BEFORE UPDATE ON `{$table}` FOR EACH ROW
                    BEGIN
                        IF NOT (NEW.number <=> OLD.number AND NEW.total <=> OLD.total AND NEW.taxable_amount <=> OLD.taxable_amount
                            AND NEW.cgst <=> OLD.cgst AND NEW.sgst <=> OLD.sgst AND NEW.igst <=> OLD.igst AND NEW.issued_at <=> OLD.issued_at
                            AND NEW.supplier_gstin <=> OLD.supplier_gstin AND NEW.recipient_gstin <=> OLD.recipient_gstin AND NEW.content_hash <=> OLD.content_hash) THEN
                            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$table} are immutable once issued';
                        END IF;
                    END
                SQL);
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            foreach (['invoices', 'credit_notes'] as $table) {
                DB::unprepared("DROP TRIGGER IF EXISTS `{$table}_no_delete`");
                DB::unprepared("DROP TRIGGER IF EXISTS `{$table}_no_update`");
            }
        }
        Schema::dropIfExists('credit_notes');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('document_sequences');
    }
};

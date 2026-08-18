<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payout destinations and identity documents. The most sensitive data here.
 *
 * Adopting Stripe Connect and Paystack split payments would have removed these
 * tables entirely by moving the liability to the processor. Settlement stays
 * manual for now, so the data stays — which turns "encrypt it" from a nicety
 * into a requirement, and is why it lands in the first migration rather than
 * being retrofitted once real bank details exist.
 *
 * Every column marked below is cast to `encrypted` on the model, so values are
 * ciphertext at rest and unreadable in a database dump. They are deliberately
 * split into their own tables so that neither is ever returned by a careless
 * `select *` on organizations — the previous platform kept banking details and
 * government ID numbers on a wide shared table that an unauthenticated
 * endpoint returned in full.
 *
 * Access to both is role-restricted in Filament and logged, because encryption
 * protects against a leaked dump and not against a legitimate login.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_payout_details', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();

            // The rail this destination pays out on. An organization selling in
            // both markets needs one per currency.
            $table->string('rail');            // interac | bank_transfer
            $table->char('currency', 3);

            // --- encrypted at rest ------------------------------------------
            $table->text('interac_email')->nullable();
            $table->text('bank_name')->nullable();
            $table->text('account_name')->nullable();
            $table->text('account_number')->nullable();
            $table->text('transit_number')->nullable();     // Canada
            $table->text('institution_number')->nullable(); // Canada
            $table->text('bank_code')->nullable();          // Nigeria
            // ----------------------------------------------------------------

            // Safe to display without decrypting the whole record.
            $table->string('account_last_four', 4)->nullable();

            $table->timestampTz('verified_at')->nullable();
            $table->timestampsTz();

            $table->unique(['organization_id', 'currency', 'rail']);
        });

        Schema::create('organization_identity_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('submitted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('document_type');    // passport | drivers_licence | national_id

            // --- encrypted at rest ------------------------------------------
            $table->text('legal_first_name')->nullable();
            $table->text('legal_last_name')->nullable();
            $table->text('date_of_birth')->nullable();
            $table->text('document_number')->nullable();
            $table->text('expires_on')->nullable();
            // ----------------------------------------------------------------

            // On the private disk, served only through an authorising
            // controller with a short-lived signed URL. Never web-reachable.
            $table->string('document_path')->nullable();

            // The previous platform collected all of this and never looked at
            // it. A review outcome makes that a decision rather than a drawer.
            $table->string('review_status')->default('pending');
            $table->text('review_note')->nullable();
            $table->foreignUuid('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();

            $table->timestampsTz();
            $table->index(['organization_id', 'review_status']);
        });

        // Who looked at the sensitive tables, and when. Encryption stops a
        // stolen dump; this is what covers an authorised person browsing.
        Schema::create('sensitive_data_accesses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subject_type');     // payout_details | identity_document
            $table->uuid('subject_id');
            $table->string('action');           // viewed | exported | decrypted
            $table->string('ip_address', 45)->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['subject_type', 'subject_id']);
            $table->index(['user_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sensitive_data_accesses');
        Schema::dropIfExists('organization_identity_documents');
        Schema::dropIfExists('organization_payout_details');
    }
};

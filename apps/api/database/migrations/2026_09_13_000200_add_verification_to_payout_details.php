<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Who verified a payout destination, and how.
 *
 * verified_at existed from the start and nothing ever set it, so every
 * destination on file was unverified and nothing depended on the difference.
 * A timestamp alone would not say enough: when a payout goes to the wrong
 * account, the question is who confirmed that account and on what evidence.
 *
 * Also repairs account numbers written before the model's mutator was fixed.
 * It encrypted with PHP serialization while the column's cast decrypts
 * without it, so reading one back produced `s:10:"0123456789";` — the string
 * somebody sending a payout would have copied.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_payout_details', function (Blueprint $table) {
            $table->foreignUuid('verified_by')->nullable()->after('verified_at')->constrained('users')->nullOnDelete();
            // How it was confirmed: a test deposit, a call to a number already
            // on file, a bank letter. Free text would drift into "ok".
            $table->string('verification_method')->nullable()->after('verified_by');
            $table->text('verification_note')->nullable()->after('verification_method');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE organization_payout_details ADD CONSTRAINT organization_payout_details_verification_method_check
            CHECK (verification_method IS NULL OR verification_method IN ('test_deposit', 'confirmed_by_phone', 'bank_document', 'interac_test_transfer'))
        SQL);

        // A verification with no verifier is not one.
        DB::statement(<<<'SQL'
            ALTER TABLE organization_payout_details ADD CONSTRAINT organization_payout_details_verification_complete_check
            CHECK (verified_at IS NULL OR verification_method IS NOT NULL)
        SQL);

        DB::table('organization_payout_details')
            ->whereNotNull('account_number')
            ->orderBy('id')
            ->each(function (object $row) {
                try {
                    $plain = Crypt::decryptString($row->account_number);
                } catch (Throwable) {
                    // Not readable with this key. Left alone rather than
                    // guessed at; the organizer re-enters it.
                    Log::warning('Payout account number could not be decrypted during repair', ['id' => $row->id]);

                    return;
                }

                if (! preg_match('/^s:\d+:".*";$/s', $plain)) {
                    return;
                }

                $value = unserialize($plain, ['allowed_classes' => false]);

                if (! is_string($value)) {
                    return;
                }

                DB::table('organization_payout_details')
                    ->where('id', $row->id)
                    ->update(['account_number' => Crypt::encryptString($value)]);
            });
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE organization_payout_details DROP CONSTRAINT IF EXISTS organization_payout_details_verification_complete_check');
        DB::statement('ALTER TABLE organization_payout_details DROP CONSTRAINT IF EXISTS organization_payout_details_verification_method_check');

        Schema::table('organization_payout_details', function (Blueprint $table) {
            $table->dropConstrainedForeignId('verified_by');
            $table->dropColumn(['verification_method', 'verification_note']);
        });
    }
};

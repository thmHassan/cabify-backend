<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->string('payment_provider')->nullable()->after('comment');
            $table->string('payment_reference')->nullable()->after('payment_provider');
        });

        $prefixes = [
            'Stripe wallet top-up: ' => 'Stripe wallet top-up',
            'Stripe package purchase: ' => 'Stripe package purchase',
        ];

        foreach ($prefixes as $prefix => $description) {
            DB::table('wallet_transactions')
                ->where('comment', 'like', $prefix . '%')
                ->orderBy('id')
                ->chunkById(100, function ($transactions) use ($prefix, $description) {
                    foreach ($transactions as $transaction) {
                        $reference = trim(substr((string) $transaction->comment, strlen($prefix)));
                        $referenceAlreadyUsed = $reference !== '' && DB::table('wallet_transactions')
                            ->where('payment_provider', 'stripe')
                            ->where('payment_reference', $reference)
                            ->exists();

                        DB::table('wallet_transactions')
                            ->where('id', $transaction->id)
                            ->update([
                                'comment' => $description,
                                'payment_provider' => 'stripe',
                                'payment_reference' => $reference !== '' && !$referenceAlreadyUsed ? $reference : null,
                            ]);
                    }
                });
        }

        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->unique(
                ['payment_provider', 'payment_reference'],
                'wallet_transactions_payment_reference_unique'
            );
        });
    }

    public function down(): void
    {
        DB::table('wallet_transactions')
            ->where('payment_provider', 'stripe')
            ->whereNotNull('payment_reference')
            ->orderBy('id')
            ->chunkById(100, function ($transactions) {
                foreach ($transactions as $transaction) {
                    DB::table('wallet_transactions')
                        ->where('id', $transaction->id)
                        ->update([
                            'comment' => $transaction->comment . ': ' . $transaction->payment_reference,
                        ]);
                }
            });

        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->dropUnique('wallet_transactions_payment_reference_unique');
            $table->dropColumn(['payment_provider', 'payment_reference']);
        });
    }
};

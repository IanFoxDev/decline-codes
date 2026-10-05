<?php

declare(strict_types=1);

namespace IanFoxDev\DeclineCodes\Tests;

use IanFoxDev\DeclineCodes\Catalog;
use IanFoxDev\DeclineCodes\DeclineClass;
use PHPUnit\Framework\TestCase;

final class StripeProfileTest extends TestCase
{
    /**
     * The card decline codes listed on docs.stripe.com/declines/codes on 5 October 2026.
     */
    private const array STRIPE_CODES = [
        'authentication_required', 'authentication_not_handled', 'approve_with_id', 'call_issuer', 'card_not_supported',
        'card_velocity_exceeded', 'currency_not_supported', 'do_not_honor', 'do_not_try_again', 'duplicate_transaction',
        'expired_card', 'fraudulent', 'generic_decline', 'incorrect_address', 'incorrect_cvc', 'incorrect_number',
        'incorrect_pin', 'incorrect_zip', 'insufficient_funds', 'invalid_account', 'invalid_amount', 'invalid_cvc',
        'invalid_expiry_month', 'invalid_expiry_year', 'invalid_number', 'invalid_pin', 'issuer_not_available', 'lost_card',
        'merchant_blacklist', 'new_account_information_available', 'no_action_taken', 'not_permitted', 'offline_pin_required',
        'online_or_offline_pin_required', 'pickup_card', 'pin_try_exceeded', 'processing_error', 'reenter_transaction',
        'restricted_card', 'revocation_of_all_authorizations', 'revocation_of_authorization', 'security_violation',
        'service_not_allowed', 'stolen_card', 'stop_payment_order', 'testmode_decline', 'transaction_not_allowed',
        'try_again_later', 'withdrawal_count_limit_exceeded', 'mobile_device_authentication_required',
    ];

    public function testEveryStripeCardCodeIsMapped(): void
    {
        $stripe = Catalog::default()->stripe();
        $mapped = $stripe->codes();
        sort($mapped);
        $expected = self::STRIPE_CODES;
        sort($expected);

        self::assertSame($expected, $mapped);
        self::assertSame(8, $stripe->recommendedMaxRetries);
    }

    public function testTheDeclineCodeAloneGivesTheReasonAndAClass(): void
    {
        $stripe = Catalog::default()->stripe();

        self::assertSame(DeclineClass::Later, $stripe->resolve('insufficient_funds')->class);
        self::assertSame(DeclineClass::Never, $stripe->resolve('stolen_card')->class);
        self::assertSame(DeclineClass::FixData, $stripe->resolve('incorrect_zip')->class);
        self::assertSame('incorrect_address', $stripe->resolve('incorrect_zip')->reason->id);
        self::assertSame(DeclineClass::CustomerAction, $stripe->resolve('authentication_required')->class);
        self::assertSame('generic_decline', $stripe->resolve('something_new')->reason->id);
    }

    public function testStripeAdviceCodeTightensTheClass(): void
    {
        $stripe = Catalog::default()->stripe();

        self::assertSame(DeclineClass::Never, $stripe->resolve('generic_decline', 'do_not_try_again')->class);
        self::assertSame(DeclineClass::FixData, $stripe->resolve('do_not_honor', 'confirm_card_data')->class);
        self::assertSame(DeclineClass::Later, $stripe->resolve('generic_decline', 'try_again_later')->class);
        self::assertSame(DeclineClass::Never, $stripe->resolve('lost_card', 'try_again_later')->class);
    }

    public function testTheNetworkCodeDecidesWhenStripePassesItOn(): void
    {
        $stripe = Catalog::default()->stripe();

        // Stripe says generic_decline, Visa says 46 Closed account: never.
        $closed = $stripe->resolve('generic_decline', brand: 'visa', networkDeclineCode: '46');
        self::assertSame(DeclineClass::Never, $closed->class);
        self::assertSame(1, $closed->category);
        self::assertSame('46', $closed->network?->code);
        self::assertSame('generic_decline', $closed->reason->id);

        // Stripe says insufficient_funds, Mastercard sends MAC 03: never.
        $mac03 = $stripe->resolve('insufficient_funds', brand: 'mastercard', networkDeclineCode: '51', networkAdviceCode: '03');
        self::assertSame(DeclineClass::Never, $mac03->class);
        self::assertSame('03', $mac03->network?->advice?->code);

        // Visa 51 is category 2.
        $funds = $stripe->resolve('insufficient_funds', brand: 'Visa', networkDeclineCode: '51');
        self::assertSame(2, $funds->category);
        self::assertSame(DeclineClass::Later, $funds->class);

        // A brand without a profile falls back to Stripe's own codes.
        self::assertNull($stripe->resolve('insufficient_funds', brand: 'amex', networkDeclineCode: '51')->network);
    }

    public function testSensitiveReasonsAreShownAsGeneric(): void
    {
        $catalog = Catalog::default();

        foreach (['lost_card', 'stolen_card', 'fraudulent', 'merchant_blacklist'] as $code) {
            self::assertSame('generic_decline', $catalog->stripe()->resolve($code)->reason->forCustomer($catalog)->id, $code);
        }
        self::assertSame('insufficient_funds', $catalog->stripe()->resolve('insufficient_funds')->reason->forCustomer($catalog)->id);
    }

    public function testUnknownAdviceCodeIsAnError(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Catalog::default()->stripe()->resolve('generic_decline', 'retry_tomorrow');
    }
}

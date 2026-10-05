<?php

declare(strict_types=1);

namespace IanFoxDev\DeclineCodes;

/**
 * Decides whether and when to retry a declined payment with the same card. The card
 * network's rules are a ceiling your schedule cannot lift: after a "never" decline there is
 * no retry, a retry never comes before a delay the network asked for, and the number of
 * attempts in the network's window never passes its limit.
 *
 * It keeps no state: pass the attempts with this card at this merchant, oldest first.
 */
final class RetryPolicy
{
    private readonly Schedule $schedule;

    public function __construct(private readonly Catalog $catalog, ?Schedule $schedule = null)
    {
        $this->schedule = $schedule ?? Schedule::default();
    }

    public static function default(): self
    {
        return new self(Catalog::default());
    }

    /**
     * @param list<Attempt> $attempts with this card, oldest first; the last one was declined
     * @param ?int $amount the amount of the next attempt; the last attempt's by default
     */
    public function decide(array $attempts, ?int $amount = null): Decision
    {
        $last = $attempts === [] ? null : $attempts[\count($attempts) - 1];
        if ($last === null || $last->decline === null) {
            throw new \InvalidArgumentException('Pass the attempts with this card; the last one must be a decline.');
        }
        for ($i = 1; $i < \count($attempts); $i++) {
            if ($attempts[$i]->at < $attempts[$i - 1]->at) {
                throw new \InvalidArgumentException('Attempts must be ordered oldest first.');
            }
        }
        $amount ??= $last->amount;

        $never = $this->never($attempts);
        if ($never !== null) {
            return $never;
        }
        $class = $last->decline->class;
        if ($class === DeclineClass::FixData || $class === DeclineClass::CustomerAction) {
            return new Decision(Verdict::NeedsCustomer, 'class.' . $class->value, \sprintf(
                'The decline (%s) needs the customer first: %s Retry once the card data or authentication has changed.',
                $last->decline->reason->id,
                lcfirst($last->decline->reason->description),
            ));
        }

        $since = $this->declinesSinceApproval($attempts);
        if (\count($attempts) >= $this->schedule->maxAttempts) {
            return new Decision(Verdict::GiveUp, 'schedule.max_attempts', \sprintf('%d attempts made; the schedule allows %d.', \count($attempts), $this->schedule->maxAttempts));
        }
        $at = $last->at->modify(\sprintf('+%d seconds', $this->schedule->delayAfter($since, $class)));
        $decision = new Decision(Verdict::RetryAt, 'schedule.' . $class->value, \sprintf('Retry %d of the schedule after a %s decline.', $since, $class->value), $at);

        foreach ($this->ceilings($attempts, $amount) as $ceiling) {
            if ($ceiling->notBefore !== null && $ceiling->notBefore > $decision->notBefore) {
                $decision = $ceiling;
            }
        }

        return $decision;
    }

    /**
     * @param list<Attempt> $attempts
     */
    private function never(array $attempts): ?Decision
    {
        $visa = $this->catalog->visa();
        foreach ($attempts as $attempt) {
            $network = $attempt->networkDecline();
            if ($network !== null && $network->profile === 'visa' && $network->category === 1) {
                return new Decision(Verdict::Never, 'visa.category_1', \sprintf(
                    'Visa code %s (%s) is category 1, "Issuer will never approve": a merchant must never resubmit for the same card.',
                    $network->code,
                    $network->meaning,
                ), null, $visa->source);
            }
            $advice = $network?->advice;
            if ($network !== null && $network->profile === 'mastercard' && $advice !== null && $advice->effect === Advice::NEVER) {
                return new Decision(Verdict::Never, 'mastercard.mac_' . $advice->code, \sprintf(
                    'Mastercard sent Merchant Advice Code %s (%s); resubmitting is charged as an excessive retry.',
                    $advice->code,
                    $advice->meaning,
                ), null, $this->catalog->mastercard()->adviceSource);
            }
        }
        $last = $attempts[\count($attempts) - 1]->decline;
        if ($last !== null && $last->class === DeclineClass::Never) {
            return new Decision(Verdict::Never, 'class.never', \sprintf('The decline (%s) means this card will not be approved: %s', $last->reason->id, lcfirst($last->reason->description)));
        }

        return null;
    }

    /**
     * The earliest time each network rule allows the next attempt.
     *
     * @param list<Attempt> $attempts
     * @return list<Decision>
     */
    private function ceilings(array $attempts, int $amount): array
    {
        $last = $attempts[\count($attempts) - 1];
        $network = $last->networkDecline();
        $ceilings = [];

        // Visa: up to 20 reattempts in 30 days. A reattempt is an attempt that follows a decline.
        $visaReattempts = [];
        $mastercard = false;
        foreach ($attempts as $i => $attempt) {
            $mastercard = $mastercard || $attempt->networkDecline()?->profile === 'mastercard';
            if ($i > 0 && $attempts[$i - 1]->networkDecline()?->profile === 'visa') {
                $visaReattempts[] = $attempt->at;
            }
        }
        if ($network !== null && $network->profile === 'visa') {
            $visa = $this->catalog->visa();
            $limit = $visa->reattemptLimit($network->category ?? 4);
            if ($limit !== null) {
                $at = self::earliestUnder($limit['max'], $visaReattempts, $limit['windowDays'] * 86400, $last->at);
                $ceilings[] = new Decision(Verdict::RetryAt, 'visa.reattempt_limit', \sprintf(
                    'Visa allows up to %d reattempts in %d days after a category %d decline.',
                    $limit['max'],
                    $limit['windowDays'],
                    $network->category ?? 4,
                ), $at, $visa->source);
            }
        }

        if ($network !== null && $network->profile === 'mastercard') {
            $advice = $network->advice;
            if ($advice !== null && $advice->effect === Advice::WAIT && $advice->waitHours !== null) {
                $ceilings[] = new Decision(Verdict::RetryAt, 'mastercard.mac_' . $advice->code, \sprintf(
                    'Mastercard sent Merchant Advice Code %s: %s.',
                    $advice->code,
                    lcfirst($advice->meaning),
                ), $last->at->modify(\sprintf('+%d hours', $advice->waitHours)), $this->catalog->mastercard()->adviceSource);
            }
        }
        if ($mastercard) {
            foreach ($this->catalog->mastercard()->limits() as $limit) {
                if ($limit->effectiveFrom !== null && $last->at < $limit->effectiveFrom) {
                    continue;
                }
                $declines = $this->matchingDeclines($attempts, $limit, $last->acceptor, $amount);
                $at = self::earliestUnder($limit->maxDeclines, $declines, $limit->windowSeconds, $last->at);
                $ceilings[] = new Decision(Verdict::RetryAt, 'mastercard.' . $limit->id, $limit->note, $at, $limit->sources[0] ?? null);
            }
        }

        return $ceilings;
    }

    /**
     * Declined Mastercard attempts that count against a limit: at the same acceptor, with the
     * same amount when the limit says so, and, for consecutive declines, only those since the
     * last approval there.
     *
     * @param list<Attempt> $attempts
     * @return list<\DateTimeImmutable>
     */
    private function matchingDeclines(array $attempts, Limit $limit, string $acceptor, int $amount): array
    {
        $times = [];
        foreach ($attempts as $attempt) {
            if (\in_array('acceptor', $limit->same, true) && $attempt->acceptor !== $acceptor) {
                continue;
            }
            if ($attempt->decline === null) {
                if ($limit->id === 'consecutive_declines') {
                    $times = [];
                }
                continue;
            }
            if (\in_array('amount', $limit->same, true) && $attempt->amount !== $amount) {
                continue;
            }
            if ($attempt->networkDecline()?->profile === 'mastercard') {
                $times[] = $attempt->at;
            }
        }

        return $times;
    }

    /**
     * The earliest moment, not before $from, at which fewer than $max of the given times fall
     * within the window ending at that moment.
     *
     * @param list<\DateTimeImmutable> $times
     */
    private static function earliestUnder(int $max, array $times, int $windowSeconds, \DateTimeImmutable $from): \DateTimeImmutable
    {
        usort($times, static fn(\DateTimeImmutable $a, \DateTimeImmutable $b): int => $a <=> $b);
        $at = $from;
        while (true) {
            $inWindow = array_values(array_filter($times, static fn(\DateTimeImmutable $t): bool => $t->getTimestamp() > $at->getTimestamp() - $windowSeconds));
            if (\count($inWindow) < $max) {
                return $at;
            }
            // Wait until enough of the oldest ones leave the window.
            $at = $inWindow[\count($inWindow) - $max]->modify(\sprintf('+%d seconds', $windowSeconds));
        }
    }

    /**
     * @param list<Attempt> $attempts
     */
    private function declinesSinceApproval(array $attempts): int
    {
        $count = 0;
        foreach ($attempts as $attempt) {
            $count = $attempt->decline === null ? 0 : $count + 1;
        }

        return $count;
    }
}

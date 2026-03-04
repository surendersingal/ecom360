<?php

declare(strict_types=1);

namespace Modules\DataSync\Enums;

/**
 * Consent level determines what data a client has opted into syncing.
 *
 * public     → no PII, always synced when connection is active
 * restricted → behavioural data; needs explicit opt-in from store admin
 * sensitive  → PII (email, name, address); needs full data-processing consent
 */
enum ConsentLevel: string
{
    case Public     = 'public';
    case Restricted = 'restricted';
    case Sensitive  = 'sensitive';

    public function label(): string
    {
        return match ($this) {
            self::Public     => 'Public (Catalog)',
            self::Restricted => 'Restricted (Behavioural)',
            self::Sensitive  => 'Sensitive (PII)',
        };
    }

    /** Whether this level exceeds or equals the given threshold. */
    public function meetsOrExceeds(self $threshold): bool
    {
        return $this->weight() >= $threshold->weight();
    }

    private function weight(): int
    {
        return match ($this) {
            self::Public     => 0,
            self::Restricted => 1,
            self::Sensitive  => 2,
        };
    }
}

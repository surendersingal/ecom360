<?php

declare(strict_types=1);

namespace Modules\DataSync\Enums;

/**
 * Syncable entity types and their default consent level.
 *
 * - public:     No PII — catalog data (products, categories, inventory, sales aggregates).
 * - restricted: Contains customer behaviour — needs explicit opt-in (orders, abandoned carts).
 * - sensitive:  Contains PII — requires full compliance consent (customers, popup captures).
 */
enum SyncEntity: string
{
    // ---- Public (catalog) ----
    case Products       = 'products';
    case Categories     = 'categories';
    case Inventory      = 'inventory';
    case Sales          = 'sales';

    // ---- Restricted (behaviour) ----
    case Orders         = 'orders';
    case AbandonedCarts = 'abandoned_carts';

    // ---- Sensitive (PII) ----
    case Customers      = 'customers';
    case PopupCaptures  = 'popup_captures';

    /** The default consent level for this entity. */
    public function consentLevel(): ConsentLevel
    {
        return match ($this) {
            self::Products, self::Categories, self::Inventory, self::Sales => ConsentLevel::Public,
            self::Orders, self::AbandonedCarts                            => ConsentLevel::Restricted,
            self::Customers, self::PopupCaptures                          => ConsentLevel::Sensitive,
        };
    }

    /** Human-friendly label. */
    public function label(): string
    {
        return match ($this) {
            self::Products       => 'Products',
            self::Categories     => 'Categories',
            self::Inventory      => 'Inventory & Stock',
            self::Sales          => 'Sales Aggregates',
            self::Orders         => 'Orders',
            self::AbandonedCarts => 'Abandoned Carts',
            self::Customers      => 'Customers',
            self::PopupCaptures  => 'Popup Captures',
        };
    }

    /** Whether this entity requires explicit client permission to sync. */
    public function requiresConsent(): bool
    {
        return $this->consentLevel() !== ConsentLevel::Public;
    }
}

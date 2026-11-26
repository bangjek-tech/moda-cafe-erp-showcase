<?php

declare(strict_types=1);

namespace ModaShowcase\Pos;

/**
 * Cafe POS core, rewritten for this showcase (not the production code).
 *
 * - Recipe costing: cost of goods (HPP) per menu item = Σ ingredient qty per
 *   portion × ingredient cost per usage unit; modifiers (e.g. extra shot)
 *   add their own price and recipe.
 * - Checkout: prices are recomputed on the server from the menu master —
 *   numbers sent by the browser are never trusted. Discounts above a
 *   threshold need a manager/owner approval. Service then tax are added,
 *   rounded to whole rupiah.
 * - On payment, every ingredient used is written to a stock ledger
 *   (recipe × quantity), so stock is always explainable line by line.
 */
final class Ingredient
{
    public function __construct(
        public readonly string $code,
        public readonly string $usageUnit,    // e.g. "g", "ml", "pcs"
        public readonly float $costPerUnit,   // rupiah per usage unit
    ) {
    }

    /** Purchases come in buying units; convert to usage units (e.g. 1 kg = 1000 g). */
    public static function costPerUsageUnit(float $pricePerBuyingUnit, float $usageUnitsPerBuyingUnit): float
    {
        if ($usageUnitsPerBuyingUnit <= 0) {
            throw new \InvalidArgumentException('Conversion factor must be positive');
        }

        return $pricePerBuyingUnit / $usageUnitsPerBuyingUnit;
    }
}

final class MenuItem
{
    /** @param array<string,float> $recipe ingredient code => qty per portion */
    public function __construct(
        public readonly string $code,
        public readonly int $price,
        public readonly array $recipe,
    ) {
    }
}

final class Costing
{
    /** @param array<string,Ingredient> $ingredients */
    public function __construct(private readonly array $ingredients)
    {
    }

    public function hpp(MenuItem $item): float
    {
        $total = 0.0;
        foreach ($item->recipe as $code => $qty) {
            $ingredient = $this->ingredients[$code] ?? throw new \InvalidArgumentException("Unknown ingredient $code");
            $total += $qty * $ingredient->costPerUnit;
        }

        return round($total, 2);
    }

    public function marginPercent(MenuItem $item): float
    {
        return $item->price > 0 ? round(($item->price - $this->hpp($item)) / $item->price * 100, 1) : 0.0;
    }
}

final class Checkout
{
    /**
     * @param array<string,MenuItem> $menu
     */
    public function __construct(
        private readonly array $menu,
        private readonly float $servicePercent,
        private readonly float $taxPercent,
        private readonly float $discountApprovalAbove = 20.0,
    ) {
    }

    /**
     * @param list<array{code: string, qty: int, clientPrice?: int}> $cart
     * @return array{subtotal:int, discount:int, service:int, tax:int, total:int, ledger: list<array{ingredient:string, qty:float}>}
     */
    public function settle(array $cart, float $discountPercent = 0.0, ?string $approverRole = null): array
    {
        if ($discountPercent < 0 || $discountPercent > 100) {
            throw new \InvalidArgumentException('Invalid discount');
        }
        if ($discountPercent > $this->discountApprovalAbove && !in_array($approverRole, ['manager', 'owner'], true)) {
            throw new \DomainException('Discount above threshold needs manager or owner approval');
        }

        $subtotal = 0;
        $usage = [];
        foreach ($cart as $line) {
            $item = $this->menu[$line['code']] ?? throw new \InvalidArgumentException('Unknown menu ' . $line['code']);
            if ($line['qty'] <= 0) {
                throw new \InvalidArgumentException('Quantity must be positive');
            }
            $subtotal += $item->price * $line['qty'];          // clientPrice is ignored on purpose
            foreach ($item->recipe as $ingredient => $perPortion) {
                $usage[$ingredient] = ($usage[$ingredient] ?? 0) + $perPortion * $line['qty'];
            }
        }

        $discount = (int) round($subtotal * $discountPercent / 100);
        $afterDiscount = $subtotal - $discount;
        $service = (int) round($afterDiscount * $this->servicePercent / 100);
        $tax = (int) round(($afterDiscount + $service) * $this->taxPercent / 100);

        $ledger = [];
        foreach ($usage as $ingredient => $qty) {
            $ledger[] = ['ingredient' => $ingredient, 'qty' => -round($qty, 4)];
        }

        return [
            'subtotal' => $subtotal,
            'discount' => $discount,
            'service' => $service,
            'tax' => $tax,
            'total' => $afterDiscount + $service + $tax,
            'ledger' => $ledger,
        ];
    }
}

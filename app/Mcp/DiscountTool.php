<?php

declare(strict_types=1);

namespace App\Mcp;

use DomainException;

final class DiscountTool
{
    public const NAME = 'calculate_discount';

    public function definition(): array
    {
        return [
            'name' => self::NAME,
            'title' => '折扣计算器',
            'description' => '根据商品原价、折扣百分比和数量，计算优惠金额与应付金额。',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'original_price' => [
                        'type' => 'number',
                        'minimum' => 0,
                        'description' => '商品原始单价，必须大于或等于 0。',
                    ],
                    'discount_percent' => [
                        'type' => 'number',
                        'minimum' => 0,
                        'maximum' => 100,
                        'description' => '优惠百分比，例如 20 表示减免 20%。',
                    ],
                    'quantity' => [
                        'type' => 'integer',
                        'minimum' => 1,
                        'default' => 1,
                        'description' => '购买数量。',
                    ],
                    'currency' => [
                        'type' => 'string',
                        'pattern' => '^[A-Z]{3}$',
                        'default' => 'CNY',
                        'description' => 'ISO 4217 三位大写货币代码。',
                    ],
                    'precision' => [
                        'type' => 'integer',
                        'minimum' => 0,
                        'maximum' => 4,
                        'default' => 2,
                        'description' => '金额保留的小数位数。',
                    ],
                ],
                'required' => ['original_price', 'discount_percent'],
                'additionalProperties' => false,
            ],
            'outputSchema' => [
                'type' => 'object',
                'properties' => [
                    'currency' => ['type' => 'string'],
                    'original_unit_price' => ['type' => 'number'],
                    'discount_percent' => ['type' => 'number'],
                    'discount_amount_per_unit' => ['type' => 'number'],
                    'final_unit_price' => ['type' => 'number'],
                    'quantity' => ['type' => 'integer'],
                    'total_original_amount' => ['type' => 'number'],
                    'total_discount_amount' => ['type' => 'number'],
                    'total_payable' => ['type' => 'number'],
                ],
                'required' => [
                    'currency',
                    'original_unit_price',
                    'discount_percent',
                    'discount_amount_per_unit',
                    'final_unit_price',
                    'quantity',
                    'total_original_amount',
                    'total_discount_amount',
                    'total_payable',
                ],
                'additionalProperties' => false,
            ],
        ];
    }

    public function invoke(array $arguments): array
    {
        $allowed = ['original_price', 'discount_percent', 'quantity', 'currency', 'precision'];
        $unknown = array_diff(array_keys($arguments), $allowed);
        if ($unknown !== []) {
            throw new DomainException('存在不支持的参数：' . implode(', ', $unknown));
        }

        $originalPrice = $this->number($arguments, 'original_price');
        $discountPercent = $this->number($arguments, 'discount_percent');
        $quantity = $arguments['quantity'] ?? 1;
        $currency = $arguments['currency'] ?? 'CNY';
        $precision = $arguments['precision'] ?? 2;

        if ($originalPrice < 0) {
            throw new DomainException('original_price 必须大于或等于 0。');
        }
        if ($discountPercent < 0 || $discountPercent > 100) {
            throw new DomainException('discount_percent 必须在 0 到 100 之间。');
        }
        if (! is_int($quantity) || $quantity < 1) {
            throw new DomainException('quantity 必须是大于或等于 1 的整数。');
        }
        if (! is_string($currency) || preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new DomainException('currency 必须是三位大写货币代码，例如 CNY。');
        }
        if (! is_int($precision) || $precision < 0 || $precision > 4) {
            throw new DomainException('precision 必须是 0 到 4 之间的整数。');
        }

        $finalUnitPrice = round($originalPrice * (1 - $discountPercent / 100), $precision);
        $discountPerUnit = round($originalPrice - $finalUnitPrice, $precision);
        $totalOriginal = round($originalPrice * $quantity, $precision);
        $totalPayable = round($finalUnitPrice * $quantity, $precision);
        $totalDiscount = round($totalOriginal - $totalPayable, $precision);

        return [
            'currency' => $currency,
            'original_unit_price' => round($originalPrice, $precision),
            'discount_percent' => $discountPercent,
            'discount_amount_per_unit' => $discountPerUnit,
            'final_unit_price' => $finalUnitPrice,
            'quantity' => $quantity,
            'total_original_amount' => $totalOriginal,
            'total_discount_amount' => $totalDiscount,
            'total_payable' => $totalPayable,
        ];
    }

    private function number(array $arguments, string $name): float
    {
        if (! array_key_exists($name, $arguments) || (! is_int($arguments[$name]) && ! is_float($arguments[$name]))) {
            throw new DomainException(sprintf('%s 必须是数字。', $name));
        }

        $value = (float) $arguments[$name];
        if (! is_finite($value)) {
            throw new DomainException(sprintf('%s 必须是有限数字。', $name));
        }

        return $value;
    }
}
